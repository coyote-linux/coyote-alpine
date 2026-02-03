#!/bin/sh
#
# 06-setup-tmpfs.sh - Setup tmpfs overlays for writable areas
#

log "Setting up tmpfs overlays..."

# Create tmpfs for /tmp
mount -t tmpfs -o mode=1777,size=64m tmpfs "${NEWROOT}/tmp"

# Create tmpfs for /var (logs, runtime data, .NET cache)
# 1GB to accommodate .NET build artifacts and package cache
mount -t tmpfs -o mode=755,size=1g tmpfs "${NEWROOT}/var"

# Create essential /var directories
mkdir -p "${NEWROOT}/var/log"
mkdir -p "${NEWROOT}/var/run"
mkdir -p "${NEWROOT}/var/lock"
mkdir -p "${NEWROOT}/var/tmp"
mkdir -p "${NEWROOT}/var/cache"
chmod 1777 "${NEWROOT}/var/tmp"

# Create .NET cache directories
# These are used by NUGET_PACKAGES, DOTNET_CLI_HOME, and TMPDIR
mkdir -p "${NEWROOT}/var/cache/dotnet/nuget"
mkdir -p "${NEWROOT}/var/cache/dotnet/cli"

# Create tmpfs for /run
mount -t tmpfs -o mode=755,size=16m tmpfs "${NEWROOT}/run"

# Setup running config directory
mkdir -p "${NEWROOT}/tmp/running-config"

# Setup overlayfs for /etc (allows writes to /etc while base is read-only)
# Load overlay module - try multiple methods
load_overlay_module() {
    # Check if already loaded or built-in
    if grep -q "^overlay " /proc/modules 2>/dev/null || \
       grep -q "overlay" /proc/filesystems 2>/dev/null; then
        return 0
    fi

    # Try modprobe first
    if modprobe overlay 2>/dev/null; then
        return 0
    fi

    # Try to find and load with insmod
    local kver=$(uname -r)
    local mod_path=""

    # Search for overlay module in initramfs and newroot
    for base in "" "${NEWROOT}"; do
        for path in \
            "${base}/lib/modules/${kver}/kernel/fs/overlay/overlay.ko" \
            "${base}/lib/modules/${kver}/kernel/fs/overlay/overlay.ko.gz" \
            "${base}/lib/modules/${kver}/kernel/fs/overlay/overlay.ko.xz" \
            "${base}/lib/modules/${kver}/kernel/fs/overlayfs/overlay.ko" \
            "${base}/lib/modules/${kver}/kernel/fs/overlayfs/overlay.ko.gz"; do
            if [ -f "$path" ]; then
                mod_path="$path"
                break 2
            fi
        done
    done

    if [ -n "$mod_path" ]; then
        log "Found overlay module at: $mod_path"
        insmod "$mod_path" 2>/dev/null && return 0
    fi

    return 1
}

if load_overlay_module; then
    log "Overlay module loaded"
else
    warn "Could not load overlay module"
fi

# Create directories for overlay on the /tmp tmpfs
mkdir -p "${NEWROOT}/tmp/.etc-overlay/upper"
mkdir -p "${NEWROOT}/tmp/.etc-overlay/work"

# Mount overlayfs over /etc
# lowerdir = read-only /etc from squashfs
# upperdir = writable layer on tmpfs
# workdir = required by overlayfs
if mount -t overlay overlay \
    -o "lowerdir=${NEWROOT}/etc,upperdir=${NEWROOT}/tmp/.etc-overlay/upper,workdir=${NEWROOT}/tmp/.etc-overlay/work" \
    "${NEWROOT}/etc"; then
    log "Overlay mounted on /etc"
else
    warn "Failed to mount /etc overlay - /etc will be read-only"
    # Show some debug info
    warn "Available filesystems:"
    cat /proc/filesystems 2>/dev/null | grep -E "overlay|fuse" || true
fi

log "Tmpfs overlays configured"
