# Coyote Linux 4 - Alpine Base System

Base system, installer, boot sequence, and core configuration scripts for Coyote Linux 4.

Coyote Linux is an immutable, firmware-based edge firewall and networking appliance distribution built on Alpine Linux. It features a read-only squashfs root filesystem, safe rollback capability, and Ed25519 firmware signature verification.

## Features

- **Immutable Design**: Read-only squashfs firmware with tmpfs overlays
- **Safe Updates**: Atomic firmware updates with automatic rollback
- **Signature Verification**: Ed25519 cryptographic firmware signing
- **Minimal Footprint**: ~214MB firmware image
- **No Root Required**: Build system operates entirely without root privileges
- **Separate Installer**: Dedicated installer initramfs bypasses normal services

## Directory Structure

```
coyote-alpine/
├── build/                      # Build system
│   ├── Makefile                # Main build orchestration
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
│       ├── 01-mount-basics.sh
│       ├── 02-load-modules.sh
│       └── 03-find-installer.sh
│
├── installer/                  # USB installer system
│   ├── install.sh              # Main installation script
│   ├── installer-init          # Minimal init (bypasses OpenRC)
│   └── tui/                    # Text UI components
│
├── rootfs/                     # Firmware root filesystem
│   ├── etc/
│   │   ├── init.d/             # OpenRC service scripts
│   │   └── lighttpd/           # Web server configuration
│   ├── sbin/
│   │   └── installer-init      # Installer init (copied from installer/)
│   └── opt/coyote/             # Main Coyote installation
│       ├── bin/                # CLI tools
│       ├── lib/                # PHP libraries (PSR-4)
│       ├── defaults/           # Default configuration
│       └── tui/                # Console TUI menus
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

### Build Outputs

| File | Size | Description |
|------|------|-------------|
| `firmware-4.0.0.squashfs` | ~214MB | Compressed root filesystem |
| `firmware-4.0.0.squashfs.sig` | 64B | Ed25519 signature |
| `coyote-4.0.0-installer.iso` | ~239MB | Bootable ISO for VMs/CD |
| `coyote-4.0.0-installer.img` | ~300MB | USB flash drive image |

## Firmware Signature Verification

Coyote Linux uses Ed25519 signatures to verify firmware integrity. This protects against corrupted downloads, tampered images, and supply chain attacks.

### Key Generation

Generate a signing keypair (do this once, keep private key secure):

```bash
# Generate private key
openssl genpkey -algorithm ED25519 -out firmware-signing.key

# Extract public key
openssl pkey -in firmware-signing.key -pubout -out firmware-signing.pub
```

### Build-Time Signing

1. Create `build/.local-config` with your signing key path:
   ```bash
   COYOTE_SIGNING_KEY="/path/to/firmware-signing.key"
   ```

2. Build firmware - it will be signed automatically:
   ```bash
   make firmware   # Creates .squashfs and .sig files
   ```

### Boot-Time Verification

The initramfs verifies firmware signatures at boot:

1. **New firmware updates** are verified before being applied
2. **Current firmware** is verified before mounting
3. **Backup firmware** is tried if primary signature fails
4. Recovery shell available if all verification fails

Signature verification can be bypassed with the `nosigcheck` kernel parameter (for development).

### Public Key Installation

The public key is embedded in the initramfs at `/etc/coyote/firmware-signing.pub`. For production deployments, this should be baked into your custom build.

## Boot Sequence

### Normal Boot (System Initramfs)

1. BIOS/UEFI loads syslinux bootloader
2. Kernel + `initramfs.gz` loaded
3. Init runs boot scripts in order:
   - `01-mount-basics.sh` - Mount proc, sys, dev
   - `02-detect-boot-media.sh` - Find boot partition
   - `03-check-firmware.sh` - Verify firmware signature
   - `04-recovery-prompt.sh` - Brief recovery menu window
   - `05-mount-firmware.sh` - Mount squashfs
   - `06-setup-tmpfs.sh` - Create writable overlays
   - `07-pivot-root.sh` - Switch to firmware root
4. OpenRC starts system services

### Installer Boot (Installer Initramfs)

1. BIOS/UEFI loads syslinux bootloader
2. Kernel + `initramfs-installer.gz` loaded (with `installer` parameter)
3. Installer init finds and mounts installer media
4. `switch_root` to installer rootfs
5. `installer-init` runs (bypasses OpenRC entirely)
6. Installation TUI launches on tty1

## Local Development Configuration

For local development settings that shouldn't be committed (local mirrors, signing keys):

1. Copy the example config:
   ```bash
   cp build/.local-config.example build/.local-config
   ```

2. Edit `.local-config` with your settings:
   ```bash
   # Use local Alpine mirror
   ALPINE_MIRROR="file:///path/to/local/mirror"

   # Firmware signing key path
   COYOTE_SIGNING_KEY="/path/to/firmware-signing.key"
   ```

The `.local-config` file is gitignored and will not be committed.

## Partition Layout (Installed System)

| Partition | Size | Filesystem | Contents |
|-----------|------|------------|----------|
| Boot | 2GB | FAT32 | Kernel, initramfs, firmware squashfs |
| Config | Remaining | ext4 | Persistent configuration |

## PHP Namespaces

- `Coyote\Config\` - Configuration management
- `Coyote\System\` - System operations (hardware, network, services)
- `Coyote\Firewall\` - Firewall and NAT management
- `Coyote\Vpn\` - IPSec VPN (StrongSwan)
- `Coyote\LoadBalancer\` - HAProxy load balancer
- `Coyote\Util\` - Utility classes

## Related Repositories

- [coyote-webadmin](https://github.com/coyote-linux/coyote-webadmin) - Web administration interface
- [coyote-firewall](https://github.com/coyote-linux/coyote-firewall) - Edge firewall add-on
- [coyote-loadbalancer](https://github.com/coyote-linux/coyote-loadbalancer) - HAProxy load balancer add-on

## License

See [LICENSE](LICENSE) for details.
