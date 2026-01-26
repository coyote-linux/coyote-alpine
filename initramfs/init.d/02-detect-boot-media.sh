#!/bin/sh
#
# 02-detect-boot-media.sh - Detect and mount boot media
#

log "Detecting boot media..."

# Load necessary kernel modules for storage devices
log "Loading storage modules..."
modprobe -q ata_piix 2>/dev/null || true
modprobe -q ata_generic 2>/dev/null || true
modprobe -q pata_acpi 2>/dev/null || true
modprobe -q ahci 2>/dev/null || true
modprobe -q sr_mod 2>/dev/null || true
modprobe -q cdrom 2>/dev/null || true
modprobe -q isofs 2>/dev/null || true
modprobe -q sd_mod 2>/dev/null || true
modprobe -q usb_storage 2>/dev/null || true
modprobe -q mptspi 2>/dev/null || true
modprobe -q mptsas 2>/dev/null || true
modprobe -q vmw_pvscsi 2>/dev/null || true

# Load filesystem modules
log "Loading filesystem modules..."
modprobe -q vfat 2>/dev/null || true
modprobe -q fat 2>/dev/null || true
modprobe -q nls_cp437 2>/dev/null || true
modprobe -q nls_iso8859-1 2>/dev/null || true
# ext4 dependencies must be loaded first
modprobe -q crc16 2>/dev/null || true
modprobe -q crc32c_generic 2>/dev/null || true
modprobe -q mbcache 2>/dev/null || true
modprobe -q jbd2 2>/dev/null || true
modprobe -q ext4 2>/dev/null || true

# Wait for devices to settle (VMware may need more time)
log "Waiting for devices..."
sleep 3

# Debug: show available block devices
log "Available block devices:"
ls -la /dev/sd* /dev/sr* /dev/hd* /dev/nvme* /dev/vd* 2>/dev/null || log "  (none found)"
log "All block devices in /dev:"
ls /dev/ | grep -E "^(sd|sr|hd|nvme|vd|cd)" || log "  (none matching)"

# Check if we're in installer mode (kernel parameter "installer")
# If so, prefer CD-ROM to allow reinstallation over existing system
INSTALLER_MODE=0
if grep -q "installer" /proc/cmdline 2>/dev/null; then
    INSTALLER_MODE=1
    log "Installer mode detected - preferring CD-ROM"
fi

# Function to check disk partitions for installed system (COYOTE_BOOT marker)
check_disk_partitions() {
    log "check_disk_partitions: scanning for COYOTE_BOOT marker..."
    for dev in /dev/sd* /dev/nvme* /dev/vd*; do
        [ -b "$dev" ] || continue

        # Skip whole disks, look for partitions
        case "$dev" in
            *[0-9]) ;;  # Partition (sda1, vda1)
            *p[0-9]) ;;  # NVMe partition (nvme0n1p1)
            *) continue ;;
        esac

        log "  Trying: $dev"
        if ! mount -o ro "$dev" /mnt 2>/dev/null; then
            log "    Mount failed"
            continue
        fi

        # Check for installed system marker (COYOTE_BOOT)
        if [ -f /mnt/coyote.marker ]; then
            local marker_content=$(cat /mnt/coyote.marker 2>/dev/null)
            log "    Found marker: [$marker_content]"
            if [ "$marker_content" = "COYOTE_BOOT" ]; then
                BOOT_MEDIA="$dev"
                BOOT_MEDIA_TYPE="disk"
                log "    SUCCESS: Found installed system"
                umount /mnt
                return 0
            fi
            log "    Marker mismatch (expected COYOTE_BOOT)"
        else
            log "    No coyote.marker file"
        fi

        umount /mnt
    done
    log "check_disk_partitions: no installed system found"
    return 1
}

# Function to check CD-ROM devices for installer (COYOTE_INSTALLER marker)
check_cdrom_devices() {
    for dev in /dev/sr* /dev/hdc /dev/hdd /dev/cdrom; do
        [ -b "$dev" ] || continue

        log "Checking CD-ROM device: $dev"
        mount -o ro "$dev" /mnt 2>/dev/null || continue

        # Check for installer marker (COYOTE_INSTALLER)
        if [ -f /mnt/coyote.marker ]; then
            local marker_content=$(cat /mnt/coyote.marker 2>/dev/null)
            if [ "$marker_content" = "COYOTE_INSTALLER" ]; then
                BOOT_MEDIA="$dev"
                BOOT_MEDIA_TYPE="cdrom"
                log "Found installer media (CD-ROM): $dev"
                umount /mnt
                return 0
            fi
            log "  Found marker but content is: $marker_content (not COYOTE_INSTALLER)"
        fi

        umount /mnt
    done
    return 1
}

# Fallback: check any device with any coyote.marker
check_any_boot_media() {
    log "check_any_boot_media: scanning for any coyote.marker..."

    # Check disks first
    for dev in /dev/sd* /dev/nvme* /dev/vd*; do
        [ -b "$dev" ] || continue
        case "$dev" in
            *[0-9]) ;;
            *p[0-9]) ;;
            *) continue ;;
        esac

        log "  Trying disk: $dev"
        if ! mount -o ro "$dev" /mnt 2>/dev/null; then
            log "    Mount failed"
            continue
        fi

        if [ -f /mnt/coyote.marker ]; then
            local marker=$(cat /mnt/coyote.marker 2>/dev/null)
            log "    Found marker: [$marker]"
            BOOT_MEDIA="$dev"
            BOOT_MEDIA_TYPE="disk"
            umount /mnt
            return 0
        fi
        log "    No marker"
        umount /mnt
    done

    # Then CD-ROMs
    for dev in /dev/sr* /dev/hdc /dev/hdd /dev/cdrom; do
        [ -b "$dev" ] || continue
        log "  Trying CD-ROM: $dev"
        if ! mount -o ro "$dev" /mnt 2>/dev/null; then
            log "    Mount failed"
            continue
        fi

        if [ -f /mnt/coyote.marker ]; then
            local marker=$(cat /mnt/coyote.marker 2>/dev/null)
            log "    Found marker: [$marker]"
            BOOT_MEDIA="$dev"
            BOOT_MEDIA_TYPE="cdrom"
            umount /mnt
            return 0
        fi
        log "    No marker"
        umount /mnt
    done

    log "check_any_boot_media: no boot media found"
    return 1
}

# Search order depends on installer mode
if [ "$INSTALLER_MODE" = "1" ]; then
    # Installer mode: check CD-ROM first (need COYOTE_INSTALLER marker)
    log "Searching for installer media..."
    check_cdrom_devices || check_disk_partitions || check_any_boot_media
else
    # Normal boot: check disk first (need COYOTE_BOOT marker from installed system)
    log "Searching for installed system..."
    check_disk_partitions || check_any_boot_media
fi

if [ -z "$BOOT_MEDIA" ]; then
    error "Boot media not found!"
    return 1
fi

# Mount boot media
mkdir -p /mnt/boot
mount -o ro "$BOOT_MEDIA" /mnt/boot
log "Boot media mounted: $BOOT_MEDIA (type: $BOOT_MEDIA_TYPE)"

export BOOT_MEDIA BOOT_MEDIA_TYPE
