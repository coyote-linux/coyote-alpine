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

# Function to check disk partitions
check_disk_partitions() {
    for dev in /dev/sd* /dev/nvme* /dev/vd*; do
        [ -b "$dev" ] || continue

        # Skip whole disks, look for partitions
        case "$dev" in
            *[0-9]) ;;  # Partition
            *) continue ;;
        esac

        log "Checking disk partition: $dev"
        mount -o ro "$dev" /mnt 2>/dev/null || continue

        if [ -f /mnt/coyote.marker ]; then
            BOOT_MEDIA="$dev"
            BOOT_MEDIA_TYPE="disk"
            log "Found boot media (disk): $dev"
            umount /mnt
            return 0
        fi

        umount /mnt
    done
    return 1
}

# Function to check CD-ROM devices
check_cdrom_devices() {
    for dev in /dev/sr* /dev/hdc /dev/hdd /dev/cdrom; do
        [ -b "$dev" ] || continue

        log "Checking CD-ROM device: $dev"
        mount -o ro "$dev" /mnt 2>/dev/null || continue

        if [ -f /mnt/coyote.marker ]; then
            BOOT_MEDIA="$dev"
            BOOT_MEDIA_TYPE="cdrom"
            log "Found boot media (CD-ROM): $dev"
            umount /mnt
            return 0
        fi

        umount /mnt
    done
    return 1
}

# Search order depends on installer mode
if [ "$INSTALLER_MODE" = "1" ]; then
    # Installer mode: check CD-ROM first to allow reinstallation
    check_cdrom_devices || check_disk_partitions
else
    # Normal boot: check disk first (prefer installed system)
    check_disk_partitions || check_cdrom_devices
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
