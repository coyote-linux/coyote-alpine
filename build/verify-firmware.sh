#!/bin/bash
#
# verify-firmware.sh - Verify Coyote Linux firmware signatures
#
# This script verifies Ed25519 signatures on firmware squashfs images.
#
# Usage: ./verify-firmware.sh <firmware.squashfs> [public-key]
#
# If public-key is not specified, uses /etc/coyote/keys/firmware-signing.pub
#

set -e

FIRMWARE_FILE="$1"
PUBKEY_FILE="${2:-/etc/coyote/keys/firmware-signing.pub}"

if [ -z "$FIRMWARE_FILE" ]; then
    echo "Usage: $0 <firmware.squashfs> [public-key]"
    echo ""
    echo "Verifies a firmware signature using Ed25519."
    echo "Default key: /etc/coyote/keys/firmware-signing.pub"
    exit 1
fi

if [ ! -f "$FIRMWARE_FILE" ]; then
    echo "Error: Firmware file not found: $FIRMWARE_FILE"
    exit 1
fi

SIG_FILE="${FIRMWARE_FILE}.sig"

if [ ! -f "$SIG_FILE" ]; then
    echo "Error: Signature file not found: $SIG_FILE"
    exit 1
fi

if [ ! -f "$PUBKEY_FILE" ]; then
    echo "Error: Public key not found: $PUBKEY_FILE"
    exit 1
fi

# Verify signature using OpenSSL
if openssl pkeyutl -verify \
    -pubin -inkey "$PUBKEY_FILE" \
    -rawin \
    -in "$FIRMWARE_FILE" \
    -sigfile "$SIG_FILE" 2>/dev/null; then
    echo "Signature verification: OK"
    exit 0
else
    echo "Signature verification: FAILED"
    exit 1
fi
