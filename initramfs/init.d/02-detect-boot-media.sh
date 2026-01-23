#!/bin/sh
#
# 02-detect-boot-media.sh - Detect and mount boot media
#

log "Detecting boot media..."

# Wait for devices to settle
sleep 1

# Look for boot media with coyote label or specific marker file
for dev in /dev/sd* /dev/nvme* /dev/vd*; do
    [ -b "$dev" ] || continue

    # Skip whole disks, look for partitions
    case "$dev" in
        *[0-9]) ;;  # Partition
        *) continue ;;
    esac

    # Try to mount and check for Coyote marker
    mount -o ro "$dev" /mnt 2>/dev/null || continue

    if [ -f /mnt/coyote.marker ]; then
        BOOT_MEDIA="$dev"
        log "Found boot media: $dev"
        umount /mnt
        break
    fi

    umount /mnt
done

if [ -z "$BOOT_MEDIA" ]; then
    error "Boot media not found!"
    return 1
fi

# Mount boot media
mkdir -p /mnt/boot
mount -o ro "$BOOT_MEDIA" /mnt/boot
log "Boot media mounted: $BOOT_MEDIA"

export BOOT_MEDIA
