# config

## OVERVIEW

Documentation for persistent configuration partition layout. Runtime configuration data lives on `/mnt/config`, not in this directory.

## WHERE TO LOOK

| Task | Location | Notes |
|------|----------|-------|
| Partition layout | `sysconfig` | documents `/mnt/config` contract |
| Default schema | `../rootfs/opt/coyote/defaults/system.json` | firmware default source |
| Load/save flow | `../rootfs/opt/coyote/lib/Coyote/Config/ConfigManager.php` | persistent/running paths |
| Web apply flow | `../rootfs/opt/coyote/webadmin/src/Service/ApplyService.php` | confirm/rollback |

## CONVENTIONS

- `/mnt/config/system.json` is the main persistent system configuration.
- `/mnt/config/webadmin-users.json` stores webadmin credentials.
- `/mnt/config/backups/*.json` stores configuration backups.
- Normal operation keeps the config partition read-only and remounts writable only around saves.
- Working edits are staged in `/tmp/working-config`; active runtime state is copied to `/tmp/running-config`.
- Firmware/runtime code reads the JSON schema from `rootfs/opt/coyote/defaults/system.json` when no persistent config exists.

## ANTI-PATTERNS

- Do not add files to this docs directory expecting runtime code to load them.
- Do not make persistent writes without restoring `/mnt/config` read-only.
- Do not document config fields here without also checking defaults, validators, UI, and tests.

## COMMANDS

```bash
python -m json.tool ../rootfs/opt/coyote/defaults/system.json >/dev/null
php -l ../rootfs/opt/coyote/lib/Coyote/Config/ConfigManager.php
```
