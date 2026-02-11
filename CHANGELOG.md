# Changelog

All notable changes to Coyote Linux 4 are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [4.0.150] - 2026-02-11

### Fixed

#### Web Admin Text Readability and Form Spacing
- Increased default content line-height so wrapped text is easier to read and no longer appears vertically cramped
- Increased label, muted text, and helper text line-height for better spacing in stacked form content
- Added extra vertical spacing between inline checkbox rows and the following form controls (for example, between "Add X-Forwarded-Proto header" and "Max Connections")

## [4.0.149] - 2026-02-10

### Added

#### DHCP Server Management
- New DHCP controller and template pages for managing DHCP server settings
- DHCP static reservation management with add/edit/delete support
- `DhcpSubsystem` for applying DHCP configuration changes via dnsmasq

#### Static Route Management
- Network routes page at `/network/routes` for editing IPv4 and IPv6 static routes
- Routes displayed from configured (working) config rather than active system state
- Edit Routes link added to the network overview Routing Table section header

#### Remote Syslog Configuration
- Remote syslog server settings (host, port, protocol) on the system page
- `SyslogSubsystem` for applying syslog configuration changes

#### NTP Time Synchronization Configuration
- NTP server settings (enable/disable, server list) on the system page
- `NtpSubsystem` for applying NTP configuration changes via chrony

#### Apply Configuration Page
- Dedicated Apply Configuration page with its own menu entry
- Highlighted menu item when uncommitted changes are pending

#### Firmware Update Management
- Check for latest release from GitHub releases API
- Download firmware update archives with progress feedback
- Upload firmware archives manually
- Stage, verify, and apply firmware updates via reboot
- `FirmwareService` encapsulating update check, download, upload, staging, and apply logic

### Changed

#### System Page Reorganization
- Split the Basic Settings card into three focused cards: Basic Settings, Remote Syslog, and Time Synchronization
- Checkbox fields for syslog and NTP use inline layout so the checkbox and label sit on the same line
- Separate POST endpoints for syslog (`/system/syslog`) and NTP (`/system/ntp`) settings

#### SSL Certificate Management Moved to Certificates Page
- SSL certificate assignment card moved from the system page to the certificates page at `/certificates#ssl-certificate`
- `saveSslCertificate` and all supporting helpers relocated from `SystemController` to `CertificateController`
- ACME table "Assign to service" link now points to `/certificates#ssl-certificate`

#### Configuration Subsystem Integration
- `SubsystemManager` now registers DHCP, NTP, and Syslog subsystems
- `NetworkSubsystem` extended with static route apply logic
- `PrivilegedExecutor` extended with service restart commands for dnsmasq, chronyd, and syslog
- `coyote-apply-helper` extended with corresponding privileged operations

### Fixed

#### UI Visual Improvements
- Alert boxes now have sufficient padding for nested lists; bullet markers no longer overlap the container border
- Buttons rendered side-by-side or stacked now have consistent spacing between them via inline-form margin rules

## [4.0.146] - 2026-02-09

### Changed

#### Custom Kernel Upgrade to 6.19.0
- Upgraded custom kernel from 6.18.8 to 6.19.0
- Updated `kernel/build-kernel.sh` default version to 6.19.0
- Updated `build/mkinitramfs.sh` with explicit `KERNEL_VERSION` variable and 6.19.0 fallback defaults
- Regenerated `kernel/configs/coyote-x86_64.defconfig` for Linux 6.19.0

#### Kernel Configuration Refinements
- Enabled additional NIC drivers for broader hardware support (3COM, AMD, Atheros, Cisco, DEC/Tulip, D-Link, HiSilicon, Marvell, nVidia, Packet Engines, Pensando, QLogic, Brocade, Qualcomm, Samsung, Silan, SIS, SMSC, VIA, Wiznet, Xilinx)
- Enabled Amazon ENA driver for AWS cloud deployments
- Disabled embedded/mobile hardware drivers not relevant to firewall appliance (regulators, IR/RC, PWM, battery/charger, gameport, speakup, I2C HID, Intel ISH/THC, AMD SFH, STM/Intel TH tracing)
- Disabled CHECKPOINT_RESTORE and PROFILING for reduced attack surface
- New IOMMU page table infrastructure (generic PT, AMDV1, VTDSS, X86_64)
- POLYVAL crypto moved from module to crypto library with arch-optimized implementation

## [4.0.143] - 2026-02-08

### Added

#### Menuconfig Build System
- Added `make menuconfig` with interactive build configuration in `build/menuconfig.sh`
- Added persistent build config file support via `build/.config` and `make show-config`
- Added configurable options for kernel type, development mode, firmware size limit, boot partition size, and optional feature toggles

#### Build-Time Feature Metadata
- Firmware builds now embed `/etc/coyote/build-config` and `/etc/coyote/features.json`
- Web admin now consumes feature metadata to hide disabled VPN and Load Balancer navigation/routes/views

### Changed

#### Build and Installer Integration
- Rootfs package selection now honors menuconfig feature toggles (DotNet, HAProxy, IPSec, OpenVPN, WireGuard)
- Kernel/initramfs build flow now supports both custom kernel and Alpine LTS kernel paths
- New installs now use configurable boot partition sizing from build config instead of fixed 4GB
- Syslinux boot menu handling now consistently uses `menu.c32` in install/upgrade paths

### Fixed

#### OpenVPN PKI Initialization
- Easy-RSA execution now passes batch/PKI/CN options as explicit CLI arguments instead of relying on environment propagation through `doas`
- Fixed `/vpn/openvpn/pki` initialization failures caused by missing Easy-RSA runtime options

#### Menuconfig Feature Toggle Persistence
- Fixed checklist parsing so selected additional options are saved reliably and no longer default to disabled unexpectedly

## [4.0.136] - 2026-02-07

### Added

#### Web Admin Utilities
- Tools section added to sidebar navigation (above Logout)
- IP Subnet Calculator supporting both IPv4 and IPv6 with full client-side computation
- Password Generator with complex, readable, and pronounceable modes using cryptographic randomness
- Tools hub page at `/tools` linking to individual utilities

#### Privileged Certificate Store Initialization
- `init-cert-store` command added to `coyote-apply-helper` for creating the certificate directory tree as root
- Certificate store directories are now owned by `lighttpd` so the web server can write certificates after initialization

### Fixed

#### First-Login Password Change Flow
- Password change enforcement now reads running config instead of persistent storage, so the gate opens immediately after setting a password
- Password changes are persisted to disk via the existing `ConfigService` pipeline instead of a custom write path
- System page now hides all cards except the password form when a password change is required, with a clear warning banner
- Password card hides the "Current Password" field on first-login since no password exists yet
- Removed stale working-config diff after password change so no spurious "Pending Changes" banner appears

#### Certificate Store Permissions
- Certificate store initialization now uses `PrivilegedExecutor` instead of direct `mkdir` calls that failed with permission denied
- Certificate store remount operations now use `PrivilegedExecutor` for consistent privilege escalation

#### System Reboot and Shutdown
- Reboot and shutdown commands now execute correctly; previously `redirect()` called `exit` which prevented the privileged helper from running

## [4.0.131] - 2026-02-07

### Added

#### Build Mode Control
- Added `DEV_BUILD` build parameter in `build/Makefile` (`DEV_BUILD=1` for development builds)
- Firmware builds now embed `/etc/coyote/build-mode` as `release` or `development`
- Web admin bootstrap now exposes `COYOTE_BUILD_MODE` and `COYOTE_DEV_BUILD` constants

#### CSRF Protection
- Added `Csrf` service with per-session token generation and validation
- Added automatic CSRF hidden token injection into POST forms rendered by `BaseController`
- Added support for `X-CSRF-Token` validation for AJAX/fetch requests
- Added CSRF token meta tag for frontend JavaScript integration

#### API Completeness
- Added `FirewallApi` implementation for registered firewall status/rules API routes

### Changed

#### Debug Route Lockdown
- Debug routes are now registered only in development builds
- Release builds no longer expose `/debug/*` endpoints

#### Password Change Enforcement
- Non-development builds now enforce first-login password change before normal admin navigation
- Password change now requires current password whenever an admin password hash already exists
- Updated system password form messaging to reflect required current password behavior

#### Session and Cookie Security
- Enabled strict PHP session settings (`use_strict_mode`, `use_only_cookies`, `HttpOnly`, `Secure`, `SameSite=Strict`)
- Added explicit session cookie invalidation on logout
- Applied configured session timeout to the auth service at app startup

#### Request Hardening
- Added CSRF enforcement for authenticated non-public state-changing requests
- Converted logout from GET to POST-only endpoint
- Added response hardening headers (`X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, CSP frame-ancestors)
- Added HSTS header in non-development builds

#### HTTPS-only Web Admin
- Disabled plain HTTP listener in runtime lighttpd config
- Updated lighttpd template to generate HTTPS-only web admin configuration

## [4.0.130] - 2026-02-07

### Added

#### Certificate Management System
- Certificate store at `/mnt/config/certificates/` with subdirectories for CA, server, client certs and private keys
- `CertificateStore` class with persistent index, atomic writes, and read-only remount safety
- `CertificateInfo` class for X.509 parsing (subject, issuer, SANs, expiry, fingerprint, key type)
- `CertificateValidator` class for PEM validation, upload checks, key-pair matching, and PKCS#12 import
- Web admin Certificates section with upload (file or PEM paste), viewing, and deletion
- Certificate selection dropdowns on Load Balancer frontend SSL and Web Admin SSL replacement
- Sidebar navigation entry for Certificates between Services and System

#### ACME / Let's Encrypt Integration
- `AcmeService` class wrapping `uacme` for account registration, certificate issuance, and renewal
- HTTP-01 challenge support via lighttpd webroot alias at `/.well-known/acme-challenge/`
- ACME challenge hook script at `/opt/coyote/bin/acme-challenge-hook`
- Daily auto-renewal cron script at `/etc/periodic/daily/coyote-acme-renew`
- Web admin ACME management page for account registration, certificate requests, and renewal
- ACME-issued certificates automatically stored in the certificate store
- Added `uacme` package dependency

#### StrongSwan IPSec VPN (Web Admin Completion)
- Full tunnel CRUD from web admin (create, edit, delete IPSec tunnels)
- Pre-shared key and X.509 certificate authentication modes with certificate store integration
- Connect/disconnect tunnels via AJAX from the tunnel list
- Status monitoring with established/disconnected badges and byte counters
- `VpnSubsystem` registered in `SubsystemManager` for apply/rollback integration (no countdown)

#### OpenVPN Support
- `OpenVpnService` class for server and client mode config generation, service management, status parsing
- `OpenVpnInstance` data class with fluent builder for instance configuration
- `EasyRsaService` class wrapping Easy-RSA CLI for full PKI management from the web admin
- Web admin pages for instance CRUD, PKI initialization, server/client cert generation, and revocation
- Downloadable `.ovpn` client configuration files with embedded certificates
- Added `easy-rsa` package dependency

#### WireGuard Support
- `WireGuardService` class for interface/peer management, key generation, config generation, and status parsing
- `WireGuardInterface` and `WireGuardPeer` data classes with fluent builders
- Private keys stored persistently in `/mnt/config/certificates/private/`
- Web admin pages for interface CRUD, peer management, and downloadable peer configs
- Auto-generated key pairs on new interface creation

### Changed

#### VPN Architecture
- VPN overview page reorganized as hub linking to IPSec, OpenVPN, and WireGuard sections
- `VpnManager` now orchestrates StrongSwan, OpenVPN, and WireGuard services
- VPN subsystem config keys cover all three VPN types

#### Load Balancer Frontend SSL
- SSL certificate field changed from raw path text input to certificate store dropdown
- Backward compatibility preserved for existing frontends with raw filesystem paths

#### System Permissions
- Added `doas` rules for `swanctl`, `openvpn`, `easyrsa`, `wg`, `wg-quick`, and `uacme` commands

#### lighttpd Configuration
- Added ACME challenge directory alias for Let's Encrypt HTTP-01 validation

## [4.0.127] - 2026-02-05

### Added

#### Firewall Address Lists
- Address lists for IPv4 and IPv6 with ACL rule support
- Web admin pages to manage lists and import provider ranges (Cloudflare preset)

### Changed

#### Firewall ACLs
- ACL rules can reference address lists for source and destination
- Prevent deleting address lists that are in use by ACL rules

#### Console Boot Behavior
- Restored standard boot parameters and reduced console log verbosity

## [4.0.114] - 2026-02-04

### Added

#### .NET Runtime Support
- Added dotnet10-runtime and dotnet10-sdk packages to base system

### Changed

#### Web Admin CSS Consolidation
- Consolidated duplicated CSS styles from 12 template files into main stylesheet
- Removed ~730 lines of embedded `<style>` blocks from page templates
- Added organized style sections: form components, config sections, badges, help content, apply/countdown modal, network badges, firewall components, services page
- Templates now rely on centralized `/assets/css/style.css` for consistent styling

## [4.0.95] - 2026-02-03

### Added

#### Custom Kernel Build Support
- Custom kernel build script with cached kernel/module archives for faster rebuilds
- Makefile target for building the custom kernel in the build workflow

### Changed

#### Boot and Initramfs
- Installer and runtime initramfs now pull custom kernel modules for storage and NIC detection
- Custom kernel selected by default when kernel sources are present

#### Kernel Configuration
- Streamlined default kernel configuration for firewall appliance scope

## [4.0.64] - 2026-02-01

### Changed

#### Alpine Linux Update
- Updated base system to Alpine Linux 3.23.3

#### Firewall Backend Migration to nftables
- Replaced iptables-based firewall with nftables for modern packet filtering
- Atomic ruleset application prevents partial rule states during configuration changes
- Native nftables sets replace ipset for efficient address/port matching
- Unified IPv4/IPv6 handling via inet family tables
- Automatic rollback support - previous ruleset saved before applying new configuration

### Added

#### nftables Infrastructure
- `NftablesService.php` - Core nft command interface for ruleset operations
- `RulesetBuilder.php` - Generates complete .nft ruleset files from configuration
- `SetManager.php` - High-level nftables set management with live element add/remove
- `ServiceAclService.php` - Service-specific ACL rule generation (SSH, SNMP, DHCP, DNS, UPnP, web admin)
- `IcmpService.php` - Granular ICMP/ICMPv6 rule generation with rate limiting and presets
- `InterfaceResolver.php` - Maps interface roles (wan, lan, dmz), aliases, and groups to physical interfaces
- `AclBindingService.php` - Enhanced ACL binding with interface resolution, wildcards, and bidirectional support
- `NftNatService.php` - NAT service with masquerade, SNAT, DNAT, bypass rules, and source restrictions
- `LoggingService.php` - Configurable firewall logging with rate limiting, presets, and log groups
- `QosManager.php` - Traffic classification and packet marking for Quality of Service
- `UpnpService.php` - UPnP/IGD service management with miniupnpd and nftables integration
- `nftables.rules.tpl` - Reference template showing base ruleset structure
- Chain structure: input, forward, output, service chains (ssh-hosts, webadmin-hosts, snmp-hosts, icmp-rules, dhcp-server), UPnP chains (igd-forward, igd-input, igd-preroute)
- Convenience methods for dynamic host blocking/unblocking via sets

#### Enhanced Firewall Configuration Schema
- `firewall.options` - MSS clamping, invalid packet logging
- `firewall.logging` - Configurable logging with prefix and level
- `firewall.icmp` - Granular ICMP control with rate limiting, per-type allow/deny, IPv4/IPv6 separation, and presets (strict, permissive, server, gateway)
- `firewall.sets` - User-defined nftables sets for ACLs
- `firewall.nat.bypass` - NAT bypass rules for site-to-site traffic
- `firewall.nat.masquerade` - Structured masquerade configuration
- `services.ssh.allowed_hosts` - SSH access control via nftables set
- `services.snmp.allowed_hosts` - SNMP access control via nftables set
- `services.webadmin.allowed_hosts` - Web admin access control via nftables set
- `services.upnp` - UPnP/IGD service configuration
- `firewall.qos` - Quality of Service with traffic classification, packet marking, and tc integration

#### QoS (Quality of Service) Features
- Traffic classification via nftables mangle table with packet marking
- Default traffic classes: realtime (VoIP), interactive (SSH/DNS), default, bulk, background
- DSCP-based classification for standards-compliant QoS
- HTB (Hierarchical Token Bucket) tc command generation for bandwidth management
- Per-interface bandwidth limiting with SFQ leaf queuing
- QoS presets: voip, gaming, streaming, general

#### UPnP/IGD Service Integration
- miniupnpd configuration generation with nftables backend
- Automatic port forwarding via UPnP IGD protocol
- NAT-PMP and PCP protocol support
- Secure mode restricts clients to forward only to themselves
- Stable UUID generation based on machine identity
- Lease tracking and status reporting
- Permission rules for allowed/denied port ranges
- Service presets: disabled, basic, full

#### Firewall Subsystem Integration
- `FirewallSubsystem.php` integrates firewall with configuration apply workflow
- Automatic nftables ruleset application on configuration changes
- UPnP service lifecycle management during apply
- QoS tc rules application for bandwidth management
- Emergency disable and rollback support
- 60-second countdown for firewall changes (can cause lockout)
- Registered in SubsystemManager for automatic configuration application

#### Firewall Init Script and CLI Tools
- `coyote-firewall` OpenRC init script for firewall service management
- Service commands: start, stop, reload, panic (emergency flush), status
- Automatic UPnP/miniupnpd lifecycle management
- QoS traffic control rule cleanup on stop
- `firewall-apply` CLI tool for applying firewall configuration
- `fw-status` updated for nftables with JSON output, connection tracking, UPnP leases, and verbose ruleset display
- Enabled in default runlevel after coyote-config

#### Console Configuration Utility
- `coyote-config` TUI for console-based system configuration
- Text-based menu interface accessible via console
- Firewall settings menu with status, enable/disable, default policy
- Web admin hosts management: add/remove hosts in CIDR format to control web admin access
- SSH access hosts management: add/remove hosts in CIDR format to control SSH access
- Blocked hosts management via nftables sets
- View current nftables ruleset with pagination
- 60-second safety rollback timer for SSH sessions when applying firewall changes
- Console sessions can apply and save immediately (no lockout risk)
- Automatic rollback if SSH user doesn't confirm within timeout

#### Web Admin Access Controls
- New Access Controls page at `/firewall/access` for managing service access
- Web admin hosts: configure which hosts can access the web administration interface
- SSH hosts: configure which hosts can access SSH on the firewall
- Empty host lists block all access (explicit 0.0.0.0/0 required for public access)
- CIDR notation support with automatic /32 for single IPs
- Link to Access Controls from Firewall overview page

## [4.0.38] - 2026-01-26

### Added

#### Version Display Throughout System
- Full version number (e.g., 4.0.43) now displayed in:
  - `/etc/issue` - login prompt
  - Installer wizard title bar
  - Web admin sidebar
- Version file written to `/etc/coyote/version` during firmware build
- Version file also copied to installer media for installer to read

## [4.0.37] - 2026-01-26

### Added

#### Firewall ACL Management UI
- Web admin interface for creating and managing firewall Access Control Lists (ACLs)
- ACL list page showing all defined ACLs with rule counts and applied interfaces
- ACL editor with rule list, reordering, and CRUD operations
- Rule editor with protocol, source/destination, port, and comment fields
- Real-time rule preview showing approximate iptables representation
- ACL applications page to apply ACLs to interface pairs (forwarded traffic)
- First-match rule processing with default deny policy
- JSON configuration structure: `firewall.acls[]` and `firewall.applied[]`

## [4.0.36] - 2026-01-26

### Added

#### Privileged Helper for Web Admin
- `coyote-apply-helper` script for secure privilege escalation from web server
- Web server (lighttpd) no longer requires root access
- doas configuration allows lighttpd to run only the helper script
- Helper validates all operations against allowlists before executing
- All privileged operations logged via syslog for audit trail
- PrivilegedExecutor PHP class provides clean API for subsystems

#### Supported Privileged Operations
- Write to specific system files: `/etc/hostname`, `/etc/hosts`, `/etc/resolv.conf`, `/etc/timezone`, `/etc/ppp/peers/*`
- Create symlinks: `/etc/localtime`
- Network commands: `ip`, `sysctl`, `modprobe` (8021q only)
- Daemons: `udhcpc`, `pppd`
- Process control: `kill`, `pkill` (for stopping DHCP/PPPoE clients)

### Changed
- All subsystems (Hostname, Timezone, DNS, Network) now use PrivilegedExecutor
- No direct file writes to `/etc/` from web server process
- No direct privileged command execution from web server process

### Security
- Web server runs as unprivileged `lighttpd` user
- Privilege escalation restricted to specific, audited operations
- Helper script validates file destinations against allowlist
- Helper script validates kernel modules against allowlist

### Fixed
- doas setuid bit now set via mksquashfs pseudo-file (enables rootless build)

## [4.0.35] - 2026-01-26

### Added

#### Configuration Apply Debugging
- Detailed logging for subsystem apply operations to `/var/log/coyote-apply.log`
- SubsystemManager now logs success/failure for each subsystem with specific error messages
- ApplyService logs detailed failure information when configuration apply fails
- Debug endpoint `/debug/logs/apply` to view apply log from web interface
- Debug endpoint `/debug/logs/syslog` to view system log (logread) from web interface
- Error messages now include specific subsystem failures instead of generic "Some subsystems failed to apply"

## [4.0.34] - 2026-01-26

### Added

#### Expanded Network Interface Editor
- Full configuration support for all interface types: Static, DHCP, PPPoE, Bridge, Disabled
- Multiple IP addresses per interface (primary + secondary aliases)
- MTU configuration (576-9000)
- MAC address override (for ISP requirements)
- 802.1q VLAN sub-interfaces on static interfaces
- DHCP hostname option for ISP requirements
- PPPoE client with username/password credentials
- Constraint: only one interface can use dynamic addressing (DHCP or PPPoE)
- VLAN interfaces shown as read-only (configured via parent interface)
- NetworkSubsystem handles all interface types: static (multi-address), DHCP (udhcpc), PPPoE (pppd), bridge mode
- VLAN support via 8021q kernel module

### Fixed
- Auto-refresh now only applies to dashboard page (was causing form resets on editor pages)
- Installer now writes correct interface configuration format (type, enabled, addresses array, routes)

## [4.0.33] - 2026-01-26

### Added

#### Network Interface Editor
- Web admin interface for configuring network interfaces
- Edit individual interfaces with static IP, DHCP, or disabled modes
- CIDR notation validation for IP addresses (e.g., 192.168.1.1/24)
- Configuration badges showing interface state (Static/DHCP/Disabled/Unconfigured)
- Loopback interface excluded from list (not user-configurable)
- Apply configuration with 60-second countdown for network changes
- NetworkSubsystem updated to handle DHCP via udhcpc

## [4.0.32] - 2026-01-26

### Added

#### Subsystem-Based Configuration Apply
- Modular subsystem architecture for configuration application
- SubsystemInterface and AbstractSubsystem base classes
- HostnameSubsystem: handles hostname/domain (safe, no countdown)
- TimezoneSubsystem: handles timezone/localtime (safe, no countdown)
- DnsSubsystem: handles resolv.conf/nameservers (safe, no countdown)
- NetworkSubsystem: handles interfaces/routes/gateway (requires 60-second countdown)
- SubsystemManager coordinates subsystems and determines countdown requirements
- Selective countdown only for changes that could cause loss of remote access
- Safe changes (hostname, timezone, DNS) apply immediately without countdown
- Network changes trigger 60-second confirmation countdown
- UI shows different styling for safe vs network changes

### Fixed
- SystemController constructor now calls parent::__construct()

## [4.0.28] - 2026-01-26

### Added

#### Firmware Signature Verification
- Ed25519 cryptographic signatures for firmware integrity verification
- Auto-signing during build when `COYOTE_SIGNING_KEY` is configured in `.local-config`
- Boot-time verification in initramfs before mounting firmware
- Installer verifies firmware signature and warns user if invalid or missing
- `nosigcheck` kernel parameter to disable verification for development
- Public key embedded in initramfs at `/etc/coyote/firmware-signing.pub`
- `make sign` target for manual firmware signing
- `verify-signature` utility in initramfs for signature checks

#### Separate Initramfs Architecture
- `initramfs/` - Full system initramfs for installed system boot
- `initramfs-installer/` - Simplified initramfs for installer boot
- `installer-init` script bypasses OpenRC to run installer directly
- ISO contains both `initramfs-system.gz` and `initramfs.gz`
- `make initramfs-installer` target for building installer initramfs

#### MRTG Monitoring System
- Lua-based data collection scripts (mrtg-cpu, mrtg-memory, mrtg-net)
- RRDtool backend for reliable graph generation
- Automatic MRTG configuration via `mrtg-setup` script
- Cron job runs MRTG every 5 minutes
- `coyote-mrtg` init service initializes monitoring on boot
- Dashboard displays CPU, memory, and per-interface traffic graphs
- Graph statistics showing current, average, and maximum values
- Fixed 0-100% Y-axis scale for percentage graphs

#### SSH Server
- Dropbear SSH server enabled by default
- Persistent host keys stored on config partition
- Support for authorized_keys from `/mnt/config/dropbear/authorized_keys`
- Default root password set to 'coyote' (change on first login)

#### Persistent Storage
- `/root` symlinked to `/mnt/config/root` for persistent home directory
- SSH known_hosts and user files persist across reboots and firmware updates
- SSL certificates stored at `/mnt/config/ssl` for persistence
- Directories created automatically on first boot by `coyote-init`
- Config partition remounted read-write only when needed, then read-only

#### Firmware Upgrade Support
- Installer main menu with options: New Install, Upgrade, Shell
- Automatic detection of existing Coyote installations
- Upgrade preserves config partition, only updates boot partition
- Backup of existing firmware as `previous.squashfs` before upgrade
- Updates kernel, initramfs, firmware, and bootloader files

#### Web Administration Interface
- Lighttpd web server on ports 80 (HTTP) and 443 (HTTPS)
- Self-signed SSL certificate auto-generation
- Dashboard with system overview and traffic graphs
- Controllers for Network, Firewall, NAT, VPN, Load Balancer, Services, System, Firmware
- Debug endpoints at `/debug/*` for troubleshooting (logs, PHP info, config status)
- Consistent dark blue theme across all pages
- REST API endpoints for programmatic access
- System settings editing (hostname, domain, timezone, nameservers)
- Service management (start, stop, restart, enable, disable)
- System power control (reboot, shutdown)
- Configuration backup and restore with download/upload support
- doas privilege escalation for web server operations (mount, hostname, rc-service, reboot)

#### Configuration Management Architecture
- Three-tier configuration: working-config, running-config, persistent storage
- working-config: Uncommitted changes from web admin (`/tmp/working-config/`)
- running-config: Configuration currently applied to system (`/tmp/running-config/`)
- persistent storage: Survives reboot (`/mnt/config/system.json`)
- 60-second confirmation countdown after applying changes
- Automatic rollback if confirmation timeout expires
- Safe for network configuration changes that could cause lockout
- ConfigService and ApplyService for proper configuration flow

#### Console TUI Application
- Menu-driven configuration interface
- Network, firewall, NAT, VPN, load balancer, services, and system menus

#### CLI Tools
- `coyote-cli` - Main command-line interface
- `apply-config` - Apply system configuration changes
- `save-config` - Save configuration to persistent storage
- `firmware-update` - Firmware update utility
- `fw-status` - Firewall status display
- `vpn-status` - VPN tunnel status display
- `lb-status` - Load balancer status display
- `lb-reload` - Reload HAProxy configuration

#### Built-in Subsystems (Consolidated)
- **Firewall**: IptablesService, NatService, AclService, QosService with rule builder
- **VPN**: StrongSwan/swanctl integration with IPSec tunnel management
- **Load Balancer**: HAProxy management with frontend/backend configuration
- **Utility**: Logging, process management, and filesystem helpers

#### Build System
- Auto-incrementing build number (version 4.0.xxx where xxx increments each build)
- Build number stored in `build/.build-number` and tracked in git
- `make show-version` displays current and next version numbers
- Rootless build using `apk --usermode` and mtools
- `mkrootfs.sh` - Alpine 3.23 bootstrap without root privileges
- `mksquashfs.sh` - Squashfs firmware image builder
- `mkinitramfs.sh` - System initramfs builder
- `mkinitramfs-installer.sh` - Installer initramfs builder
- `mkiso.sh` - Hybrid ISO image builder (CD and USB bootable)
- `mkinstaller.sh` - USB installer image builder using mtools
- `sign-firmware.sh` - Ed25519 firmware signing
- `verify-firmware.sh` - Signature verification testing
- Local development config via `.local-config` (gitignored)
- Support for local Alpine mirror via `ALPINE_MIRROR` setting

#### Boot Sequence
- Initramfs init with ordered boot scripts (01-07)
- Recovery menu available during boot (press 'r')
- Boot media detection distinguishes installed system from installer
- Overlayfs on `/etc` for writable configuration over read-only squashfs
- Automatic firmware update detection and verification
- Fallback to backup firmware if signature verification fails

#### Networking
- Coyote-managed networking (bypasses Alpine's networking service)
- Loopback interface configuration
- Direct interface configuration using `ip` commands
- DNS/resolv.conf management
- Common NIC kernel module loading during early boot

#### Installer
- Dialog-based TUI with professional menu-driven interface
- Main menu with New Installation, Upgrade, and Exit to Shell options
- Automatic hardware detection with progress indicator
- Disk selection menu with size display
- Network interface selection menu showing MAC, driver, and link state
- Network configuration form with all fields on one screen
- Input validation for IP addresses (CIDR notation), hostnames, and domains
- Progress gauges for partitioning, formatting, and installation phases
- Confirmation dialogs before destructive operations
- Default values: hostname "coyote", domain "local.lan", DNS "1.1.1.1"
- Optional gateway configuration
- 2GB boot partition (FAT32) + remaining space config partition (ext4)

### Changed

- Consolidated firewall, VPN, load balancer, and web admin into single repository
- Removed separate add-on architecture in favor of built-in components
- Boot partition increased from 512MB to 2GB for firmware storage
- Installer writes config to `/mnt/config/system.json` (was nested path)
- Installer rewritten to use `dialog` utility for professional TUI experience
- Added `dialog` package to base system for installer and future TUI tools

### Fixed

- Boot media detection now checks marker file content (COYOTE_BOOT vs COYOTE_INSTALLER)
- Busybox symlinks for all applets including syslogd, klogd, crond
- Networking service conflict with OpenRC resolved
- Array handling in apply-config DNS configuration
- CPU stats parsing in mrtg-cpu (was showing ~100% when idle)
- MRTG cron job location (moved to `/etc/crontabs/root` for busybox crond)
- Config partition mounting with explicit `-t ext4` for busybox compatibility
- PHP symlink (`/usr/bin/php` -> `php83`)
- Circular dependency in root init script
- Suppressed kernel module loading errors during boot (missing symbols, invalid ELF)
- Added `quiet` kernel parameter to all bootloader configurations
- Persistent directory setup moved to `coyote-config` service (runs after config partition mount)
- Removed `/var/run` migration warning by disabling `bootmisc` service
- Installer utility output suppressed for clean dialog progress display
- Web admin file permissions set up in coyote-config init service for lighttpd access

### Optimized

- Aggressive firmware/module cleanup reduces image size significantly:
  - Removed GPU firmware (amdgpu, nvidia, radeon, i915)
  - Removed WiFi/Bluetooth firmware
  - Removed sound/media firmware
  - Removed unnecessary kernel modules (sound, DRM, wireless, bluetooth)
  - Firmware: 743MB -> 104MB
  - Modules: 129MB -> 74MB
- Final image sizes: firmware 214MB, ISO 239MB, USB installer 300MB
- Squashfs uses `-all-root` for proper file ownership

### Security

- Ed25519 firmware signature verification prevents tampering
- Self-signed SSL certificates for web admin HTTPS
- Dropbear SSH with persistent host keys
- Authentication framework for web admin (bypass mode for initial setup)
- doas rules restrict web server to specific privileged commands only
- Config partition mounted read-only by default, remounted read-write only during writes

## [Unreleased]

### Planned

- Web admin authentication enforcement
- Firewall rule management UI
- VPN tunnel configuration UI
- Load balancer configuration UI
- Network interface configuration UI
- UEFI boot support
