#!/bin/sh
#
# rollback-firmware.sh - Rollback to previous firmware version
#
# This script swaps the current and previous firmware, kernel, and initramfs
# to allow booting the previous version of the system.
#

BOOT_DIR="/mnt/boot/boot"
FIRMWARE_DIR="/mnt/boot/firmware"

FIRMWARE_CURRENT="${FIRMWARE_DIR}/current.squashfs"
FIRMWARE_PREVIOUS="${FIRMWARE_DIR}/previous.squashfs"

KERNEL_CURRENT="${BOOT_DIR}/vmlinuz"
KERNEL_PREVIOUS="${BOOT_DIR}/vmlinuz.prev"

INITRAMFS_CURRENT="${BOOT_DIR}/initramfs.gz"
INITRAMFS_PREVIOUS="${BOOT_DIR}/initramfs.gz.prev"

echo ""
echo "Firmware Rollback"
echo "-----------------"

# Check for previous firmware
if [ ! -f "$FIRMWARE_PREVIOUS" ]; then
    echo "ERROR: No previous firmware available!"
    echo ""
    echo "This system has not been upgraded, or the previous"
    echo "firmware was not preserved during upgrade."
    echo ""
    echo "Press Enter to continue..."
    read dummy
    return 1
fi

# Show firmware info
echo ""
echo "Current firmware:  $(ls -lh "$FIRMWARE_CURRENT" 2>/dev/null | awk '{print $5, $6, $7, $8}')"
echo "Previous firmware: $(ls -lh "$FIRMWARE_PREVIOUS" 2>/dev/null | awk '{print $5, $6, $7, $8}')"
echo ""

# Check for kernel/initramfs
has_kernel_prev=0
has_initramfs_prev=0

if [ -f "$KERNEL_PREVIOUS" ]; then
    has_kernel_prev=1
    echo "Previous kernel:   $(ls -lh "$KERNEL_PREVIOUS" 2>/dev/null | awk '{print $5, $6, $7, $8}')"
else
    echo "Previous kernel:   NOT AVAILABLE"
fi

if [ -f "$INITRAMFS_PREVIOUS" ]; then
    has_initramfs_prev=1
    echo "Previous initramfs: $(ls -lh "$INITRAMFS_PREVIOUS" 2>/dev/null | awk '{print $5, $6, $7, $8}')"
else
    echo "Previous initramfs: NOT AVAILABLE"
fi

echo ""

if [ "$has_kernel_prev" = "0" ] || [ "$has_initramfs_prev" = "0" ]; then
    echo "WARNING: Previous kernel or initramfs not found."
    echo "Only the firmware will be rolled back. If the kernel"
    echo "version changed between releases, this may cause problems."
    echo ""
fi

echo -n "Rollback to previous version? [y/N]: "
read confirm

if [ "$confirm" != "y" ] && [ "$confirm" != "Y" ]; then
    echo "Rollback cancelled."
    return 0
fi

echo ""
echo "Rolling back..."

# Remount boot partition read-write
mount -o remount,rw /mnt/boot

# Swap firmware images
echo "  Swapping firmware..."
mv "$FIRMWARE_CURRENT" "${FIRMWARE_DIR}/rollback-temp.squashfs"
mv "$FIRMWARE_PREVIOUS" "$FIRMWARE_CURRENT"
mv "${FIRMWARE_DIR}/rollback-temp.squashfs" "$FIRMWARE_PREVIOUS"

# Swap signature files if they exist
if [ -f "${FIRMWARE_CURRENT}.sig" ] || [ -f "${FIRMWARE_PREVIOUS}.sig" ]; then
    [ -f "${FIRMWARE_CURRENT}.sig" ] && mv "${FIRMWARE_CURRENT}.sig" "${FIRMWARE_DIR}/rollback-temp.sig"
    [ -f "${FIRMWARE_PREVIOUS}.sig" ] && mv "${FIRMWARE_PREVIOUS}.sig" "${FIRMWARE_CURRENT}.sig"
    [ -f "${FIRMWARE_DIR}/rollback-temp.sig" ] && mv "${FIRMWARE_DIR}/rollback-temp.sig" "${FIRMWARE_PREVIOUS}.sig"
fi

# Swap kernel if previous exists
if [ -f "$KERNEL_PREVIOUS" ]; then
    echo "  Swapping kernel..."
    mv "$KERNEL_CURRENT" "${BOOT_DIR}/vmlinuz.temp"
    mv "$KERNEL_PREVIOUS" "$KERNEL_CURRENT"
    mv "${BOOT_DIR}/vmlinuz.temp" "$KERNEL_PREVIOUS"
fi

# Swap initramfs if previous exists
if [ -f "$INITRAMFS_PREVIOUS" ]; then
    echo "  Swapping initramfs..."
    mv "$INITRAMFS_CURRENT" "${BOOT_DIR}/initramfs.gz.temp"
    mv "$INITRAMFS_PREVIOUS" "$INITRAMFS_CURRENT"
    mv "${BOOT_DIR}/initramfs.gz.temp" "$INITRAMFS_PREVIOUS"
fi

# Sync and remount read-only
sync
mount -o remount,ro /mnt/boot

echo ""
echo "Rollback complete!"
echo ""
echo "The system will now reboot with the previous version."
echo "After rollback, the 'previous' files will contain the"
echo "version you just rolled back from."
echo ""
echo "Press Enter to reboot..."
read dummy

reboot -f
