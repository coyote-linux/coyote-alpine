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
BUILD_DIR="${SCRIPT_DIR}/../output"
CACHE_DIR="${SCRIPT_DIR}/../.cache"
ROOTFS_DIR="${SCRIPT_DIR}/../output/rootfs"
COYOTE_ROOTFS="${SCRIPT_DIR}/../rootfs"
PACKAGES_FILE="${SCRIPT_DIR}/apk-packages.txt"

# Create directories
mkdir -p "$BUILD_DIR" "$CACHE_DIR" "$ROOTFS_DIR"

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

    # Read packages, filter comments and empty lines
    local packages=$(grep -v '^#' "$PACKAGES_FILE" | grep -v '^$' | tr '\n' ' ')

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
# Create busybox symlinks that --no-scripts skipped
#
create_busybox_symlinks() {
    echo "Creating busybox symlinks..."

    local busybox="${ROOTFS_DIR}/bin/busybox"
    if [ ! -x "$busybox" ]; then
        echo "Warning: busybox not found, skipping symlinks"
        return
    fi

    # Get list of applets from busybox itself
    # Run busybox --list to get all supported applets
    local applets
    applets=$("$busybox" --list 2>/dev/null) || {
        echo "Warning: Could not get busybox applet list"
        # Fallback to essential applets
        applets="[ [[ ar ash awk base64 basename cat chgrp chmod chown chroot clear cmp cp cut date dd df dirname dmesg du echo ed egrep env expr false fgrep find free grep gunzip gzip head hostname id install kill killall less ln ls md5sum mkdir mknod mktemp more mount mv nproc od passwd ping ping6 printf ps pwd readlink realpath rm rmdir sed seq sh sha256sum sha512sum sleep sort stat strings stty su sync tail tar tee test time touch tr true truncate tty uname uniq unlink usleep vi wc wget which xargs yes zcat"
    }

    # Create /bin symlinks
    local count=0
    for applet in $applets; do
        if [ ! -e "${ROOTFS_DIR}/bin/${applet}" ] && [ ! -e "${ROOTFS_DIR}/sbin/${applet}" ] && [ ! -e "${ROOTFS_DIR}/usr/bin/${applet}" ] && [ ! -e "${ROOTFS_DIR}/usr/sbin/${applet}" ]; then
            ln -sf busybox "${ROOTFS_DIR}/bin/${applet}"
            count=$((count + 1))
        fi
    done

    # Create essential /sbin symlinks
    local sbin_applets="init halt poweroff reboot hwclock ifconfig ifup ifdown route sysctl modprobe insmod lsmod rmmod depmod fsck getty sulogin"
    for applet in $sbin_applets; do
        if [ ! -e "${ROOTFS_DIR}/sbin/${applet}" ]; then
            ln -sf /bin/busybox "${ROOTFS_DIR}/sbin/${applet}"
            count=$((count + 1))
        fi
    done

    # Force-create symlinks for busybox-suid commands that don't work in rootless build
    # These commands (login, su, passwd) normally need setuid but we can't set that up
    # as non-root. Force symlink to regular busybox - functionality may be limited.
    local suid_applets="login su passwd"
    for applet in $suid_applets; do
        rm -f "${ROOTFS_DIR}/bin/${applet}" 2>/dev/null || true
        ln -sf busybox "${ROOTFS_DIR}/bin/${applet}"
        count=$((count + 1))
    done

    echo "Created $count busybox symlinks"
}

#
# Apply Coyote-specific overlay
#
apply_coyote_overlay() {
    echo "Applying Coyote overlay..."

    # Copy Coyote-specific files from the source rootfs
    if [ -d "$COYOTE_ROOTFS" ]; then
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

# Serial console
ttyS0::respawn:/sbin/getty -L ttyS0 115200 vt100

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
    for svc in bootmisc hostname hwclock modules sysctl urandom; do
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
    # rm -rf "${ROOTFS_DIR}/lib/apk"
    # rm -rf "${ROOTFS_DIR}/etc/apk"

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
    echo "=========================================="
    echo ""

    setup_apk_static
    setup_alpine_keys
    init_rootfs
    install_packages
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
