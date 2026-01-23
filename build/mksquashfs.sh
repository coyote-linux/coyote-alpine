#!/bin/bash
#
# mksquashfs.sh - Build squashfs firmware image
#

set -e

ROOTFS_DIR="$1"
OUTPUT_FILE="$2"
SQUASHFS_OPTS="$3"

if [ -z "$ROOTFS_DIR" ] || [ -z "$OUTPUT_FILE" ]; then
    echo "Usage: $0 <rootfs-dir> <output-file> [squashfs-opts]"
    exit 1
fi

if [ ! -d "$ROOTFS_DIR" ]; then
    echo "Error: Root filesystem directory not found: $ROOTFS_DIR"
    exit 1
fi

echo "Creating squashfs image from: $ROOTFS_DIR"
echo "Output: $OUTPUT_FILE"

# Remove existing image
rm -f "$OUTPUT_FILE"

# Create squashfs image
# shellcheck disable=SC2086
mksquashfs "$ROOTFS_DIR" "$OUTPUT_FILE" $SQUASHFS_OPTS \
    -e '.git' \
    -e '.gitignore' \
    -e '*.md' \
    -e 'tests'

echo "Squashfs image created successfully"
ls -lh "$OUTPUT_FILE"
