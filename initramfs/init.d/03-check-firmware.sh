#!/bin/sh
#
# 03-check-firmware.sh - Check for and validate firmware image
#

log "Checking firmware image..."

FIRMWARE_DIR="/mnt/boot/firmware"
FIRMWARE_CURRENT="${FIRMWARE_DIR}/current.squashfs"
FIRMWARE_PREVIOUS="${FIRMWARE_DIR}/previous.squashfs"
FIRMWARE_NEW="${FIRMWARE_DIR}/new.squashfs"
FIRMWARE_BACKUP="${FIRMWARE_DIR}/backup.squashfs"

# Check for nosigcheck kernel parameter (development mode)
SKIP_SIGCHECK=0
if grep -q "nosigcheck" /proc/cmdline 2>/dev/null; then
    warn "Signature verification DISABLED (nosigcheck)"
    SKIP_SIGCHECK=1
fi

# Check for firmware=previous kernel parameter (rollback boot)
USE_PREVIOUS=0
if grep -q "firmware=previous" /proc/cmdline 2>/dev/null; then
    log "Booting previous firmware version (rollback mode)"
    USE_PREVIOUS=1
fi

# Function to verify firmware signature
verify_firmware_signature() {
    local firmware="$1"

    if [ "$SKIP_SIGCHECK" = "1" ]; then
        return 0
    fi

    if [ ! -f "${firmware}.sig" ]; then
        warn "No signature file for firmware"
        # Allow unsigned firmware for now (can be made strict later)
        return 0
    fi

    log "Verifying firmware signature..."
    if verify-signature "$firmware"; then
        log "Firmware signature: VALID"
        return 0
    else
        error "Firmware signature: INVALID"
        return 1
    fi
}

# Check for new firmware to apply
if [ -f "$FIRMWARE_NEW" ]; then
    log "New firmware detected, validating..."

    # Check hash if available
    if [ -f "${FIRMWARE_NEW}.sha256" ]; then
        expected_hash=$(cat "${FIRMWARE_NEW}.sha256" | cut -d' ' -f1)
        actual_hash=$(sha256sum "$FIRMWARE_NEW" | cut -d' ' -f1)

        if [ "$expected_hash" = "$actual_hash" ]; then
            log "Firmware hash valid"

            # Verify signature
            if ! verify_firmware_signature "$FIRMWARE_NEW"; then
                error "Firmware signature verification failed! Update aborted."
                rm -f "$FIRMWARE_NEW" "${FIRMWARE_NEW}.sha256" "${FIRMWARE_NEW}.sig"
            else
                log "Applying firmware update..."

                # Backup current firmware
                if [ -f "$FIRMWARE_CURRENT" ]; then
                    mv "$FIRMWARE_CURRENT" "$FIRMWARE_BACKUP"
                    [ -f "${FIRMWARE_CURRENT}.sig" ] && mv "${FIRMWARE_CURRENT}.sig" "${FIRMWARE_BACKUP}.sig"
                fi

                # Activate new firmware
                mv "$FIRMWARE_NEW" "$FIRMWARE_CURRENT"
                [ -f "${FIRMWARE_NEW}.sig" ] && mv "${FIRMWARE_NEW}.sig" "${FIRMWARE_CURRENT}.sig"
                rm -f "${FIRMWARE_NEW}.sha256"

                log "Firmware update applied"
            fi
        else
            error "Firmware hash mismatch! Update aborted."
            rm -f "$FIRMWARE_NEW" "${FIRMWARE_NEW}.sha256" "${FIRMWARE_NEW}.sig"
        fi
    else
        warn "No hash file for new firmware, skipping update"
    fi
fi

# Handle rollback boot (firmware=previous kernel parameter)
if [ "$USE_PREVIOUS" = "1" ]; then
    if [ -f "$FIRMWARE_PREVIOUS" ]; then
        log "Using previous firmware for rollback boot"
        if verify_firmware_signature "$FIRMWARE_PREVIOUS"; then
            FIRMWARE_PATH="$FIRMWARE_PREVIOUS"
            export FIRMWARE_PATH
            log "Firmware image: $FIRMWARE_PATH (previous version)"
            return 0
        else
            error "Previous firmware signature verification FAILED!"
            error "Cannot boot previous version securely."
            # Fall through to try current firmware
        fi
    else
        warn "No previous firmware available, booting current version"
    fi
fi

# Verify current firmware exists
if [ ! -f "$FIRMWARE_CURRENT" ]; then
    error "No firmware image found!"

    # Try backup
    if [ -f "$FIRMWARE_BACKUP" ]; then
        warn "Attempting to use backup firmware..."
        cp "$FIRMWARE_BACKUP" "$FIRMWARE_CURRENT"
        [ -f "${FIRMWARE_BACKUP}.sig" ] && cp "${FIRMWARE_BACKUP}.sig" "${FIRMWARE_CURRENT}.sig"
    else
        return 1
    fi
fi

# Verify signature of current firmware before booting
if ! verify_firmware_signature "$FIRMWARE_CURRENT"; then
    error "Current firmware signature verification FAILED!"

    # Try backup firmware
    if [ -f "$FIRMWARE_BACKUP" ]; then
        warn "Attempting to boot backup firmware..."
        if verify_firmware_signature "$FIRMWARE_BACKUP"; then
            log "Backup firmware signature valid, using backup"
            FIRMWARE_PATH="$FIRMWARE_BACKUP"
        else
            error "Backup firmware also has invalid signature!"
            error "System cannot boot securely."
            # Drop to recovery
            return 1
        fi
    else
        error "No backup firmware available!"
        return 1
    fi
else
    FIRMWARE_PATH="$FIRMWARE_CURRENT"
fi

export FIRMWARE_PATH

log "Firmware image: $FIRMWARE_PATH"
