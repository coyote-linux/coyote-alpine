# coyote-alpine

## OVERVIEW

Main Coyote Linux 4 repository: Alpine firmware image, initramfs, installer, rootfs overlay, PHP runtime, web admin, tests.

## STRUCTURE

```
coyote-alpine/
├── build/                 # Make targets and image builders
├── initramfs/             # normal boot PID 1 and recovery
├── initramfs-installer/   # installer-mode PID 1
├── installer/             # install/upgrade UI and TUI assets
├── kernel/                # kernel build inputs and generated/extracted outputs
├── rootfs/                # firmware root filesystem source overlay
└── tests/                 # PHPUnit suites
```

## WHERE TO LOOK

| Task | Location | Notes |
|------|----------|-------|
| Configure/build image | `build/` | `make` must be run here |
| Firmware boot issues | `initramfs/` | numbered shell stages are sourced by PID 1 |
| Installer boot issues | `initramfs-installer/` | separate from normal initramfs |
| Disk install flow | `installer/install.sh` | dialog TUI, firmware signature warning |
| Runtime services | `rootfs/etc/init.d/` | OpenRC scripts |
| Runtime PHP | `rootfs/opt/coyote/` | libraries, webadmin, templates, TUI |
| Config schema | `rootfs/opt/coyote/defaults/system.json` | persistent schema defaults |
| Unit/integration tests | `tests/` | PHPUnit config and bootstrap |

## CONVENTIONS

- Source overlay paths under `rootfs/` become absolute runtime paths in firmware.
- Build outputs live under `output/`; never patch generated `output/rootfs` instead of `rootfs`.
- Build config lives in `build/.config`; local signing/mirror overrides in `build/.local-config`.
- Ed25519 signature sidecars and SHA256 sidecars travel with firmware/kernel/initramfs artifacts.
- Feature toggles are embedded into `/etc/coyote/features.json` during `make firmware`.

## ANTI-PATTERNS

- Do not edit `output/`, `.cache/`, `kernel/output/`, or extracted `kernel/linux-*` trees as source.
- Do not assume Composer exists; PHP autoloading is custom.
- Do not treat README output sizes as invariant; public release artifacts may be larger.
- Do not treat `docs/webadmin-implementation-plan.md` as current status; changelog/code may be newer.

## COMMANDS

```bash
cd build
make lint
make test-boot
phpunit -c ../tests/phpunit.xml
```
