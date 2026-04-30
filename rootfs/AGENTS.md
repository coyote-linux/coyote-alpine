# rootfs

## OVERVIEW

Source overlay for the immutable firmware filesystem. Paths here become runtime absolute paths after `make rootfs`/`make firmware`.

## STRUCTURE

```
rootfs/
├── etc/          # OpenRC, lighttpd, doas, cron, system config snippets
├── opt/coyote/   # Coyote PHP/libs/webadmin/bin/templates/defaults
├── sbin/         # installer init hook
└── var/          # seed runtime/state directories
```

## WHERE TO LOOK

| Task | Location | Notes |
|------|----------|-------|
| Services/privilege/web server | `etc/` | OpenRC, doas, lighttpd |
| Coyote runtime app | `opt/coyote/` | PHP + shell runtime |
| Installer runtime init | `sbin/installer-init` | source overlay counterpart |
| Firewall seed state | `var/lib/coyote/firewall/` | state directory seed only |

## CONVENTIONS

- Edit source files here, not `../output/rootfs`.
- Root filesystem is read-only at runtime; mutable paths must be tmpfs or `/mnt/config`.
- Generated runtime configs should come from templates/services, not manual edits under output.
- OpenRC runlevel link farms under `etc/runlevels/` are generated/derived; service scripts live in `etc/init.d/`.

## ANTI-PATTERNS

- Do not introduce runtime writes to the squashfs base.
- Do not add privileged webadmin actions without updating `etc/doas.d/coyote.conf` deliberately.
- Do not assume `/var` survives reboot unless backed by `/mnt/config` syncing logic.

## COMMANDS

```bash
php -l opt/coyote/path/to/file.php
shellcheck etc/init.d/service opt/coyote/bin/script
cd ../build && make rootfs && make firmware NO_BUMP=1
```
