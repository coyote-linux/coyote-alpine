# installer

## OVERVIEW

Installer runtime and install/upgrade UI. Handles target disk prep, firmware verification, config preservation, network setup, and first-boot handoff.

## STRUCTURE

```
installer/
├── install.sh          # main dialog installer
├── installer-init      # OpenRC-bypass installer launcher
├── tty1-handler.sh     # console dispatcher
└── tui/                # PHP TUI installer/menu support
```

## WHERE TO LOOK

| Task | Location | Notes |
|------|----------|-------|
| Main install flow | `install.sh` | disk selection, partitioning, firmware copy, upgrade |
| Minimal runtime init | `installer-init` | mounts tmpfs and launches installer |
| Console launch | `tty1-handler.sh` | chooses installer/getty path |
| PHP TUI | `tui/installer-menu.php` | alternate menu workflow |
| Build packaging | `../build/mkinstaller.sh`, `../build/mkiso.sh` | media assembly and sidecars |

## CONVENTIONS

- `install.sh` uses POSIX shell plus `dialog`; maintain dialog return-code handling.
- Firmware source normally comes from mounted boot media under `firmware/current.squashfs`.
- Invalid signatures warn loudly and require explicit user confirmation to continue.
- Upgrade flow preserves config and stores previous firmware for rollback.

## ANTI-PATTERNS

- Do not silently continue after invalid firmware signatures.
- Do not shrink boot/config partition minimums without checking `build/Makefile` and README expectations.
- Do not assume the installer is running under full OpenRC.
- Do not forget sidecar files (`.sha256`, `.sig`) when changing copy/upgrade paths.

## COMMANDS

```bash
shellcheck *.sh installer-init init coyote-installer.init tty1-handler.sh
php -l tui/installer-menu.php
cd ../build && make iso && make installer
```
