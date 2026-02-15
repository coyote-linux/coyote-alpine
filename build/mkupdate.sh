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

sha256sum "${STAGE_DIR}/vmlinuz" > "${STAGE_DIR}/vmlinuz.sha256"
sha256sum "${STAGE_DIR}/initramfs.img" > "${STAGE_DIR}/initramfs.img.sha256"
sha256sum "${STAGE_DIR}/firmware.squashfs" > "${STAGE_DIR}/firmware.squashfs.sha256"

if [ -f "${SCRIPT_DIR}/.local-config" ]; then
    . "${SCRIPT_DIR}/.local-config"
fi

if [ -n "${COYOTE_SIGNING_KEY:-}" ] && [ -f "${COYOTE_SIGNING_KEY}" ]; then
    openssl pkeyutl -sign -inkey "$COYOTE_SIGNING_KEY" -rawin -in "${STAGE_DIR}/vmlinuz" -out "${STAGE_DIR}/vmlinuz.sig"
    openssl pkeyutl -sign -inkey "$COYOTE_SIGNING_KEY" -rawin -in "${STAGE_DIR}/initramfs.img" -out "${STAGE_DIR}/initramfs.img.sig"
    openssl pkeyutl -sign -inkey "$COYOTE_SIGNING_KEY" -rawin -in "${STAGE_DIR}/firmware.squashfs" -out "${STAGE_DIR}/firmware.squashfs.sig"
fi

set -- vmlinuz initramfs.img firmware.squashfs vmlinuz.sha256 initramfs.img.sha256 firmware.squashfs.sha256
if [ -f "${STAGE_DIR}/vmlinuz.sig" ]; then
    set -- "$@" vmlinuz.sig initramfs.img.sig firmware.squashfs.sig
fi

tar -czf "$ARCHIVE_FILE" -C "$STAGE_DIR" "$@"

sha256sum "$ARCHIVE_FILE" > "${ARCHIVE_FILE}.sha256"

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
