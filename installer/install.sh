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

# Load common network interface modules
load_network_modules() {
    printf "Detecting network hardware...\n"

    # Common virtual/emulated NIC drivers (for VMs)
    modprobe -q virtio_net 2>/dev/null || true    # KVM/QEMU virtio
    modprobe -q vmxnet3 2>/dev/null || true       # VMware vmxnet3
    modprobe -q e1000 2>/dev/null || true         # VMware/QEMU e1000
    modprobe -q e1000e 2>/dev/null || true        # Intel e1000e (also used in VMs)
    modprobe -q xen-netfront 2>/dev/null || true  # Xen

    # Common Intel NIC drivers
    modprobe -q igb 2>/dev/null || true           # Intel 1GbE (82575/82576/etc)
    modprobe -q igc 2>/dev/null || true           # Intel 2.5GbE (I225/I226)
    modprobe -q ixgbe 2>/dev/null || true         # Intel 10GbE
    modprobe -q i40e 2>/dev/null || true          # Intel 10/25/40GbE
    modprobe -q ice 2>/dev/null || true           # Intel E800 series

    # Common Realtek NIC drivers
    modprobe -q r8169 2>/dev/null || true         # Realtek 8169/8168/8101/8125
    modprobe -q r8152 2>/dev/null || true         # Realtek USB

    # Common Broadcom NIC drivers
    modprobe -q tg3 2>/dev/null || true           # Broadcom Tigon3
    modprobe -q bnx2 2>/dev/null || true          # Broadcom NetXtreme II

    # Other common NIC drivers
    modprobe -q atlantic 2>/dev/null || true      # Aquantia/Marvell AQtion
    modprobe -q mlx4_en 2>/dev/null || true       # Mellanox ConnectX-3
    modprobe -q mlx5_core 2>/dev/null || true     # Mellanox ConnectX-4+
    modprobe -q be2net 2>/dev/null || true        # Emulex
    modprobe -q ena 2>/dev/null || true           # Amazon ENA

    # Give time for interfaces to appear
    sleep 1
}

# Get list of available network interfaces (excluding loopback)
get_network_interfaces() {
    for iface in /sys/class/net/*; do
        [ -d "$iface" ] || continue
        local name=$(basename "$iface")

        # Skip loopback
        [ "$name" = "lo" ] && continue

        # Skip virtual interfaces
        case "$name" in
            veth*|docker*|br-*|virbr*) continue ;;
        esac

        # Get MAC address
        local mac=$(cat "$iface/address" 2>/dev/null)

        # Get driver/type info
        local driver=""
        if [ -L "$iface/device/driver" ]; then
            driver=$(basename $(readlink "$iface/device/driver"))
        fi

        # Get link state
        local state=$(cat "$iface/operstate" 2>/dev/null)

        printf "%s %s %s %s\n" "$name" "$mac" "$driver" "$state"
    done
}

# Check that at least one network interface exists
check_network_interfaces() {
    local interfaces=$(get_network_interfaces)

    if [ -z "$interfaces" ]; then
        print_header
        print_error "No network interfaces detected!"
        printf "\n"
        printf "Coyote Linux requires at least one network interface.\n"
        printf "Please ensure your network hardware is properly connected.\n"
        printf "\nPress Enter to exit..."
        read dummy
        return 1
    fi

    return 0
}

# Select network interface for initial configuration
select_network_interface() {
    print_header
    printf "Select the network interface for initial configuration:\n\n"

    local interfaces=$(get_network_interfaces)
    echo "$interfaces" > /tmp/available_nics

    local i=1
    printf "  ${BOLD}#  Interface   MAC Address         Driver       State${NC}\n"
    printf "  -------------------------------------------------------\n"

    while IFS=' ' read -r name mac driver state; do
        printf "  %d) %-10s %-18s %-12s %s\n" "$i" "$name" "$mac" "$driver" "$state"
        i=$((i + 1))
    done < /tmp/available_nics

    printf "\n"

    local count=$(wc -l < /tmp/available_nics)

    while true; do
        printf "Enter selection [1-%d]: " "$count"
        read selection

        if [ "$selection" -ge 1 ] 2>/dev/null && [ "$selection" -le "$count" ]; then
            NET_INTERFACE=$(sed -n "${selection}p" /tmp/available_nics | cut -d' ' -f1)
            NET_MAC=$(sed -n "${selection}p" /tmp/available_nics | cut -d' ' -f2)
            return 0
        fi

        print_error "Invalid selection"
    done
}

# Validate IP address in CIDR notation
validate_cidr() {
    local cidr="$1"

    # Check format: x.x.x.x/y
    echo "$cidr" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+/[0-9]+$' || return 1

    # Extract IP and prefix
    local ip="${cidr%/*}"
    local prefix="${cidr#*/}"

    # Validate prefix (1-32)
    [ "$prefix" -ge 1 ] 2>/dev/null && [ "$prefix" -le 32 ] || return 1

    # Validate each octet
    local IFS='.'
    set -- $ip
    [ $# -eq 4 ] || return 1

    for octet in "$@"; do
        [ "$octet" -ge 0 ] 2>/dev/null && [ "$octet" -le 255 ] || return 1
    done

    return 0
}

# Validate IP address (without CIDR)
validate_ip() {
    local ip="$1"

    echo "$ip" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$' || return 1

    local IFS='.'
    set -- $ip

    [ $# -eq 4 ] || return 1

    for octet in "$@"; do
        [ "$octet" -ge 0 ] 2>/dev/null && [ "$octet" -le 255 ] || return 1
    done

    return 0
}

# Validate hostname
validate_hostname() {
    local hostname="$1"

    # Must be 1-63 characters, alphanumeric and hyphens, not start/end with hyphen
    echo "$hostname" | grep -qE '^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$' || return 1

    return 0
}

# Validate domain name
validate_domain() {
    local domain="$1"

    # Basic domain validation
    echo "$domain" | grep -qE '^[a-zA-Z0-9]([a-zA-Z0-9.-]*[a-zA-Z0-9])?$' || return 1

    return 0
}

# Configure network settings
configure_network() {
    # Select interface
    if ! select_network_interface; then
        return 1
    fi

    print_header
    printf "Network Configuration for ${BOLD}${NET_INTERFACE}${NC}\n\n"
    printf "Press Enter to accept the default value shown in brackets.\n\n"

    # Get IP address in CIDR notation
    local default_ip="192.168.0.1/24"
    while true; do
        printf "IP Address [${default_ip}]: "
        read NET_IP_CIDR

        if [ -z "$NET_IP_CIDR" ]; then
            NET_IP_CIDR="$default_ip"
            break
        fi

        if validate_cidr "$NET_IP_CIDR"; then
            break
        fi
        print_error "Invalid IP address. Please use CIDR notation (e.g., 192.168.1.1/24)"
    done

    # Get default gateway (optional)
    while true; do
        printf "Default Gateway (optional, press Enter to skip): "
        read NET_GATEWAY

        if [ -z "$NET_GATEWAY" ]; then
            break
        fi

        if validate_ip "$NET_GATEWAY"; then
            break
        fi
        print_error "Invalid gateway IP address"
    done

    # Generate default DNS based on IP if no gateway specified
    # Use common public DNS as fallback default
    local default_dns="1.1.1.1"

    # Get primary DNS server
    while true; do
        printf "Primary DNS Server [${default_dns}]: "
        read NET_DNS1

        if [ -z "$NET_DNS1" ]; then
            NET_DNS1="$default_dns"
            break
        fi

        if validate_ip "$NET_DNS1"; then
            break
        fi
        print_error "Invalid DNS server IP address"
    done

    # Get secondary DNS server (optional)
    while true; do
        printf "Secondary DNS Server (optional, press Enter to skip): "
        read NET_DNS2

        if [ -z "$NET_DNS2" ]; then
            break
        fi

        if validate_ip "$NET_DNS2"; then
            break
        fi
        print_error "Invalid DNS server IP address"
    done

    # Get hostname
    local default_hostname="coyote"
    while true; do
        printf "Hostname [${default_hostname}]: "
        read NET_HOSTNAME

        if [ -z "$NET_HOSTNAME" ]; then
            NET_HOSTNAME="$default_hostname"
            break
        fi

        if validate_hostname "$NET_HOSTNAME"; then
            break
        fi
        print_error "Invalid hostname. Use alphanumeric characters and hyphens only."
    done

    # Get search domain
    local default_domain="local.lan"
    while true; do
        printf "Search Domain [${default_domain}]: "
        read NET_DOMAIN

        if [ -z "$NET_DOMAIN" ]; then
            NET_DOMAIN="$default_domain"
            break
        fi

        if validate_domain "$NET_DOMAIN"; then
            break
        fi
        print_error "Invalid domain name"
    done

    # Show summary and confirm
    print_header
    printf "Network Configuration Summary\n"
    printf "=============================\n\n"
    printf "  Interface:      ${BOLD}%s${NC} (%s)\n" "$NET_INTERFACE" "$NET_MAC"
    printf "  IP Address:     ${BOLD}%s${NC}\n" "$NET_IP_CIDR"
    if [ -n "$NET_GATEWAY" ]; then
        printf "  Gateway:        ${BOLD}%s${NC}\n" "$NET_GATEWAY"
    else
        printf "  Gateway:        ${BOLD}(none)${NC}\n"
    fi
    printf "  DNS Servers:    ${BOLD}%s${NC}" "$NET_DNS1"
    [ -n "$NET_DNS2" ] && printf ", ${BOLD}%s${NC}" "$NET_DNS2"
    printf "\n"
    printf "  Hostname:       ${BOLD}%s${NC}\n" "$NET_HOSTNAME"
    printf "  Search Domain:  ${BOLD}%s${NC}\n" "$NET_DOMAIN"
    printf "\n"

    printf "Is this configuration correct? [Y/n]: "
    read confirm

    case "$confirm" in
        [Nn]*)
            return 1
            ;;
    esac

    return 0
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

    # Format boot partition as FAT32 (force to avoid prompts)
    printf "  Formatting boot partition (FAT32)...\n"
    mkfs.vfat -F 32 -n COYOTE "$BOOT_PART" >/dev/null 2>&1 || {
        print_error "Failed to format boot partition"
        return 1
    }

    # Format config partition as ext4 (force to avoid prompts)
    printf "  Formatting config partition (ext4)...\n"
    mkfs.ext4 -F -L COYOTE_CFG -q "$CONFIG_PART" >/dev/null 2>&1 || {
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

    # Create configuration with network settings
    printf "  Creating system configuration...\n"

    # Build DNS array
    local dns_servers="\"$NET_DNS1\""
    [ -n "$NET_DNS2" ] && dns_servers="$dns_servers, \"$NET_DNS2\""

    # Build interface JSON with optional gateway
    local interface_json
    if [ -n "$NET_GATEWAY" ]; then
        interface_json=$(cat << IFACE
            {
                "name": "$NET_INTERFACE",
                "mac": "$NET_MAC",
                "address": "$NET_IP_CIDR",
                "gateway": "$NET_GATEWAY"
            }
IFACE
)
    else
        interface_json=$(cat << IFACE
            {
                "name": "$NET_INTERFACE",
                "mac": "$NET_MAC",
                "address": "$NET_IP_CIDR"
            }
IFACE
)
    fi

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
$interface_json
        ]
    }
}
EOF

    # Ensure data is written to disk
    sync

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

    # Load network modules and check for interfaces (required)
    load_network_modules
    if ! check_network_interfaces; then
        exec /bin/sh
    fi

    # Select target disk
    if ! select_disk; then
        printf "\nInstallation cancelled.\n"
        printf "Press Enter for shell or Ctrl+Alt+Del to reboot..."
        read dummy
        exec /bin/sh
    fi

    # Configure network (loop until user confirms)
    while ! configure_network; do
        : # User chose to re-enter network configuration
    done

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
