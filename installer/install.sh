#!/bin/sh
#
# Coyote Linux Installer - Main installation script
#

set -e

# Configuration
INSTALLER_MEDIA=""
TARGET_DISK=""
FIRMWARE_FILE=""

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

log() {
    echo -e "${GREEN}[INSTALL]${NC} $1"
}

warn() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Detect available disks
detect_disks() {
    log "Detecting available disks..."

    for disk in /sys/block/sd* /sys/block/nvme* /sys/block/vd*; do
        [ -d "$disk" ] || continue

        name=$(basename "$disk")

        # Skip the installer USB (check for COYOTE_INSTALLER marker)
        mount -o ro "/dev/${name}1" /mnt 2>/dev/null || continue
        if [ -f /mnt/coyote.marker ] && grep -q "COYOTE_INSTALLER" /mnt/coyote.marker 2>/dev/null; then
            INSTALLER_MEDIA="/dev/${name}"
            log "Installer media detected: ${INSTALLER_MEDIA}"
            FIRMWARE_FILE="/mnt/firmware/current.squashfs"
            # Keep mounted for firmware access
            continue
        fi
        umount /mnt 2>/dev/null

        # Get disk size
        size_sectors=$(cat "/sys/block/${name}/size")
        size_gb=$((size_sectors * 512 / 1024 / 1024 / 1024))

        # Get model if available
        model=""
        [ -f "/sys/block/${name}/device/model" ] && model=$(cat "/sys/block/${name}/device/model" | tr -d ' ')

        echo "  /dev/${name} - ${size_gb}GB ${model}"
    done
}

# Partition the target disk
partition_disk() {
    local disk="$1"

    log "Partitioning ${disk}..."

    # Create GPT partition table
    parted -s "$disk" mklabel gpt

    # Partition 1: Boot/Firmware (512MB)
    parted -s "$disk" mkpart primary fat32 1MiB 513MiB
    parted -s "$disk" set 1 boot on

    # Partition 2: Config (64MB)
    parted -s "$disk" mkpart primary ext4 513MiB 577MiB

    # Wait for partition devices
    sleep 2

    # Format partitions
    log "Formatting partitions..."

    case "$disk" in
        /dev/nvme*)
            mkfs.vfat -F 32 -n COYOTE "${disk}p1"
            mkfs.ext4 -L CONFIG "${disk}p2"
            ;;
        *)
            mkfs.vfat -F 32 -n COYOTE "${disk}1"
            mkfs.ext4 -L CONFIG "${disk}2"
            ;;
    esac
}

# Install the system
install_system() {
    local disk="$1"

    log "Installing Coyote Linux to ${disk}..."

    # Determine partition names
    local boot_part config_part
    case "$disk" in
        /dev/nvme*)
            boot_part="${disk}p1"
            config_part="${disk}p2"
            ;;
        *)
            boot_part="${disk}1"
            config_part="${disk}2"
            ;;
    esac

    # Mount target partitions
    mkdir -p /target/boot /target/config
    mount "$boot_part" /target/boot
    mount "$config_part" /target/config

    # Copy firmware
    log "Copying firmware..."
    mkdir -p /target/boot/firmware
    cp "$FIRMWARE_FILE" /target/boot/firmware/current.squashfs
    sha256sum /target/boot/firmware/current.squashfs > /target/boot/firmware/current.squashfs.sha256

    # Copy kernel and initramfs
    log "Copying boot files..."
    mkdir -p /target/boot/boot
    cp /mnt/boot/vmlinuz /target/boot/boot/
    cp /mnt/boot/initramfs.gz /target/boot/boot/

    # Create boot marker
    echo "COYOTE_BOOT" > /target/boot/coyote.marker

    # Install bootloader
    log "Installing bootloader..."
    install_bootloader "$disk" "$boot_part"

    # Create default configuration
    log "Creating default configuration..."
    cat > /target/config/system.json << 'EOF'
{
    "system": {
        "hostname": "coyote",
        "timezone": "UTC"
    },
    "network": {
        "interfaces": {},
        "routes": []
    },
    "services": {},
    "addons": {}
}
EOF

    # Cleanup
    umount /target/config
    umount /target/boot

    log "Installation complete!"
}

# Install bootloader (syslinux)
install_bootloader() {
    local disk="$1"
    local boot_part="$2"

    mkdir -p /target/boot/boot/syslinux
    cp /usr/share/syslinux/ldlinux.c32 /target/boot/boot/syslinux/
    cp /usr/share/syslinux/menu.c32 /target/boot/boot/syslinux/
    cp /usr/share/syslinux/libutil.c32 /target/boot/boot/syslinux/

    cat > /target/boot/boot/syslinux/syslinux.cfg << 'EOF'
DEFAULT menu.c32
PROMPT 0
TIMEOUT 30

MENU TITLE Coyote Linux

LABEL coyote
    MENU LABEL Coyote Linux
    LINUX /boot/vmlinuz
    INITRD /boot/initramfs.gz
    APPEND quiet

LABEL recovery
    MENU LABEL Recovery Mode
    LINUX /boot/vmlinuz
    INITRD /boot/initramfs.gz
    APPEND quiet recovery
EOF

    syslinux --install "$boot_part"
}

# Main installation flow
main() {
    clear
    echo ""
    echo "========================================"
    echo "   Coyote Linux 4 - Installation"
    echo "========================================"
    echo ""

    # Detect disks
    echo "Available disks:"
    detect_disks
    echo ""

    if [ -z "$FIRMWARE_FILE" ] || [ ! -f "$FIRMWARE_FILE" ]; then
        error "Firmware not found on installer media!"
        exit 1
    fi

    # Select target disk
    echo -n "Enter target disk (e.g., /dev/sda): "
    read TARGET_DISK

    if [ -z "$TARGET_DISK" ] || [ ! -b "$TARGET_DISK" ]; then
        error "Invalid disk selection"
        exit 1
    fi

    if [ "$TARGET_DISK" = "$INSTALLER_MEDIA" ]; then
        error "Cannot install to the installer media!"
        exit 1
    fi

    echo ""
    warn "WARNING: All data on ${TARGET_DISK} will be destroyed!"
    echo -n "Continue? [y/N]: "
    read confirm

    if [ "$confirm" != "y" ] && [ "$confirm" != "Y" ]; then
        echo "Installation cancelled."
        exit 0
    fi

    # Perform installation
    partition_disk "$TARGET_DISK"
    install_system "$TARGET_DISK"

    echo ""
    log "========================================"
    log "   Installation Complete!"
    log "========================================"
    echo ""
    echo "Remove the installer USB and reboot."
    echo ""
    echo -n "Press Enter to reboot..."
    read dummy

    umount /mnt 2>/dev/null
    reboot -f
}

main "$@"
