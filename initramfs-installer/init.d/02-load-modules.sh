#!/bin/sh
#
# 02-load-modules.sh - Load storage and filesystem modules for installer
#

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

# Load virtio modules for KVM/QEMU
modprobe -q virtio_pci 2>/dev/null || true
modprobe -q virtio_blk 2>/dev/null || true
modprobe -q virtio_scsi 2>/dev/null || true

log "Loading filesystem modules..."
modprobe -q vfat 2>/dev/null || true
modprobe -q fat 2>/dev/null || true
modprobe -q nls_cp437 2>/dev/null || true
modprobe -q nls_iso8859-1 2>/dev/null || true
modprobe -q ext4 2>/dev/null || true
modprobe -q squashfs 2>/dev/null || true
modprobe -q overlay 2>/dev/null || true

# Wait for devices to settle
log "Waiting for devices..."
sleep 2

log "Modules loaded"
