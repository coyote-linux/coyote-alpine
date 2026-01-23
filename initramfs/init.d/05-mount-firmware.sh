#!/bin/sh
#
# 05-mount-firmware.sh - Mount the squashfs firmware image
#

log "Mounting firmware image..."

# Create mount point
mkdir -p "$NEWROOT"

# Mount squashfs firmware
if ! mount -t squashfs -o ro "$FIRMWARE_PATH" "$NEWROOT"; then
    error "Failed to mount firmware image!"
    return 1
fi

log "Firmware mounted at $NEWROOT"

# Verify essential directories exist
for dir in bin sbin etc opt; do
    if [ ! -d "${NEWROOT}/${dir}" ]; then
        error "Firmware image appears corrupt: missing /${dir}"
        umount "$NEWROOT"
        return 1
    fi
done

log "Firmware image validated"
