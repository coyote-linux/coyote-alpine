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
BOOT_KERNEL="/mnt/boot/boot/vmlinuz"
BOOT_KERNEL_PREV="/mnt/boot/boot/vmlinuz.prev"
BOOT_KERNEL_NEW="/mnt/boot/boot/vmlinuz.new"
BOOT_INITRAMFS="/mnt/boot/boot/initramfs.gz"
BOOT_INITRAMFS_PREV="/mnt/boot/boot/initramfs.gz.prev"
BOOT_INITRAMFS_NEW="/mnt/boot/boot/initramfs.gz.new"
FIRMWARE_STAGING_DIR="/mnt/config/firmware-staging"

find_config_partition() {
    local candidate=""

    case "$BOOT_MEDIA" in
        /dev/nvme*n*p[0-9]|/dev/mmcblk*p[0-9])
            candidate="${BOOT_MEDIA%p[0-9]*}p2"
            ;;
        /dev/*[0-9])
            candidate="${BOOT_MEDIA%[0-9]*}2"
            ;;
    esac

    if [ -n "$candidate" ] && [ -b "$candidate" ]; then
        echo "$candidate"
        return 0
    fi

    for candidate in /dev/sd?2 /dev/nvme?n?p2 /dev/vd?2 /dev/mmcblk?p2; do
        [ -b "$candidate" ] || continue
        echo "$candidate"
        return 0
    done

    return 1
}

mount_config_partition() {
    local config_part="$1"

    [ -n "$config_part" ] || return 1

    mkdir -p /mnt/config

    if grep -q " /mnt/config " /proc/mounts 2>/dev/null; then
        return 0
    fi

    mount -t ext4 -o ro "$config_part" /mnt/config 2>/dev/null
}

apply_staged_boot_components() {
    local has_kernel_new=0
    local has_initramfs_new=0

    [ -f "$BOOT_KERNEL_NEW" ] && has_kernel_new=1
    [ -f "$BOOT_INITRAMFS_NEW" ] && has_initramfs_new=1

    if [ "$has_kernel_new" = "0" ] && [ "$has_initramfs_new" = "0" ]; then
        return 0
    fi

    if [ "$has_kernel_new" != "$has_initramfs_new" ]; then
        error "Incomplete staged boot component update"
        return 1
    fi

    if [ -f "$BOOT_KERNEL" ]; then
        mv "$BOOT_KERNEL" "$BOOT_KERNEL_PREV" 2>/dev/null || true
    fi

    if [ -f "$BOOT_INITRAMFS" ]; then
        mv "$BOOT_INITRAMFS" "$BOOT_INITRAMFS_PREV" 2>/dev/null || true
    fi

    if ! mv "$BOOT_KERNEL_NEW" "$BOOT_KERNEL"; then
        error "Failed to install staged kernel"
        return 1
    fi

    if ! mv "$BOOT_INITRAMFS_NEW" "$BOOT_INITRAMFS"; then
        error "Failed to install staged initramfs"
        return 1
    fi

    log "Staged kernel/initramfs update installed"
    return 0
}

remove_staged_firmware_files() {
    if mount -o remount,rw /mnt/boot 2>/dev/null; then
        rm -f "$FIRMWARE_NEW" "${FIRMWARE_NEW}.sha256" "${FIRMWARE_NEW}.sig" 2>/dev/null || true
        rm -f "$BOOT_KERNEL_NEW" "$BOOT_INITRAMFS_NEW" 2>/dev/null || true
        mount -o remount,ro /mnt/boot 2>/dev/null || true
    else
        warn "Could not remount boot partition to clean invalid staged firmware"
    fi
}

process_staged_firmware_archive() {
    local config_part=""
    local apply_flag="${FIRMWARE_STAGING_DIR}/.apply"
    local archive=""
    local archive_base=""
    local stage_dir="/tmp/firmware-update"

    config_part=$(find_config_partition) || return 0

    if ! mount_config_partition "$config_part"; then
        warn "Could not mount config partition ($config_part) to check staged firmware archive"
        return 0
    fi

    if [ ! -f "$apply_flag" ]; then
        umount /mnt/config 2>/dev/null || true
        return 0
    fi

    for candidate in "${FIRMWARE_STAGING_DIR}"/*.tar.gz; do
        if [ -f "$candidate" ]; then
            archive="$candidate"
            break
        fi
    done

    if [ -z "$archive" ]; then
        error "Firmware apply flag exists but no staged archive found"
        umount /mnt/config 2>/dev/null || true
        return 0
    fi

    if ! tar -tzf "$archive" >/tmp/firmware-update-list.txt 2>/dev/null; then
        error "Staged firmware archive is invalid: $archive"
        rm -f /tmp/firmware-update-list.txt
        umount /mnt/config 2>/dev/null || true
        return 0
    fi

    if ! grep -qx "vmlinuz" /tmp/firmware-update-list.txt || \
       ! grep -qx "initramfs.img" /tmp/firmware-update-list.txt || \
       ! grep -qx "firmware.squashfs" /tmp/firmware-update-list.txt; then
        error "Staged firmware archive is missing required files"
        rm -f /tmp/firmware-update-list.txt
        umount /mnt/config 2>/dev/null || true
        return 0
    fi

    rm -f /tmp/firmware-update-list.txt

    rm -rf "$stage_dir"
    mkdir -p "$stage_dir"

    if ! tar -xzf "$archive" -C "$stage_dir" vmlinuz initramfs.img firmware.squashfs; then
        error "Failed to extract staged firmware archive"
        rm -rf "$stage_dir"
        umount /mnt/config 2>/dev/null || true
        return 0
    fi

    if ! mount -o remount,rw /mnt/boot 2>/dev/null; then
        error "Cannot remount boot partition read-write for firmware staging"
        rm -rf "$stage_dir"
        umount /mnt/config 2>/dev/null || true
        return 0
    fi

    mkdir -p "$(dirname "$BOOT_KERNEL_NEW")"
    mkdir -p "$(dirname "$FIRMWARE_NEW")"

    if ! cp "$stage_dir/firmware.squashfs" "$FIRMWARE_NEW" || \
       ! cp "$stage_dir/vmlinuz" "$BOOT_KERNEL_NEW" || \
       ! cp "$stage_dir/initramfs.img" "$BOOT_INITRAMFS_NEW"; then
        error "Failed to stage extracted firmware files to boot partition"
        rm -f "$FIRMWARE_NEW" "${FIRMWARE_NEW}.sha256" "${FIRMWARE_NEW}.sig" "$BOOT_KERNEL_NEW" "$BOOT_INITRAMFS_NEW" 2>/dev/null || true
        rm -rf "$stage_dir"
        mount -o remount,ro /mnt/boot 2>/dev/null || true
        umount /mnt/config 2>/dev/null || true
        return 0
    fi

    sha256sum "$FIRMWARE_NEW" > "${FIRMWARE_NEW}.sha256"
    rm -f "${FIRMWARE_NEW}.sig"

    chmod 644 "$FIRMWARE_NEW" "$BOOT_KERNEL_NEW" "$BOOT_INITRAMFS_NEW" "${FIRMWARE_NEW}.sha256" 2>/dev/null || true

    mount -o remount,ro /mnt/boot 2>/dev/null || true

    if mount -o remount,rw /mnt/config 2>/dev/null; then
        archive_base="${archive%.tar.gz}"
        rm -f "$archive" "$apply_flag" "${archive_base}.tar.gz.sha256" "${archive_base}.tar.gz.sig" 2>/dev/null || true
        mount -o remount,ro /mnt/config 2>/dev/null || true
    fi

    umount /mnt/config 2>/dev/null || true
    rm -rf "$stage_dir"

    log "Staged firmware archive prepared for boot-time apply"
}

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

process_staged_firmware_archive

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
                remove_staged_firmware_files
            else
                log "Applying firmware update..."

                if ! mount -o remount,rw /mnt/boot 2>/dev/null; then
                    error "Cannot remount boot partition read-write! Update aborted."
                    remove_staged_firmware_files
                else
                    # Backup current firmware
                    if [ -f "$FIRMWARE_CURRENT" ]; then
                        mv "$FIRMWARE_CURRENT" "$FIRMWARE_BACKUP"
                        [ -f "${FIRMWARE_CURRENT}.sig" ] && mv "${FIRMWARE_CURRENT}.sig" "${FIRMWARE_BACKUP}.sig"
                    fi

                    if ! apply_staged_boot_components; then
                        error "Boot component update failed! Firmware update aborted."
                        mount -o remount,ro /mnt/boot 2>/dev/null || true
                        remove_staged_firmware_files
                    else
                        # Activate new firmware
                        mv "$FIRMWARE_NEW" "$FIRMWARE_CURRENT"
                        [ -f "${FIRMWARE_NEW}.sig" ] && mv "${FIRMWARE_NEW}.sig" "${FIRMWARE_CURRENT}.sig"
                        rm -f "${FIRMWARE_NEW}.sha256"

                        mount -o remount,ro /mnt/boot 2>/dev/null || true

                        log "Firmware update applied"
                    fi
                fi
            fi
        else
            error "Firmware hash mismatch! Update aborted."
            remove_staged_firmware_files
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
        warn "Using backup firmware for this boot"
        FIRMWARE_PATH="$FIRMWARE_BACKUP"
        export FIRMWARE_PATH
        log "Firmware image: $FIRMWARE_PATH"
        return 0
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
