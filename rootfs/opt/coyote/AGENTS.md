# rootfs/opt/coyote

## OVERVIEW

Coyote runtime application tree: CLI helpers, default config schema, PHP libraries, generated-config templates, console TUI, and web administration UI.

## STRUCTURE

```
opt/coyote/
├── bin/        # runtime CLI/helpers, some PHP shebang scripts
├── defaults/   # source defaults for config schema
├── lib/        # `Coyote\*` PHP libraries
├── templates/  # source templates for generated runtime configs
├── tui/        # console PHP TUI
└── webadmin/   # lighttpd-served PHP app
```

## WHERE TO LOOK

| Task | Location | Notes |
|------|----------|-------|
| Config lifecycle | `lib/Coyote/Config/`, `webadmin/src/Service/ConfigService.php` | persistent/running/working config |
| Apply/rollback | `webadmin/src/Service/ApplyService.php`, `bin/coyote-apply-helper` | 60s safety flow |
| Firewall | `lib/Coyote/Firewall/`, `bin/firewall-apply` | nftables and rollback |
| VPN | `lib/Coyote/Vpn/` | StrongSwan/OpenVPN/WireGuard |
| Load balancer | `lib/Coyote/LoadBalancer/` | HAProxy generation/control |
| Firmware updates | `lib/Coyote/System/FirmwareManager.php`, `bin/firmware-update` | staging and sidecars |

## CONVENTIONS

- PHP namespaces under `lib/Coyote/` map through `lib/autoload.php`; webadmin has its own bootstrap.
- Key config paths: `/mnt/config/system.json`, `/tmp/running-config/system.json`, `/tmp/working-config/system.json`.
- Rollback state lives at `/tmp/coyote-apply-state.json`.
- Config defaults are source; generated runtime configs target `/etc/*`, `/var/lib/coyote/*`, or `/mnt/config/*`.
- Add-on support is schema-level here; no dedicated add-on runtime package was found.

## ANTI-PATTERNS

- Do not edit generated runtime configs instead of source templates/classes.
- Do not swallow command or config errors; propagate failures with clear messages for webadmin/TUI callers.
- Do not call privileged commands directly from webadmin if `coyote-apply-helper` or `PrivilegedExecutor` is the intended path.
- Do not bypass config validation before persistence.

## COMMANDS

```bash
php -l lib/Coyote/Config/ConfigManager.php
php -l webadmin/src/App.php
shellcheck bin/coyote-apply-helper
```
