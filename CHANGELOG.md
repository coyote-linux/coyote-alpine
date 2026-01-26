# Changelog

All notable changes to Coyote Linux 4 are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [4.0.0] - 2026-01-25

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
