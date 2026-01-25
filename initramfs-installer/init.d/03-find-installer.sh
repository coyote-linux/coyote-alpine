#!/bin/sh
#
# 03-find-installer.sh - Find installer media and set up installer rootfs
#

log "Searching for installer media..."

# Mount point for installer media (separate from /mnt/squashfs and /mnt/overlay)
MEDIA_MNT="/mnt/media"
mkdir -p "$MEDIA_MNT"

# Function to check CD-ROM devices for installer
check_cdrom_devices() {
    for dev in /dev/sr* /dev/hdc /dev/hdd /dev/cdrom; do
        [ -b "$dev" ] || continue

        log "  Checking CD-ROM: $dev"
        mount -o ro "$dev" "$MEDIA_MNT" 2>/dev/null || continue

        if [ -f "${MEDIA_MNT}/coyote.marker" ]; then
            local marker=$(cat "${MEDIA_MNT}/coyote.marker" 2>/dev/null)
            if [ "$marker" = "COYOTE_INSTALLER" ]; then
                INSTALLER_MEDIA="$dev"
                INSTALLER_MEDIA_TYPE="cdrom"
                log "  Found installer (CD-ROM): $dev"
                return 0
            fi
        fi
        umount "$MEDIA_MNT" 2>/dev/null
    done
    return 1
}

# Function to check USB/disk devices for installer
check_disk_devices() {
    for dev in /dev/sd* /dev/nvme* /dev/vd*; do
        [ -b "$dev" ] || continue

        # Only check partitions
        case "$dev" in
            *[0-9]) ;;
            *p[0-9]) ;;
            *) continue ;;
        esac

        log "  Checking disk: $dev"
        mount -o ro "$dev" "$MEDIA_MNT" 2>/dev/null || continue

        if [ -f "${MEDIA_MNT}/coyote.marker" ]; then
            local marker=$(cat "${MEDIA_MNT}/coyote.marker" 2>/dev/null)
            if [ "$marker" = "COYOTE_INSTALLER" ]; then
                INSTALLER_MEDIA="$dev"
                INSTALLER_MEDIA_TYPE="disk"
                log "  Found installer (USB/disk): $dev"
                return 0
            fi
        fi
        umount "$MEDIA_MNT" 2>/dev/null
    done
    return 1
}

# Search for installer media - CD-ROM first, then USB/disk
if ! check_cdrom_devices && ! check_disk_devices; then
    error "Installer media not found!"
    error "Please insert the Coyote Linux installer CD/USB"
    return 1
fi

export INSTALLER_MEDIA INSTALLER_MEDIA_TYPE
log "Installer media: $INSTALLER_MEDIA (type: $INSTALLER_MEDIA_TYPE)"

# Find the firmware squashfs on installer media
SQUASHFS_PATH=""
if [ -f "${MEDIA_MNT}/firmware/current.squashfs" ]; then
    SQUASHFS_PATH="${MEDIA_MNT}/firmware/current.squashfs"
elif [ -f "${MEDIA_MNT}/boot/firmware/current.squashfs" ]; then
    SQUASHFS_PATH="${MEDIA_MNT}/boot/firmware/current.squashfs"
elif [ -f "${MEDIA_MNT}/coyote.squashfs" ]; then
    SQUASHFS_PATH="${MEDIA_MNT}/coyote.squashfs"
fi

if [ -z "$SQUASHFS_PATH" ]; then
    error "Installer rootfs not found on media!"
    return 1
fi

log "Found installer rootfs: $SQUASHFS_PATH"

# Verify firmware signature before mounting
if [ -f "${SQUASHFS_PATH}.sig" ]; then
    log "Verifying firmware signature..."
    if [ -x /bin/verify-signature ]; then
        if verify-signature "$SQUASHFS_PATH"; then
            log "Firmware signature: VALID"
        else
            warn "Firmware signature: INVALID"
            warn "Proceeding anyway (installer mode)"
            # In installer mode, we warn but continue
            # The installed system will do strict verification
        fi
    else
        warn "Signature verification not available"
    fi
else
    log "No signature file found (unsigned firmware)"
fi

# Mount the squashfs (read-only base)
# /mnt/squashfs should already exist from initramfs build
if ! mount -t squashfs -o ro "$SQUASHFS_PATH" /mnt/squashfs; then
    error "Failed to mount installer squashfs!"
    return 1
fi

log "Mounted installer squashfs"

# Set up overlay filesystem for writable installer environment
# The installer needs to be able to write to /etc, /var, /tmp, etc.

# Mount tmpfs for the overlay upper/work directories
# /mnt/overlay should already exist from initramfs build
mount -t tmpfs -o size=256M tmpfs /mnt/overlay

# Create directories on the tmpfs
mkdir -p /mnt/overlay/upper
mkdir -p /mnt/overlay/work

# Create the overlay root
if ! mount -t overlay overlay -o lowerdir=/mnt/squashfs,upperdir=/mnt/overlay/upper,workdir=/mnt/overlay/work "$NEWROOT"; then
    error "Failed to create overlay filesystem!"
    error "Falling back to read-only squashfs..."
    # Fall back to mounting squashfs directly (limited functionality)
    umount "$NEWROOT" 2>/dev/null
    mount --bind /mnt/squashfs "$NEWROOT"
fi

log "Installer rootfs ready at $NEWROOT"

# Move installer media mount into new root so it survives switch_root
# The installer expects media at /mnt/boot
mkdir -p "${NEWROOT}/mnt/boot"
if ! mount --move "$MEDIA_MNT" "${NEWROOT}/mnt/boot"; then
    warn "mount --move failed, trying mount --bind"
    if ! mount --bind "$MEDIA_MNT" "${NEWROOT}/mnt/boot"; then
        warn "mount --bind also failed, remounting device directly"
        mount -o ro "$INSTALLER_MEDIA" "${NEWROOT}/mnt/boot"
    fi
fi

# Verify the mount worked
if [ -f "${NEWROOT}/mnt/boot/coyote.marker" ]; then
    log "Installer media available at /mnt/boot"
else
    error "Failed to mount installer media at /mnt/boot!"
    ls -la "${NEWROOT}/mnt/boot/" 2>/dev/null || true
fi
