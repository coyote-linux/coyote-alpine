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
FIRMWARE_CURRENT_HASH="${FIRMWARE_CURRENT}.sha256"
FIRMWARE_PREVIOUS_HASH="${FIRMWARE_PREVIOUS}.sha256"
FIRMWARE_NEW_HASH="${FIRMWARE_NEW}.sha256"
FIRMWARE_BACKUP_HASH="${FIRMWARE_BACKUP}.sha256"
BOOT_KERNEL="/mnt/boot/boot/vmlinuz"
BOOT_KERNEL_PREV="/mnt/boot/boot/vmlinuz.prev"
BOOT_KERNEL_NEW="/mnt/boot/boot/vmlinuz.new"
BOOT_KERNEL_HASH="${BOOT_KERNEL}.sha256"
BOOT_KERNEL_PREV_HASH="${BOOT_KERNEL_PREV}.sha256"
BOOT_KERNEL_NEW_HASH="${BOOT_KERNEL_NEW}.sha256"
BOOT_KERNEL_SIG="${BOOT_KERNEL}.sig"
BOOT_KERNEL_PREV_SIG="${BOOT_KERNEL_PREV}.sig"
BOOT_KERNEL_NEW_SIG="${BOOT_KERNEL_NEW}.sig"
BOOT_INITRAMFS="/mnt/boot/boot/initramfs.gz"
BOOT_INITRAMFS_PREV="/mnt/boot/boot/initramfs.gz.prev"
BOOT_INITRAMFS_NEW="/mnt/boot/boot/initramfs.gz.new"
BOOT_INITRAMFS_HASH="${BOOT_INITRAMFS}.sha256"
BOOT_INITRAMFS_PREV_HASH="${BOOT_INITRAMFS_PREV}.sha256"
BOOT_INITRAMFS_NEW_HASH="${BOOT_INITRAMFS_NEW}.sha256"
BOOT_INITRAMFS_SIG="${BOOT_INITRAMFS}.sig"
BOOT_INITRAMFS_PREV_SIG="${BOOT_INITRAMFS_PREV}.sig"
BOOT_INITRAMFS_NEW_SIG="${BOOT_INITRAMFS_NEW}.sig"
FIRMWARE_STAGING_DIR="/mnt/config/firmware-staging"

calculate_sha256() {
    local file="$1"
    local sum=""

    if command -v sha256sum >/dev/null 2>&1; then
        sum=$(sha256sum "$file" 2>/dev/null) || return 1
        echo "${sum%% *}"
        return 0
    fi

    if command -v busybox >/dev/null 2>&1; then
        sum=$(busybox sha256sum "$file" 2>/dev/null) || sum=""
        if [ -n "$sum" ]; then
            echo "${sum%% *}"
            return 0
        fi
    fi

    if command -v openssl >/dev/null 2>&1; then
        sum=$(openssl dgst -sha256 "$file" 2>/dev/null) || return 1
        sum=${sum##*= }
        if [ -n "$sum" ]; then
            echo "$sum"
            return 0
        fi
    fi

    return 1
}

read_expected_hash() {
    local hash_file="$1"
    local hash_line=""

    [ -f "$hash_file" ] || return 1

    hash_line=$(cat "$hash_file" 2>/dev/null) || return 1
    hash_line=${hash_line%% *}

    if [ ${#hash_line} -ne 64 ]; then
        return 1
    fi

    echo "$hash_line"
}

verify_file_hash() {
    local file="$1"
    local hash_file="$2"
    local label="$3"
    local require_hash="${4:-0}"
    local expected_hash=""
    local actual_hash=""

    if [ ! -f "$file" ]; then
        error "Missing file for ${label}: ${file}"
        return 1
    fi

    if [ ! -f "$hash_file" ]; then
        if [ "$require_hash" = "1" ]; then
            error "Missing hash file for ${label}"
            return 1
        fi

        warn "No hash file for ${label}"
        return 0
    fi

    expected_hash=$(read_expected_hash "$hash_file") || {
        error "Invalid hash file for ${label}"
        return 1
    }

    actual_hash=$(calculate_sha256 "$file") || {
        error "Unable to compute SHA256 hash for ${label}"
        return 1
    }

    if [ "$expected_hash" != "$actual_hash" ]; then
        error "${label} hash mismatch"
        return 1
    fi

    log "${label} hash valid"
    return 0
}

verify_file_signature() {
    local file="$1"
    local label="$2"
    local require_signature="${3:-0}"

    if [ ! -f "$file" ]; then
        error "Missing file for ${label}: ${file}"
        return 1
    fi

    if [ "$SKIP_SIGCHECK" = "1" ]; then
        return 0
    fi

    if [ ! -f "${file}.sig" ]; then
        if [ "$require_signature" = "1" ]; then
            error "Missing signature file for ${label}"
            return 1
        fi

        log "No signature file for ${label}; skipping signature check"
        return 0
    fi

    if ! command -v verify-signature >/dev/null 2>&1; then
        error "Signature verification utility unavailable for ${label}"
        return 1
    fi

    log "Verifying ${label} signature..."
    if verify-signature "$file"; then
        log "${label} signature: VALID"
        return 0
    fi

    error "${label} signature: INVALID"
    return 1
}

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

    if ! verify_file_hash "$BOOT_KERNEL_NEW" "$BOOT_KERNEL_NEW_HASH" "staged kernel" 1 ||
       ! verify_file_hash "$BOOT_INITRAMFS_NEW" "$BOOT_INITRAMFS_NEW_HASH" "staged initramfs" 1; then
        return 1
    fi

    if ! verify_file_signature "$BOOT_KERNEL_NEW" "staged kernel" 0 ||
       ! verify_file_signature "$BOOT_INITRAMFS_NEW" "staged initramfs" 0; then
        return 1
    fi

    if [ -f "$BOOT_KERNEL" ]; then
        mv "$BOOT_KERNEL" "$BOOT_KERNEL_PREV" 2>/dev/null || true
        [ -f "$BOOT_KERNEL_HASH" ] && mv "$BOOT_KERNEL_HASH" "$BOOT_KERNEL_PREV_HASH" 2>/dev/null || true
        [ -f "$BOOT_KERNEL_SIG" ] && mv "$BOOT_KERNEL_SIG" "$BOOT_KERNEL_PREV_SIG" 2>/dev/null || true
    fi

    if [ -f "$BOOT_INITRAMFS" ]; then
        mv "$BOOT_INITRAMFS" "$BOOT_INITRAMFS_PREV" 2>/dev/null || true
        [ -f "$BOOT_INITRAMFS_HASH" ] && mv "$BOOT_INITRAMFS_HASH" "$BOOT_INITRAMFS_PREV_HASH" 2>/dev/null || true
        [ -f "$BOOT_INITRAMFS_SIG" ] && mv "$BOOT_INITRAMFS_SIG" "$BOOT_INITRAMFS_PREV_SIG" 2>/dev/null || true
    fi

    if ! mv "$BOOT_KERNEL_NEW" "$BOOT_KERNEL"; then
        error "Failed to install staged kernel"
        return 1
    fi

    if ! mv "$BOOT_INITRAMFS_NEW" "$BOOT_INITRAMFS"; then
        error "Failed to install staged initramfs"
        return 1
    fi

    [ -f "$BOOT_KERNEL_NEW_HASH" ] && mv "$BOOT_KERNEL_NEW_HASH" "$BOOT_KERNEL_HASH" 2>/dev/null || true
    [ -f "$BOOT_KERNEL_NEW_SIG" ] && mv "$BOOT_KERNEL_NEW_SIG" "$BOOT_KERNEL_SIG" 2>/dev/null || true
    [ -f "$BOOT_INITRAMFS_NEW_HASH" ] && mv "$BOOT_INITRAMFS_NEW_HASH" "$BOOT_INITRAMFS_HASH" 2>/dev/null || true
    [ -f "$BOOT_INITRAMFS_NEW_SIG" ] && mv "$BOOT_INITRAMFS_NEW_SIG" "$BOOT_INITRAMFS_SIG" 2>/dev/null || true

    log "Staged kernel/initramfs update installed"
    return 0
}

remove_staged_firmware_files() {
    if mount -o remount,rw /mnt/boot 2>/dev/null; then
        rm -f "$FIRMWARE_NEW" "${FIRMWARE_NEW}.sha256" "${FIRMWARE_NEW}.sig" 2>/dev/null || true
        rm -f "$BOOT_KERNEL_NEW" "$BOOT_KERNEL_NEW_HASH" "$BOOT_KERNEL_NEW_SIG" 2>/dev/null || true
        rm -f "$BOOT_INITRAMFS_NEW" "$BOOT_INITRAMFS_NEW_HASH" "$BOOT_INITRAMFS_NEW_SIG" 2>/dev/null || true
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
    local firmware_hash=""
    local kernel_hash=""
    local initramfs_hash=""
    local archive_hash_file=""
    local archive_sig_file=""
    local expected_archive_hash=""
    local actual_archive_hash=""
    local has_firmware_sig=0
    local has_kernel_sig=0
    local has_initramfs_sig=0

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

    archive_hash_file="${archive}.sha256"
    archive_sig_file="${archive}.sig"

    if [ -f "$archive_hash_file" ]; then
        expected_archive_hash=$(read_expected_hash "$archive_hash_file") || expected_archive_hash=""
        actual_archive_hash=$(calculate_sha256 "$archive") || actual_archive_hash=""
        if [ -z "$expected_archive_hash" ] || [ -z "$actual_archive_hash" ] || [ "$expected_archive_hash" != "$actual_archive_hash" ]; then
            error "Staged firmware archive hash verification failed"
            umount /mnt/config 2>/dev/null || true
            return 0
        fi
    else
        warn "No hash file for staged firmware archive"
    fi

    if [ -f "$archive_sig_file" ]; then
        if ! verify_file_signature "$archive" "staged firmware archive" 1; then
            error "Staged firmware archive signature verification failed"
            umount /mnt/config 2>/dev/null || true
            return 0
        fi
    else
        warn "No signature file for staged firmware archive"
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

    grep -qx "firmware.squashfs.sig" /tmp/firmware-update-list.txt && has_firmware_sig=1
    grep -qx "vmlinuz.sig" /tmp/firmware-update-list.txt && has_kernel_sig=1
    grep -qx "initramfs.img.sig" /tmp/firmware-update-list.txt && has_initramfs_sig=1

    rm -f /tmp/firmware-update-list.txt

    rm -rf "$stage_dir"
    mkdir -p "$stage_dir"

    if ! tar -xzf "$archive" -C "$stage_dir" vmlinuz initramfs.img firmware.squashfs; then
        error "Failed to extract staged firmware archive"
        rm -rf "$stage_dir"
        umount /mnt/config 2>/dev/null || true
        return 0
    fi

    if [ "$has_firmware_sig" = "1" ] && ! tar -xzf "$archive" -C "$stage_dir" firmware.squashfs.sig; then
        error "Failed to extract firmware signature from staged archive"
        rm -rf "$stage_dir"
        umount /mnt/config 2>/dev/null || true
        return 0
    fi

    if [ "$has_kernel_sig" = "1" ] && ! tar -xzf "$archive" -C "$stage_dir" vmlinuz.sig; then
        error "Failed to extract kernel signature from staged archive"
        rm -rf "$stage_dir"
        umount /mnt/config 2>/dev/null || true
        return 0
    fi

    if [ "$has_initramfs_sig" = "1" ] && ! tar -xzf "$archive" -C "$stage_dir" initramfs.img.sig; then
        error "Failed to extract initramfs signature from staged archive"
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
        rm -f "$FIRMWARE_NEW" "$FIRMWARE_NEW_HASH" "${FIRMWARE_NEW}.sig" 2>/dev/null || true
        rm -f "$BOOT_KERNEL_NEW" "$BOOT_KERNEL_NEW_HASH" "$BOOT_KERNEL_NEW_SIG" 2>/dev/null || true
        rm -f "$BOOT_INITRAMFS_NEW" "$BOOT_INITRAMFS_NEW_HASH" "$BOOT_INITRAMFS_NEW_SIG" 2>/dev/null || true
        rm -rf "$stage_dir"
        mount -o remount,ro /mnt/boot 2>/dev/null || true
        umount /mnt/config 2>/dev/null || true
        return 0
    fi

    rm -f "${FIRMWARE_NEW}.sig" "$BOOT_KERNEL_NEW_SIG" "$BOOT_INITRAMFS_NEW_SIG" 2>/dev/null || true

    if [ "$has_firmware_sig" = "1" ] && ! cp "$stage_dir/firmware.squashfs.sig" "${FIRMWARE_NEW}.sig"; then
        error "Failed to stage firmware signature"
        rm -f "$FIRMWARE_NEW" "$FIRMWARE_NEW_HASH" "${FIRMWARE_NEW}.sig" 2>/dev/null || true
        rm -f "$BOOT_KERNEL_NEW" "$BOOT_KERNEL_NEW_HASH" "$BOOT_KERNEL_NEW_SIG" 2>/dev/null || true
        rm -f "$BOOT_INITRAMFS_NEW" "$BOOT_INITRAMFS_NEW_HASH" "$BOOT_INITRAMFS_NEW_SIG" 2>/dev/null || true
        rm -rf "$stage_dir"
        mount -o remount,ro /mnt/boot 2>/dev/null || true
        umount /mnt/config 2>/dev/null || true
        return 0
    fi

    if [ "$has_kernel_sig" = "1" ] && ! cp "$stage_dir/vmlinuz.sig" "$BOOT_KERNEL_NEW_SIG"; then
        error "Failed to stage kernel signature"
        rm -f "$FIRMWARE_NEW" "$FIRMWARE_NEW_HASH" "${FIRMWARE_NEW}.sig" 2>/dev/null || true
        rm -f "$BOOT_KERNEL_NEW" "$BOOT_KERNEL_NEW_HASH" "$BOOT_KERNEL_NEW_SIG" 2>/dev/null || true
        rm -f "$BOOT_INITRAMFS_NEW" "$BOOT_INITRAMFS_NEW_HASH" "$BOOT_INITRAMFS_NEW_SIG" 2>/dev/null || true
        rm -rf "$stage_dir"
        mount -o remount,ro /mnt/boot 2>/dev/null || true
        umount /mnt/config 2>/dev/null || true
        return 0
    fi

    if [ "$has_initramfs_sig" = "1" ] && ! cp "$stage_dir/initramfs.img.sig" "$BOOT_INITRAMFS_NEW_SIG"; then
        error "Failed to stage initramfs signature"
        rm -f "$FIRMWARE_NEW" "$FIRMWARE_NEW_HASH" "${FIRMWARE_NEW}.sig" 2>/dev/null || true
        rm -f "$BOOT_KERNEL_NEW" "$BOOT_KERNEL_NEW_HASH" "$BOOT_KERNEL_NEW_SIG" 2>/dev/null || true
        rm -f "$BOOT_INITRAMFS_NEW" "$BOOT_INITRAMFS_NEW_HASH" "$BOOT_INITRAMFS_NEW_SIG" 2>/dev/null || true
        rm -rf "$stage_dir"
        mount -o remount,ro /mnt/boot 2>/dev/null || true
        umount /mnt/config 2>/dev/null || true
        return 0
    fi

    firmware_hash=$(calculate_sha256 "$FIRMWARE_NEW") || firmware_hash=""
    kernel_hash=$(calculate_sha256 "$BOOT_KERNEL_NEW") || kernel_hash=""
    initramfs_hash=$(calculate_sha256 "$BOOT_INITRAMFS_NEW") || initramfs_hash=""

    if [ -z "$firmware_hash" ] || [ -z "$kernel_hash" ] || [ -z "$initramfs_hash" ]; then
        error "No SHA256 utility available in initramfs; cannot validate staged firmware"
        rm -f "$FIRMWARE_NEW" "$FIRMWARE_NEW_HASH" "${FIRMWARE_NEW}.sig" 2>/dev/null || true
        rm -f "$BOOT_KERNEL_NEW" "$BOOT_KERNEL_NEW_HASH" "$BOOT_KERNEL_NEW_SIG" 2>/dev/null || true
        rm -f "$BOOT_INITRAMFS_NEW" "$BOOT_INITRAMFS_NEW_HASH" "$BOOT_INITRAMFS_NEW_SIG" 2>/dev/null || true
        rm -rf "$stage_dir"
        mount -o remount,ro /mnt/boot 2>/dev/null || true
        umount /mnt/config 2>/dev/null || true
        return 0
    fi

    printf '%s  %s\n' "$firmware_hash" "$FIRMWARE_NEW" > "$FIRMWARE_NEW_HASH"
    printf '%s  %s\n' "$kernel_hash" "$BOOT_KERNEL_NEW" > "$BOOT_KERNEL_NEW_HASH"
    printf '%s  %s\n' "$initramfs_hash" "$BOOT_INITRAMFS_NEW" > "$BOOT_INITRAMFS_NEW_HASH"

    chmod 644 "$FIRMWARE_NEW" "$BOOT_KERNEL_NEW" "$BOOT_INITRAMFS_NEW" "$FIRMWARE_NEW_HASH" "$BOOT_KERNEL_NEW_HASH" "$BOOT_INITRAMFS_NEW_HASH" 2>/dev/null || true
    chmod 644 "${FIRMWARE_NEW}.sig" "$BOOT_KERNEL_NEW_SIG" "$BOOT_INITRAMFS_NEW_SIG" 2>/dev/null || true

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

    verify_file_signature "$firmware" "firmware" 0
}

process_staged_firmware_archive

# Check for new firmware to apply
if [ -f "$FIRMWARE_NEW" ]; then
    log "New firmware detected, validating..."

    if ! verify_file_hash "$FIRMWARE_NEW" "$FIRMWARE_NEW_HASH" "staged firmware" 1; then
        remove_staged_firmware_files
    elif ! verify_firmware_signature "$FIRMWARE_NEW"; then
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
                [ -f "$FIRMWARE_CURRENT_HASH" ] && mv "$FIRMWARE_CURRENT_HASH" "$FIRMWARE_BACKUP_HASH"
            fi

            if ! apply_staged_boot_components; then
                error "Boot component update failed! Firmware update aborted."
                mount -o remount,ro /mnt/boot 2>/dev/null || true
                remove_staged_firmware_files
            else
                # Activate new firmware
                mv "$FIRMWARE_NEW" "$FIRMWARE_CURRENT"
                [ -f "${FIRMWARE_NEW}.sig" ] && mv "${FIRMWARE_NEW}.sig" "${FIRMWARE_CURRENT}.sig"
                [ -f "$FIRMWARE_NEW_HASH" ] && mv "$FIRMWARE_NEW_HASH" "$FIRMWARE_CURRENT_HASH"

                mount -o remount,ro /mnt/boot 2>/dev/null || true

                log "Firmware update applied"
            fi
        fi
    fi
fi

if ! verify_file_hash "$BOOT_KERNEL" "$BOOT_KERNEL_HASH" "boot kernel" 0 ||
   ! verify_file_signature "$BOOT_KERNEL" "boot kernel" 0 ||
   ! verify_file_hash "$BOOT_INITRAMFS" "$BOOT_INITRAMFS_HASH" "boot initramfs" 0 ||
   ! verify_file_signature "$BOOT_INITRAMFS" "boot initramfs" 0; then
    error "Boot component verification failed"
    return 1
fi

# Handle rollback boot (firmware=previous kernel parameter)
if [ "$USE_PREVIOUS" = "1" ]; then
    if [ -f "$FIRMWARE_PREVIOUS" ]; then
        log "Using previous firmware for rollback boot"
        if verify_file_hash "$FIRMWARE_PREVIOUS" "$FIRMWARE_PREVIOUS_HASH" "previous firmware" 0 &&
           verify_firmware_signature "$FIRMWARE_PREVIOUS"; then
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
        if verify_file_hash "$FIRMWARE_BACKUP" "$FIRMWARE_BACKUP_HASH" "backup firmware" 0 &&
           verify_firmware_signature "$FIRMWARE_BACKUP"; then
            FIRMWARE_PATH="$FIRMWARE_BACKUP"
            export FIRMWARE_PATH
            log "Firmware image: $FIRMWARE_PATH"
            return 0
        fi

        error "Backup firmware verification failed"
        return 1
    else
        return 1
    fi
fi

# Verify signature of current firmware before booting
if ! verify_file_hash "$FIRMWARE_CURRENT" "$FIRMWARE_CURRENT_HASH" "current firmware" 0 ||
   ! verify_firmware_signature "$FIRMWARE_CURRENT"; then
    error "Current firmware integrity verification FAILED!"

    # Try backup firmware
    if [ -f "$FIRMWARE_BACKUP" ]; then
        warn "Attempting to boot backup firmware..."
        if verify_file_hash "$FIRMWARE_BACKUP" "$FIRMWARE_BACKUP_HASH" "backup firmware" 0 &&
           verify_firmware_signature "$FIRMWARE_BACKUP"; then
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
