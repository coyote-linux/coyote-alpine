# coyote-alpine

Base system, installer, boot sequence, and core configuration scripts for Coyote Linux 4.

## Directory Structure

```
coyote-alpine/
├── build/                  # Firmware image build scripts
│   ├── Makefile           # Main build orchestration
│   ├── mksquashfs.sh      # Squashfs image builder
│   ├── mkinstaller.sh     # USB installer builder
│   └── apk-packages.txt   # Required Alpine packages
│
├── installer/             # USB installer system
│   ├── init               # Installer initramfs init script
│   ├── install.sh         # Main installation script
│   └── tui/               # Text UI for installation
│
├── initramfs/             # Early boot initramfs
│   ├── init               # PID 1 init script
│   ├── init.d/            # Ordered boot sequence scripts
│   └── recovery/          # Recovery menu scripts
│
├── rootfs/                # Firmware root filesystem
│   ├── etc/init.d/        # OpenRC service scripts
│   └── opt/coyote/        # Main Coyote installation
│       ├── bin/           # CLI tools
│       ├── lib/           # PHP libraries (PSR-4)
│       ├── defaults/      # Default configuration
│       └── tui/           # Console TUI menus
│
├── config/                # Config partition layout docs
└── tests/                 # Unit and integration tests
```

## Build Prerequisites

### Fedora

```bash
dnf install mtools syslinux parted squashfs-tools
```

### Other distributions

- `mtools` - FAT filesystem manipulation without root
- `syslinux` - Bootloader and MBR installation
- `parted` - Disk partitioning
- `squashfs-tools` - Creating squashfs firmware images

## Building

```bash
cd build
make firmware     # Build firmware squashfs image
make installer    # Build USB installer image (no root required)
make clean        # Clean build outputs
```

The build process does not require root privileges. The installer image is created
using mtools for FAT filesystem manipulation and syslinux with offset addressing.

## Boot Sequence

1. BIOS/UEFI loads syslinux bootloader
2. Kernel + initramfs loaded
3. Initramfs init runs boot scripts in order (01-07)
4. Recovery menu available during boot (press 'r')
5. Pivot root to firmware squashfs
6. OpenRC starts system services

## PHP Namespaces

- `Coyote\Config\` - Configuration management
- `Coyote\System\` - System operations (hardware, network, services)
- `Coyote\Addon\` - Add-on framework
- `Coyote\Util\` - Utility classes
