#!/bin/sh
#
# 07-pivot-root.sh - Final preparations before pivot_root
#

log "Preparing for pivot root..."

# Mount config partition
CONFIG_PART=""
for part in /dev/sd*2 /dev/nvme*p2 /dev/vd*2; do
    [ -b "$part" ] || continue

    mount -o ro "$part" /mnt 2>/dev/null || continue

    if [ -f /mnt/system.json ]; then
        CONFIG_PART="$part"
        umount /mnt
        break
    fi

    umount /mnt
done

if [ -n "$CONFIG_PART" ]; then
    mkdir -p "${NEWROOT}/mnt/config"
    mount -o ro "$CONFIG_PART" "${NEWROOT}/mnt/config"
    log "Config partition mounted: $CONFIG_PART"
else
    warn "Config partition not found, using defaults"
    mkdir -p "${NEWROOT}/mnt/config"
fi

# Keep boot media mounted for firmware updates
mkdir -p "${NEWROOT}/mnt/boot"
mount --move /mnt/boot "${NEWROOT}/mnt/boot"

log "Ready for pivot root"
