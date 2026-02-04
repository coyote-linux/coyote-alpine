#!/bin/bash
#
# mkiso.sh - Build bootable ISO installer image
#
# This script creates a bootable ISO image for CD/DVD installation
# or virtual machine boot. Uses isolinux for BIOS boot.
#
# Prerequisites (Fedora):
#   dnf install syslinux xorriso
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BUILD_DIR="${SCRIPT_DIR}/../output"

# Detect version from firmware filename (firmware-X.Y.Z.squashfs)
FIRMWARE_FILE=$(ls -t "${BUILD_DIR}"/firmware-*.squashfs 2>/dev/null | head -1)
if [ -n "$FIRMWARE_FILE" ]; then
    VERSION=$(basename "$FIRMWARE_FILE" | sed 's/firmware-\(.*\)\.squashfs/\1/')
else
    VERSION="4.0.0"
    echo "Warning: No firmware found, using default version $VERSION"
fi
echo "Building ISO for Coyote Linux $VERSION"

# Check for ISO creation tool (prefer xorriso, fall back to genisoimage/mkisofs)
ISO_TOOL=""
if command -v xorriso &>/dev/null; then
    ISO_TOOL="xorriso"
elif command -v genisoimage &>/dev/null; then
    ISO_TOOL="genisoimage"
elif command -v mkisofs &>/dev/null; then
    ISO_TOOL="mkisofs"
else
    echo "Error: No ISO creation tool found."
    echo "On Fedora, install with: dnf install xorriso"
    echo "  or: dnf install genisoimage"
    exit 1
fi
echo "Using ISO tool: $ISO_TOOL"

# Find isolinux files
ISOLINUX_DIR=""
for dir in /usr/share/syslinux /usr/lib/syslinux/bios /usr/lib/syslinux; do
    if [ -f "${dir}/isolinux.bin" ]; then
        ISOLINUX_DIR="$dir"
        break
    fi
done

if [ -z "$ISOLINUX_DIR" ]; then
    echo "Error: Cannot find isolinux.bin"
    echo "On Fedora, install with: dnf install syslinux"
    exit 1
fi

ISO_BUILD="${BUILD_DIR}/iso-build"
ISO_FILE="${BUILD_DIR}/coyote-installer-${VERSION}.iso"

echo "Building ISO image..."

# Clean and create ISO staging directory
rm -rf "$ISO_BUILD"
mkdir -p "${ISO_BUILD}/boot/isolinux"
mkdir -p "${ISO_BUILD}/firmware"

# Copy isolinux bootloader files
echo "Copying isolinux bootloader..."
cp "${ISOLINUX_DIR}/isolinux.bin" "${ISO_BUILD}/boot/isolinux/"
cp "${ISOLINUX_DIR}/ldlinux.c32" "${ISO_BUILD}/boot/isolinux/"
cp "${ISOLINUX_DIR}/menu.c32" "${ISO_BUILD}/boot/isolinux/"
if [ -f "${ISOLINUX_DIR}/vesamenu.c32" ]; then
    cp "${ISOLINUX_DIR}/vesamenu.c32" "${ISO_BUILD}/boot/isolinux/"
fi
cp "${ISOLINUX_DIR}/libutil.c32" "${ISO_BUILD}/boot/isolinux/"

# Copy libcom32.c32 if it exists (needed by some menu.c32 versions)
if [ -f "${ISOLINUX_DIR}/libcom32.c32" ]; then
    cp "${ISOLINUX_DIR}/libcom32.c32" "${ISO_BUILD}/boot/isolinux/"
fi
if [ -f "${ISOLINUX_DIR}/libmenu.c32" ]; then
    cp "${ISOLINUX_DIR}/libmenu.c32" "${ISO_BUILD}/boot/isolinux/"
fi
if [ -f "${ISOLINUX_DIR}/libgpl.c32" ]; then
    cp "${ISOLINUX_DIR}/libgpl.c32" "${ISO_BUILD}/boot/isolinux/"
fi
if [ -f "${ISOLINUX_DIR}/liblua.c32" ]; then
    cp "${ISOLINUX_DIR}/liblua.c32" "${ISO_BUILD}/boot/isolinux/"
fi

# Copy kernel and installer initramfs
echo "Copying kernel and installer initramfs..."
if [ -f "${BUILD_DIR}/vmlinuz" ]; then
    cp "${BUILD_DIR}/vmlinuz" "${ISO_BUILD}/boot/vmlinuz"
else
    echo "Error: Kernel not found at ${BUILD_DIR}/vmlinuz"
    echo "Run 'make initramfs' first"
    exit 1
fi

# Use installer-specific initramfs for booting the installer
if [ -f "${BUILD_DIR}/initramfs-installer.cpio.gz" ]; then
    cp "${BUILD_DIR}/initramfs-installer.cpio.gz" "${ISO_BUILD}/boot/initramfs.gz"
else
    echo "Error: Installer initramfs not found at ${BUILD_DIR}/initramfs-installer.cpio.gz"
    echo "Run 'make initramfs-installer' first"
    exit 1
fi

if [ -f "${BUILD_DIR}/coyote-3-square.png" ]; then
    cp "${BUILD_DIR}/coyote-3-square.png" "${ISO_BUILD}/boot/isolinux/"
fi

# Also include the system initramfs - this is what gets installed to the target system
echo "Copying system initramfs..."
if [ -f "${BUILD_DIR}/initramfs.cpio.gz" ]; then
    cp "${BUILD_DIR}/initramfs.cpio.gz" "${ISO_BUILD}/boot/initramfs-system.gz"
else
    echo "Error: System initramfs not found at ${BUILD_DIR}/initramfs.cpio.gz"
    echo "Run 'make initramfs' first"
    exit 1
fi

# Copy firmware
echo "Copying firmware..."
FIRMWARE_SRC=$(ls "${BUILD_DIR}"/firmware-*.squashfs 2>/dev/null | head -1)
if [ -n "$FIRMWARE_SRC" ] && [ -f "$FIRMWARE_SRC" ]; then
    cp "$FIRMWARE_SRC" "${ISO_BUILD}/firmware/current.squashfs"
    if [ -f "${FIRMWARE_SRC}.sha256" ]; then
        cp "${FIRMWARE_SRC}.sha256" "${ISO_BUILD}/firmware/current.squashfs.sha256"
    fi
    # Copy signature file if it exists
    if [ -f "${FIRMWARE_SRC}.sig" ]; then
        echo "Copying firmware signature..."
        cp "${FIRMWARE_SRC}.sig" "${ISO_BUILD}/firmware/current.squashfs.sig"
    else
        echo "Note: No firmware signature found (unsigned build)"
    fi
    # Write version file for installer to read
    echo "$VERSION" > "${ISO_BUILD}/firmware/version"
else
    echo "Error: No firmware image found in ${BUILD_DIR}/"
    echo "Run 'make firmware' first"
    exit 1
fi

# Create boot marker
echo "COYOTE_INSTALLER" > "${ISO_BUILD}/coyote.marker"

# Create isolinux configuration
echo "Creating isolinux configuration..."
cat > "${ISO_BUILD}/boot/isolinux/isolinux.cfg" << 'EOF'
DEFAULT menu.c32
PROMPT 0
TIMEOUT 50

MENU TITLE Coyote Linux Installer

LABEL install
    MENU LABEL Install Coyote Linux
    MENU DEFAULT
    LINUX /boot/vmlinuz
    INITRD /boot/initramfs.gz
    APPEND console=tty0 quiet installer

LABEL rescue
    MENU LABEL Rescue Mode
    LINUX /boot/vmlinuz
    INITRD /boot/initramfs.gz
    APPEND console=tty0 quiet rescue

LABEL localdisk
    MENU LABEL Boot from local disk
    LOCALBOOT -1
EOF

# Build ISO using available tool
echo "Creating ISO image..."
if [ "$ISO_TOOL" = "xorriso" ]; then
    xorriso -as mkisofs \
        -o "$ISO_FILE" \
        -isohybrid-mbr "${ISOLINUX_DIR}/isohdpfx.bin" \
        -c boot/isolinux/boot.cat \
        -b boot/isolinux/isolinux.bin \
        -no-emul-boot \
        -boot-load-size 4 \
        -boot-info-table \
        -V "COYOTE_INSTALLER" \
        -R -J \
        "$ISO_BUILD"
else
    # genisoimage or mkisofs
    "$ISO_TOOL" \
        -o "$ISO_FILE" \
        -c boot/isolinux/boot.cat \
        -b boot/isolinux/isolinux.bin \
        -no-emul-boot \
        -boot-load-size 4 \
        -boot-info-table \
        -V "COYOTE_INSTALLER" \
        -R -J \
        "$ISO_BUILD"

    # Make hybrid if isohybrid is available
    if command -v isohybrid &>/dev/null; then
        echo "Making ISO hybrid bootable..."
        isohybrid "$ISO_FILE"
    fi
fi

# Generate checksum
sha256sum "$ISO_FILE" > "${ISO_FILE}.sha256"

# Clean up staging directory
rm -rf "$ISO_BUILD"

# Create/update symlink to latest ISO
LATEST_LINK="${BUILD_DIR}/coyote-installer-latest.iso"
rm -f "$LATEST_LINK"
ln -s "$(basename "$ISO_FILE")" "$LATEST_LINK"

echo ""
echo "=========================================="
echo "ISO image created: $ISO_FILE"
ls -lh "$ISO_FILE"
echo ""
echo "SHA256: $(cat "${ISO_FILE}.sha256" | cut -d' ' -f1)"
echo ""
echo "To test with QEMU:"
echo "  qemu-system-x86_64 -cdrom $ISO_FILE -m 512M"
echo "=========================================="
