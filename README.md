# Coyote Linux 4

Coyote Linux is an immutable, firmware-based edge firewall and networking appliance distribution built on Alpine Linux. It features a read-only squashfs root filesystem, safe configuration rollback, and Ed25519 firmware signature verification.

This repository contains the complete system: base OS, initramfs, installer, web admin, firewall, load balancer, and all PHP libraries.

## Features

- **Immutable Design**: Read-only squashfs firmware with tmpfs overlays
- **Safe Updates**: Atomic firmware updates with automatic rollback
- **Signature Verification**: Ed25519 cryptographic firmware signing
- **Web Administration**: PHP-based management interface served by lighttpd
- **Edge Firewall**: iptables/ip6tables with ACLs, NAT, port forwarding, and address lists
- **VPN**: StrongSwan IPSec, OpenVPN, and WireGuard
- **Load Balancer**: HAProxy with frontend/backend management
- **DHCP Server**: dnsmasq-based with static reservations
- **Services**: NTP, remote syslog, MRTG monitoring, SNMP, SSH (Dropbear)
- **Certificate Management**: Upload, ACME/Let's Encrypt, and web admin SSL assignment
- **Minimal Footprint**: ~214MB firmware image
- **No Root Required**: Build system operates entirely without root privileges

## Directory Structure

```
coyote-alpine/
├── build/                      # Build system
│   ├── Makefile                # Main build orchestration
│   ├── menuconfig.sh           # Interactive build configuration
│   ├── mkrootfs.sh             # Alpine rootfs builder
│   ├── mksquashfs.sh           # Squashfs firmware builder
│   ├── mkinitramfs.sh          # System initramfs builder
│   ├── mkinitramfs-installer.sh # Installer initramfs builder
│   ├── mkiso.sh                # ISO image builder
│   ├── mkinstaller.sh          # USB installer builder
│   ├── sign-firmware.sh        # Firmware signing script
│   ├── verify-firmware.sh      # Signature verification test
│   ├── apk-packages.txt        # Required Alpine packages
│   ├── .local-config.example   # Local config template
│   └── output/                 # Build artifacts (gitignored)
│
├── initramfs/                  # System boot initramfs
│   ├── init                    # PID 1 init script
│   ├── init.d/                 # Ordered boot sequence scripts
│   │   ├── 01-mount-basics.sh
│   │   ├── 02-detect-boot-media.sh
│   │   ├── 03-check-firmware.sh   # Signature verification
│   │   ├── 04-recovery-prompt.sh
│   │   ├── 05-mount-firmware.sh
│   │   ├── 06-setup-tmpfs.sh
│   │   └── 07-pivot-root.sh
│   ├── bin/                    # Initramfs utilities
│   │   └── verify-signature    # Ed25519 signature verifier
│   └── recovery/               # Recovery menu scripts
│
├── initramfs-installer/        # Installer boot initramfs
│   ├── init                    # Installer-specific init
│   └── init.d/                 # Installer boot scripts
│
├── installer/                  # USB installer system
│   ├── install.sh              # Main installation script
│   ├── installer-init          # Minimal init (bypasses OpenRC)
│   └── tui/                    # Text UI components
│
├── kernel/                     # Custom kernel build (optional)
│   ├── build-kernel.sh         # Kernel build script
│   └── configs/                # Kernel defconfigs
│
├── rootfs/                     # Firmware root filesystem
│   ├── etc/
│   │   ├── init.d/             # OpenRC service scripts
│   │   └── lighttpd/           # Web server configuration
│   ├── sbin/
│   │   └── installer-init      # Installer init
│   └── opt/coyote/             # Main Coyote installation
│       ├── bin/                # CLI tools and helpers
│       ├── defaults/           # Default configuration (system.json)
│       ├── lib/                # PHP libraries (PSR-4)
│       │   └── Coyote/
│       │       ├── Certificate/    # Certificate store and ACME
│       │       ├── Config/         # Configuration management
│       │       ├── Firewall/       # Firewall rules and ACLs
│       │       ├── LoadBalancer/   # HAProxy management
│       │       ├── System/         # System operations and subsystems
│       │       ├── Util/           # Utility classes
│       │       └── Vpn/            # IPSec, OpenVPN, WireGuard
│       ├── tui/                # Console TUI menus
│       └── webadmin/           # Web administration interface
│           ├── public/         # Document root (CSS, JS, images)
│           ├── src/            # Controllers, services, routing
│           └── templates/      # PHP page templates
│
├── config/                     # Config partition layout docs
└── tests/                      # Unit and integration tests
```

## Build Prerequisites

### Fedora

```bash
sudo dnf install mtools syslinux parted squashfs-tools xorriso openssl
```

### Debian/Ubuntu

```bash
sudo apt install mtools syslinux syslinux-common parted squashfs-tools xorriso openssl
```

### Required Tools

| Tool | Purpose |
|------|---------|
| `mtools` | FAT filesystem manipulation without root |
| `syslinux` | Bootloader and MBR installation |
| `parted` | Disk partitioning |
| `squashfs-tools` | Creating squashfs firmware images |
| `xorriso` | ISO image creation |
| `openssl` | Ed25519 signature verification |

## Building

All build commands must be run from the `build/` directory:

```bash
cd build
make help              # Show all available targets

# Configure build options
make menuconfig        # Interactive build configuration

# Typical build sequence
make rootfs            # Download Alpine and build rootfs (first time)
make firmware          # Build firmware squashfs image
make initramfs         # Build system initramfs
make initramfs-installer  # Build installer initramfs
make iso               # Build bootable ISO image
make installer         # Build USB installer image

# Optional
make sign              # Sign firmware (requires signing key)
make clean             # Remove build outputs
make distclean         # Remove outputs and cached downloads
```

The build process does not require root privileges. Images are created using `mtools` for FAT manipulation and `apk --usermode` for package installation.

### Build Configuration

Run `make menuconfig` to configure optional features before building:

- Kernel type (custom or Alpine LTS)
- Development mode
- Optional components: IPSec VPN, OpenVPN, WireGuard, Load Balancer

Feature selections are saved to `build/.config` and embedded into the firmware as `/etc/coyote/features.json`. The web admin automatically hides UI for disabled features.

### Custom Kernel (Optional)

If a custom kernel is built under `kernel/`, the initramfs build will use it automatically:

- Kernel image: `kernel/linux-*/arch/x86/boot/bzImage`
- Modules (optional): `kernel/output/modules/lib/modules/`

If no custom kernel is found, the build falls back to Alpine `linux-lts`.

### Build Outputs

| File | Size | Description |
|------|------|-------------|
| `firmware-4.0.0.squashfs` | ~214MB | Compressed root filesystem |
| `firmware-4.0.0.squashfs.sig` | 64B | Ed25519 signature |
| `coyote-4.0.0-installer.iso` | ~239MB | Bootable ISO for VMs/CD |
| `coyote-4.0.0-installer.img` | ~300MB | USB flash drive image |

## Web Administration

The web admin is a PHP application served by lighttpd at `https://<device-ip>/`.

### Managed Features

| Section | Capabilities |
|---------|-------------|
| **Dashboard** | System status, resource graphs (MRTG) |
| **Network** | Interface configuration, static routes |
| **Firewall** | ACLs, rules, address lists, access controls |
| **NAT** | Port forwarding, masquerade rules |
| **VPN** | IPSec tunnels, OpenVPN instances, WireGuard interfaces |
| **Load Balancer** | HAProxy frontends, backends, statistics |
| **DHCP** | Server settings, static reservations |
| **Services** | Start/stop/enable system services |
| **Certificates** | Upload, ACME/Let's Encrypt, web admin SSL assignment |
| **System** | Hostname, DNS, syslog, NTP, backup/restore, password |
| **Firmware** | Check for updates, download, upload, stage, and apply |
| **Apply Config** | Review and apply pending changes with rollback safety |

### Configuration Management

Configuration changes follow a safe apply model:

1. User edits settings — saved to **working config** (not yet active)
2. User clicks **Apply Configuration** — changes applied to running system
3. If changes include network settings — 60-second confirmation countdown
4. If not confirmed — automatic rollback to previous configuration
5. Confirmed changes are persisted to disk

## Firmware Signature Verification

Coyote Linux uses Ed25519 signatures to verify firmware integrity.

### Key Generation

```bash
openssl genpkey -algorithm ED25519 -out firmware-signing.key
openssl pkey -in firmware-signing.key -pubout -out firmware-signing.pub
```

### Build-Time Signing

Set `COYOTE_SIGNING_KEY` in `build/.local-config`:

```bash
COYOTE_SIGNING_KEY="/path/to/firmware-signing.key"
```

Firmware is signed automatically during `make firmware`.

### Boot-Time Verification

1. New firmware updates verified before being applied
2. Current firmware verified before mounting
3. Backup firmware tried if primary signature fails
4. Recovery shell available if all verification fails

Bypass with the `nosigcheck` kernel parameter (development only).

## Boot Sequence

### Normal Boot

1. BIOS/UEFI loads syslinux bootloader
2. Kernel + `initramfs.gz` loaded
3. Init runs boot scripts in order:
   - Mount proc, sys, dev
   - Detect boot media
   - Verify firmware signature
   - Brief recovery menu window
   - Mount squashfs firmware
   - Create writable tmpfs overlays
   - Pivot root into firmware
4. OpenRC starts system services

### Installer Boot

1. Boot from USB/ISO with `installer` kernel parameter
2. Installer initramfs finds and mounts installer media
3. `installer-init` runs (bypasses OpenRC)
4. Installation TUI launches on tty1

## Partition Layout (Installed System)

| Partition | Size | Filesystem | Contents |
|-----------|------|------------|----------|
| Boot | Configurable (default 4GB) | FAT32 | Kernel, initramfs, firmware squashfs |
| Config | Remaining | ext4 | Persistent configuration |

## PHP Namespaces

| Namespace | Purpose |
|-----------|---------|
| `Coyote\Config\` | Configuration management and loading |
| `Coyote\System\` | System operations, subsystems, privileged execution |
| `Coyote\Firewall\` | Firewall rules, ACLs, NAT |
| `Coyote\Vpn\` | IPSec, OpenVPN, WireGuard |
| `Coyote\LoadBalancer\` | HAProxy frontend/backend management |
| `Coyote\Certificate\` | Certificate store, ACME, and validation |
| `Coyote\Util\` | Utility classes |
| `Coyote\WebAdmin\` | Web admin controllers, routing, services |

## Local Development

For local settings that shouldn't be committed:

```bash
cp build/.local-config.example build/.local-config
```

Edit with your local Alpine mirror and signing key paths. This file is gitignored.
