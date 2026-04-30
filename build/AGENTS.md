# build

## OVERVIEW

Rootless build orchestration for rootfs, firmware squashfs, initramfs, ISO, USB installer, update archive, signing, lint, and QEMU boot tests.

## WHERE TO LOOK

| Task | Location | Notes |
|------|----------|-------|
| Target list/versioning | `Makefile` | canonical command surface |
| Wrapper without version bump | `build.sh` | `-n` maps to `NO_BUMP=1` |
| Interactive config | `menuconfig.sh` | writes `.config` |
| Package list | `apk-packages.txt` | rootfs dependency source |
| Rootfs assembly | `mkrootfs.sh` | uses `apk --usermode`, creates runlevel links |
| Firmware image | `mksquashfs.sh` | squashfs wrapper |
| Update archive | `mkupdate.sh` | firmware/kernel/initramfs bundle |
| ISO/USB media | `mkiso.sh`, `mkinstaller.sh` | copy sidecars when present |

## CONVENTIONS

- Run build targets from `coyote-alpine/build/` only.
- `make firmware` increments `.build-number`; use `NO_BUMP=1` or `./build.sh -n` for rebuilds without bumping.
- `.local-config` is local-only; `.local-config.example` documents mirror/signing variables.
- `mkrootfs.sh` is source of truth for generated OpenRC runlevel symlinks.
- Build produces artifacts under `../output/`; do not edit them as source.

## ANTI-PATTERNS

- Do not add root-only build steps; the design expects rootless builds.
- Do not hardcode local signing keys or mirrors into tracked scripts.
- Do not update `rootfs/etc/runlevels/*` without also checking `mkrootfs.sh` generation.
- Do not rely on `make lint` exit status alone; inspect shellcheck/php output.

## COMMANDS

```bash
make help
make show-config
make rootfs
make firmware NO_BUMP=1
make initramfs
make iso
make installer
make update-archive
make sign
make lint
make test-boot
```
