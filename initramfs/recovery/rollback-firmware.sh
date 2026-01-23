#!/bin/sh
#
# rollback-firmware.sh - Rollback to previous firmware version
#

FIRMWARE_DIR="/mnt/boot/firmware"
FIRMWARE_CURRENT="${FIRMWARE_DIR}/current.squashfs"
FIRMWARE_BACKUP="${FIRMWARE_DIR}/backup.squashfs"

echo ""
echo "Firmware Rollback"
echo "-----------------"

# Check for backup firmware
if [ ! -f "$FIRMWARE_BACKUP" ]; then
    echo "ERROR: No backup firmware available!"
    echo ""
    echo "Press Enter to continue..."
    read dummy
    return 1
fi

# Show firmware info
echo ""
echo "Current firmware: $(ls -lh "$FIRMWARE_CURRENT" 2>/dev/null | awk '{print $5, $6, $7, $8}')"
echo "Backup firmware:  $(ls -lh "$FIRMWARE_BACKUP" 2>/dev/null | awk '{print $5, $6, $7, $8}')"
echo ""

echo -n "Rollback to backup firmware? [y/N]: "
read confirm

if [ "$confirm" != "y" ] && [ "$confirm" != "Y" ]; then
    echo "Rollback cancelled."
    return 0
fi

echo "Rolling back firmware..."

# Remount boot partition read-write
mount -o remount,rw /mnt/boot

# Swap firmware images
mv "$FIRMWARE_CURRENT" "${FIRMWARE_DIR}/failed.squashfs"
mv "$FIRMWARE_BACKUP" "$FIRMWARE_CURRENT"
mv "${FIRMWARE_DIR}/failed.squashfs" "$FIRMWARE_BACKUP"

# Remount read-only
mount -o remount,ro /mnt/boot

echo ""
echo "Firmware rollback complete!"
echo "System will now reboot with previous firmware."
echo ""
echo "Press Enter to reboot..."
read dummy

reboot -f
