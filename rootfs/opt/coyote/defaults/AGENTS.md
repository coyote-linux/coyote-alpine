# defaults

## OVERVIEW

Canonical source defaults for Coyote configuration. These defaults shape first boot, missing-config fallback, tests, and webadmin/TUI assumptions.

## WHERE TO LOOK

| Task | Location | Notes |
|------|----------|-------|
| Main schema/defaults | `system.json` | source for `/opt/coyote/defaults/system.json` |
| Loader fallback | `../lib/Coyote/Config/ConfigManager.php` | falls back when `/mnt/config/system.json` is absent |
| Webadmin config defaults | `../webadmin/src/Service/ConfigService.php` | working/running/persistent copies |
| Validation | `../lib/Coyote/Config/ConfigValidator.php` | validates only selected sections |

## CONVENTIONS

- JSON indentation is 4 spaces.
- Top-level sections currently include `version`, `system`, `users`, `network`, `services`, `firewall`, `vpn`, `loadbalancer`.
- `services` contains `ssh`, `dhcpd`, `dns`, `snmp`, `upnp`, `syslog`, `ntp`.
- `firewall` contains nftables-era structures: `sets`, `acls`, `applied`, `nat`, `rules`, `port_forwards`.

## ANTI-PATTERNS

- Do not add required schema fields without updating validators, webadmin forms, TUI, and tests.
- Do not assume `vpn` or `loadbalancer` defaults are fully validated by `ConfigValidator`.
- Do not store secrets in defaults.

## COMMANDS

```bash
python -m json.tool system.json >/dev/null
php -l ../lib/Coyote/Config/ConfigValidator.php
```
