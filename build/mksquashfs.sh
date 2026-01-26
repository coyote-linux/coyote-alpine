#!/bin/bash
#
# mksquashfs.sh - Build squashfs firmware image
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
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

# Pseudo-file for setuid bits (needed for doas)
PSEUDO_FILE="$SCRIPT_DIR/squashfs-pseudos.txt"
PSEUDO_OPTS=""
if [ -f "$PSEUDO_FILE" ]; then
    echo "Using pseudo-file definitions: $PSEUDO_FILE"
    PSEUDO_OPTS="-pf $PSEUDO_FILE"
fi

# Create squashfs image
# -all-root: Make all files owned by root:root (UID 0, GID 0)
#            This is needed since we build as non-root user
# -pf:       Pseudo-file definitions for setuid bits etc.
# shellcheck disable=SC2086
mksquashfs "$ROOTFS_DIR" "$OUTPUT_FILE" $SQUASHFS_OPTS \
    -all-root \
    $PSEUDO_OPTS \
    -e '.git' \
    -e '.gitignore' \
    -e '*.md' \
    -e 'tests'

echo "Squashfs image created successfully"
ls -lh "$OUTPUT_FILE"
