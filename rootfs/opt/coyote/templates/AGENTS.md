# templates

## OVERVIEW

Source templates for runtime-generated service configs. Generated outputs are not source; edit templates or the generator classes instead.

## WHERE TO LOOK

| Template | Runtime target / generator |
|----------|----------------------------|
| `haproxy.cfg.tpl` | `/etc/haproxy/haproxy.cfg` via `LoadBalancer/HaproxyService.php` |
| `swanctl.conf.tpl` | `/etc/swanctl/swanctl.conf` via `Vpn/StrongSwanService.php` |
| `lighttpd.conf.tpl` | `/etc/lighttpd/lighttpd.conf` paths used by webadmin/cert code |
| `nftables.rules.tpl` | reference template; active nftables generator is `Firewall/RulesetBuilder.php` |
| `iptables.rules.tpl` | legacy/reference firewall template |
| `dhcpcd.conf.tpl`, `dnsmasq.conf.tpl` | source templates; writer may live outside current in-scope classes |

## CONVENTIONS

- Generated-file headers such as “do not edit manually” refer to runtime outputs, not source templates.
- Keep placeholders aligned with the service class that renders the template.
- nftables behavior is primarily PHP-generated; update `RulesetBuilder.php` for actual firewall rules.

## ANTI-PATTERNS

- Do not edit `/etc/haproxy/haproxy.cfg`, `/etc/swanctl/swanctl.conf`, or firewall output directly as a persistent fix.
- Do not resurrect iptables behavior without checking nftables migration history and tests.
- Do not add template variables without updating the rendering service and syntax checks.

## COMMANDS

```bash
php -l ../lib/Coyote/LoadBalancer/HaproxyService.php
php -l ../lib/Coyote/Vpn/StrongSwanService.php
php -l ../lib/Coyote/Firewall/RulesetBuilder.php
```
