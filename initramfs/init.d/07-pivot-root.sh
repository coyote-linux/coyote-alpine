#!/bin/sh
#
# 07-pivot-root.sh - Final preparations before pivot_root
#

log "Preparing for pivot root..."

# Debug: Show boot media info
log "Boot media: ${BOOT_MEDIA:-<not set>} (type: ${BOOT_MEDIA_TYPE:-<not set>})"

# List available block devices for debugging
log "Available block devices:"
for dev in /dev/sd* /dev/nvme* /dev/vd* /dev/sr*; do
    [ -b "$dev" ] && log "  $dev"
done

# Determine config partition based on boot media
# For installed systems, config is partition 2 on the same disk as boot (partition 1)
CONFIG_PART=""

# First, try to find config partition on the same disk as boot media
if [ -n "$BOOT_MEDIA" ] && [ "$BOOT_MEDIA_TYPE" = "disk" ]; then
    # Extract base disk from boot partition (e.g., /dev/sda1 -> /dev/sda)
    case "$BOOT_MEDIA" in
        /dev/sd*|/dev/vd*)
            # Remove trailing partition number: sda1 -> sda
            base_disk="${BOOT_MEDIA%[0-9]}"
            config_candidate="${base_disk}2"
            ;;
        /dev/nvme*)
            # Remove trailing p and partition number: nvme0n1p1 -> nvme0n1
            base_disk="${BOOT_MEDIA%p[0-9]}"
            config_candidate="${base_disk}p2"
            ;;
    esac

    if [ -n "$config_candidate" ] && [ -b "$config_candidate" ]; then
        log "Checking config partition: $config_candidate"
        if mount -t ext4 -o ro "$config_candidate" /mnt 2>/dev/null; then
            log "  Mounted successfully"
            log "  Contents: $(ls -la /mnt 2>&1 | head -5)"
            if [ -f /mnt/system.json ]; then
                CONFIG_PART="$config_candidate"
                log "  Found system.json!"
            else
                log "  No system.json found"
            fi
            umount /mnt
        else
            log "  Failed to mount $config_candidate"
        fi
    else
        log "Config candidate not valid: config_candidate=$config_candidate"
    fi
fi

# Fallback: scan all partition 2's for system.json
if [ -z "$CONFIG_PART" ]; then
    log "Scanning for config partition..."
    for part in /dev/sd*2 /dev/nvme*p2 /dev/vd*2; do
        [ -b "$part" ] || continue
        log "  Checking: $part"

        if mount -t ext4 -o ro "$part" /mnt 2>/dev/null; then
            log "    Mounted successfully"
            # Show what's on the partition
            log "    Contents: $(ls -la /mnt 2>&1 | head -5)"
            if [ -f /mnt/system.json ]; then
                CONFIG_PART="$part"
                log "    Found system.json!"
                umount /mnt
                break
            else
                log "    No system.json found"
            fi
            umount /mnt
        else
            log "    Mount FAILED"
        fi
    done
fi

# Mount config partition to final location
mkdir -p "${NEWROOT}/mnt/config"
if [ -n "$CONFIG_PART" ]; then
    if mount -t ext4 -o ro "$CONFIG_PART" "${NEWROOT}/mnt/config"; then
        log "Config partition mounted: $CONFIG_PART -> ${NEWROOT}/mnt/config"
    else
        warn "Failed to mount config partition $CONFIG_PART"
    fi
else
    warn "Config partition not found, using defaults"
fi

# Keep boot media mounted for firmware updates
mkdir -p "${NEWROOT}/mnt/boot"
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
