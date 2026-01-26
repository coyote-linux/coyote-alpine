#!/bin/sh
#
# Coyote Linux Installer
#
# This script guides the user through installing Coyote Linux to a target disk.
# Uses the 'dialog' utility for a professional TUI experience.
#

# Dialog settings
DIALOG=${DIALOG:-dialog}
DIALOG_OK=0
DIALOG_CANCEL=1
DIALOG_ESC=255
BACKTITLE="Coyote Linux 4 - Installation Wizard"

# Temp file for dialog output
DIALOG_TEMP=$(mktemp)
trap "rm -f $DIALOG_TEMP" EXIT

# Installation paths
BOOT_MEDIA="${BOOT_MEDIA:-/mnt/boot}"
FIRMWARE_SRC="${BOOT_MEDIA}/firmware/current.squashfs"

# Upgrade mode flag
UPGRADE_MODE=0

# Firmware signature status
FIRMWARE_SIGNED=0
FIRMWARE_SIG_VALID=0

#
# Dialog helper functions
#

# Show an error message
msg_error() {
    $DIALOG --backtitle "$BACKTITLE" \
        --title "Error" \
        --msgbox "$1" 8 50
}

# Show an info message
msg_info() {
    $DIALOG --backtitle "$BACKTITLE" \
        --title "$1" \
        --msgbox "$2" 10 60
}

# Show a yes/no question, returns 0 for yes
ask_yesno() {
    $DIALOG --backtitle "$BACKTITLE" \
        --title "$1" \
        --yesno "$2" 10 60
}

# Show progress gauge
# Usage: show_progress "title" "text" percent
show_progress() {
    echo "$3" | $DIALOG --backtitle "$BACKTITLE" \
        --title "$1" \
        --gauge "$2" 8 60 0
}

# Update progress gauge (pipe percentages to this)
# Usage: command | progress_gauge "title" "text"
progress_gauge() {
    $DIALOG --backtitle "$BACKTITLE" \
        --title "$1" \
        --gauge "$2" 8 60 0
}

#
# Firmware verification
#

verify_firmware_source() {
    local firmware="$FIRMWARE_SRC"
    local sigfile="${firmware}.sig"
    local pubkey="/etc/coyote/keys/firmware-signing.pub"

    # Check if signature file exists
    if [ ! -f "$sigfile" ]; then
        FIRMWARE_SIGNED=0
        FIRMWARE_SIG_VALID=0
        return 0
    fi

    FIRMWARE_SIGNED=1

    # Check if public key exists
    if [ ! -f "$pubkey" ]; then
        FIRMWARE_SIG_VALID=0
        return 0
    fi

    # Verify signature
    if command -v openssl >/dev/null 2>&1; then
        if openssl pkeyutl -verify \
            -pubin -inkey "$pubkey" \
            -rawin \
            -in "$firmware" \
            -sigfile "$sigfile" >/dev/null 2>&1; then
            FIRMWARE_SIG_VALID=1
        else
            FIRMWARE_SIG_VALID=0
        fi
    else
        FIRMWARE_SIG_VALID=0
    fi

    return 0
}

get_firmware_status() {
    if [ "$FIRMWARE_SIGNED" = "1" ]; then
        if [ "$FIRMWARE_SIG_VALID" = "1" ]; then
            echo "Signed and verified"
        else
            echo "SIGNATURE INVALID!"
        fi
    else
        echo "Unsigned"
    fi
}

#
# Network detection
#

load_network_modules() {
    # Suppress kernel messages
    local saved_printk=$(cat /proc/sys/kernel/printk 2>/dev/null)
    echo "1 1 1 1" > /proc/sys/kernel/printk 2>/dev/null

    # Load common NIC drivers
    modprobe -q virtio_net >/dev/null 2>&1 || true
    modprobe -q vmxnet3 >/dev/null 2>&1 || true
    modprobe -q e1000 >/dev/null 2>&1 || true
    modprobe -q e1000e >/dev/null 2>&1 || true
    modprobe -q xen-netfront >/dev/null 2>&1 || true
    modprobe -q igb >/dev/null 2>&1 || true
    modprobe -q igc >/dev/null 2>&1 || true
    modprobe -q ixgbe >/dev/null 2>&1 || true
    modprobe -q i40e >/dev/null 2>&1 || true
    modprobe -q ice >/dev/null 2>&1 || true
    modprobe -q r8169 >/dev/null 2>&1 || true
    modprobe -q r8152 >/dev/null 2>&1 || true
    modprobe -q tg3 >/dev/null 2>&1 || true
    modprobe -q bnx2 >/dev/null 2>&1 || true
    modprobe -q atlantic >/dev/null 2>&1 || true
    modprobe -q mlx4_en >/dev/null 2>&1 || true
    modprobe -q mlx5_core >/dev/null 2>&1 || true
    modprobe -q be2net >/dev/null 2>&1 || true
    modprobe -q ena >/dev/null 2>&1 || true

    # Restore printk level
    [ -n "$saved_printk" ] && echo "$saved_printk" > /proc/sys/kernel/printk 2>/dev/null

    sleep 1
}

# Select network interface using dialog menu
select_network_interface() {
    local menu_args=""
    local count=0

    for iface in /sys/class/net/*; do
        [ -d "$iface" ] || continue
        local name=$(basename "$iface")
        [ "$name" = "lo" ] && continue
        case "$name" in
            veth*|docker*|br-*|virbr*) continue ;;
        esac

        local mac=$(cat "$iface/address" 2>/dev/null)
        local driver=""
        if [ -L "$iface/device/driver" ]; then
            driver=$(basename $(readlink "$iface/device/driver"))
        fi
        local state=$(cat "$iface/operstate" 2>/dev/null)

        # Build menu args with proper quoting for spaces
        menu_args="$menu_args \"$name\" \"$mac ($driver) [$state]\""
        count=$((count + 1))
    done

    if [ $count -eq 0 ]; then
        msg_error "No network interfaces detected!\n\nCoyote Linux requires at least one network interface."
        return 1
    fi

    eval "$DIALOG --backtitle \"$BACKTITLE\" \
        --title \"Select Network Interface\" \
        --menu \"Choose the interface for initial configuration:\" 15 70 6 \
        $menu_args" 2>$DIALOG_TEMP

    local ret=$?
    if [ $ret -ne $DIALOG_OK ]; then
        return 1
    fi

    NET_INTERFACE=$(cat $DIALOG_TEMP)

    # Get MAC address
    NET_MAC=$(cat /sys/class/net/$NET_INTERFACE/address 2>/dev/null)

    return 0
}

#
# Input validation
#

validate_cidr() {
    local cidr="$1"
    echo "$cidr" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+/[0-9]+$' || return 1
    local ip="${cidr%/*}"
    local prefix="${cidr#*/}"
    [ "$prefix" -ge 1 ] 2>/dev/null && [ "$prefix" -le 32 ] || return 1
    local IFS='.'
    set -- $ip
    [ $# -eq 4 ] || return 1
    for octet in "$@"; do
        [ "$octet" -ge 0 ] 2>/dev/null && [ "$octet" -le 255 ] || return 1
    done
    return 0
}

validate_ip() {
    local ip="$1"
    [ -z "$ip" ] && return 0  # Empty is OK (optional fields)
    echo "$ip" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$' || return 1
    local IFS='.'
    set -- $ip
    [ $# -eq 4 ] || return 1
    for octet in "$@"; do
        [ "$octet" -ge 0 ] 2>/dev/null && [ "$octet" -le 255 ] || return 1
    done
    return 0
}

validate_hostname() {
    local hostname="$1"
    echo "$hostname" | grep -qE '^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$' || return 1
    return 0
}

validate_domain() {
    local domain="$1"
    echo "$domain" | grep -qE '^[a-zA-Z0-9]([a-zA-Z0-9.-]*[a-zA-Z0-9])?$' || return 1
    return 0
}

#
# Network configuration
#

configure_network() {
    # Select interface first
    if ! select_network_interface; then
        return 1
    fi

    # Use a form for network configuration
    local default_ip="192.168.99.2/24"
    local default_dns="1.1.1.1"
    local default_hostname="coyote"
    local default_domain="local.lan"

    while true; do
        $DIALOG --backtitle "$BACKTITLE" \
            --title "Network Configuration for $NET_INTERFACE" \
            --form "Enter network settings (Tab to move between fields):" 18 70 6 \
            "IP Address (CIDR):" 1 1 "$default_ip" 1 22 20 20 \
            "Default Gateway:"   2 1 "" 2 22 20 20 \
            "Primary DNS:"       3 1 "$default_dns" 3 22 20 20 \
            "Secondary DNS:"     4 1 "" 4 22 20 20 \
            "Hostname:"          5 1 "$default_hostname" 5 22 20 63 \
            "Search Domain:"     6 1 "$default_domain" 6 22 30 255 \
            2>$DIALOG_TEMP

        local ret=$?
        if [ $ret -ne $DIALOG_OK ]; then
            return 1
        fi

        # Parse form output (one field per line)
        NET_IP_CIDR=$(sed -n '1p' $DIALOG_TEMP)
        NET_GATEWAY=$(sed -n '2p' $DIALOG_TEMP)
        NET_DNS1=$(sed -n '3p' $DIALOG_TEMP)
        NET_DNS2=$(sed -n '4p' $DIALOG_TEMP)
        NET_HOSTNAME=$(sed -n '5p' $DIALOG_TEMP)
        NET_DOMAIN=$(sed -n '6p' $DIALOG_TEMP)

        # Apply defaults for empty required fields
        [ -z "$NET_IP_CIDR" ] && NET_IP_CIDR="$default_ip"
        [ -z "$NET_DNS1" ] && NET_DNS1="$default_dns"
        [ -z "$NET_HOSTNAME" ] && NET_HOSTNAME="$default_hostname"
        [ -z "$NET_DOMAIN" ] && NET_DOMAIN="$default_domain"

        # Validate inputs
        local errors=""

        if ! validate_cidr "$NET_IP_CIDR"; then
            errors="${errors}Invalid IP address. Use CIDR notation (e.g., 192.168.1.1/24)\n"
        fi

        if [ -n "$NET_GATEWAY" ] && ! validate_ip "$NET_GATEWAY"; then
            errors="${errors}Invalid gateway IP address\n"
        fi

        if ! validate_ip "$NET_DNS1"; then
            errors="${errors}Invalid primary DNS server\n"
        fi

        if [ -n "$NET_DNS2" ] && ! validate_ip "$NET_DNS2"; then
            errors="${errors}Invalid secondary DNS server\n"
        fi

        if ! validate_hostname "$NET_HOSTNAME"; then
            errors="${errors}Invalid hostname\n"
        fi

        if ! validate_domain "$NET_DOMAIN"; then
            errors="${errors}Invalid domain name\n"
        fi

        if [ -n "$errors" ]; then
            $DIALOG --backtitle "$BACKTITLE" \
                --title "Validation Error" \
                --msgbox "$errors" 12 60
            continue
        fi

        break
    done

    # Show summary and confirm
    local gateway_display="${NET_GATEWAY:-none}"
    local dns2_display=""
    [ -n "$NET_DNS2" ] && dns2_display=", $NET_DNS2"

    $DIALOG --backtitle "$BACKTITLE" \
        --title "Confirm Network Configuration" \
        --yesno "Interface:      $NET_INTERFACE ($NET_MAC)
IP Address:     $NET_IP_CIDR
Gateway:        $gateway_display
DNS Servers:    $NET_DNS1$dns2_display
Hostname:       $NET_HOSTNAME
Search Domain:  $NET_DOMAIN

Is this configuration correct?" 14 60

    return $?
}

#
# Disk operations
#


check_existing_installation() {
    local disk="$1"
    local boot_part

    case "$disk" in
        /dev/nvme*) boot_part="${disk}p1" ;;
        *) boot_part="${disk}1" ;;
    esac

    [ -b "$boot_part" ] || return 1

    local tmp_mount="/tmp/check_install"
    mkdir -p "$tmp_mount"

    if mount -t vfat -o ro "$boot_part" "$tmp_mount" >/dev/null 2>&1; then
        if [ -f "$tmp_mount/coyote.marker" ]; then
            local marker_content=$(cat "$tmp_mount/coyote.marker" 2>/dev/null)
            umount "$tmp_mount" >/dev/null 2>&1
            rmdir "$tmp_mount" >/dev/null 2>&1
            [ "$marker_content" = "COYOTE_BOOT" ] && return 0
        else
            umount "$tmp_mount" >/dev/null 2>&1
        fi
    fi

    rmdir "$tmp_mount" >/dev/null 2>&1
    return 1
}


select_disk() {
    local menu_args=""
    local count=0
    local boot_disk=""

    if [ -n "$BOOT_MEDIA_DEV" ]; then
        boot_disk=$(echo "$BOOT_MEDIA_DEV" | sed 's/[0-9]*$//')
    fi

    for disk in /dev/sd? /dev/nvme?n? /dev/vd?; do
        [ -b "$disk" ] || continue
        [ "$disk" = "$boot_disk" ] && continue
        case "$disk" in
            /dev/sr*) continue ;;
        esac

        local size_sectors=$(cat /sys/block/$(basename $disk)/size 2>/dev/null)
        if [ -n "$size_sectors" ] && [ "$size_sectors" -gt 0 ]; then
            local size_gb=$((size_sectors * 512 / 1024 / 1024 / 1024))
            menu_args="$menu_args \"$disk\" \"${size_gb}GB\""
            count=$((count + 1))
        fi
    done

    if [ $count -eq 0 ]; then
        msg_error "No suitable disks found for installation.\n\nMake sure you have a disk attached (other than the installer media)."
        return 1
    fi

    eval "$DIALOG --backtitle \"$BACKTITLE\" \
        --title \"Select Target Disk\" \
        --menu \"Choose the disk for installation:\n\nWARNING: All data on the selected disk will be erased!\" 15 60 6 \
        $menu_args" 2>$DIALOG_TEMP

    local ret=$?
    if [ $ret -ne $DIALOG_OK ]; then
        return 1
    fi

    TARGET_DISK=$(cat $DIALOG_TEMP)
    local size_sectors=$(cat /sys/block/$(basename $TARGET_DISK)/size 2>/dev/null)
    TARGET_SIZE="$((size_sectors * 512 / 1024 / 1024 / 1024))GB"

    return 0
}

select_upgrade_disk() {
    local menu_args=""
    local count=0
    local boot_disk=""

    if [ -n "$BOOT_MEDIA_DEV" ]; then
        boot_disk=$(echo "$BOOT_MEDIA_DEV" | sed 's/[0-9]*$//')
    fi

    for disk in /dev/sd? /dev/nvme?n? /dev/vd?; do
        [ -b "$disk" ] || continue
        [ "$disk" = "$boot_disk" ] && continue
        case "$disk" in
            /dev/sr*) continue ;;
        esac

        if check_existing_installation "$disk"; then
            local size_sectors=$(cat /sys/block/$(basename $disk)/size 2>/dev/null)
            if [ -n "$size_sectors" ] && [ "$size_sectors" -gt 0 ]; then
                local size_gb=$((size_sectors * 512 / 1024 / 1024 / 1024))
                menu_args="$menu_args \"$disk\" \"${size_gb}GB (Coyote installed)\""
                count=$((count + 1))
            fi
        fi
    done

    if [ $count -eq 0 ]; then
        msg_error "No existing Coyote Linux installations found."
        return 1
    fi

    eval "$DIALOG --backtitle \"$BACKTITLE\" \
        --title \"Select Disk to Upgrade\" \
        --menu \"Choose an existing Coyote installation to upgrade:\" 15 60 6 \
        $menu_args" 2>$DIALOG_TEMP

    local ret=$?
    if [ $ret -ne $DIALOG_OK ]; then
        return 1
    fi

    TARGET_DISK=$(cat $DIALOG_TEMP)
    local size_sectors=$(cat /sys/block/$(basename $TARGET_DISK)/size 2>/dev/null)
    TARGET_SIZE="$((size_sectors * 512 / 1024 / 1024 / 1024))GB"

    case "$TARGET_DISK" in
        /dev/nvme*)
            BOOT_PART="${TARGET_DISK}p1"
            CONFIG_PART="${TARGET_DISK}p2"
            ;;
        *)
            BOOT_PART="${TARGET_DISK}1"
            CONFIG_PART="${TARGET_DISK}2"
            ;;
    esac

    return 0
}

confirm_install() {
    local fw_status=$(get_firmware_status)

    $DIALOG --backtitle "$BACKTITLE" \
        --title "Confirm Installation" \
        --yesno "WARNING: All data on $TARGET_DISK will be destroyed!

Target Disk:  $TARGET_DISK ($TARGET_SIZE)
Firmware:     $fw_status

The installer will:
  1. Create a new partition table
  2. Create a boot partition (2GB, FAT32)
  3. Create a config partition (remaining space, ext4)
  4. Install the Coyote Linux bootloader and firmware

Do you want to proceed?" 18 65

    return $?
}

confirm_upgrade() {
    local fw_status=$(get_firmware_status)

    $DIALOG --backtitle "$BACKTITLE" \
        --title "Confirm Upgrade" \
        --yesno "Target Disk:  $TARGET_DISK ($TARGET_SIZE)
Firmware:     $fw_status

The upgrade will:
  1. Backup the existing firmware (as previous.squashfs)
  2. Install the new kernel, initramfs, and firmware
  3. Update the bootloader configuration

Your configuration will be preserved.

Do you want to proceed?" 16 65

    return $?
}

partition_disk() {
    (
        echo "10"; echo "Creating partition table..."
        umount ${TARGET_DISK}* >/dev/null 2>&1 || true
        parted -s "$TARGET_DISK" mklabel msdos >/dev/null 2>&1 || exit 1

        echo "30"; echo "Creating boot partition (2GB)..."
        parted -s "$TARGET_DISK" mkpart primary fat32 1MiB 2049MiB >/dev/null 2>&1 || exit 1
        parted -s "$TARGET_DISK" set 1 boot on >/dev/null 2>&1

        echo "50"; echo "Creating config partition..."
        parted -s "$TARGET_DISK" mkpart primary ext4 2049MiB 100% >/dev/null 2>&1 || exit 1

        echo "70"; echo "Waiting for partitions..."
        partprobe "$TARGET_DISK" >/dev/null 2>&1 || blockdev --rereadpt "$TARGET_DISK" >/dev/null 2>&1 || true
        [ -x /sbin/mdev ] && mdev -s >/dev/null 2>&1

        # Determine partition names
        case "$TARGET_DISK" in
            /dev/nvme*)
                BOOT_PART="${TARGET_DISK}p1"
                CONFIG_PART="${TARGET_DISK}p2"
                ;;
            *)
                BOOT_PART="${TARGET_DISK}1"
                CONFIG_PART="${TARGET_DISK}2"
                ;;
        esac

        # Wait for partitions
        local tries=0
        while [ ! -b "$BOOT_PART" ] && [ $tries -lt 10 ]; do
            [ -x /sbin/mdev ] && mdev -s >/dev/null 2>&1
            sleep 1
            tries=$((tries + 1))
        done

        echo "100"; echo "Partitioning complete"
    ) | $DIALOG --backtitle "$BACKTITLE" \
        --title "Partitioning Disk" \
        --gauge "Initializing..." 8 60 0

    # Set partition names again (subshell doesn't export)
    case "$TARGET_DISK" in
        /dev/nvme*)
            BOOT_PART="${TARGET_DISK}p1"
            CONFIG_PART="${TARGET_DISK}p2"
            ;;
        *)
            BOOT_PART="${TARGET_DISK}1"
            CONFIG_PART="${TARGET_DISK}2"
            ;;
    esac

    if [ ! -b "$BOOT_PART" ]; then
        msg_error "Partition device $BOOT_PART did not appear"
        return 1
    fi

    return 0
}

format_partitions() {
    (
        echo "20"; echo "Formatting boot partition (FAT32)..."
        mkfs.vfat -F 32 -n COYOTE "$BOOT_PART" >/dev/null 2>&1 || exit 1

        echo "70"; echo "Formatting config partition (ext4)..."
        mkfs.ext4 -F -L COYOTE_CFG -q "$CONFIG_PART" >/dev/null 2>&1 || exit 1

        echo "100"; echo "Formatting complete"
    ) | $DIALOG --backtitle "$BACKTITLE" \
        --title "Formatting Partitions" \
        --gauge "Initializing..." 8 60 0

    return $?
}

install_system() {
    local target_boot="/tmp/target_boot"
    local target_config="/tmp/target_config"

    mkdir -p "$target_boot" "$target_config"

    # Load filesystem modules
    modprobe -q vfat >/dev/null 2>&1 || true
    modprobe -q fat >/dev/null 2>&1 || true
    modprobe -q nls_cp437 >/dev/null 2>&1 || true
    modprobe -q nls_iso8859-1 >/dev/null 2>&1 || true

    (
        echo "5"; echo "Mounting target partitions..."
        mount -t vfat "$BOOT_PART" "$target_boot" >/dev/null 2>&1 || exit 1
        mount -t ext4 "$CONFIG_PART" "$target_config" >/dev/null 2>&1 || exit 1

        echo "10"; echo "Creating directory structure..."
        mkdir -p "$target_boot/boot/syslinux"
        mkdir -p "$target_boot/firmware"

        echo "20"; echo "Copying kernel..."
        cp "${BOOT_MEDIA}/boot/vmlinuz" "$target_boot/boot/" >/dev/null 2>&1 || exit 1

        echo "30"; echo "Copying initramfs..."
        cp "${BOOT_MEDIA}/boot/initramfs-system.gz" "$target_boot/boot/initramfs.gz" >/dev/null 2>&1 || exit 1

        echo "40"; echo "Copying firmware image..."
        cp "$FIRMWARE_SRC" "$target_boot/firmware/current.squashfs" >/dev/null 2>&1 || exit 1
        [ -f "${FIRMWARE_SRC}.sha256" ] && cp "${FIRMWARE_SRC}.sha256" "$target_boot/firmware/current.squashfs.sha256" >/dev/null 2>&1
        [ -f "${FIRMWARE_SRC}.sig" ] && cp "${FIRMWARE_SRC}.sig" "$target_boot/firmware/current.squashfs.sig" >/dev/null 2>&1

        echo "70"; echo "Creating boot marker..."
        echo "COYOTE_BOOT" > "$target_boot/coyote.marker"

        echo "75"; echo "Installing bootloader files..."
        cp "${BOOT_MEDIA}/boot/isolinux/ldlinux.c32" "$target_boot/boot/syslinux/" >/dev/null 2>&1 || true
        cp "${BOOT_MEDIA}/boot/isolinux/menu.c32" "$target_boot/boot/syslinux/" >/dev/null 2>&1 || true
        cp "${BOOT_MEDIA}/boot/isolinux/libutil.c32" "$target_boot/boot/syslinux/" >/dev/null 2>&1 || true
        cp "${BOOT_MEDIA}/boot/isolinux/libcom32.c32" "$target_boot/boot/syslinux/" >/dev/null 2>&1 || true

        echo "80"; echo "Creating bootloader configuration..."
        cat > "$target_boot/boot/syslinux/syslinux.cfg" << 'SYSLINUX_CFG'
DEFAULT menu.c32
PROMPT 0
TIMEOUT 30

MENU TITLE Coyote Linux 4

LABEL coyote
    MENU LABEL Coyote Linux
    MENU DEFAULT
    LINUX /boot/vmlinuz
    INITRD /boot/initramfs.gz
    APPEND console=tty0 quiet

LABEL rescue
    MENU LABEL Rescue Mode
    LINUX /boot/vmlinuz
    INITRD /boot/initramfs.gz
    APPEND console=tty0 quiet rescue
SYSLINUX_CFG

        echo "85"; echo "Creating system configuration..."
        # Build DNS array
        local dns_servers="\"$NET_DNS1\""
        [ -n "$NET_DNS2" ] && dns_servers="$dns_servers, \"$NET_DNS2\""

        # Build interface JSON
        if [ -n "$NET_GATEWAY" ]; then
            cat > "$target_config/system.json" << EOF
{
    "system": {
        "hostname": "$NET_HOSTNAME",
        "domain": "$NET_DOMAIN",
        "timezone": "UTC"
    },
    "network": {
        "dns": [$dns_servers],
        "search": ["$NET_DOMAIN"],
        "interfaces": [
            {
                "name": "$NET_INTERFACE",
                "type": "static",
                "enabled": true,
                "addresses": ["$NET_IP_CIDR"]
            }
        ],
        "routes": [
            {
                "destination": "default",
                "gateway": "$NET_GATEWAY",
                "interface": "$NET_INTERFACE"
            }
        ]
    }
}
EOF
        else
            cat > "$target_config/system.json" << EOF
{
    "system": {
        "hostname": "$NET_HOSTNAME",
        "domain": "$NET_DOMAIN",
        "timezone": "UTC"
    },
    "network": {
        "dns": [$dns_servers],
        "search": ["$NET_DOMAIN"],
        "interfaces": [
            {
                "name": "$NET_INTERFACE",
                "type": "static",
                "enabled": true,
                "addresses": ["$NET_IP_CIDR"]
            }
        ]
    }
}
EOF
        fi

        echo "90"; echo "Unmounting partitions..."
        sync >/dev/null 2>&1
        umount "$target_config" >/dev/null 2>&1
        umount "$target_boot" >/dev/null 2>&1

        echo "95"; echo "Installing syslinux bootloader..."
        syslinux --install "$BOOT_PART" >/dev/null 2>&1 || exit 1

        echo "98"; echo "Installing MBR..."
        dd if=/usr/share/syslinux/mbr.bin of="$TARGET_DISK" bs=440 count=1 conv=notrunc >/dev/null 2>&1 || \
        dd if=/usr/lib/syslinux/bios/mbr.bin of="$TARGET_DISK" bs=440 count=1 conv=notrunc >/dev/null 2>&1 || true

        echo "100"; echo "Installation complete!"
    ) | $DIALOG --backtitle "$BACKTITLE" \
        --title "Installing Coyote Linux" \
        --gauge "Initializing..." 8 60 0

    return $?
}

upgrade_system() {
    local target_boot="/tmp/target_boot"
    mkdir -p "$target_boot"

    modprobe -q vfat >/dev/null 2>&1 || true
    modprobe -q fat >/dev/null 2>&1 || true
    modprobe -q nls_cp437 >/dev/null 2>&1 || true
    modprobe -q nls_iso8859-1 >/dev/null 2>&1 || true

    (
        echo "5"; echo "Mounting boot partition..."
        mount -t vfat "$BOOT_PART" "$target_boot" >/dev/null 2>&1 || exit 1

        echo "15"; echo "Backing up existing firmware..."
        if [ -f "$target_boot/firmware/current.squashfs" ]; then
            mv "$target_boot/firmware/current.squashfs" "$target_boot/firmware/previous.squashfs" >/dev/null 2>&1 || true
            [ -f "$target_boot/firmware/current.squashfs.sha256" ] && \
                mv "$target_boot/firmware/current.squashfs.sha256" "$target_boot/firmware/previous.squashfs.sha256" >/dev/null 2>&1
            [ -f "$target_boot/firmware/current.squashfs.sig" ] && \
                mv "$target_boot/firmware/current.squashfs.sig" "$target_boot/firmware/previous.squashfs.sig" >/dev/null 2>&1
        fi

        echo "30"; echo "Installing new kernel..."
        cp "${BOOT_MEDIA}/boot/vmlinuz" "$target_boot/boot/" >/dev/null 2>&1 || exit 1

        echo "45"; echo "Installing new initramfs..."
        cp "${BOOT_MEDIA}/boot/initramfs-system.gz" "$target_boot/boot/initramfs.gz" >/dev/null 2>&1 || exit 1

        echo "60"; echo "Installing new firmware..."
        cp "$FIRMWARE_SRC" "$target_boot/firmware/current.squashfs" >/dev/null 2>&1 || exit 1
        [ -f "${FIRMWARE_SRC}.sha256" ] && cp "${FIRMWARE_SRC}.sha256" "$target_boot/firmware/current.squashfs.sha256" >/dev/null 2>&1
        [ -f "${FIRMWARE_SRC}.sig" ] && cp "${FIRMWARE_SRC}.sig" "$target_boot/firmware/current.squashfs.sig" >/dev/null 2>&1

        echo "85"; echo "Updating bootloader files..."
        if [ -f "${BOOT_MEDIA}/boot/isolinux/ldlinux.c32" ]; then
            cp "${BOOT_MEDIA}/boot/isolinux/ldlinux.c32" "$target_boot/boot/syslinux/" >/dev/null 2>&1 || true
            cp "${BOOT_MEDIA}/boot/isolinux/menu.c32" "$target_boot/boot/syslinux/" >/dev/null 2>&1 || true
            cp "${BOOT_MEDIA}/boot/isolinux/libutil.c32" "$target_boot/boot/syslinux/" >/dev/null 2>&1 || true
            cp "${BOOT_MEDIA}/boot/isolinux/libcom32.c32" "$target_boot/boot/syslinux/" >/dev/null 2>&1 || true
        fi

        echo "95"; echo "Syncing disk..."
        sync >/dev/null 2>&1
        umount "$target_boot" >/dev/null 2>&1

        echo "100"; echo "Upgrade complete!"
    ) | $DIALOG --backtitle "$BACKTITLE" \
        --title "Upgrading Coyote Linux" \
        --gauge "Initializing..." 8 60 0

    return $?
}

#
# Main menu
#

# Check if any upgradeable installations exist
has_upgradeable_disks() {
    local boot_disk=""
    if [ -n "$BOOT_MEDIA_DEV" ]; then
        boot_disk=$(echo "$BOOT_MEDIA_DEV" | sed 's/[0-9]*$//')
    fi

    for disk in /dev/sd? /dev/nvme?n? /dev/vd?; do
        [ -b "$disk" ] || continue
        [ "$disk" = "$boot_disk" ] && continue
        case "$disk" in
            /dev/sr*) continue ;;
        esac

        if check_existing_installation "$disk"; then
            return 0
        fi
    done
    return 1
}

show_main_menu() {
    local has_upgrade=0
    has_upgradeable_disks && has_upgrade=1

    local menu_items=""
    menu_items="install \"New Installation\" "

    if [ "$has_upgrade" = "1" ]; then
        menu_items="$menu_items upgrade \"Upgrade Existing Installation\" "
    else
        menu_items="$menu_items upgrade \"Upgrade (no installations found)\" "
    fi

    menu_items="$menu_items shell \"Exit to Shell\" "

    eval "$DIALOG --backtitle \"$BACKTITLE\" \
        --title \"Welcome to Coyote Linux\" \
        --menu \"Please select an option:\" 14 60 4 \
        $menu_items" 2>$DIALOG_TEMP

    local ret=$?
    if [ $ret -ne $DIALOG_OK ]; then
        return 1
    fi

    local choice=$(cat $DIALOG_TEMP)

    case "$choice" in
        install)
            UPGRADE_MODE=0
            return 0
            ;;
        upgrade)
            if [ "$has_upgrade" = "0" ]; then
                msg_error "No existing Coyote Linux installations found."
                return 2  # Return to menu
            fi
            UPGRADE_MODE=1
            return 0
            ;;
        shell)
            return 1
            ;;
    esac
}

#
# Main
#

main() {
    # Check for dialog
    if ! command -v dialog >/dev/null 2>&1; then
        echo "Error: 'dialog' utility not found"
        echo "Press Enter for shell..."
        read dummy
        exec /bin/sh
    fi

    # Check for required files
    if [ ! -f "$FIRMWARE_SRC" ]; then
        msg_error "Firmware not found at $FIRMWARE_SRC\n\nBoot media may not be mounted correctly.\nBOOT_MEDIA=$BOOT_MEDIA"
        exec /bin/sh
    fi

    # Verify firmware signature
    verify_firmware_source

    # Warn if signature is invalid
    if [ "$FIRMWARE_SIGNED" = "1" ] && [ "$FIRMWARE_SIG_VALID" = "0" ]; then
        $DIALOG --backtitle "$BACKTITLE" \
            --title "WARNING: Invalid Firmware Signature" \
            --yesno "The firmware on this installation media has an invalid signature.\n\nThis could indicate the firmware has been tampered with or corrupted.\n\nDo you want to continue anyway? (NOT RECOMMENDED)" 12 65

        if [ $? -ne $DIALOG_OK ]; then
            exec /bin/sh
        fi
    fi

    # Load network modules
    $DIALOG --backtitle "$BACKTITLE" \
        --infobox "Detecting hardware..." 3 30
    load_network_modules

    # Main menu loop
    while true; do
        if ! show_main_menu; then
            clear
            echo "Exiting to shell..."
            exec /bin/sh
        fi

        # Check for "return to menu" code
        [ $? -eq 2 ] && continue

        if [ "$UPGRADE_MODE" = "1" ]; then
            # Upgrade path
            select_upgrade_disk || continue
            confirm_upgrade || continue

            if upgrade_system; then
                $DIALOG --backtitle "$BACKTITLE" \
                    --title "Upgrade Complete" \
                    --msgbox "Coyote Linux on $TARGET_DISK has been upgraded.\n\nYour configuration has been preserved.\n\nThe previous firmware has been saved as previous.squashfs and can be used for rollback if needed.\n\nPlease remove the installation media and reboot." 13 60
                clear
                reboot -f
            else
                msg_error "Upgrade failed. Please check the error and try again."
            fi
        else
            # New installation path
            select_disk || continue

            # Configure network (loop until confirmed)
            while ! configure_network; do
                if ! ask_yesno "Cancel Installation?" "Do you want to cancel the installation?"; then
                    continue 2  # Back to main menu
                fi
            done

            confirm_install || continue

            if ! partition_disk; then
                msg_error "Partitioning failed. Please check the error and try again."
                continue
            fi

            if ! format_partitions; then
                msg_error "Formatting failed. Please check the error and try again."
                continue
            fi

            if install_system; then
                msg_info "Installation Complete" "Coyote Linux has been installed to $TARGET_DISK.\n\nPlease remove the installation media and reboot."
                clear
                reboot -f
            else
                msg_error "Installation failed. Please check the error and try again."
            fi
        fi
    done
}

main "$@"
