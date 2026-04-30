# rootfs/etc

## OVERVIEW

Runtime system configuration overlay: OpenRC services, lighttpd config, doas privilege rules, cron jobs, sysctl/profile snippets, and generated runlevel links.

## WHERE TO LOOK

| Task | Location | Notes |
|------|----------|-------|
| OpenRC services | `init.d/` | `root`, `coyote-*`, `dropbear` |
| Boot membership | `runlevels/` | generated link farm; not source of truth |
| Web server | `lighttpd/` | serves `/opt/coyote/webadmin/public` |
| Privilege surface | `doas.d/coyote.conf` | lighttpd allowlist |
| Scheduled jobs | `periodic/daily/` | ACME renewal |

## CONVENTIONS

- OpenRC scripts use `#!/sbin/openrc-run`, `depend()`, and `start/stop/reload` style.
- `root` service intentionally replaces Alpine root remount behavior for read-only squashfs.
- `coyote-config` prepares `/tmp/working-config`, `/tmp/running-config`, and `/mnt/config` subdirs.
- `coyote-firewall` owns firewall state dir permissions for webadmin applies.

## ANTI-PATTERNS

- Do not edit `runlevels/*` directly without checking `build/mkrootfs.sh` generation.
- Do not broaden doas rules for lighttpd without a concrete caller and rollback story.
- Do not make service startup depend on writable rootfs.
- Do not use bashisms in OpenRC scripts.

## COMMANDS

```bash
shellcheck init.d/* periodic/daily/*
```
