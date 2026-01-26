#!/bin/sh
#
# 07-pivot-root.sh - Final preparations before pivot_root
#
# Note: Config partition is mounted later by apply-config during OpenRC boot.
# The ext4 module dependencies aren't available in the minimal initramfs.
#

log "Preparing for pivot root..."

# Debug: Show boot media info
log "Boot media: ${BOOT_MEDIA:-<not set>} (type: ${BOOT_MEDIA_TYPE:-<not set>})"

# Create mount points in newroot
mkdir -p "${NEWROOT}/mnt/config"
mkdir -p "${NEWROOT}/mnt/boot"

# Keep boot media mounted for firmware updates
if [ -d /mnt/boot ] && mountpoint -q /mnt/boot 2>/dev/null; then
    mount --move /mnt/boot "${NEWROOT}/mnt/boot"
    log "Boot media moved to ${NEWROOT}/mnt/boot"
else
    # Mount boot media directly if not already mounted
    if [ -n "$BOOT_MEDIA" ]; then
        mount -o ro "$BOOT_MEDIA" "${NEWROOT}/mnt/boot"
        log "Boot media mounted: $BOOT_MEDIA -> ${NEWROOT}/mnt/boot"
    fi
fi

log "Ready for pivot root"
