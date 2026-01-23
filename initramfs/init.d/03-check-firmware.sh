#!/bin/sh
#
# 03-check-firmware.sh - Check for and validate firmware image
#

log "Checking firmware image..."

FIRMWARE_DIR="/mnt/boot/firmware"
FIRMWARE_CURRENT="${FIRMWARE_DIR}/current.squashfs"
FIRMWARE_NEW="${FIRMWARE_DIR}/new.squashfs"
FIRMWARE_BACKUP="${FIRMWARE_DIR}/backup.squashfs"

# Check for new firmware to apply
if [ -f "$FIRMWARE_NEW" ]; then
    log "New firmware detected, validating..."

    # Check hash if available
    if [ -f "${FIRMWARE_NEW}.sha256" ]; then
        expected_hash=$(cat "${FIRMWARE_NEW}.sha256" | cut -d' ' -f1)
        actual_hash=$(sha256sum "$FIRMWARE_NEW" | cut -d' ' -f1)

        if [ "$expected_hash" = "$actual_hash" ]; then
            log "Firmware hash valid, applying update..."

            # Backup current firmware
            if [ -f "$FIRMWARE_CURRENT" ]; then
                mv "$FIRMWARE_CURRENT" "$FIRMWARE_BACKUP"
            fi

            # Activate new firmware
            mv "$FIRMWARE_NEW" "$FIRMWARE_CURRENT"
            rm -f "${FIRMWARE_NEW}.sha256"

            log "Firmware update applied"
        else
            error "Firmware hash mismatch! Update aborted."
            rm -f "$FIRMWARE_NEW" "${FIRMWARE_NEW}.sha256"
        fi
    else
        warn "No hash file for new firmware, skipping update"
    fi
fi

# Verify current firmware exists
if [ ! -f "$FIRMWARE_CURRENT" ]; then
    error "No firmware image found!"

    # Try backup
    if [ -f "$FIRMWARE_BACKUP" ]; then
        warn "Attempting to use backup firmware..."
        cp "$FIRMWARE_BACKUP" "$FIRMWARE_CURRENT"
    else
        return 1
    fi
fi

FIRMWARE_PATH="$FIRMWARE_CURRENT"
export FIRMWARE_PATH

log "Firmware image: $FIRMWARE_PATH"
