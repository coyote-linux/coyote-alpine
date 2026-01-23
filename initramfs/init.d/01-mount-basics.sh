#!/bin/sh
#
# 01-mount-basics.sh - Mount basic filesystems
#

log "Mounting basic filesystems..."

# Mount proc
mount -t proc none /proc

# Mount sysfs
mount -t sysfs none /sys

# Mount devtmpfs
mount -t devtmpfs none /dev

# Create device directories
mkdir -p /dev/pts /dev/shm

# Mount devpts
mount -t devpts none /dev/pts

# Mount tmpfs for /tmp
mount -t tmpfs -o mode=1777 none /tmp

log "Basic filesystems mounted"
