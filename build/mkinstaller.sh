#!/bin/bash
#
# mkinstaller.sh - Build USB installer image
#

set -e

BUILD_DIR="$1"

if [ -z "$BUILD_DIR" ]; then
    echo "Usage: $0 <build-dir>"
    exit 1
fi

INSTALLER_IMG="${BUILD_DIR}/coyote-installer.img"
INSTALLER_SIZE="512M"

echo "Creating installer image..."

# Create disk image
truncate -s "$INSTALLER_SIZE" "$INSTALLER_IMG"

# Create partition table and partitions
parted -s "$INSTALLER_IMG" mklabel msdos
parted -s "$INSTALLER_IMG" mkpart primary fat32 1MiB 100%
parted -s "$INSTALLER_IMG" set 1 boot on

# Setup loop device
LOOP_DEV=$(losetup --find --show --partscan "$INSTALLER_IMG")
LOOP_PART="${LOOP_DEV}p1"

# Wait for partition device
sleep 1

# Format partition
mkfs.vfat -F 32 -n COYOTE "$LOOP_PART"

# Mount and copy files
MOUNT_DIR=$(mktemp -d)
mount "$LOOP_PART" "$MOUNT_DIR"

# Copy bootloader (syslinux)
mkdir -p "${MOUNT_DIR}/boot/syslinux"
cp /usr/share/syslinux/ldlinux.c32 "${MOUNT_DIR}/boot/syslinux/"
cp /usr/share/syslinux/menu.c32 "${MOUNT_DIR}/boot/syslinux/"
cp /usr/share/syslinux/libutil.c32 "${MOUNT_DIR}/boot/syslinux/"

# Copy kernel and initramfs
cp /boot/vmlinuz-lts "${MOUNT_DIR}/boot/vmlinuz"
cp "${BUILD_DIR}/initramfs.cpio.gz" "${MOUNT_DIR}/boot/initramfs.gz"

# Copy firmware
mkdir -p "${MOUNT_DIR}/firmware"
cp "${BUILD_DIR}"/firmware-*.squashfs "${MOUNT_DIR}/firmware/current.squashfs"
cp "${BUILD_DIR}"/firmware-*.squashfs.sha256 "${MOUNT_DIR}/firmware/current.squashfs.sha256"

# Create boot marker
echo "COYOTE_INSTALLER" > "${MOUNT_DIR}/coyote.marker"

# Create syslinux config
cat > "${MOUNT_DIR}/boot/syslinux/syslinux.cfg" << 'EOF'
DEFAULT menu.c32
PROMPT 0
TIMEOUT 50

MENU TITLE Coyote Linux Installer

LABEL install
    MENU LABEL Install Coyote Linux
    LINUX /boot/vmlinuz
    INITRD /boot/initramfs.gz
    APPEND quiet installer

LABEL rescue
    MENU LABEL Rescue Mode
    LINUX /boot/vmlinuz
    INITRD /boot/initramfs.gz
    APPEND quiet rescue
EOF

# Install syslinux
syslinux --install "$LOOP_PART"

# Cleanup
umount "$MOUNT_DIR"
rmdir "$MOUNT_DIR"
losetup -d "$LOOP_DEV"

echo "Installer image created: $INSTALLER_IMG"
ls -lh "$INSTALLER_IMG"
