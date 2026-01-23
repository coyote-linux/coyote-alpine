#!/bin/bash
#
# mkinitramfs.sh - Build bootable initramfs for Coyote Linux
#
# Creates an initramfs with:
#   - Busybox for basic utilities
#   - Kernel modules for boot (storage, filesystem)
#   - Coyote init scripts
#
# Prerequisites (Fedora):
#   dnf install cpio gzip
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BUILD_DIR="${SCRIPT_DIR}/../output"
CACHE_DIR="${SCRIPT_DIR}/../.cache"
INITRAMFS_SRC="${SCRIPT_DIR}/../initramfs"
INITRAMFS_BUILD="${BUILD_DIR}/initramfs-build"
ROOTFS_BUILD="${BUILD_DIR}/rootfs"

ALPINE_VERSION="3.23"
ALPINE_MIRROR="https://dl-cdn.alpinelinux.org/alpine"
ARCH="x86_64"

# Create directories
mkdir -p "$BUILD_DIR" "$CACHE_DIR" "$INITRAMFS_BUILD"

#
# Download and extract kernel
#
setup_kernel() {
    local kernel_dest="${BUILD_DIR}/vmlinuz"

    if [ -f "$kernel_dest" ]; then
        echo "Kernel already exists: $kernel_dest"
        return 0
    fi

    echo "Downloading Alpine kernel..."

    local apk_static="${CACHE_DIR}/apk.static"
    if [ ! -x "$apk_static" ]; then
        echo "Error: apk.static not found. Run 'make rootfs' first."
        exit 1
    fi

    # Get the kernel package
    local repo_url="${ALPINE_MIRROR}/v${ALPINE_VERSION}/main/${ARCH}"
    local kernel_pkg=$(curl -s "${repo_url}/" | grep -oP 'linux-lts-[0-9][^"]+\.apk' | grep -v 'dev' | head -1)

    if [ -z "$kernel_pkg" ]; then
        echo "Error: Cannot find linux-lts package"
        exit 1
    fi

    local tmp_dir="${CACHE_DIR}/kernel-tmp"
    mkdir -p "$tmp_dir"

    echo "Downloading: ${repo_url}/${kernel_pkg}"
    curl -L -o "${tmp_dir}/linux-lts.apk" "${repo_url}/${kernel_pkg}"

    # Extract kernel
    cd "$tmp_dir"
    tar -xzf linux-lts.apk 2>/dev/null || true

    if [ -f "${tmp_dir}/boot/vmlinuz-lts" ]; then
        cp "${tmp_dir}/boot/vmlinuz-lts" "$kernel_dest"
        echo "Kernel extracted: $kernel_dest"
    else
        echo "Error: vmlinuz not found in kernel package"
        ls -la "${tmp_dir}/"
        exit 1
    fi

    # Also extract modules if present
    if [ -d "${tmp_dir}/lib/modules" ]; then
        mkdir -p "${CACHE_DIR}/modules"
        cp -a "${tmp_dir}/lib/modules/"* "${CACHE_DIR}/modules/"
        echo "Kernel modules cached"
    fi

    rm -rf "$tmp_dir"
}

#
# Download busybox-static for initramfs
#
setup_busybox() {
    local busybox_dest="${CACHE_DIR}/busybox"

    if [ -x "$busybox_dest" ]; then
        echo "Using cached busybox"
        return 0
    fi

    echo "Downloading busybox-static..."

    local repo_url="${ALPINE_MIRROR}/v${ALPINE_VERSION}/main/${ARCH}"
    local bb_pkg=$(curl -s "${repo_url}/" | grep -oP 'busybox-static-[0-9][^"]+\.apk' | head -1)

    if [ -z "$bb_pkg" ]; then
        echo "Error: Cannot find busybox-static package"
        exit 1
    fi

    local tmp_dir="${CACHE_DIR}/busybox-tmp"
    mkdir -p "$tmp_dir"

    curl -L -o "${tmp_dir}/busybox-static.apk" "${repo_url}/${bb_pkg}"

    cd "$tmp_dir"
    tar -xzf busybox-static.apk 2>/dev/null || true

    if [ -f "${tmp_dir}/bin/busybox.static" ]; then
        cp "${tmp_dir}/bin/busybox.static" "$busybox_dest"
        chmod +x "$busybox_dest"
        echo "Busybox extracted: $busybox_dest"
    else
        echo "Error: busybox.static not found"
        exit 1
    fi

    rm -rf "$tmp_dir"
}

#
# Build the initramfs
#
build_initramfs() {
    echo "Building initramfs..."

    # Clean and create structure
    rm -rf "$INITRAMFS_BUILD"
    mkdir -p "${INITRAMFS_BUILD}"/{bin,sbin,etc,proc,sys,dev,mnt,tmp,newroot,lib/modules}
    mkdir -p "${INITRAMFS_BUILD}/mnt"/{boot,config}

    # Install busybox
    cp "${CACHE_DIR}/busybox" "${INITRAMFS_BUILD}/bin/busybox"
    chmod +x "${INITRAMFS_BUILD}/bin/busybox"

    # Create busybox symlinks
    local applets="sh ash basename cat chmod chown cp cut dd df dirname dmesg echo env expr find grep gzip gunzip head kill ln ls mkdir mknod modprobe mount mv ping ps pwd rm rmdir sed sh sleep sort switch_root sync tail tar touch tr umount uname vi wc xargs blkid"
    for applet in $applets; do
        ln -sf busybox "${INITRAMFS_BUILD}/bin/${applet}"
    done

    # Additional sbin symlinks
    local sbin_applets="init halt reboot poweroff modprobe insmod lsmod"
    for applet in $sbin_applets; do
        ln -sf ../bin/busybox "${INITRAMFS_BUILD}/sbin/${applet}"
    done

    # Copy our init scripts
    cp "${INITRAMFS_SRC}/init" "${INITRAMFS_BUILD}/init"
    chmod +x "${INITRAMFS_BUILD}/init"

    # Copy init.d scripts
    mkdir -p "${INITRAMFS_BUILD}/init.d"
    cp "${INITRAMFS_SRC}/init.d/"*.sh "${INITRAMFS_BUILD}/init.d/"
    chmod +x "${INITRAMFS_BUILD}/init.d/"*.sh

    # Copy recovery scripts
    mkdir -p "${INITRAMFS_BUILD}/recovery"
    cp "${INITRAMFS_SRC}/recovery/"*.sh "${INITRAMFS_BUILD}/recovery/"
    chmod +x "${INITRAMFS_BUILD}/recovery/"*.sh

    # Copy kernel modules needed for boot (if available)
    if [ -d "${CACHE_DIR}/modules" ]; then
        echo "Including kernel modules..."

        # Find the kernel version directory
        local kver=$(ls "${CACHE_DIR}/modules/" | head -1)
        if [ -n "$kver" ]; then
            mkdir -p "${INITRAMFS_BUILD}/lib/modules/${kver}/kernel/drivers"

            # Copy essential modules for VMware/QEMU boot
            # Include: storage (ata, scsi, block, virtio), cdrom, message (mpt drivers)
            local module_dirs="ata scsi block virtio cdrom"
            for mdir in $module_dirs; do
                src="${CACHE_DIR}/modules/${kver}/kernel/drivers/${mdir}"
                if [ -d "$src" ]; then
                    mkdir -p "${INITRAMFS_BUILD}/lib/modules/${kver}/kernel/drivers/${mdir}"
                    cp -a "$src"/* "${INITRAMFS_BUILD}/lib/modules/${kver}/kernel/drivers/${mdir}/" 2>/dev/null || true
                fi
            done

            # Copy MPT Fusion drivers (used by VMware)
            src="${CACHE_DIR}/modules/${kver}/kernel/drivers/message/fusion"
            if [ -d "$src" ]; then
                mkdir -p "${INITRAMFS_BUILD}/lib/modules/${kver}/kernel/drivers/message/fusion"
                cp -a "$src"/* "${INITRAMFS_BUILD}/lib/modules/${kver}/kernel/drivers/message/fusion/" 2>/dev/null || true
            fi

            # Copy filesystem modules (squashfs, isofs for CD-ROM, fat, ext4)
            local fs_modules="squashfs isofs fat ext4 nls"
            for fsmod in $fs_modules; do
                src="${CACHE_DIR}/modules/${kver}/kernel/fs/${fsmod}"
                if [ -d "$src" ]; then
                    mkdir -p "${INITRAMFS_BUILD}/lib/modules/${kver}/kernel/fs/${fsmod}"
                    cp -a "$src"/* "${INITRAMFS_BUILD}/lib/modules/${kver}/kernel/fs/${fsmod}/" 2>/dev/null || true
                fi
            done

            # Copy modules.dep and related files
            for f in modules.dep modules.alias modules.symbols modules.builtin; do
                if [ -f "${CACHE_DIR}/modules/${kver}/${f}" ]; then
                    cp "${CACHE_DIR}/modules/${kver}/${f}" "${INITRAMFS_BUILD}/lib/modules/${kver}/"
                fi
            done
        fi
    fi

    # Create basic device nodes
    # These will be replaced by devtmpfs at boot, but having them helps early boot
    mknod -m 600 "${INITRAMFS_BUILD}/dev/console" c 5 1 2>/dev/null || true
    mknod -m 666 "${INITRAMFS_BUILD}/dev/null" c 1 3 2>/dev/null || true
    mknod -m 666 "${INITRAMFS_BUILD}/dev/zero" c 1 5 2>/dev/null || true
    mknod -m 666 "${INITRAMFS_BUILD}/dev/tty" c 5 0 2>/dev/null || true

    # Create etc files
    echo "root:x:0:0:root:/:/bin/sh" > "${INITRAMFS_BUILD}/etc/passwd"
    echo "root:x:0:" > "${INITRAMFS_BUILD}/etc/group"

    # Create the cpio archive
    echo "Creating initramfs archive..."
    cd "$INITRAMFS_BUILD"
    find . | cpio -o -H newc 2>/dev/null | gzip > "${BUILD_DIR}/initramfs.cpio.gz"

    local size=$(du -h "${BUILD_DIR}/initramfs.cpio.gz" | cut -f1)
    echo "Initramfs created: ${BUILD_DIR}/initramfs.cpio.gz ($size)"
}

#
# Main
#
main() {
    echo "=========================================="
    echo "Coyote Linux Initramfs Builder"
    echo "=========================================="
    echo ""

    setup_busybox
    setup_kernel
    build_initramfs

    echo ""
    echo "=========================================="
    echo "Build complete!"
    echo ""
    echo "Kernel:    ${BUILD_DIR}/vmlinuz"
    echo "Initramfs: ${BUILD_DIR}/initramfs.cpio.gz"
    echo "=========================================="
}

main "$@"
