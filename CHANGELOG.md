# Changelog

All notable changes to Coyote Linux 4 are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [4.0.47] - 2026-02-01

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
- `nftables.rules.tpl` - Reference template showing base ruleset structure
- Chain structure: input, forward, output, service chains (ssh-hosts, snmp-hosts, icmp-rules, dhcp-server), UPnP chains (igd-forward, igd-input, igd-preroute)
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
- `services.upnp` - UPnP/IGD service configuration
- `firewall.qos` - Quality of Service with traffic classification, packet marking, and tc integration

#### QoS (Quality of Service) Features
- Traffic classification via nftables mangle table with packet marking
- Default traffic classes: realtime (VoIP), interactive (SSH/DNS), default, bulk, background
- DSCP-based classification for standards-compliant QoS
- HTB (Hierarchical Token Bucket) tc command generation for bandwidth management
- Per-interface bandwidth limiting with SFQ leaf queuing
- QoS presets: voip, gaming, streaming, general

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
