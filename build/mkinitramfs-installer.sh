#!/bin/bash
#
# mkinitramfs-installer.sh - Build initramfs for Coyote Linux installer
#
# Creates a simplified initramfs for the installer environment with:
#   - Busybox for basic utilities
#   - Kernel modules for boot (storage, filesystem)
#   - Installer-specific init scripts (no firmware/config logic)
#
# This script uses cached resources from mkinitramfs.sh (busybox, modules)
# Run 'make initramfs' first to ensure cache is populated.
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BUILD_DIR="${SCRIPT_DIR}/../output"
CACHE_DIR="${SCRIPT_DIR}/../.cache"
INITRAMFS_SRC="${SCRIPT_DIR}/../initramfs-installer"
INITRAMFS_BUILD="${BUILD_DIR}/initramfs-installer-build"

# Create directories
mkdir -p "$BUILD_DIR" "$INITRAMFS_BUILD"

#
# Verify prerequisites
#
check_prerequisites() {
    if [ ! -x "${CACHE_DIR}/busybox" ]; then
        echo "Error: busybox not found in cache. Run 'make initramfs' first."
        exit 1
    fi

    if ! ls "${SCRIPT_DIR}/../kernel/output"/modules-*.tar.gz >/dev/null 2>&1; then
        echo "Warning: No custom kernel modules archive found. Installer may have limited hardware support."
    fi

    if [ ! -d "$INITRAMFS_SRC" ]; then
        echo "Error: Installer initramfs source not found: $INITRAMFS_SRC"
        exit 1
    fi
}

#
# Build the installer initramfs
#
build_initramfs() {
    echo "Building installer initramfs..."

    # Clean and create structure
    rm -rf "$INITRAMFS_BUILD"
    mkdir -p "${INITRAMFS_BUILD}"/{bin,sbin,etc,proc,sys,dev,mnt,tmp,newroot,lib/modules}
    mkdir -p "${INITRAMFS_BUILD}/mnt"/{squashfs,overlay,media}

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

    # Copy installer init script
    cp "${INITRAMFS_SRC}/init" "${INITRAMFS_BUILD}/init"
    chmod +x "${INITRAMFS_BUILD}/init"

    # Copy init.d scripts
    mkdir -p "${INITRAMFS_BUILD}/init.d"
    cp "${INITRAMFS_SRC}/init.d/"*.sh "${INITRAMFS_BUILD}/init.d/"
    chmod +x "${INITRAMFS_BUILD}/init.d/"*.sh

    # Copy kernel modules needed for boot (if available)
    local modules_archive
    local modules_root="${BUILD_DIR}/modules-archive"

    modules_archive=$(ls -t "${SCRIPT_DIR}/../kernel/output"/modules-*.tar.gz 2>/dev/null | head -1)

    if [ -n "$modules_archive" ] && [ -f "$modules_archive" ]; then
        echo "Extracting custom kernel modules archive..."
        rm -rf "$modules_root"
        mkdir -p "$modules_root"
        tar -xzf "$modules_archive" -C "$modules_root"
    fi

    if [ -d "${modules_root}/lib/modules" ]; then
        echo "Including kernel modules..."

        # Find the kernel version directory
        local kver=$(ls "${modules_root}/lib/modules/" | head -1)
        if [ -n "$kver" ]; then
            mkdir -p "${INITRAMFS_BUILD}/lib/modules/${kver}/kernel/drivers"

            copy_module_with_deps() {
                local module_name="$1"
                local modules_base="${modules_root}/lib/modules/${kver}"
                local dep_file="${modules_base}/modules.dep"

                if [ ! -f "$dep_file" ]; then
                    return 0
                fi

                while IFS=: read -r modpath deps; do
                    case "$modpath" in
                        */${module_name}.ko* )
                            local src="${modules_base}/${modpath}"
                            local dst="${INITRAMFS_BUILD}/lib/modules/${kver}/${modpath}"
                            if [ -f "$src" ]; then
                                mkdir -p "$(dirname "$dst")"
                                cp -a "$src" "$dst" 2>/dev/null || true
                            fi
                            for dep in $deps; do
                                local dep_src="${modules_base}/${dep}"
                                local dep_dst="${INITRAMFS_BUILD}/lib/modules/${kver}/${dep}"
                                if [ -f "$dep_src" ]; then
                                    mkdir -p "$(dirname "$dep_dst")"
                                    cp -a "$dep_src" "$dep_dst" 2>/dev/null || true
                                fi
                            done
                            break
                            ;;
                    esac
                done < "$dep_file"
            }

            # Copy essential modules for VMware/QEMU boot
            local module_dirs="ata scsi block virtio cdrom"
            for mdir in $module_dirs; do
                src="${modules_root}/lib/modules/${kver}/kernel/drivers/${mdir}"
                if [ -d "$src" ]; then
                    mkdir -p "${INITRAMFS_BUILD}/lib/modules/${kver}/kernel/drivers/${mdir}"
                    cp -a "$src"/* "${INITRAMFS_BUILD}/lib/modules/${kver}/kernel/drivers/${mdir}/" 2>/dev/null || true
                fi
            done

            # Copy MPT Fusion drivers (used by VMware)
            src="${modules_root}/lib/modules/${kver}/kernel/drivers/message/fusion"
            if [ -d "$src" ]; then
                mkdir -p "${INITRAMFS_BUILD}/lib/modules/${kver}/kernel/drivers/message/fusion"
                cp -a "$src"/* "${INITRAMFS_BUILD}/lib/modules/${kver}/kernel/drivers/message/fusion/" 2>/dev/null || true
            fi

            # Copy filesystem modules (squashfs, isofs for CD-ROM, fat, ext4, overlay)
            local fs_modules="squashfs isofs fat ext4 nls overlay"
            for fsmod in $fs_modules; do
                src="${modules_root}/lib/modules/${kver}/kernel/fs/${fsmod}"
                if [ -d "$src" ]; then
                    mkdir -p "${INITRAMFS_BUILD}/lib/modules/${kver}/kernel/fs/${fsmod}"
                    cp -a "$src"/* "${INITRAMFS_BUILD}/lib/modules/${kver}/kernel/fs/${fsmod}/" 2>/dev/null || true
                fi
            done

            # Copy key NIC modules for installer probing
            local nic_modules="virtio_net vmxnet3 e1000 e1000e xen-netfront igb igc ixgbe i40e r8169 r8152 tg3 bnx2 atlantic hv_netvsc"
            for mod in $nic_modules; do
                copy_module_with_deps "$mod"
            done

            # Copy modules.builtin
            if [ -f "${modules_root}/lib/modules/${kver}/modules.builtin" ]; then
                cp "${modules_root}/lib/modules/${kver}/modules.builtin" "${INITRAMFS_BUILD}/lib/modules/${kver}/"
            fi

            # Regenerate modules.dep for the initramfs subset
            if command -v depmod &>/dev/null; then
                echo "Regenerating modules.dep for initramfs..."
                depmod -b "${INITRAMFS_BUILD}" "$kver" 2>/dev/null || true
            fi
        fi
    fi

    # Create basic device nodes
    mknod -m 600 "${INITRAMFS_BUILD}/dev/console" c 5 1 2>/dev/null || true
    mknod -m 666 "${INITRAMFS_BUILD}/dev/null" c 1 3 2>/dev/null || true
    mknod -m 666 "${INITRAMFS_BUILD}/dev/zero" c 1 5 2>/dev/null || true
    mknod -m 666 "${INITRAMFS_BUILD}/dev/tty" c 5 0 2>/dev/null || true

    # Create etc files
    echo "root:x:0:0:root:/:/bin/sh" > "${INITRAMFS_BUILD}/etc/passwd"
    echo "root:x:0:" > "${INITRAMFS_BUILD}/etc/group"

    # Copy openssl for signature verification (from cache created by mkinitramfs.sh)
    local openssl_cache="${CACHE_DIR}/openssl"
    if [ -d "$openssl_cache" ] && [ -x "${openssl_cache}/usr/bin/openssl" ]; then
        echo "Including openssl for signature verification..."
        cp -a "${openssl_cache}/usr/bin/openssl" "${INITRAMFS_BUILD}/bin/"
        cp -a "${openssl_cache}/lib/"* "${INITRAMFS_BUILD}/lib/" 2>/dev/null || true
        mkdir -p "${INITRAMFS_BUILD}/usr/lib"
        cp -a "${openssl_cache}/usr/lib/"* "${INITRAMFS_BUILD}/usr/lib/" 2>/dev/null || true
    else
        echo "Warning: openssl not available, signature verification disabled"
    fi

    # Copy verify-signature script from main initramfs source
    local main_initramfs="${SCRIPT_DIR}/../initramfs"
    if [ -f "${main_initramfs}/bin/verify-signature" ]; then
        cp "${main_initramfs}/bin/verify-signature" "${INITRAMFS_BUILD}/bin/"
        chmod +x "${INITRAMFS_BUILD}/bin/verify-signature"
    fi

    # Copy firmware signing public key
    if [ -d "${main_initramfs}/etc/coyote/keys" ]; then
        mkdir -p "${INITRAMFS_BUILD}/etc/coyote/keys"
        cp "${main_initramfs}/etc/coyote/keys/"* "${INITRAMFS_BUILD}/etc/coyote/keys/" 2>/dev/null || true
    fi

    # Create the cpio archive
    echo "Creating installer initramfs archive..."
    cd "$INITRAMFS_BUILD"
    find . | cpio -o -H newc 2>/dev/null | gzip > "${BUILD_DIR}/initramfs-installer.cpio.gz"

    local size=$(du -h "${BUILD_DIR}/initramfs-installer.cpio.gz" | cut -f1)
    echo "Installer initramfs created: ${BUILD_DIR}/initramfs-installer.cpio.gz ($size)"
}

#
# Main
#
main() {
    echo "=========================================="
    echo "Coyote Linux Installer Initramfs Builder"
    echo "=========================================="
    echo ""

    check_prerequisites
    build_initramfs

    echo ""
    echo "=========================================="
    echo "Build complete!"
    echo ""
    echo "Installer initramfs: ${BUILD_DIR}/initramfs-installer.cpio.gz"
    echo "=========================================="
}

main "$@"
