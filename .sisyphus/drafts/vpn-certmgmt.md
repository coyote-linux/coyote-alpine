# Draft: VPN Support & Certificate Management for Coyote Linux 4

## Requirements (confirmed)
- Complete StrongSwan IPSec (existing stub → full CRUD, status, connect/disconnect)
- Add OpenVPN (server + client modes, TUN/TAP, PSK + cert auth)
- Add WireGuard (multi-interface, peer CRUD, key generation)
- Certificate Management (upload, view, delete, PKCS12 import, cert selection dropdowns)
- ACME/Let's Encrypt (uacme-based, HTTP-01 challenge, auto-renewal via cron)

## Codebase Research Findings

### Existing VPN Code (StrongSwan)
- **VpnManager.php** (201 lines): Orchestrates IPSec only. Has `applyConfig()`, `getStatus()`, `getTunnelStatus()`, `connectTunnel()`, `disconnectTunnel()`, `addTunnel()`, `removeTunnel()`. Only handles `$config['ipsec']`.
- **StrongSwanService.php** (282 lines): Full service mgmt. `start()`, `stop()`, `restart()`, `reload()`, `isRunning()`, `applyConfig()`, `getStatus()`, `getTunnelStatus()`, `initiateConnection()`, `terminateConnection()`. Uses `rc-service strongswan` and `swanctl` CLI. Config at `/etc/swanctl/`.
- **IpsecTunnel.php** (305 lines): Fluent config builder. Supports PSK auth, cert auth placeholders, traffic selectors, DPD, proposals, rekey, lifetime. Has `validate()` method.
- **SwanctlConfig.php** (266 lines): Generates swanctl.conf text. Handles connections, secrets (PSK + `private_key` stub), pools. Has `validate()`.
- **VpnController.php** (51 lines): STUB. Only shows status badge. `saveTunnels()` flashes "not yet implemented".
- **vpn.php template** (34 lines): Placeholder with onclick alerts.

### Subsystem Pattern
- **SubsystemInterface**: `getName()`, `requiresCountdown()`, `getConfigKeys()`, `hasChanges()`, `apply()`
- **AbstractSubsystem**: `getNestedValue()`, `valuesChanged()`, `exec()`, `success()`, `failure()`, `getPrivilegedExecutor()`
- **SubsystemManager**: Registers subsystems in order. Current: Hostname → Timezone → Dns → Network → Firewall. VPN needs to be registered AFTER Firewall.
- **FirewallSubsystem**: Good reference — handles multiple sub-services (nftables, UPnP, QoS), error accumulation pattern, `rollback()`, `emergencyDisable()`.

### Controller/Template Pattern
- **BaseController**: `render()`, `json()`, `redirect()`, `flash()`, `getPostData()`, `post()`, `query()`, `isAjax()`
- **LoadBalancerController**: Full CRUD example — `newFrontend()`, `editFrontend()`, `saveFrontend()`, `deleteFrontend()`. Uses `ConfigService` for working-config get/set. Validates input. Flash + redirect.
- Templates extract `$data` vars. Forms POST to same URL. Delete via POST `{entity}/{name}/delete`.

### Config Structure
- `system.json` has `vpn.ipsec.{enabled, tunnels}` section already
- Need to add: `vpn.openvpn`, `vpn.wireguard`, `certificates` sections
- Config flow: working-config → apply → countdown → confirm/rollback → persistent

### Privileged Execution
- **PrivilegedExecutor**: Calls `coyote-apply-helper` via doas.
- **coyote-apply-helper**: Shell script with allowlisted commands. Currently supports: write-file (allowlisted paths only), ip, sysctl, mount-rw/mount-ro, rc-service (via doas directly in PHP).
- **Key finding**: StrongSwanService.php calls `rc-service` directly without PrivilegedExecutor. Same pattern should work for openvpn, wireguard.
- **Certificate file writes**: Need to go to `/mnt/config/certificates/` — requires mount-rw → write → mount-ro cycle. Apply-helper allowlist needs `/etc/swanctl/*`, `/etc/openvpn/*`.

### SSL Cert Current State
- LB frontend edit: `<input type="text" name="ssl_cert" ...>` — raw path input
- HaproxyService: `bind *:443 ssl crt {path}`
- FrontendConfig: `sslCert(string $certPath)`
- Web admin self-signed cert at `/mnt/config/ssl/server.pem`

### Navigation
- Sidebar in `main.php` has flat list: Dashboard, Network, Firewall, NAT, VPN, Load Balancer, Services, System, Firmware
- Need to add "Certificates" entry

### Routes (VPN current)
- GET /vpn → VpnController::index
- GET /vpn/tunnels → VpnController::tunnels
- POST /vpn/tunnels → VpnController::saveTunnels

## Technical Decisions
- Certificate store location: `/mnt/config/certificates/` with subdirs (ca/, server/, client/, private/)
- ACME client: uacme (smallest footprint for firmware/embedded)
- VPN subsystem: Single VpnSubsystem handling all 3 VPN types, `requiresCountdown()` → true (VPN changes could break connectivity)
- VpnManager: Extend to orchestrate StrongSwan + OpenVPN + WireGuard services
- Config keys: `vpn.ipsec.*`, `vpn.openvpn.*`, `vpn.wireguard.*`
- File upload: PHP `$_FILES` → validate → mount-rw → copy to `/mnt/config/certificates/` → mount-ro

## Decisions Made (from interview)
- **Test strategy**: No automated tests. Agent-executed QA scenarios only.
- **VPN page organization**: Tabbed overview — single VPN page with tabs for Overview | IPSec | OpenVPN | WireGuard
- **OpenVPN mode**: Multiple named instances — each can be server or client independently (OpenRC `openvpn.<name>` instance syntax)
- **IPSec scope**: Site-to-site only — remote access / roadwarrior excluded
- **WireGuard naming**: Interface names will be user-defined (wg0, wg1, etc. as defaults suggested)

## Scope Boundaries
- INCLUDE: StrongSwan site-to-site completion, OpenVPN (multi-instance server+client), WireGuard (multi-interface), Certificate Management (full lifecycle), ACME/Let's Encrypt (uacme), firewall integration for all VPN types
- EXCLUDE: PPTP/L2TP (legacy), IPSec remote access server mode, OpenVPN client provisioning/downloadable configs, automated unit tests
