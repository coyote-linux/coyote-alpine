#!/bin/sh

set -eu

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"

KERNEL_VERSION="${1:-${KERNEL_VERSION:-6.19.0}}"
ARCH="${ARCH:-x86_64}"
JOBS="${JOBS:-$(getconf _NPROCESSORS_ONLN 2>/dev/null || echo 1)}"

KERNEL_TARBALL="${ROOT_DIR}/linux-${KERNEL_VERSION}.tar.xz"
KERNEL_SRC_DIR="${ROOT_DIR}/linux-${KERNEL_VERSION}"
CONFIG_FILE="${ROOT_DIR}/configs/coyote-x86_64.defconfig"
OUTPUT_MODULES="${ROOT_DIR}/output/modules"
ARCHIVE_DIR="${ROOT_DIR}/output"
KERNEL_ARCHIVE="${ARCHIVE_DIR}/kernel-${KERNEL_VERSION}.tar.gz"
MODULES_ARCHIVE="${ARCHIVE_DIR}/modules-${KERNEL_VERSION}.tar.gz"
LOGO_SOURCE="${LOGO_SOURCE:-${ROOT_DIR}/../build/coyote-3-square.png}"
FORCE_REBUILD="${FORCE_REBUILD:-0}"

if [ ! -f "$CONFIG_FILE" ]; then
    echo "Error: config file not found: $CONFIG_FILE" >&2
    exit 1
fi

if [ "$FORCE_REBUILD" != "1" ] && [ -f "$KERNEL_ARCHIVE" ] && [ -f "$MODULES_ARCHIVE" ]; then
    echo "Kernel archives already exist:"
    echo "  $KERNEL_ARCHIVE"
    echo "  $MODULES_ARCHIVE"
    exit 0
fi

if [ ! -d "$KERNEL_SRC_DIR" ]; then
    if [ ! -f "$KERNEL_TARBALL" ]; then
        major="${KERNEL_VERSION%%.*}"
        case "$major" in
            6) series="v6.x" ;;
            5) series="v5.x" ;;
            4) series="v4.x" ;;
            *)
                echo "Error: unsupported kernel major version: $KERNEL_VERSION" >&2
                exit 1
                ;;
        esac

        url="https://cdn.kernel.org/pub/linux/kernel/${series}/linux-${KERNEL_VERSION}.tar.xz"
        echo "Downloading $url"
        curl -L -o "$KERNEL_TARBALL" "$url"
    fi

    echo "Extracting ${KERNEL_TARBALL}"
    tar -xf "$KERNEL_TARBALL" -C "$ROOT_DIR"
fi

if [ -f "$LOGO_SOURCE" ]; then
    echo "Generating kernel logo from ${LOGO_SOURCE}"
    LOGO_SOURCE="$LOGO_SOURCE" KERNEL_SRC_DIR="$KERNEL_SRC_DIR" python3 - <<'PY'
import os
from pathlib import Path

try:
    from PIL import Image, ImageOps
except Exception as exc:
    raise SystemExit(f"Pillow not available: {exc}")

src = Path(os.environ["LOGO_SOURCE"])
dst = Path(os.environ["KERNEL_SRC_DIR"]) / "drivers/video/logo/logo_linux_clut224.ppm"

img = Image.open(src).convert("RGBA")
canvas = Image.new("RGBA", (80, 80), (0, 0, 0, 255))
resized = ImageOps.contain(img, (80, 80), Image.Resampling.LANCZOS)
offset = ((80 - resized.width) // 2, (80 - resized.height) // 2)
canvas.paste(resized, offset, resized)

rgb = canvas.convert("RGB")
quant = rgb.quantize(colors=224, method=Image.Quantize.MEDIANCUT)
final = quant.convert("RGB")

dst.parent.mkdir(parents=True, exist_ok=True)
with dst.open("w", encoding="ascii") as handle:
    handle.write("P3\n80 80\n255\n")
    for y in range(80):
        row = []
        for x in range(80):
            r, g, b = final.getpixel((x, y))
            row.append(f"{r} {g} {b}")
        handle.write(" ".join(row) + "\n")

print(f"Wrote {dst}")
PY
else
    echo "Logo source not found: ${LOGO_SOURCE} (using default kernel logo)"
fi

echo "Copying kernel config"
cp "$CONFIG_FILE" "${KERNEL_SRC_DIR}/.config"

echo "Building kernel ${KERNEL_VERSION}"
make -j20 -C "$KERNEL_SRC_DIR" ARCH="$ARCH" olddefconfig
make -j20 -C "$KERNEL_SRC_DIR" ARCH="$ARCH" -j"$JOBS" bzImage modules

echo "Installing modules"
rm -rf "$OUTPUT_MODULES"
make -j20 -C "$KERNEL_SRC_DIR" ARCH="$ARCH" modules_install INSTALL_MOD_PATH="$OUTPUT_MODULES"

mkdir -p "$ARCHIVE_DIR"
echo "Archiving kernel image"
tar -C "${KERNEL_SRC_DIR}/arch/x86/boot" -czf "$KERNEL_ARCHIVE" bzImage

echo "Archiving kernel modules"
tar -C "$OUTPUT_MODULES" -czf "$MODULES_ARCHIVE" lib/modules

echo "Done"
echo "Kernel image: ${KERNEL_SRC_DIR}/arch/x86/boot/bzImage"
echo "Modules: ${OUTPUT_MODULES}/lib/modules"
echo "Kernel archive: ${KERNEL_ARCHIVE}"
echo "Modules archive: ${MODULES_ARCHIVE}"
