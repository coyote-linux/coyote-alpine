#!/bin/sh
#
# Coyote Linux Installer
#
# This script guides the user through installing Coyote Linux to a target disk.
#

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

# Installation paths
BOOT_MEDIA="${BOOT_MEDIA:-/mnt/boot}"
FIRMWARE_SRC="${BOOT_MEDIA}/firmware/current.squashfs"

# Clear screen and show header
clear_screen() {
    printf '\033[2J\033[H'
}

print_header() {
    clear_screen
    printf "${CYAN}${BOLD}"
    printf "============================================================\n"
    printf "         Coyote Linux 4 - Installation Wizard\n"
    printf "============================================================${NC}\n\n"
}

print_error() {
    printf "${RED}Error: %s${NC}\n" "$1"
}

print_success() {
    printf "${GREEN}%s${NC}\n" "$1"
}

print_warn() {
    printf "${YELLOW}%s${NC}\n" "$1"
}

# Get list of available disks (excluding the boot media)
get_available_disks() {
    local boot_disk=""

    # Find boot media's parent disk
    if [ -n "$BOOT_MEDIA_DEV" ]; then
        boot_disk=$(echo "$BOOT_MEDIA_DEV" | sed 's/[0-9]*$//')
    fi

    # List block devices that are disks (not partitions, not CD-ROMs)
    for disk in /dev/sd? /dev/nvme?n? /dev/vd?; do
        [ -b "$disk" ] || continue

        # Skip the boot media disk
        [ "$disk" = "$boot_disk" ] && continue

        # Skip if it's the CD-ROM
        case "$disk" in
            /dev/sr*) continue ;;
        esac

        # Get disk size
        local size_sectors=$(cat /sys/block/$(basename $disk)/size 2>/dev/null)
        if [ -n "$size_sectors" ] && [ "$size_sectors" -gt 0 ]; then
            local size_gb=$((size_sectors * 512 / 1024 / 1024 / 1024))
            printf "%s %dGB\n" "$disk" "$size_gb"
        fi
    done
}

# Select target disk
select_disk() {
    print_header
    printf "Select the target disk for installation:\n\n"

    local disks=$(get_available_disks)

    if [ -z "$disks" ]; then
        print_error "No suitable disks found for installation."
        printf "\nMake sure you have a disk attached (other than the installer media).\n"
        printf "\nPress Enter to return..."
        read dummy
        return 1
    fi

    local i=1
    echo "$disks" > /tmp/available_disks

    printf "  ${BOLD}#  Device          Size${NC}\n"
    printf "  -------------------------\n"

    while IFS=' ' read -r disk size; do
        printf "  %d) %-14s %s\n" "$i" "$disk" "$size"
        i=$((i + 1))
    done < /tmp/available_disks

    printf "\n  0) Cancel installation\n"
    printf "\n"

    local count=$(wc -l < /tmp/available_disks)

    while true; do
        printf "Enter selection [1-%d]: " "$count"
        read selection

        if [ "$selection" = "0" ]; then
            return 1
        fi

        if [ "$selection" -ge 1 ] 2>/dev/null && [ "$selection" -le "$count" ]; then
            TARGET_DISK=$(sed -n "${selection}p" /tmp/available_disks | cut -d' ' -f1)
            TARGET_SIZE=$(sed -n "${selection}p" /tmp/available_disks | cut -d' ' -f2)
            return 0
        fi

        print_error "Invalid selection"
    done
}

# Confirm installation
confirm_install() {
    print_header
    printf "${YELLOW}${BOLD}WARNING: All data on ${TARGET_DISK} will be destroyed!${NC}\n\n"
    printf "Target disk: ${BOLD}${TARGET_DISK}${NC} (${TARGET_SIZE})\n\n"
    printf "The installer will:\n"
    printf "  1. Create a new partition table\n"
    printf "  2. Create a boot partition (2GB, FAT32)\n"
    printf "  3. Create a config partition (remaining space, ext4)\n"
    printf "  4. Install the Coyote Linux bootloader and firmware\n"
    printf "\n"

    printf "Type ${BOLD}YES${NC} to continue, or anything else to cancel: "
    read confirm

    [ "$confirm" = "YES" ]
}

# Partition the disk
partition_disk() {
    print_header
    printf "Partitioning ${TARGET_DISK}...\n\n"

    # Unmount any existing partitions
    umount ${TARGET_DISK}* 2>/dev/null || true

    # Create new partition table
    printf "  Creating partition table...\n"
    parted -s "$TARGET_DISK" mklabel msdos || {
        print_error "Failed to create partition table"
        return 1
    }

    # Create boot partition (2GB - needs space for kernel, initramfs, firmware)
    printf "  Creating boot partition (2GB)...\n"
    parted -s "$TARGET_DISK" mkpart primary fat32 1MiB 2049MiB || {
        print_error "Failed to create boot partition"
        return 1
    }
    parted -s "$TARGET_DISK" set 1 boot on

    # Create config partition (rest of disk)
    printf "  Creating config partition...\n"
    parted -s "$TARGET_DISK" mkpart primary ext4 2049MiB 100% || {
        print_error "Failed to create config partition"
        return 1
    }

    # Determine partition naming
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

    # Force kernel to re-read partition table
    printf "  Re-reading partition table...\n"
    partprobe "$TARGET_DISK" 2>/dev/null || \
        blockdev --rereadpt "$TARGET_DISK" 2>/dev/null || \
        true

    # Wait for partitions to appear
    printf "  Waiting for partition devices...\n"
    local tries=0
    while [ ! -b "$BOOT_PART" ] && [ $tries -lt 10 ]; do
        sleep 1
        tries=$((tries + 1))
    done

    if [ ! -b "$BOOT_PART" ]; then
        print_error "Partition device $BOOT_PART did not appear"
        printf "  Available devices:\n"
        ls -la ${TARGET_DISK}* 2>/dev/null || true
        return 1
    fi

    print_success "  Partitioning complete"
    return 0
}

# Format partitions
format_partitions() {
    printf "\nFormatting partitions...\n\n"

    # Format boot partition as FAT32
    printf "  Formatting boot partition (FAT32)...\n"
    mkfs.vfat -F 32 -n COYOTE "$BOOT_PART" || {
        print_error "Failed to format boot partition"
        return 1
    }

    # Format config partition as ext4
    printf "  Formatting config partition (ext4)...\n"
    mkfs.ext4 -L COYOTE_CFG -q "$CONFIG_PART" || {
        print_error "Failed to format config partition"
        return 1
    }

    print_success "  Formatting complete"
    return 0
}

# Install bootloader and firmware
install_system() {
    printf "\nInstalling Coyote Linux...\n\n"

    local target_boot="/tmp/target_boot"
    local target_config="/tmp/target_config"

    mkdir -p "$target_boot" "$target_config"

    # Load filesystem modules if needed
    modprobe -q vfat 2>/dev/null || true
    modprobe -q fat 2>/dev/null || true
    modprobe -q nls_cp437 2>/dev/null || true
    modprobe -q nls_iso8859-1 2>/dev/null || true

    # Mount target partitions
    printf "  Mounting target partitions...\n"
    mount -t vfat "$BOOT_PART" "$target_boot" || {
        print_error "Failed to mount boot partition"
        return 1
    }
    mount -t ext4 "$CONFIG_PART" "$target_config" || {
        print_error "Failed to mount config partition"
        umount "$target_boot"
        return 1
    }

    # Create directory structure
    printf "  Creating directory structure...\n"
    mkdir -p "$target_boot/boot/syslinux"
    mkdir -p "$target_boot/firmware"
    mkdir -p "$target_config/system"

    # Copy kernel and initramfs
    printf "  Copying kernel...\n"
    cp "${BOOT_MEDIA}/boot/vmlinuz" "$target_boot/boot/" || {
        print_error "Failed to copy kernel"
        return 1
    }

    printf "  Copying initramfs...\n"
    cp "${BOOT_MEDIA}/boot/initramfs.gz" "$target_boot/boot/" || {
        print_error "Failed to copy initramfs"
        return 1
    }

    # Copy firmware
    printf "  Copying firmware image...\n"
    cp "$FIRMWARE_SRC" "$target_boot/firmware/current.squashfs" || {
        print_error "Failed to copy firmware"
        return 1
    }
    if [ -f "${FIRMWARE_SRC}.sha256" ]; then
        cp "${FIRMWARE_SRC}.sha256" "$target_boot/firmware/current.squashfs.sha256"
    fi

    # Create boot marker
    echo "COYOTE_BOOT" > "$target_boot/coyote.marker"

    # Install syslinux files
    printf "  Installing bootloader...\n"

    # Copy syslinux modules from isolinux on install media
    cp "${BOOT_MEDIA}/boot/isolinux/ldlinux.c32" "$target_boot/boot/syslinux/" 2>/dev/null || true
    cp "${BOOT_MEDIA}/boot/isolinux/menu.c32" "$target_boot/boot/syslinux/" 2>/dev/null || true
    cp "${BOOT_MEDIA}/boot/isolinux/libutil.c32" "$target_boot/boot/syslinux/" 2>/dev/null || true
    cp "${BOOT_MEDIA}/boot/isolinux/libcom32.c32" "$target_boot/boot/syslinux/" 2>/dev/null || true

    # Create syslinux.cfg
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
    APPEND console=ttyS0,115200 console=tty0 quiet

LABEL rescue
    MENU LABEL Rescue Mode
    LINUX /boot/vmlinuz
    INITRD /boot/initramfs.gz
    APPEND console=ttyS0,115200 console=tty0 rescue
SYSLINUX_CFG

    # Unmount boot partition for syslinux install
    umount "$target_boot"

    # Install syslinux bootloader
    syslinux --install "$BOOT_PART" || {
        print_error "Failed to install syslinux"
        return 1
    }

    # Install MBR
    dd if=/usr/share/syslinux/mbr.bin of="$TARGET_DISK" bs=440 count=1 conv=notrunc 2>/dev/null || \
    dd if=/usr/lib/syslinux/bios/mbr.bin of="$TARGET_DISK" bs=440 count=1 conv=notrunc 2>/dev/null || \
        print_warn "  Warning: Could not install MBR"

    # Create default config
    printf "  Creating default configuration...\n"
    cat > "$target_config/system/config.json" << 'CONFIG_JSON'
{
    "system": {
        "hostname": "coyote",
        "timezone": "UTC"
    },
    "network": {
        "interfaces": []
    }
}
CONFIG_JSON

    # Cleanup
    umount "$target_config" 2>/dev/null

    print_success "  Installation complete!"
    return 0
}

# Main installation flow
main() {
    print_header
    printf "Welcome to the Coyote Linux installer.\n\n"
    printf "This wizard will guide you through installing Coyote Linux\n"
    printf "to your system.\n\n"
    printf "Press ${BOLD}Enter${NC} to continue or ${BOLD}Ctrl+C${NC} to cancel..."
    read dummy

    # Check for required files
    if [ ! -f "$FIRMWARE_SRC" ]; then
        print_error "Firmware not found at $FIRMWARE_SRC"
        print_error "Boot media may not be mounted correctly"
        printf "\nBOOT_MEDIA=$BOOT_MEDIA\n"
        printf "\nPress Enter to drop to shell for debugging..."
        read dummy
        exec /bin/sh
    fi

    # Select target disk
    if ! select_disk; then
        printf "\nInstallation cancelled.\n"
        printf "Press Enter for shell or Ctrl+Alt+Del to reboot..."
        read dummy
        exec /bin/sh
    fi

    # Confirm installation
    if ! confirm_install; then
        printf "\nInstallation cancelled.\n"
        printf "Press Enter for shell or Ctrl+Alt+Del to reboot..."
        read dummy
        exec /bin/sh
    fi

    # Perform installation
    if ! partition_disk; then
        printf "\nPress Enter for shell..."
        read dummy
        exec /bin/sh
    fi

    if ! format_partitions; then
        printf "\nPress Enter for shell..."
        read dummy
        exec /bin/sh
    fi

    if ! install_system; then
        printf "\nPress Enter for shell..."
        read dummy
        exec /bin/sh
    fi

    # Done
    print_header
    print_success "Installation completed successfully!"
    printf "\n"
    printf "Coyote Linux has been installed to ${BOLD}${TARGET_DISK}${NC}\n\n"
    printf "You can now:\n"
    printf "  1. Remove the installation media\n"
    printf "  2. Reboot the system\n"
    printf "\n"
    printf "Press ${BOLD}Enter${NC} to reboot..."
    read dummy

    reboot -f
}

main "$@"
