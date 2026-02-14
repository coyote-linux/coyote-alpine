#!/bin/bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

VERSION="${1:-}"
FIRMWARE_FILE="${2:-}"
KERNEL_FILE="${3:-}"
INITRAMFS_FILE="${4:-}"
OUTPUT_DIR="${5:-${SCRIPT_DIR}/../output}"

if [ -z "$VERSION" ] || [ -z "$FIRMWARE_FILE" ] || [ -z "$KERNEL_FILE" ] || [ -z "$INITRAMFS_FILE" ]; then
    echo "Usage: $0 <version> <firmware.squashfs> <vmlinuz> <initramfs.cpio.gz> [output-dir]"
    exit 1
fi

if [ ! -f "$FIRMWARE_FILE" ]; then
    echo "Error: Firmware file not found: $FIRMWARE_FILE"
    exit 1
fi

if [ ! -f "$KERNEL_FILE" ]; then
    echo "Error: Kernel file not found: $KERNEL_FILE"
    exit 1
fi

if [ ! -f "$INITRAMFS_FILE" ]; then
    echo "Error: Initramfs file not found: $INITRAMFS_FILE"
    exit 1
fi

mkdir -p "$OUTPUT_DIR"

ARCHIVE_FILE="${OUTPUT_DIR}/coyote-update-${VERSION}.tar.gz"
STAGE_DIR="$(mktemp -d "${OUTPUT_DIR}/update-${VERSION}.XXXXXX")"

cleanup() {
    rm -rf "$STAGE_DIR"
}
trap cleanup EXIT

cp "$KERNEL_FILE" "${STAGE_DIR}/vmlinuz"
cp "$INITRAMFS_FILE" "${STAGE_DIR}/initramfs.img"
cp "$FIRMWARE_FILE" "${STAGE_DIR}/firmware.squashfs"

tar -czf "$ARCHIVE_FILE" -C "$STAGE_DIR" vmlinuz initramfs.img firmware.squashfs

sha256sum "$ARCHIVE_FILE" > "${ARCHIVE_FILE}.sha256"

if [ -f "${SCRIPT_DIR}/.local-config" ]; then
    . "${SCRIPT_DIR}/.local-config"
fi

if [ -n "${COYOTE_SIGNING_KEY:-}" ] && [ -f "${COYOTE_SIGNING_KEY}" ]; then
    "${SCRIPT_DIR}/sign-firmware.sh" "$ARCHIVE_FILE" "$COYOTE_SIGNING_KEY" >/dev/null
else
    echo "Note: Update archive not signed (COYOTE_SIGNING_KEY not configured)"
fi

echo "Update archive created: $ARCHIVE_FILE"
echo "SHA256 file: ${ARCHIVE_FILE}.sha256"
if [ -f "${ARCHIVE_FILE}.sig" ]; then
    echo "Signature file: ${ARCHIVE_FILE}.sig"
fi
