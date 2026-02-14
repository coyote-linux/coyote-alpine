#!/bin/bash
#
# mkrootfs.sh - Build Alpine Linux rootfs for Coyote Linux
#
# This script downloads apk-tools-static and uses it to bootstrap
# an Alpine Linux rootfs without requiring root privileges or containers.
#
# Prerequisites (Fedora):
#   dnf install curl tar gzip
#

set -e

# Configuration
ALPINE_VERSION="3.23"
ALPINE_MIRROR="https://dl-cdn.alpinelinux.org/alpine"
ARCH="x86_64"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Source local config if it exists (can override ALPINE_MIRROR, etc.)
# This file is gitignored and used for local development settings
if [ -f "${SCRIPT_DIR}/.local-config" ]; then
    echo "Loading local config from ${SCRIPT_DIR}/.local-config"
    source "${SCRIPT_DIR}/.local-config"
fi
BUILD_DIR="${SCRIPT_DIR}/../output"
CACHE_DIR="${SCRIPT_DIR}/../.cache"
ROOTFS_DIR="${SCRIPT_DIR}/../output/rootfs"
COYOTE_ROOTFS="${SCRIPT_DIR}/../rootfs"
PACKAGES_FILE="${SCRIPT_DIR}/apk-packages.txt"
CUSTOM_KERNEL_ROOT="${SCRIPT_DIR}/../kernel"

CONFIG_KERNEL_TYPE="${CONFIG_KERNEL_TYPE:-custom}"
CONFIG_DOTNET="${CONFIG_DOTNET:-1}"
CONFIG_LOADBALANCER="${CONFIG_LOADBALANCER:-1}"
CONFIG_IPSEC="${CONFIG_IPSEC:-1}"
CONFIG_OPENVPN="${CONFIG_OPENVPN:-1}"
CONFIG_WIREGUARD="${CONFIG_WIREGUARD:-1}"

normalize_bool() {
    case "$(echo "$1" | tr '[:upper:]' '[:lower:]')" in
        1|y|yes|true|on|enabled) echo "1" ;;
        *) echo "0" ;;
    esac
}

case "$CONFIG_KERNEL_TYPE" in
    custom|alpine-lts) ;;
    *)
        echo "Warning: Invalid CONFIG_KERNEL_TYPE='$CONFIG_KERNEL_TYPE', defaulting to custom"
        CONFIG_KERNEL_TYPE="custom"
        ;;
esac

CONFIG_DOTNET="$(normalize_bool "$CONFIG_DOTNET")"
CONFIG_LOADBALANCER="$(normalize_bool "$CONFIG_LOADBALANCER")"
CONFIG_IPSEC="$(normalize_bool "$CONFIG_IPSEC")"
CONFIG_OPENVPN="$(normalize_bool "$CONFIG_OPENVPN")"
CONFIG_WIREGUARD="$(normalize_bool "$CONFIG_WIREGUARD")"

# Create directories
mkdir -p "$BUILD_DIR" "$CACHE_DIR" "$ROOTFS_DIR"

echo "Using Alpine mirror: ${ALPINE_MIRROR}"

#
# Download and extract apk-tools-static
#
setup_apk_static() {
    local apk_static="${CACHE_DIR}/apk.static"

    if [ -x "$apk_static" ]; then
        echo "Using cached apk-tools-static"
        return 0
    fi

    echo "Downloading apk-tools-static..."

    # Get the latest apk-tools-static package name from the repository
    local repo_url="${ALPINE_MIRROR}/v${ALPINE_VERSION}/main/${ARCH}"
    local apk_pkg=$(curl -s "${repo_url}/" | grep -oP 'apk-tools-static-[0-9][^"]+\.apk' | head -1)

    if [ -z "$apk_pkg" ]; then
        echo "Error: Cannot find apk-tools-static package"
        exit 1
    fi

    local apk_url="${repo_url}/${apk_pkg}"
    local tmp_apk="${CACHE_DIR}/apk-tools-static.apk"

    echo "Downloading: $apk_url"
    curl -L -o "$tmp_apk" "$apk_url"

    # Extract the static binary
    # APK files are gzipped tarballs with a data.tar.gz inside
    echo "Extracting apk.static..."
    cd "$CACHE_DIR"
    tar -xzf "$tmp_apk" 2>/dev/null || true

    # The static binary is in sbin/apk.static
    if [ -f "${CACHE_DIR}/sbin/apk.static" ]; then
        mv "${CACHE_DIR}/sbin/apk.static" "$apk_static"
        chmod +x "$apk_static"
        rm -rf "${CACHE_DIR}/sbin" "${CACHE_DIR}/.PKGINFO" "${CACHE_DIR}/.SIGN."* 2>/dev/null || true
    else
        echo "Error: Failed to extract apk.static"
        exit 1
    fi

    rm -f "$tmp_apk"
    echo "apk-tools-static ready: $apk_static"
}

#
# Download Alpine signing keys
#
setup_alpine_keys() {
    local keys_dir="${CACHE_DIR}/keys"

    if [ -d "$keys_dir" ] && [ "$(ls -A "$keys_dir" 2>/dev/null)" ]; then
        echo "Using cached Alpine keys"
        return 0
    fi

    echo "Downloading Alpine signing keys..."
    mkdir -p "$keys_dir"

    # Download the alpine-keys package and extract
    local repo_url="${ALPINE_MIRROR}/v${ALPINE_VERSION}/main/${ARCH}"
    local keys_pkg=$(curl -s "${repo_url}/" | grep -oP 'alpine-keys-[0-9][^"]+\.apk' | head -1)

    if [ -z "$keys_pkg" ]; then
        echo "Error: Cannot find alpine-keys package"
        exit 1
    fi

    local tmp_keys="${CACHE_DIR}/alpine-keys.apk"
    curl -L -o "$tmp_keys" "${repo_url}/${keys_pkg}"

    cd "$CACHE_DIR"
    tar -xzf "$tmp_keys" 2>/dev/null || true

    if [ -d "${CACHE_DIR}/usr/share/apk/keys" ]; then
        cp -a "${CACHE_DIR}/usr/share/apk/keys/"* "$keys_dir/"
        rm -rf "${CACHE_DIR}/usr" "${CACHE_DIR}/.PKGINFO" "${CACHE_DIR}/.SIGN."* 2>/dev/null || true
    fi

    # Also download the specific keys needed for package verification
    for key in "alpine-devel@lists.alpinelinux.org-4a6a0840.rsa.pub" \
               "alpine-devel@lists.alpinelinux.org-5243ef4b.rsa.pub" \
               "alpine-devel@lists.alpinelinux.org-5261cecb.rsa.pub" \
               "alpine-devel@lists.alpinelinux.org-6165ee59.rsa.pub" \
               "alpine-devel@lists.alpinelinux.org-61666e3f.rsa.pub" \
               "alpine-devel@lists.alpinelinux.org-616a9724.rsa.pub" \
               "alpine-devel@lists.alpinelinux.org-616abc23.rsa.pub" \
               "alpine-devel@lists.alpinelinux.org-616adfeb.rsa.pub" \
               "alpine-devel@lists.alpinelinux.org-616ae350.rsa.pub" \
               "alpine-devel@lists.alpinelinux.org-616db30d.rsa.pub"; do
        if [ ! -f "${keys_dir}/${key}" ]; then
            curl -s -o "${keys_dir}/${key}" "https://alpinelinux.org/keys/${key}" 2>/dev/null || true
        fi
    done

    rm -f "$tmp_keys"
    echo "Alpine keys ready"
}

#
# Initialize the rootfs
#
init_rootfs() {
    local apk_static="${CACHE_DIR}/apk.static"
    local keys_dir="${CACHE_DIR}/keys"

    echo "Initializing rootfs at: $ROOTFS_DIR"

    # Clean existing rootfs
    rm -rf "$ROOTFS_DIR"
    mkdir -p "$ROOTFS_DIR"

    # Create essential directories
    mkdir -p "${ROOTFS_DIR}/etc/apk/keys"
    mkdir -p "${ROOTFS_DIR}/var/cache/apk"
    mkdir -p "${ROOTFS_DIR}/lib/apk/db"

    # Copy Alpine signing keys
    cp -a "${keys_dir}/"* "${ROOTFS_DIR}/etc/apk/keys/"

    # Create repositories file
    cat > "${ROOTFS_DIR}/etc/apk/repositories" << EOF
${ALPINE_MIRROR}/v${ALPINE_VERSION}/main
${ALPINE_MIRROR}/v${ALPINE_VERSION}/community
EOF

    # Initialize the APK database
    "$apk_static" \
        --root "$ROOTFS_DIR" \
        --keys-dir "${ROOTFS_DIR}/etc/apk/keys" \
        --usermode \
        --initdb \
        --update-cache \
        add --no-scripts

    echo "Rootfs initialized"
}

#
# Install packages from apk-packages.txt
#
install_packages() {
    local apk_static="${CACHE_DIR}/apk.static"

    if [ ! -f "$PACKAGES_FILE" ]; then
        echo "Error: Packages file not found: $PACKAGES_FILE"
        exit 1
    fi

    echo "Installing packages from: $PACKAGES_FILE"

    should_include_package() {
        local package_name="$1"

        case "$package_name" in
            linux-lts)
                [ "$CONFIG_KERNEL_TYPE" = "alpine-lts" ]
                return
                ;;
            dotnet10-runtime|dotnet10-sdk)
                [ "$CONFIG_DOTNET" = "1" ]
                return
                ;;
            haproxy)
                [ "$CONFIG_LOADBALANCER" = "1" ]
                return
                ;;
            strongswan)
                [ "$CONFIG_IPSEC" = "1" ]
                return
                ;;
            openvpn|easy-rsa)
                [ "$CONFIG_OPENVPN" = "1" ]
                return
                ;;
            wireguard-tools)
                [ "$CONFIG_WIREGUARD" = "1" ]
                return
                ;;
        esac

        return 0
    }

    local packages=""
    local package
    while IFS= read -r package || [ -n "$package" ]; do
        package="${package%%#*}"
        package="${package#${package%%[![:space:]]*}}"
        package="${package%${package##*[![:space:]]}}"

        [ -z "$package" ] && continue

        if should_include_package "$package"; then
            packages="${packages} ${package}"
        fi
    done < "$PACKAGES_FILE"

    echo "Packages to install: $packages"

    # Install packages
    # Using --no-scripts because some scripts expect to run as root
    # Using --usermode to allow running as non-root user
    # We'll handle necessary setup manually
    "$apk_static" \
        --root "$ROOTFS_DIR" \
        --keys-dir "${ROOTFS_DIR}/etc/apk/keys" \
        --usermode \
        --no-cache \
        --no-scripts \
        add $packages

    echo "Packages installed"
}

#
# Create busybox symlinks for all available applets
#
create_busybox_symlinks() {
    echo "Creating busybox symlinks..."

    local busybox="${ROOTFS_DIR}/bin/busybox"
    if [ ! -f "$busybox" ]; then
        echo "Warning: busybox not found, skipping symlinks"
        return
    fi

    # Complete list of Alpine busybox applets (from busybox --list on Alpine 3.23)
    # Note: Cannot run busybox --list on build host due to musl/glibc mismatch
    local applets="acpid add-shell addgroup adduser adjtimex arch arp arping ash awk base64 basename bbconfig bc beep blkdiscard blkid blockdev brctl bunzip2 bzcat bzip2 cal cat chattr chgrp chmod chown chpasswd chroot chvt cksum clear cmp comm cp cpio crond crontab cryptpw cut date dc dd deallocvt delgroup deluser depmod df diff dirname dmesg dnsdomainname dos2unix du dumpkmap echo egrep eject env ether-wake expand expr factor fallocate false fatattr fbset fbsplash fdflush fdisk fgrep find findfs flock fold free fsck fstrim fsync fuser getopt getty grep groups gunzip gzip halt hd head hexdump hostid hostname hwclock id ifconfig ifdown ifenslave ifup init inotifyd insmod install ionice iostat ip ipaddr ipcalc ipcrm ipcs iplink ipneigh iproute iprule iptunnel kbd_mode kill killall killall5 klogd last less link linux32 linux64 ln loadfont loadkmap logger login logread losetup ls lsattr lsmod lsof lsusb lzcat lzma lzop lzopcat makemime md5sum mdev mesg microcom mkdir mkdosfs mkfifo mknod mkpasswd mkswap mktemp modinfo modprobe more mount mountpoint mpstat mv nameif nanddump nandwrite nbd-client nc netstat nice nl nmeter nohup nologin nproc nsenter nslookup ntpd od openvt partprobe passwd paste pgrep pidof ping ping6 pipe_progress pivot_root pkill pmap poweroff printenv printf ps pscan pstree pwd pwdx raidautorun rdate rdev readahead readlink realpath reboot reformime remove-shell renice reset resize rev rfkill rm rmdir rmmod route run-parts sed sendmail seq setconsole setfont setkeycodes setlogcons setpriv setserial setsid sh sha1sum sha256sum sha3sum sha512sum showkey shred shuf slattach sleep sort split stat strings stty su sum swapoff swapon switch_root sync sysctl syslogd tac tail tar tee test time timeout top touch tr traceroute traceroute6 tree true truncate tty ttysize tunctl udhcpc udhcpc6 umount uname unexpand uniq unix2dos unlink unlzma unlzop unshare unxz unzip uptime usleep uudecode uuencode vconfig vi vlock volname watch watchdog wc wget which who whoami whois xargs xxd xzcat yes zcat zcip"

    # Applets that belong in /sbin (system administration)
    local sbin_applets=" acpid addgroup adduser blkid blockdev crond depmod delgroup deluser fdisk findfs fsck fstrim getty halt hwclock ifconfig ifdown ifenslave ifup init insmod klogd loadkmap losetup lsmod mdev mkdosfs mkswap modinfo modprobe mount nologin partprobe pivot_root poweroff raidautorun rdate reboot rmmod route runlevel slattach sulogin swapoff swapon switch_root sysctl syslogd tunctl udhcpc udhcpc6 umount vconfig watchdog zcip "

    # Applets that init scripts expect in /usr/sbin
    local usrsbin_applets=" crond ntpd "

    local bin_count=0
    local sbin_count=0

    for applet in $applets; do
        # Check if this applet belongs in /sbin
        if echo "$sbin_applets" | grep -q " ${applet} "; then
            # Create in /sbin
            ln -sf /bin/busybox "${ROOTFS_DIR}/sbin/${applet}"
            sbin_count=$((sbin_count + 1))
        else
            # Create in /bin
            ln -sf busybox "${ROOTFS_DIR}/bin/${applet}"
            bin_count=$((bin_count + 1))
        fi
    done

    # Create /usr/sbin symlinks for daemons expected there by init scripts
    mkdir -p "${ROOTFS_DIR}/usr/sbin"
    for applet in $usrsbin_applets; do
        ln -sf /bin/busybox "${ROOTFS_DIR}/usr/sbin/${applet}"
    done

    echo "Created $bin_count symlinks in /bin, $sbin_count symlinks in /sbin"
}

install_custom_kernel_modules() {
    if [ "$CONFIG_KERNEL_TYPE" = "alpine-lts" ]; then
        if [ -d "${ROOTFS_DIR}/lib/modules" ] && [ -n "$(ls -A "${ROOTFS_DIR}/lib/modules" 2>/dev/null)" ]; then
            echo "Using Alpine LTS kernel modules from package install"
            return 0
        fi

        echo "Warning: Alpine LTS kernel modules not found in rootfs"
        return 0
    fi

    local archive=""

    if [ -n "${KERNEL_VERSION:-}" ]; then
        archive="${CUSTOM_KERNEL_ROOT}/output/modules-${KERNEL_VERSION}.tar.gz"
    fi

    if [ -z "$archive" ] || [ ! -f "$archive" ]; then
        archive=$(ls -t "${CUSTOM_KERNEL_ROOT}/output"/modules-*.tar.gz 2>/dev/null | head -1)
    fi

    if [ -z "$archive" ]; then
        echo "Warning: No custom kernel modules archive found; rootfs will lack kernel modules"
        return 0
    fi

    echo "Installing custom kernel modules from ${archive}"
    rm -rf "${ROOTFS_DIR}/lib/modules"
    mkdir -p "${ROOTFS_DIR}/lib"
    tar -xzf "$archive" -C "${ROOTFS_DIR}"
}

#
# Apply Coyote-specific overlay
#
apply_coyote_overlay() {
    echo "Applying Coyote overlay..."

    # Copy Coyote-specific files from the source rootfs
    if [ -d "$COYOTE_ROOTFS" ]; then
        # Handle symlinks that would replace directories
        # (e.g., /root is a symlink to /mnt/config/root for persistence)
        for item in "${COYOTE_ROOTFS}/"*; do
            local basename=$(basename "$item")
            local target="${ROOTFS_DIR}/${basename}"

            # If source is a symlink and target is a directory, remove target first
            if [ -L "$item" ] && [ -d "$target" ] && [ ! -L "$target" ]; then
                echo "Replacing directory $target with symlink..."
                rm -rf "$target"
            fi
        done

        cp -a "${COYOTE_ROOTFS}/"* "$ROOTFS_DIR/"
    fi

    # Create essential directories that may be missing
    mkdir -p "${ROOTFS_DIR}/dev"
    mkdir -p "${ROOTFS_DIR}/proc"
    mkdir -p "${ROOTFS_DIR}/sys"
    mkdir -p "${ROOTFS_DIR}/tmp"
    mkdir -p "${ROOTFS_DIR}/run"
    mkdir -p "${ROOTFS_DIR}/mnt/config"
    mkdir -p "${ROOTFS_DIR}/mnt/boot"
    mkdir -p "${ROOTFS_DIR}/opt/coyote/addons"
    mkdir -p "${ROOTFS_DIR}/tmp/running-config"

    # Set permissions
    chmod 1777 "${ROOTFS_DIR}/tmp"

    # Create basic device nodes (these are typically created at runtime,
    # but having them in the image doesn't hurt)
    # Note: mknod requires root, so we skip this for now
    # The initramfs will mount devtmpfs which provides these

    # Install installer scripts
    local installer_dir="${SCRIPT_DIR}/../installer"
    if [ -d "$installer_dir" ]; then
        echo "Installing installer scripts..."
        mkdir -p "${ROOTFS_DIR}/usr/bin"
        cp "${installer_dir}/install.sh" "${ROOTFS_DIR}/usr/bin/installer.sh"
        cp "${installer_dir}/tty1-handler.sh" "${ROOTFS_DIR}/usr/bin/tty1-handler.sh"
        chmod +x "${ROOTFS_DIR}/usr/bin/installer.sh"
        chmod +x "${ROOTFS_DIR}/usr/bin/tty1-handler.sh"
    fi

    # Create /etc/inittab with tty1-handler for installer mode support
    cat > "${ROOTFS_DIR}/etc/inittab" << 'INITTAB'
# /etc/inittab - Coyote Linux

::sysinit:/sbin/openrc sysinit
::sysinit:/sbin/openrc boot
::wait:/sbin/openrc default

# tty1 uses handler that detects installer mode
tty1::respawn:/usr/bin/tty1-handler.sh
tty2::respawn:/sbin/getty 38400 tty2
tty3::respawn:/sbin/getty 38400 tty3

# Serial console (disabled - enable if serial console is available)
# ttyS0::respawn:/sbin/getty -L ttyS0 115200 vt100

::shutdown:/sbin/openrc shutdown
::ctrlaltdel:/sbin/reboot
INITTAB

    # Create /etc/hostname
    echo "coyote" > "${ROOTFS_DIR}/etc/hostname"

    # Create /etc/hosts
    cat > "${ROOTFS_DIR}/etc/hosts" << 'EOF'
127.0.0.1	localhost localhost.localdomain
::1		localhost localhost.localdomain
EOF

    # Create /etc/resolv.conf placeholder
    echo "# Configured by Coyote Linux" > "${ROOTFS_DIR}/etc/resolv.conf"

    # Create system users and groups
    echo "Creating system users and groups..."

    # Add lighttpd group and user (system account for web server)
    if ! grep -q "^lighttpd:" "${ROOTFS_DIR}/etc/group" 2>/dev/null; then
        echo "lighttpd:x:100:" >> "${ROOTFS_DIR}/etc/group"
    fi
    if ! grep -q "^lighttpd:" "${ROOTFS_DIR}/etc/passwd" 2>/dev/null; then
        echo "lighttpd:x:100:100:lighttpd:/var/www:/sbin/nologin" >> "${ROOTFS_DIR}/etc/passwd"
    fi
    if ! grep -q "^lighttpd:" "${ROOTFS_DIR}/etc/shadow" 2>/dev/null; then
        echo "lighttpd:!::0:::::" >> "${ROOTFS_DIR}/etc/shadow"
    fi

    if ! grep -q "^dnsmasq:" "${ROOTFS_DIR}/etc/group" 2>/dev/null; then
        echo "dnsmasq:x:101:" >> "${ROOTFS_DIR}/etc/group"
    fi
    if ! grep -q "^dnsmasq:" "${ROOTFS_DIR}/etc/passwd" 2>/dev/null; then
        echo "dnsmasq:x:101:101:dnsmasq:/var/lib/misc:/sbin/nologin" >> "${ROOTFS_DIR}/etc/passwd"
    fi
    if ! grep -q "^dnsmasq:" "${ROOTFS_DIR}/etc/shadow" 2>/dev/null; then
        echo "dnsmasq:!::0:::::" >> "${ROOTFS_DIR}/etc/shadow"
    fi

    # Add admin group and user (non-privileged user for future use)
    if ! grep -q "^admin:" "${ROOTFS_DIR}/etc/group" 2>/dev/null; then
        echo "admin:x:1000:" >> "${ROOTFS_DIR}/etc/group"
    fi
    if ! grep -q "^admin:" "${ROOTFS_DIR}/etc/passwd" 2>/dev/null; then
        echo "admin:x:1000:1000:Coyote Admin:/home/admin:/bin/sh" >> "${ROOTFS_DIR}/etc/passwd"
    fi
    if ! grep -q "^admin:" "${ROOTFS_DIR}/etc/shadow" 2>/dev/null; then
        # Password is locked (!) until set
        echo "admin:!::0:::::" >> "${ROOTFS_DIR}/etc/shadow"
    fi
    mkdir -p "${ROOTFS_DIR}/home/admin"
    chmod 755 "${ROOTFS_DIR}/home/admin"

    # Set default root password (password: coyote)
    # Users should change this on first login
    local root_hash='$6$coyote$UOHRCUYPULONPztxMmmSzxIbCjkf4Gjljpocw4eCjIGExMr35nyHdsovoP2PkChE.yg6QIBeQoJT4RLjmJ3A9/'
    if [ -f "${ROOTFS_DIR}/etc/shadow" ]; then
        sed -i "s|^root:[^:]*:|root:${root_hash}:|" "${ROOTFS_DIR}/etc/shadow"
        echo "Set default root password (password: coyote)"
    fi

    # Create /var/www for lighttpd
    mkdir -p "${ROOTFS_DIR}/var/www"

    # Create /etc/fstab
    cat > "${ROOTFS_DIR}/etc/fstab" << 'EOF'
# Coyote Linux fstab
# Most filesystems are mounted by the initramfs
none		/tmp		tmpfs	nosuid,nodev,mode=1777	0 0
none		/run		tmpfs	nosuid,nodev,mode=0755	0 0
EOF

    # Enable Coyote services (create symlinks for OpenRC)
    mkdir -p "${ROOTFS_DIR}/etc/runlevels/default"
    mkdir -p "${ROOTFS_DIR}/etc/runlevels/boot"

    # Link standard services (excluding networking - handled by coyote-config)
    # Note: bootmisc is excluded because it tries to migrate /var/run on read-only rootfs
    for svc in hostname hwclock modules sysctl urandom; do
        if [ -f "${ROOTFS_DIR}/etc/init.d/${svc}" ]; then
            ln -sf "/etc/init.d/${svc}" "${ROOTFS_DIR}/etc/runlevels/boot/" 2>/dev/null || true
        fi
    done

    # Link Coyote services
    if [ -f "${ROOTFS_DIR}/etc/init.d/coyote-init" ]; then
        ln -sf "/etc/init.d/coyote-init" "${ROOTFS_DIR}/etc/runlevels/default/"
    fi
    if [ -f "${ROOTFS_DIR}/etc/init.d/coyote-config" ]; then
        ln -sf "/etc/init.d/coyote-config" "${ROOTFS_DIR}/etc/runlevels/default/"
    fi

    # Make CLI tools executable
    if [ -d "${ROOTFS_DIR}/opt/coyote/bin" ]; then
        chmod +x "${ROOTFS_DIR}/opt/coyote/bin/"* 2>/dev/null || true
    fi

    # Create PHP symlink (Alpine uses php83, scripts expect 'php')
    if [ -x "${ROOTFS_DIR}/usr/bin/php83" ] && [ ! -e "${ROOTFS_DIR}/usr/bin/php" ]; then
        ln -sf php83 "${ROOTFS_DIR}/usr/bin/php"
        echo "Created /usr/bin/php -> php83 symlink"
    fi

    # Create env symlink (Alpine has /bin/env, scripts expect /usr/bin/env)
    if [ -x "${ROOTFS_DIR}/bin/env" ] && [ ! -e "${ROOTFS_DIR}/usr/bin/env" ]; then
        ln -sf /bin/env "${ROOTFS_DIR}/usr/bin/env"
        echo "Created /usr/bin/env -> /bin/env symlink"
    fi

    echo "Coyote overlay applied"
}

#
# Clean up the rootfs
#
cleanup_rootfs() {
    echo "Cleaning up rootfs..."

    # Remove APK cache
    rm -rf "${ROOTFS_DIR}/var/cache/apk/"*

    # Remove APK package database to save space (optional, makes image smaller)
    # Uncomment if you don't need apk in the final image:
    rm -rf "${ROOTFS_DIR}/lib/apk"
    rm -rf "${ROOTFS_DIR}/etc/apk"

    # Remove unnecessary files
    rm -rf "${ROOTFS_DIR}/usr/share/man"
    rm -rf "${ROOTFS_DIR}/usr/share/doc"
    rm -rf "${ROOTFS_DIR}/usr/share/info"

    # Remove unnecessary firmware - keep only what's needed for a network appliance
    # This dramatically reduces image size (from ~900MB to ~100MB)
    echo "Removing unnecessary firmware..."
    local fw_dir="${ROOTFS_DIR}/lib/firmware"
    if [ -d "$fw_dir" ]; then
        local before_size=$(du -sm "$fw_dir" | cut -f1)

        # GPU firmware - not needed for headless firewall
        rm -rf "${fw_dir}/amdgpu"
        rm -rf "${fw_dir}/radeon"
        rm -rf "${fw_dir}/nvidia"
        rm -rf "${fw_dir}/i915"
        rm -rf "${fw_dir}/xe"
        rm -rf "${fw_dir}/matrox"
        rm -rf "${fw_dir}/r128"

        # WiFi firmware - typically not needed for wired firewall
        rm -rf "${fw_dir}/ath9k_htc"
        rm -rf "${fw_dir}/ath10k"
        rm -rf "${fw_dir}/ath11k"
        rm -rf "${fw_dir}/ath12k"
        rm -rf "${fw_dir}/ath6k"
        rm -rf "${fw_dir}/iwlwifi"*
        rm -rf "${fw_dir}/rtlwifi"
        rm -rf "${fw_dir}/rtw88"
        rm -rf "${fw_dir}/rtw89"
        rm -rf "${fw_dir}/brcm"
        rm -rf "${fw_dir}/mwlwifi"
        rm -rf "${fw_dir}/mwl8k"
        rm -rf "${fw_dir}/mrvl"
        rm -rf "${fw_dir}/libertas"
        rm -rf "${fw_dir}/ti-connectivity"
        rm -rf "${fw_dir}/mediatek"
        rm -rf "${fw_dir}/qca"
        rm -rf "${fw_dir}/ar3k"
        rm -rf "${fw_dir}/rsi"
        rm -rf "${fw_dir}/wfx"
        rm -rf "${fw_dir}/cypress"
        rm -rf "${fw_dir}/nxp"
        rm -rf "${fw_dir}/airoha"

        # Bluetooth firmware - not needed
        rm -rf "${fw_dir}/intel/ibt-"*
        rm -rf "${fw_dir}/rtl_bt"
        rm -rf "${fw_dir}/qcom"

        # Sound/media firmware - not needed
        rm -rf "${fw_dir}/yamaha"
        rm -rf "${fw_dir}/korg"
        rm -rf "${fw_dir}/ess"
        rm -rf "${fw_dir}/sb16"
        rm -rf "${fw_dir}/dsp56k"
        rm -rf "${fw_dir}/cpia2"
        rm -rf "${fw_dir}/go7007"
        rm -rf "${fw_dir}/s5p-mfc"
        rm -rf "${fw_dir}/av7110"
        rm -rf "${fw_dir}/ttusb-budget"
        rm -rf "${fw_dir}/vicam"
        rm -rf "${fw_dir}/dabusb"
        rm -rf "${fw_dir}/v4l-"*

        # Mobile/embedded platform firmware - not needed for x86 firewall
        rm -rf "${fw_dir}/qcom"
        rm -rf "${fw_dir}/arm"
        rm -rf "${fw_dir}/rockchip"
        rm -rf "${fw_dir}/meson"
        rm -rf "${fw_dir}/amlogic"
        rm -rf "${fw_dir}/imx"
        rm -rf "${fw_dir}/amphion"
        rm -rf "${fw_dir}/cadence"
        rm -rf "${fw_dir}/cnm"
        rm -rf "${fw_dir}/powervr"
        rm -rf "${fw_dir}/cirrus"
        rm -rf "${fw_dir}/dpaa2"
        rm -rf "${fw_dir}/ti"
        rm -rf "${fw_dir}/ti-keystone"

        # Other unnecessary firmware
        rm -rf "${fw_dir}/amd-ucode"  # CPU microcode loaded by bootloader
        rm -rf "${fw_dir}/intel-ucode"  # CPU microcode loaded by bootloader
        rm -rf "${fw_dir}/amdtee"
        rm -rf "${fw_dir}/amdnpu"
        rm -rf "${fw_dir}/cis"
        rm -rf "${fw_dir}/ositech"
        rm -rf "${fw_dir}/yam"
        rm -rf "${fw_dir}/3com"
        rm -rf "${fw_dir}/adaptec"
        rm -rf "${fw_dir}/advansys"
        rm -rf "${fw_dir}/atusb"
        rm -rf "${fw_dir}/keyspan"*
        rm -rf "${fw_dir}/edgeport"
        rm -rf "${fw_dir}/emi26"
        rm -rf "${fw_dir}/emi62"
        rm -rf "${fw_dir}/kaweth"
        rm -rf "${fw_dir}/moxa"
        rm -rf "${fw_dir}/microchip"
        rm -rf "${fw_dir}/ueagle-atm"
        rm -rf "${fw_dir}/sun"
        rm -rf "${fw_dir}/inside-secure"

        # Large unnecessary driver firmware
        rm -rf "${fw_dir}/liquidio"
        rm -rf "${fw_dir}/netronome"
        rm -rf "${fw_dir}/mellanox"
        rm -rf "${fw_dir}/qed"
        rm -rf "${fw_dir}/myricom"
        rm -rf "${fw_dir}/cxgb3"
        rm -rf "${fw_dir}/cxgb4"
        rm -rf "${fw_dir}/bnx2x"  # Keep bnx2 but remove bnx2x (10GbE)

        local after_size=$(du -sm "$fw_dir" | cut -f1)
        echo "  Firmware reduced: ${before_size}MB -> ${after_size}MB"
    fi

    # Remove kernel source/build files if present
    rm -rf "${ROOTFS_DIR}/usr/src"

    # Remove unnecessary kernel modules (keep networking, storage, filesystems)
    echo "Cleaning up kernel modules..."
    local mod_dir="${ROOTFS_DIR}/lib/modules"
    if [ -d "$mod_dir" ]; then
        local kver=$(ls "$mod_dir" | head -1)
        if [ -n "$kver" ] && [ -d "${mod_dir}/${kver}/kernel" ]; then
            local km="${mod_dir}/${kver}/kernel"
            local before_size=$(du -sm "$mod_dir" | cut -f1)

            # Remove sound modules
            rm -rf "${km}/sound"

            # Remove GPU/DRM modules (keep basic framebuffer)
            rm -rf "${km}/drivers/gpu/drm/amd"
            rm -rf "${km}/drivers/gpu/drm/nouveau"
            rm -rf "${km}/drivers/gpu/drm/radeon"
            rm -rf "${km}/drivers/gpu/drm/i915"
            rm -rf "${km}/drivers/gpu/drm/xe"

            # Remove media/video capture modules
            rm -rf "${km}/drivers/media"

            # Remove staging drivers
            rm -rf "${km}/drivers/staging"

            # Remove wireless modules
            rm -rf "${km}/drivers/net/wireless"
            rm -rf "${km}/net/wireless"
            rm -rf "${km}/net/mac80211"

            # Remove Bluetooth
            rm -rf "${km}/drivers/bluetooth"
            rm -rf "${km}/net/bluetooth"

            # Remove IIO (industrial I/O)
            rm -rf "${km}/drivers/iio"

            # Remove misc unnecessary modules
            rm -rf "${km}/drivers/isdn"
            rm -rf "${km}/drivers/infiniband"
            rm -rf "${km}/drivers/thunderbolt"
            rm -rf "${km}/drivers/android"

            local after_size=$(du -sm "$mod_dir" | cut -f1)
            echo "  Modules reduced: ${before_size}MB -> ${after_size}MB"
        fi
    fi

    # Calculate final size
    local size=$(du -sh "$ROOTFS_DIR" | cut -f1)
    echo "Rootfs size: $size"
}

#
# Main
#
main() {
    echo "=========================================="
    echo "Coyote Linux Rootfs Builder"
    echo "Alpine Linux ${ALPINE_VERSION} (${ARCH})"
    echo "Kernel type: ${CONFIG_KERNEL_TYPE}"
    echo "Feature set: dotnet=${CONFIG_DOTNET} loadbalancer=${CONFIG_LOADBALANCER} ipsec=${CONFIG_IPSEC} openvpn=${CONFIG_OPENVPN} wireguard=${CONFIG_WIREGUARD}"
    echo "=========================================="
    echo ""

    setup_apk_static
    setup_alpine_keys
    init_rootfs
    install_packages
    install_custom_kernel_modules
    create_busybox_symlinks
    apply_coyote_overlay
    cleanup_rootfs

    echo ""
    echo "=========================================="
    echo "Rootfs build complete: $ROOTFS_DIR"
    echo ""
    echo "Next steps:"
    echo "  make firmware    # Create squashfs image"
    echo "  make installer   # Create bootable installer"
    echo "=========================================="
}

main "$@"
