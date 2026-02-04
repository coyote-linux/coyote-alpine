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

CUSTOM_KERNEL_ROOT="${SCRIPT_DIR}/../kernel"
REQUIRE_CUSTOM_KERNEL="${REQUIRE_CUSTOM_KERNEL:-auto}"
CUSTOM_KERNEL_ARCHIVE="${CUSTOM_KERNEL_ROOT}/output/kernel-${KERNEL_VERSION:-6.18.8}.tar.gz"
CUSTOM_MODULES_ARCHIVE="${CUSTOM_KERNEL_ROOT}/output/modules-${KERNEL_VERSION:-6.18.8}.tar.gz"
CUSTOM_KERNEL_IMAGE=""
CUSTOM_KERNEL_MODULES_DIR=""

# Create directories
mkdir -p "$BUILD_DIR" "$CACHE_DIR" "$INITRAMFS_BUILD"

#
# Download and extract kernel
#
setup_kernel() {
    local kernel_dest="${BUILD_DIR}/vmlinuz"

    if [ "$REQUIRE_CUSTOM_KERNEL" = "auto" ]; then
        if [ -d "$CUSTOM_KERNEL_ROOT" ] && [ -f "${CUSTOM_KERNEL_ROOT}/build-kernel.sh" ]; then
            REQUIRE_CUSTOM_KERNEL="1"
        else
            REQUIRE_CUSTOM_KERNEL="0"
        fi
    fi

    detect_custom_kernel() {
        local kernel_tree=""

        if [ -f "$CUSTOM_KERNEL_ARCHIVE" ] && [ -f "$CUSTOM_MODULES_ARCHIVE" ]; then
            echo "Using custom kernel archives"

            local kernel_tmp="${BUILD_DIR}/kernel-archive"
            rm -rf "$kernel_tmp"
            mkdir -p "$kernel_tmp"
            tar -xzf "$CUSTOM_KERNEL_ARCHIVE" -C "$kernel_tmp"
            if [ -f "${kernel_tmp}/bzImage" ]; then
                CUSTOM_KERNEL_IMAGE="${kernel_tmp}/bzImage"
            fi

            local modules_tmp="${BUILD_DIR}/modules-archive"
            rm -rf "$modules_tmp"
            mkdir -p "$modules_tmp"
            tar -xzf "$CUSTOM_MODULES_ARCHIVE" -C "$modules_tmp"
            if [ -d "${modules_tmp}/lib/modules" ]; then
                CUSTOM_KERNEL_MODULES_DIR="${modules_tmp}/lib/modules"
            fi

            return
        fi

        if [ -n "$CUSTOM_KERNEL_IMAGE" ] && [ -f "$CUSTOM_KERNEL_IMAGE" ]; then
            kernel_tree="$(cd "$(dirname "$CUSTOM_KERNEL_IMAGE")/../.." && pwd)"
        else
            for dir in "${CUSTOM_KERNEL_ROOT}"/linux-*; do
                [ -d "$dir" ] || continue
                if [ -f "${dir}/arch/x86/boot/bzImage" ]; then
                    CUSTOM_KERNEL_IMAGE="${dir}/arch/x86/boot/bzImage"
                    kernel_tree="$dir"
                    break
                fi
            done
        fi

        if [ -z "$CUSTOM_KERNEL_MODULES_DIR" ]; then
            if [ -d "${CUSTOM_KERNEL_ROOT}/output/modules/lib/modules" ]; then
                CUSTOM_KERNEL_MODULES_DIR="${CUSTOM_KERNEL_ROOT}/output/modules/lib/modules"
            elif [ -d "${CUSTOM_KERNEL_ROOT}/modules/lib/modules" ]; then
                CUSTOM_KERNEL_MODULES_DIR="${CUSTOM_KERNEL_ROOT}/modules/lib/modules"
            elif [ -n "$kernel_tree" ] && [ -d "${kernel_tree}/modules/lib/modules" ]; then
                CUSTOM_KERNEL_MODULES_DIR="${kernel_tree}/modules/lib/modules"
            fi
        fi
    }

    detect_custom_kernel

    if [ -f "$kernel_dest" ] && [ -z "$CUSTOM_KERNEL_IMAGE" ]; then
        echo "Kernel already exists: $kernel_dest"
        return 0
    fi

    if [ -n "$CUSTOM_KERNEL_IMAGE" ] && [ -f "$CUSTOM_KERNEL_IMAGE" ]; then
        echo "Using custom kernel: $CUSTOM_KERNEL_IMAGE"
        cp "$CUSTOM_KERNEL_IMAGE" "$kernel_dest"

        if [ -n "$CUSTOM_KERNEL_MODULES_DIR" ] && [ -d "$CUSTOM_KERNEL_MODULES_DIR" ]; then
            echo "Using custom kernel modules from ${CUSTOM_KERNEL_MODULES_DIR}"
        else
            echo "Warning: No custom kernel modules found; initramfs will include none"
        fi

        return 0
    fi

    if [ "$REQUIRE_CUSTOM_KERNEL" = "1" ]; then
        echo "Error: Custom kernel not found. Run 'make kernel' first."
        exit 1
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
# Setup openssl for signature verification
# We extract from the built rootfs since it's already downloaded
#
setup_openssl() {
    local openssl_cache="${CACHE_DIR}/openssl"

    if [ -d "$openssl_cache" ] && [ -x "${openssl_cache}/usr/bin/openssl" ]; then
        echo "Using cached openssl"
        return 0
    fi

    echo "Extracting openssl from rootfs..."

    # Check if rootfs exists
    if [ ! -d "$ROOTFS_BUILD" ]; then
        echo "Warning: Rootfs not built yet, openssl will be missing from initramfs"
        echo "         Run 'make rootfs' first for signature verification support"
        return 1
    fi

    mkdir -p "$openssl_cache"

    # Copy openssl binary
    if [ -f "${ROOTFS_BUILD}/usr/bin/openssl" ]; then
        mkdir -p "${openssl_cache}/usr/bin"
        cp "${ROOTFS_BUILD}/usr/bin/openssl" "${openssl_cache}/usr/bin/"
        chmod +x "${openssl_cache}/usr/bin/openssl"
    else
        echo "Warning: openssl not found in rootfs"
        return 1
    fi

    # Copy required libraries
    mkdir -p "${openssl_cache}/lib" "${openssl_cache}/usr/lib"

    # Find and copy required shared libraries
    local libs="libssl libcrypto libc.musl"
    for lib in $libs; do
        # Check /lib
        for f in "${ROOTFS_BUILD}/lib/${lib}"*.so*; do
            [ -f "$f" ] && cp -a "$f" "${openssl_cache}/lib/" 2>/dev/null
        done
        # Check /usr/lib
        for f in "${ROOTFS_BUILD}/usr/lib/${lib}"*.so*; do
            [ -f "$f" ] && cp -a "$f" "${openssl_cache}/usr/lib/" 2>/dev/null
        done
    done

    # Copy ld-musl (the dynamic linker)
    for f in "${ROOTFS_BUILD}/lib/ld-musl"*.so*; do
        [ -f "$f" ] && cp -a "$f" "${openssl_cache}/lib/" 2>/dev/null
    done

    echo "OpenSSL extracted for initramfs"
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
    if [ -n "$CUSTOM_KERNEL_MODULES_DIR" ] && [ -d "$CUSTOM_KERNEL_MODULES_DIR" ]; then
        echo "Including kernel modules..."

        # Find the kernel version directory
        local kver=$(ls "${CUSTOM_KERNEL_MODULES_DIR}/" | head -1)
        if [ -n "$kver" ]; then
            mkdir -p "${INITRAMFS_BUILD}/lib/modules/${kver}/kernel/drivers"

            copy_module_with_deps() {
                local module_name="$1"
                local modules_base="${CUSTOM_KERNEL_MODULES_DIR}/${kver}"
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
            # Include: storage (ata, scsi, block, virtio), cdrom, message (mpt drivers)
            local module_dirs="ata scsi block virtio cdrom"
            for mdir in $module_dirs; do
                src="${CUSTOM_KERNEL_MODULES_DIR}/${kver}/kernel/drivers/${mdir}"
                if [ -d "$src" ]; then
                    mkdir -p "${INITRAMFS_BUILD}/lib/modules/${kver}/kernel/drivers/${mdir}"
                    cp -a "$src"/* "${INITRAMFS_BUILD}/lib/modules/${kver}/kernel/drivers/${mdir}/" 2>/dev/null || true
                fi
            done

            # Copy MPT Fusion drivers (used by VMware)
            src="${CUSTOM_KERNEL_MODULES_DIR}/${kver}/kernel/drivers/message/fusion"
            if [ -d "$src" ]; then
                mkdir -p "${INITRAMFS_BUILD}/lib/modules/${kver}/kernel/drivers/message/fusion"
                cp -a "$src"/* "${INITRAMFS_BUILD}/lib/modules/${kver}/kernel/drivers/message/fusion/" 2>/dev/null || true
            fi

            # Copy filesystem modules (squashfs, isofs for CD-ROM, fat, ext4, overlay)
            # Note: ext4 depends on jbd2, mbcache, and crc modules
            local fs_modules="squashfs isofs fat ext4 nls overlay jbd2 mbcache"
            for fsmod in $fs_modules; do
                src="${CUSTOM_KERNEL_MODULES_DIR}/${kver}/kernel/fs/${fsmod}"
                if [ -d "$src" ]; then
                    mkdir -p "${INITRAMFS_BUILD}/lib/modules/${kver}/kernel/fs/${fsmod}"
                    cp -a "$src"/* "${INITRAMFS_BUILD}/lib/modules/${kver}/kernel/fs/${fsmod}/" 2>/dev/null || true
                fi
            done

            # Copy crypto/crc modules needed by ext4 and other filesystems
            local crypto_dirs="crypto lib/crc"
            for cdir in $crypto_dirs; do
                src="${CUSTOM_KERNEL_MODULES_DIR}/${kver}/kernel/${cdir}"
                if [ -d "$src" ]; then
                    mkdir -p "${INITRAMFS_BUILD}/lib/modules/${kver}/kernel/${cdir}"
                    # Only copy crc-related modules to keep size down
                    find "$src" -name "*crc*" -type f -exec cp {} "${INITRAMFS_BUILD}/lib/modules/${kver}/kernel/${cdir}/" \; 2>/dev/null || true
                fi
            done

            # Copy modules.builtin (lists built-in modules)
            if [ -f "${CUSTOM_KERNEL_MODULES_DIR}/${kver}/modules.builtin" ]; then
                cp "${CUSTOM_KERNEL_MODULES_DIR}/${kver}/modules.builtin" "${INITRAMFS_BUILD}/lib/modules/${kver}/"
            fi

            # Copy key NIC modules for installer/runtime probing
            local nic_modules="virtio_net vmxnet3 e1000 e1000e xen-netfront igb igc ixgbe i40e r8169 r8152 tg3 bnx2 atlantic hv_netvsc"
            for mod in $nic_modules; do
                copy_module_with_deps "$mod"
            done

            # Regenerate modules.dep for the initramfs subset
            # This ensures modprobe can find the modules we included
            if command -v depmod &>/dev/null; then
                echo "Regenerating modules.dep for initramfs..."
                depmod -b "${INITRAMFS_BUILD}" "$kver" 2>/dev/null || true
            fi
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

    # Copy openssl for signature verification
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

    # Copy verify-signature script
    if [ -f "${INITRAMFS_SRC}/bin/verify-signature" ]; then
        cp "${INITRAMFS_SRC}/bin/verify-signature" "${INITRAMFS_BUILD}/bin/"
        chmod +x "${INITRAMFS_BUILD}/bin/verify-signature"
    fi

    # Copy firmware signing public key
    if [ -d "${INITRAMFS_SRC}/etc/coyote/keys" ]; then
        mkdir -p "${INITRAMFS_BUILD}/etc/coyote/keys"
        cp "${INITRAMFS_SRC}/etc/coyote/keys/"* "${INITRAMFS_BUILD}/etc/coyote/keys/" 2>/dev/null || true
    fi

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
    setup_openssl
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
