#!/bin/bash
#
# sign-firmware.sh - Sign Coyote Linux firmware images
#
# This script creates Ed25519 signatures for firmware squashfs images.
# The signature is stored alongside the firmware as firmware.squashfs.sig
#
# Usage: ./sign-firmware.sh <firmware.squashfs> [key-file]
#
# If key-file is not specified, uses COYOTE_SIGNING_KEY from .local-config
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Source local config if it exists (for COYOTE_SIGNING_KEY)
if [ -f "${SCRIPT_DIR}/.local-config" ]; then
    source "${SCRIPT_DIR}/.local-config"
fi

FIRMWARE_FILE="$1"
KEY_FILE="${2:-$COYOTE_SIGNING_KEY}"

if [ -z "$FIRMWARE_FILE" ]; then
    echo "Usage: $0 <firmware.squashfs> [key-file]"
    echo ""
    echo "Signs a firmware image using Ed25519."
    echo "Key is read from COYOTE_SIGNING_KEY in .local-config"
    exit 1
fi

if [ ! -f "$FIRMWARE_FILE" ]; then
    echo "Error: Firmware file not found: $FIRMWARE_FILE"
    exit 1
fi

if [ -z "$KEY_FILE" ]; then
    echo "Error: No signing key specified"
    echo ""
    echo "Set COYOTE_SIGNING_KEY in build/.local-config or pass key as argument."
    echo ""
    echo "Generate a key pair with:"
    echo "  openssl genpkey -algorithm Ed25519 -out firmware-signing.key"
    echo "  openssl pkey -in firmware-signing.key -pubout -out firmware-signing.pub"
    exit 1
fi

if [ ! -f "$KEY_FILE" ]; then
    echo "Error: Signing key not found: $KEY_FILE"
    exit 1
fi

SIG_FILE="${FIRMWARE_FILE}.sig"

echo "Signing firmware..."
echo "  Firmware: $FIRMWARE_FILE"
echo "  Key:      $KEY_FILE"
echo "  Output:   $SIG_FILE"

# Create signature using OpenSSL
# The signature is created over the raw file content
openssl pkeyutl -sign \
    -inkey "$KEY_FILE" \
    -rawin \
    -in "$FIRMWARE_FILE" \
    -out "$SIG_FILE"

# Display signature info
echo ""
echo "Signature created:"
ls -la "$SIG_FILE"
echo ""
echo "Signature (base64):"
base64 "$SIG_FILE"
echo ""
echo "To verify:"
echo "  openssl pkeyutl -verify -pubin -inkey firmware-signing.pub -rawin -in $FIRMWARE_FILE -sigfile $SIG_FILE"
