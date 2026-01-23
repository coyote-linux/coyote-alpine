#!/bin/sh
#
# 06-setup-tmpfs.sh - Setup tmpfs overlays for writable areas
#

log "Setting up tmpfs overlays..."

# Create tmpfs for /tmp
mount -t tmpfs -o mode=1777,size=64m tmpfs "${NEWROOT}/tmp"

# Create tmpfs for /var (logs, runtime data)
mount -t tmpfs -o mode=755,size=128m tmpfs "${NEWROOT}/var"

# Create essential /var directories
mkdir -p "${NEWROOT}/var/log"
mkdir -p "${NEWROOT}/var/run"
mkdir -p "${NEWROOT}/var/lock"
mkdir -p "${NEWROOT}/var/tmp"
mkdir -p "${NEWROOT}/var/cache"
chmod 1777 "${NEWROOT}/var/tmp"

# Create tmpfs for /run
mount -t tmpfs -o mode=755,size=16m tmpfs "${NEWROOT}/run"

# Setup running config directory
mkdir -p "${NEWROOT}/tmp/running-config"

log "Tmpfs overlays configured"
