# initramfs

## OVERVIEW

Normal firmware boot environment. `init` runs as PID 1, validates firmware/update state, mounts squashfs, prepares tmpfs overlays, and switches to the firmware root.

## STRUCTURE

```
initramfs/
├── init          # PID 1 entry
├── init.d/       # numbered boot stages sourced in order
├── recovery/     # recovery menu, rollback, config edit
└── bin/          # initramfs utilities such as verify-signature
```

## WHERE TO LOOK

| Task | Location | Notes |
|------|----------|-------|
| Boot entry | `init` | never let PID 1 exit |
| Boot media detection | `init.d/02-detect-boot-media.sh` | disk preferred unless installer param/marker |
| Firmware/update validation | `init.d/03-check-firmware.sh` | hash/signature/staged update logic |
| Recovery prompt | `init.d/04-recovery-prompt.sh` | launches recovery menu |
| Firmware mount | `init.d/05-mount-firmware.sh` | squashfs current/previous/backup |
| Writable runtime setup | `init.d/06-setup-tmpfs.sh` | `/tmp`, `/var`, `/run`, `/etc` overlay |
| Final switch | `init.d/07-pivot-root.sh` | prepares `switch_root` |

## CONVENTIONS

- Scripts are POSIX `#!/bin/sh` and sourced, so globals and return codes matter.
- Numbered `NN-name.sh` ordering is the boot contract.
- `log`, `warn`, `error`, `BOOT_MEDIA`, `FIRMWARE_PATH`, `CONFIG_PATH`, `NEWROOT` come from `init` and earlier stages.
- Signature checks use `/bin/verify-signature`; `nosigcheck` is developer-only.

## ANTI-PATTERNS

- Do not add `set -e` or unguarded exits to PID 1 paths.
- Do not make recovery depend on mounted firmware root.
- Do not remove backup/previous firmware fallback paths.
- Do not write persistent state unless the relevant partition is intentionally remounted writable and restored read-only.

## COMMANDS

```bash
shellcheck init init.d/*.sh recovery/*.sh bin/*
cd ../build && make initramfs
```
