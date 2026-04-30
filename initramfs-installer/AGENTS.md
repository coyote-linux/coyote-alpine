# initramfs-installer

## OVERVIEW

Installer-mode initramfs. Separate PID 1 path for finding installer media, mounting installer squashfs with overlay, and switching into installer runtime.

## WHERE TO LOOK

| Task | Location | Notes |
|------|----------|-------|
| Installer PID 1 | `init` | sources numbered installer stages |
| Basic mounts | `init.d/01-mount-basics.sh` | `/proc`, `/sys`, `/dev`, `/tmp` |
| Media scan | `init.d/02-detect-media.sh` | detects boot/install media |
| Installer root | `init.d/03-find-installer.sh` | verifies firmware, mounts squashfs, overlay fallback |

## CONVENTIONS

- Keep this tree independent from normal `initramfs/`; installer media may boot with fewer assumptions.
- Firmware signature verification is attempted before mounting installer firmware.
- Overlay failure falls back to read-only squashfs instead of aborting immediately.
- Build through `build/mkinitramfs-installer.sh`, not by packing files manually.

## ANTI-PATTERNS

- Do not share mutable globals with normal initramfs unless explicitly exported by this tree.
- Do not assume OpenRC is available before `switch_root`.
- Do not make unsigned firmware look valid; warn distinctly when signature metadata is absent.

## COMMANDS

```bash
shellcheck init init.d/*.sh
cd ../build && make initramfs-installer
```
