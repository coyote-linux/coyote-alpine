#!/bin/bash
#
# mkinstaller.sh - Build USB installer image
#
# This script creates a bootable USB installer image without requiring
# root privileges. It uses mtools for FAT filesystem manipulation.
#
# Prerequisites (Fedora):
#   dnf install mtools syslinux parted dosfstools
#

set -e

BUILD_DIR="$1"

if [ -z "$BUILD_DIR" ]; then
    echo "Usage: $0 <build-dir>"
    exit 1
fi

# Check for required tools
for tool in parted mformat mcopy mmd syslinux; do
    if ! command -v "$tool" &>/dev/null; then
        echo "Error: Required tool '$tool' not found."
        echo "On Fedora, install with: dnf install mtools syslinux parted"
        exit 1
    fi
done

# Detect version from firmware filename (firmware-X.Y.Z.squashfs)
FIRMWARE_FILE=$(ls -t "${BUILD_DIR}"/firmware-*.squashfs 2>/dev/null | head -1)
if [ -n "$FIRMWARE_FILE" ]; then
    VERSION=$(basename "$FIRMWARE_FILE" | sed 's/firmware-\(.*\)\.squashfs/\1/')
else
    VERSION="4.0.0"
    echo "Warning: No firmware found, using default version $VERSION"
fi
echo "Building USB installer for Coyote Linux $VERSION"

INSTALLER_IMG="${BUILD_DIR}/coyote-installer-${VERSION}.img"
# Size must accommodate: kernel (~10MB) + initramfs (~11MB) + firmware (~220MB) + syslinux
INSTALLER_SIZE="300M"
PARTITION_START=1048576      # 1MiB in bytes
PARTITION_START_SECTORS=2048 # 1MiB in 512-byte sectors

echo "Creating installer image..."

# Create disk image
truncate -s "$INSTALLER_SIZE" "$INSTALLER_IMG"

# Create partition table and partitions
parted -s "$INSTALLER_IMG" mklabel msdos
parted -s "$INSTALLER_IMG" mkpart primary fat32 1MiB 100%
parted -s "$INSTALLER_IMG" set 1 boot on

# Get partition size (total size minus 1MiB for partition start)
TOTAL_BYTES=$(stat -c%s "$INSTALLER_IMG")
PARTITION_BYTES=$((TOTAL_BYTES - PARTITION_START))

# Format FAT32 partition using mformat
# mtools uses "drive letters" configured via env or mtools.conf
# We use the -i option to specify the image file directly with offset
echo "Formatting FAT32 partition..."
mformat -i "${INSTALLER_IMG}@@${PARTITION_START}" -F -v COYOTE ::

# Create directory structure using mtools
echo "Creating directory structure..."
mmd -i "${INSTALLER_IMG}@@${PARTITION_START}" ::/boot
mmd -i "${INSTALLER_IMG}@@${PARTITION_START}" ::/boot/syslinux
mmd -i "${INSTALLER_IMG}@@${PARTITION_START}" ::/firmware

# Find syslinux modules (Fedora locations)
SYSLINUX_DIR=""
for dir in /usr/share/syslinux /usr/lib/syslinux/bios /usr/lib/syslinux; do
    if [ -f "${dir}/ldlinux.c32" ]; then
        SYSLINUX_DIR="$dir"
        break
    fi
done

if [ -z "$SYSLINUX_DIR" ]; then
    echo "Error: Cannot find syslinux modules (ldlinux.c32)"
    echo "On Fedora, install with: dnf install syslinux"
    exit 1
fi

# Copy syslinux modules
echo "Copying syslinux modules..."
mcopy -i "${INSTALLER_IMG}@@${PARTITION_START}" "${SYSLINUX_DIR}/ldlinux.c32" ::/boot/syslinux/
mcopy -i "${INSTALLER_IMG}@@${PARTITION_START}" "${SYSLINUX_DIR}/menu.c32" ::/boot/syslinux/
mcopy -i "${INSTALLER_IMG}@@${PARTITION_START}" "${SYSLINUX_DIR}/libutil.c32" ::/boot/syslinux/

# Also copy ldlinux.sys if it exists (needed by some syslinux versions)
if [ -f "${SYSLINUX_DIR}/ldlinux.sys" ]; then
    mcopy -i "${INSTALLER_IMG}@@${PARTITION_START}" "${SYSLINUX_DIR}/ldlinux.sys" ::/boot/syslinux/
fi

# Copy kernel and initramfs (check for different possible locations/names)
echo "Copying kernel and initramfs..."
KERNEL_SRC=""
for kernel in "${BUILD_DIR}/vmlinuz" /boot/vmlinuz-lts /boot/vmlinuz; do
    if [ -f "$kernel" ]; then
        KERNEL_SRC="$kernel"
        break
    fi
done

if [ -z "$KERNEL_SRC" ]; then
    echo "Warning: No kernel found, skipping kernel copy"
    echo "         Place vmlinuz in ${BUILD_DIR}/ before building installer"
else
    mcopy -i "${INSTALLER_IMG}@@${PARTITION_START}" "$KERNEL_SRC" ::/boot/vmlinuz
fi

# Use installer-specific initramfs for booting the installer
INITRAMFS_SRC="${BUILD_DIR}/initramfs-installer.cpio.gz"
if [ -f "$INITRAMFS_SRC" ]; then
    mcopy -i "${INSTALLER_IMG}@@${PARTITION_START}" "$INITRAMFS_SRC" ::/boot/initramfs.gz
else
    echo "Warning: No installer initramfs found at ${INITRAMFS_SRC}"
    echo "         Run 'make initramfs-installer' to build it"
fi

# Also include the system initramfs - this is what gets installed to the target system
INITRAMFS_SYSTEM="${BUILD_DIR}/initramfs.cpio.gz"
if [ -f "$INITRAMFS_SYSTEM" ]; then
    echo "Copying system initramfs..."
    mcopy -i "${INSTALLER_IMG}@@${PARTITION_START}" "$INITRAMFS_SYSTEM" ::/boot/initramfs-system.gz
else
    echo "Warning: No system initramfs found at ${INITRAMFS_SYSTEM}"
    echo "         Run 'make initramfs' to build it"
fi

# Copy firmware
echo "Copying firmware..."
FIRMWARE_SRC=$(ls "${BUILD_DIR}"/firmware-*.squashfs 2>/dev/null | head -1)
if [ -n "$FIRMWARE_SRC" ] && [ -f "$FIRMWARE_SRC" ]; then
    mcopy -i "${INSTALLER_IMG}@@${PARTITION_START}" "$FIRMWARE_SRC" ::/firmware/current.squashfs
    if [ -f "${FIRMWARE_SRC}.sha256" ]; then
        mcopy -i "${INSTALLER_IMG}@@${PARTITION_START}" "${FIRMWARE_SRC}.sha256" ::/firmware/current.squashfs.sha256
    fi
    # Copy signature file if it exists
    if [ -f "${FIRMWARE_SRC}.sig" ]; then
        echo "Copying firmware signature..."
        mcopy -i "${INSTALLER_IMG}@@${PARTITION_START}" "${FIRMWARE_SRC}.sig" ::/firmware/current.squashfs.sig
    else
        echo "Note: No firmware signature found (unsigned build)"
    fi
else
    echo "Warning: No firmware image found in ${BUILD_DIR}/"
fi

# Create boot marker
echo "COYOTE_INSTALLER" > "${BUILD_DIR}/coyote.marker"
mcopy -i "${INSTALLER_IMG}@@${PARTITION_START}" "${BUILD_DIR}/coyote.marker" ::/
rm -f "${BUILD_DIR}/coyote.marker"

# Create syslinux config
echo "Creating syslinux configuration..."
cat > "${BUILD_DIR}/syslinux.cfg" << 'EOF'
DEFAULT menu.c32
PROMPT 0
TIMEOUT 50

MENU TITLE Coyote Linux Installer

LABEL install
    MENU LABEL Install Coyote Linux
    LINUX /boot/vmlinuz
    INITRD /boot/initramfs.gz
    APPEND console=tty0 installer

LABEL rescue
    MENU LABEL Rescue Mode
    LINUX /boot/vmlinuz
    INITRD /boot/initramfs.gz
    APPEND console=tty0 rescue
EOF
mcopy -i "${INSTALLER_IMG}@@${PARTITION_START}" "${BUILD_DIR}/syslinux.cfg" ::/boot/syslinux/
rm -f "${BUILD_DIR}/syslinux.cfg"

# Install syslinux bootloader
# syslinux can install to a file with --offset
echo "Installing syslinux bootloader..."
syslinux --install --offset "$PARTITION_START" "$INSTALLER_IMG"

# Install MBR boot code
echo "Installing MBR..."
MBR_BIN=""
for mbr in /usr/share/syslinux/mbr.bin /usr/lib/syslinux/bios/mbr.bin /usr/lib/syslinux/mbr.bin; do
    if [ -f "$mbr" ]; then
        MBR_BIN="$mbr"
        break
    fi
done

if [ -n "$MBR_BIN" ]; then
    dd if="$MBR_BIN" of="$INSTALLER_IMG" bs=440 count=1 conv=notrunc 2>/dev/null
else
    echo "Warning: MBR boot code not found, image may not boot on all systems"
fi

echo ""
echo "=========================================="
echo "Installer image created: $INSTALLER_IMG"
ls -lh "$INSTALLER_IMG"
echo ""
echo "To write to USB drive:"
echo "  dd if=$INSTALLER_IMG of=/dev/sdX bs=4M status=progress"
echo "=========================================="
