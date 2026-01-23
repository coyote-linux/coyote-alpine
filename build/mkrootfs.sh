#!/bin/bash
#
# mkrootfs.sh - Build Alpine Linux rootfs for Coyote Linux
#
# This script downloads apk-tools-static and uses it to bootstrap
# an Alpine Linux rootfs without requiring root privileges or containers.
#
# Prerequisites (Fedora):
#   dnf install curl tar gzip
#

set -e

# Configuration
ALPINE_VERSION="3.23"
ALPINE_MIRROR="https://dl-cdn.alpinelinux.org/alpine"
ARCH="x86_64"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BUILD_DIR="${SCRIPT_DIR}/../output"
CACHE_DIR="${SCRIPT_DIR}/../.cache"
ROOTFS_DIR="${SCRIPT_DIR}/../output/rootfs"
COYOTE_ROOTFS="${SCRIPT_DIR}/../rootfs"
PACKAGES_FILE="${SCRIPT_DIR}/apk-packages.txt"

# Create directories
mkdir -p "$BUILD_DIR" "$CACHE_DIR" "$ROOTFS_DIR"

#
# Download and extract apk-tools-static
#
setup_apk_static() {
    local apk_static="${CACHE_DIR}/apk.static"

    if [ -x "$apk_static" ]; then
        echo "Using cached apk-tools-static"
        return 0
    fi

    echo "Downloading apk-tools-static..."

    # Get the latest apk-tools-static package name from the repository
    local repo_url="${ALPINE_MIRROR}/v${ALPINE_VERSION}/main/${ARCH}"
    local apk_pkg=$(curl -s "${repo_url}/" | grep -oP 'apk-tools-static-[0-9][^"]+\.apk' | head -1)

    if [ -z "$apk_pkg" ]; then
        echo "Error: Cannot find apk-tools-static package"
        exit 1
    fi

    local apk_url="${repo_url}/${apk_pkg}"
    local tmp_apk="${CACHE_DIR}/apk-tools-static.apk"

    echo "Downloading: $apk_url"
    curl -L -o "$tmp_apk" "$apk_url"

    # Extract the static binary
    # APK files are gzipped tarballs with a data.tar.gz inside
    echo "Extracting apk.static..."
    cd "$CACHE_DIR"
    tar -xzf "$tmp_apk" 2>/dev/null || true

    # The static binary is in sbin/apk.static
    if [ -f "${CACHE_DIR}/sbin/apk.static" ]; then
        mv "${CACHE_DIR}/sbin/apk.static" "$apk_static"
        chmod +x "$apk_static"
        rm -rf "${CACHE_DIR}/sbin" "${CACHE_DIR}/.PKGINFO" "${CACHE_DIR}/.SIGN."* 2>/dev/null || true
    else
        echo "Error: Failed to extract apk.static"
        exit 1
    fi

    rm -f "$tmp_apk"
    echo "apk-tools-static ready: $apk_static"
}

#
# Download Alpine signing keys
#
setup_alpine_keys() {
    local keys_dir="${CACHE_DIR}/keys"

    if [ -d "$keys_dir" ] && [ "$(ls -A "$keys_dir" 2>/dev/null)" ]; then
        echo "Using cached Alpine keys"
        return 0
    fi

    echo "Downloading Alpine signing keys..."
    mkdir -p "$keys_dir"

    # Download the alpine-keys package and extract
    local repo_url="${ALPINE_MIRROR}/v${ALPINE_VERSION}/main/${ARCH}"
    local keys_pkg=$(curl -s "${repo_url}/" | grep -oP 'alpine-keys-[0-9][^"]+\.apk' | head -1)

    if [ -z "$keys_pkg" ]; then
        echo "Error: Cannot find alpine-keys package"
        exit 1
    fi

    local tmp_keys="${CACHE_DIR}/alpine-keys.apk"
    curl -L -o "$tmp_keys" "${repo_url}/${keys_pkg}"

    cd "$CACHE_DIR"
    tar -xzf "$tmp_keys" 2>/dev/null || true

    if [ -d "${CACHE_DIR}/usr/share/apk/keys" ]; then
        cp -a "${CACHE_DIR}/usr/share/apk/keys/"* "$keys_dir/"
        rm -rf "${CACHE_DIR}/usr" "${CACHE_DIR}/.PKGINFO" "${CACHE_DIR}/.SIGN."* 2>/dev/null || true
    fi

    # Also download the specific keys needed for package verification
    for key in "alpine-devel@lists.alpinelinux.org-4a6a0840.rsa.pub" \
               "alpine-devel@lists.alpinelinux.org-5243ef4b.rsa.pub" \
               "alpine-devel@lists.alpinelinux.org-5261cecb.rsa.pub" \
               "alpine-devel@lists.alpinelinux.org-6165ee59.rsa.pub" \
               "alpine-devel@lists.alpinelinux.org-61666e3f.rsa.pub" \
               "alpine-devel@lists.alpinelinux.org-616a9724.rsa.pub" \
               "alpine-devel@lists.alpinelinux.org-616abc23.rsa.pub" \
               "alpine-devel@lists.alpinelinux.org-616adfeb.rsa.pub" \
               "alpine-devel@lists.alpinelinux.org-616ae350.rsa.pub" \
               "alpine-devel@lists.alpinelinux.org-616db30d.rsa.pub"; do
        if [ ! -f "${keys_dir}/${key}" ]; then
            curl -s -o "${keys_dir}/${key}" "https://alpinelinux.org/keys/${key}" 2>/dev/null || true
        fi
    done

    rm -f "$tmp_keys"
    echo "Alpine keys ready"
}

#
# Initialize the rootfs
#
init_rootfs() {
    local apk_static="${CACHE_DIR}/apk.static"
    local keys_dir="${CACHE_DIR}/keys"

    echo "Initializing rootfs at: $ROOTFS_DIR"

    # Clean existing rootfs
    rm -rf "$ROOTFS_DIR"
    mkdir -p "$ROOTFS_DIR"

    # Create essential directories
    mkdir -p "${ROOTFS_DIR}/etc/apk/keys"
    mkdir -p "${ROOTFS_DIR}/var/cache/apk"
    mkdir -p "${ROOTFS_DIR}/lib/apk/db"

    # Copy Alpine signing keys
    cp -a "${keys_dir}/"* "${ROOTFS_DIR}/etc/apk/keys/"

    # Create repositories file
    cat > "${ROOTFS_DIR}/etc/apk/repositories" << EOF
${ALPINE_MIRROR}/v${ALPINE_VERSION}/main
${ALPINE_MIRROR}/v${ALPINE_VERSION}/community
EOF

    # Initialize the APK database
    "$apk_static" \
        --root "$ROOTFS_DIR" \
        --keys-dir "${ROOTFS_DIR}/etc/apk/keys" \
        --usermode \
        --initdb \
        --update-cache \
        add --no-scripts

    echo "Rootfs initialized"
}

#
# Install packages from apk-packages.txt
#
install_packages() {
    local apk_static="${CACHE_DIR}/apk.static"

    if [ ! -f "$PACKAGES_FILE" ]; then
        echo "Error: Packages file not found: $PACKAGES_FILE"
        exit 1
    fi

    echo "Installing packages from: $PACKAGES_FILE"

    # Read packages, filter comments and empty lines
    local packages=$(grep -v '^#' "$PACKAGES_FILE" | grep -v '^$' | tr '\n' ' ')

    echo "Packages to install: $packages"

    # Install packages
    # Using --no-scripts because some scripts expect to run as root
    # Using --usermode to allow running as non-root user
    # We'll handle necessary setup manually
    "$apk_static" \
        --root "$ROOTFS_DIR" \
        --keys-dir "${ROOTFS_DIR}/etc/apk/keys" \
        --usermode \
        --no-cache \
        --no-scripts \
        add $packages

    echo "Packages installed"
}

#
# Apply Coyote-specific overlay
#
apply_coyote_overlay() {
    echo "Applying Coyote overlay..."

    # Copy Coyote-specific files from the source rootfs
    if [ -d "$COYOTE_ROOTFS" ]; then
        cp -a "${COYOTE_ROOTFS}/"* "$ROOTFS_DIR/"
    fi

    # Create essential directories that may be missing
    mkdir -p "${ROOTFS_DIR}/dev"
    mkdir -p "${ROOTFS_DIR}/proc"
    mkdir -p "${ROOTFS_DIR}/sys"
    mkdir -p "${ROOTFS_DIR}/tmp"
    mkdir -p "${ROOTFS_DIR}/run"
    mkdir -p "${ROOTFS_DIR}/mnt/config"
    mkdir -p "${ROOTFS_DIR}/mnt/boot"
    mkdir -p "${ROOTFS_DIR}/opt/coyote/addons"
    mkdir -p "${ROOTFS_DIR}/tmp/running-config"

    # Set permissions
    chmod 1777 "${ROOTFS_DIR}/tmp"

    # Create basic device nodes (these are typically created at runtime,
    # but having them in the image doesn't hurt)
    # Note: mknod requires root, so we skip this for now
    # The initramfs will mount devtmpfs which provides these

    # Create /etc/hostname
    echo "coyote" > "${ROOTFS_DIR}/etc/hostname"

    # Create /etc/hosts
    cat > "${ROOTFS_DIR}/etc/hosts" << 'EOF'
127.0.0.1	localhost localhost.localdomain
::1		localhost localhost.localdomain
EOF

    # Create /etc/resolv.conf placeholder
    echo "# Configured by Coyote Linux" > "${ROOTFS_DIR}/etc/resolv.conf"

    # Create /etc/fstab
    cat > "${ROOTFS_DIR}/etc/fstab" << 'EOF'
# Coyote Linux fstab
# Most filesystems are mounted by the initramfs
none		/tmp		tmpfs	nosuid,nodev,mode=1777	0 0
none		/run		tmpfs	nosuid,nodev,mode=0755	0 0
EOF

    # Enable Coyote services (create symlinks for OpenRC)
    mkdir -p "${ROOTFS_DIR}/etc/runlevels/default"
    mkdir -p "${ROOTFS_DIR}/etc/runlevels/boot"

    # Link standard services
    for svc in bootmisc hostname hwclock modules sysctl urandom networking; do
        if [ -f "${ROOTFS_DIR}/etc/init.d/${svc}" ]; then
            ln -sf "/etc/init.d/${svc}" "${ROOTFS_DIR}/etc/runlevels/boot/" 2>/dev/null || true
        fi
    done

    # Link Coyote services
    if [ -f "${ROOTFS_DIR}/etc/init.d/coyote-init" ]; then
        ln -sf "/etc/init.d/coyote-init" "${ROOTFS_DIR}/etc/runlevels/default/"
    fi
    if [ -f "${ROOTFS_DIR}/etc/init.d/coyote-config" ]; then
        ln -sf "/etc/init.d/coyote-config" "${ROOTFS_DIR}/etc/runlevels/default/"
    fi

    # Make CLI tools executable
    if [ -d "${ROOTFS_DIR}/opt/coyote/bin" ]; then
        chmod +x "${ROOTFS_DIR}/opt/coyote/bin/"* 2>/dev/null || true
    fi

    echo "Coyote overlay applied"
}

#
# Clean up the rootfs
#
cleanup_rootfs() {
    echo "Cleaning up rootfs..."

    # Remove APK cache
    rm -rf "${ROOTFS_DIR}/var/cache/apk/"*

    # Remove APK package database to save space (optional, makes image smaller)
    # Uncomment if you don't need apk in the final image:
    # rm -rf "${ROOTFS_DIR}/lib/apk"
    # rm -rf "${ROOTFS_DIR}/etc/apk"

    # Remove unnecessary files
    rm -rf "${ROOTFS_DIR}/usr/share/man"
    rm -rf "${ROOTFS_DIR}/usr/share/doc"
    rm -rf "${ROOTFS_DIR}/usr/share/info"

    # Calculate size
    local size=$(du -sh "$ROOTFS_DIR" | cut -f1)
    echo "Rootfs size: $size"
}

#
# Main
#
main() {
    echo "=========================================="
    echo "Coyote Linux Rootfs Builder"
    echo "Alpine Linux ${ALPINE_VERSION} (${ARCH})"
    echo "=========================================="
    echo ""

    setup_apk_static
    setup_alpine_keys
    init_rootfs
    install_packages
    apply_coyote_overlay
    cleanup_rootfs

    echo ""
    echo "=========================================="
    echo "Rootfs build complete: $ROOTFS_DIR"
    echo ""
    echo "Next steps:"
    echo "  make firmware    # Create squashfs image"
    echo "  make installer   # Create bootable installer"
    echo "=========================================="
}

main "$@"
