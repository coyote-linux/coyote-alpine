# Coyote Linux 4 Build Makefile

# Version with auto-incrementing build number
# Major.Minor are fixed, build number increments with each build
VERSION_MAJOR := 4
VERSION_MINOR := 0
BUILD_NUMBER_FILE := .build-number
BUILD_NUMBER := $(shell cat $(BUILD_NUMBER_FILE) 2>/dev/null || echo 0)
VERSION := $(VERSION_MAJOR).$(VERSION_MINOR).$(BUILD_NUMBER)

CONFIG_FILE := .config
DEFAULT_KERNEL_TYPE := custom
DEFAULT_DEV_BUILD := 0
DEFAULT_FIRMWARE_SIZE_MB := 512
DEFAULT_BOOT_PARTITION_SIZE_MIB := 4096
DEFAULT_DOTNET := 1
DEFAULT_LOADBALANCER := 1
DEFAULT_IPSEC := 1
DEFAULT_OPENVPN := 1
DEFAULT_WIREGUARD := 1

MIN_FIRMWARE_SIZE_MB := 256
MIN_BOOT_PARTITION_SIZE_MIB := 512

-include $(CONFIG_FILE)

CONFIG_KERNEL_TYPE ?= $(DEFAULT_KERNEL_TYPE)
CONFIG_DEV_BUILD ?= $(DEFAULT_DEV_BUILD)
CONFIG_FIRMWARE_SIZE_MB ?= $(DEFAULT_FIRMWARE_SIZE_MB)
CONFIG_BOOT_PARTITION_SIZE_MIB ?= $(DEFAULT_BOOT_PARTITION_SIZE_MIB)
CONFIG_DOTNET ?= $(DEFAULT_DOTNET)
CONFIG_LOADBALANCER ?= $(DEFAULT_LOADBALANCER)
CONFIG_IPSEC ?= $(DEFAULT_IPSEC)
CONFIG_OPENVPN ?= $(DEFAULT_OPENVPN)
CONFIG_WIREGUARD ?= $(DEFAULT_WIREGUARD)

DEV_BUILD ?= $(CONFIG_DEV_BUILD)
NO_BUMP ?= 0

BUILD_DIR := ../output
ROOTFS_SRC := ../rootfs
ROOTFS_BUILD := ../output/rootfs
INITRAMFS_DIR := ../initramfs
INITRAMFS_INSTALLER_DIR := ../initramfs-installer
CACHE_DIR := ../.cache

SQUASHFS_OPTS := -comp xz -Xdict-size 100%

# Find all source files for dependency tracking
ROOTFS_FILES := $(shell find $(ROOTFS_SRC) -type f 2>/dev/null)
INITRAMFS_FILES := $(shell find $(INITRAMFS_DIR) -type f 2>/dev/null)
INITRAMFS_INSTALLER_FILES := $(shell find $(INITRAMFS_INSTALLER_DIR) -type f 2>/dev/null)

.PHONY: all clean firmware installer iso initramfs initramfs-installer kernel rootfs distclean help rebuild-firmware rebuild-iso sign bump-version show-version menuconfig show-config update-archive

all: firmware

# Show current version
show-version:
	@echo "Current version: $(VERSION)"
	@echo "Build mode: $(if $(filter 1,$(DEV_BUILD)),development,release)"
	@echo "Next build will be: $(VERSION_MAJOR).$(VERSION_MINOR).$$(($(BUILD_NUMBER) + 1))"

show-config:
	@echo "Build configuration"
	@echo "  Kernel type:          $(CONFIG_KERNEL_TYPE)"
	@echo "  Development mode:     $(CONFIG_DEV_BUILD)"
	@echo "  Firmware size limit:  $(CONFIG_FIRMWARE_SIZE_MB) MB"
	@echo "  Boot partition size:  $(CONFIG_BOOT_PARTITION_SIZE_MIB) MiB"
	@echo "  DotNet runtime:       $(CONFIG_DOTNET)"
	@echo "  Load balancer:        $(CONFIG_LOADBALANCER)"
	@echo "  IPSec VPN:            $(CONFIG_IPSEC)"
	@echo "  OpenVPN server:       $(CONFIG_OPENVPN)"
	@echo "  WireGuard server:     $(CONFIG_WIREGUARD)"

menuconfig:
	./menuconfig.sh

# Increment build number (called automatically by firmware target)
bump-version:
	@NEW_BUILD=$$(($(BUILD_NUMBER) + 1)); \
	echo $$NEW_BUILD > $(BUILD_NUMBER_FILE); \
	echo "Build number incremented to $$NEW_BUILD"

help:
	@echo "Coyote Linux Build System"
	@echo ""
	@echo "Current version: $(VERSION)"
	@echo ""
	@echo "Targets:"
	@echo "  menuconfig          - Configure build options (kernel, features, sizes)"
	@echo "  show-config         - Display active build configuration"
	@echo "  rootfs              - Download Alpine and build rootfs"
	@echo "  firmware            - Build firmware squashfs image (+ update archive)"
	@echo "  update-archive      - Build webadmin-compatible update archive from latest artifacts"
	@echo "  sign                - Sign the latest firmware image"
	@echo "  kernel              - Build custom kernel (kernel/build-kernel.sh)"
	@echo "  initramfs           - Build initramfs for running system"
	@echo "  initramfs-installer - Build initramfs for installer (simplified)"
	@echo "  installer           - Build USB installer image"
	@echo "  iso                 - Build ISO installer image (for CD/VM boot)"
	@echo "  rebuild-firmware    - Force rebuild firmware (preserves rootfs)"
	@echo "  rebuild-iso         - Force rebuild firmware and ISO"
	@echo "  show-version        - Show current and next version numbers"
	@echo "  clean               - Remove build outputs"
	@echo "  distclean           - Remove build outputs and cached downloads"
	@echo ""
	@echo "Typical build sequence:"
	@echo "  make menuconfig # Configure build options"
	@echo "  make rootfs     # First time or when packages change"
	@echo "  make iso        # Build bootable ISO installer"
	@echo ""
	@echo "After editing rootfs/ files:"
	@echo "  make firmware   # Detects changes, increments build number"
	@echo "  make firmware NO_BUMP=1   # Rebuild current version without incrementing"
	@echo "  ./build.sh -n firmware    # Same as above via command line flag"
	@echo "  make firmware DEV_BUILD=1   # Build development firmware (enables debug routes)"
	@echo "  make sign       # Sign firmware (if COYOTE_SIGNING_KEY set in .local-config)"
	@echo "  make iso        # Rebuild ISO with new firmware"

# Build the Alpine rootfs with Coyote overlay
rootfs: kernel
	@echo "Building rootfs..."
	CONFIG_KERNEL_TYPE="$(CONFIG_KERNEL_TYPE)" \
	CONFIG_DOTNET="$(CONFIG_DOTNET)" \
	CONFIG_LOADBALANCER="$(CONFIG_LOADBALANCER)" \
	CONFIG_IPSEC="$(CONFIG_IPSEC)" \
	CONFIG_OPENVPN="$(CONFIG_OPENVPN)" \
	CONFIG_WIREGUARD="$(CONFIG_WIREGUARD)" \
	./mkrootfs.sh
	@touch $(ROOTFS_BUILD)/.stamp

# Build the main firmware squashfs image
# This target increments the build number and creates the firmware
firmware: $(ROOTFS_BUILD)/.stamp $(ROOTFS_FILES)
	@if [ "$(NO_BUMP)" = "1" ]; then \
		NEW_BUILD=$(BUILD_NUMBER); \
		echo "Skipping build number bump (NO_BUMP=1)"; \
	else \
		NEW_BUILD=$$(($(BUILD_NUMBER) + 1)); \
		echo $$NEW_BUILD > $(BUILD_NUMBER_FILE); \
		echo "Build number incremented to $$NEW_BUILD"; \
	fi; \
	NEW_VERSION="$(VERSION_MAJOR).$(VERSION_MINOR).$$NEW_BUILD"; \
	if [ "$(DEV_BUILD)" = "1" ]; then BUILD_MODE="development"; else BUILD_MODE="release"; fi; \
	case "$(CONFIG_KERNEL_TYPE)" in custom|alpine-lts) ;; *) echo "Error: CONFIG_KERNEL_TYPE must be 'custom' or 'alpine-lts'"; exit 1 ;; esac; \
	case "$(CONFIG_FIRMWARE_SIZE_MB)" in ''|*[!0-9]*) echo "Error: CONFIG_FIRMWARE_SIZE_MB must be numeric"; exit 1 ;; esac; \
	case "$(CONFIG_BOOT_PARTITION_SIZE_MIB)" in ''|*[!0-9]*) echo "Error: CONFIG_BOOT_PARTITION_SIZE_MIB must be numeric"; exit 1 ;; esac; \
	if [ "$(CONFIG_FIRMWARE_SIZE_MB)" -lt "$(MIN_FIRMWARE_SIZE_MB)" ]; then echo "Error: CONFIG_FIRMWARE_SIZE_MB must be >= $(MIN_FIRMWARE_SIZE_MB)"; exit 1; fi; \
	if [ "$(CONFIG_BOOT_PARTITION_SIZE_MIB)" -lt "$(MIN_BOOT_PARTITION_SIZE_MIB)" ]; then echo "Error: CONFIG_BOOT_PARTITION_SIZE_MIB must be >= $(MIN_BOOT_PARTITION_SIZE_MIB)"; exit 1; fi; \
	FIRMWARE_FILE="$(BUILD_DIR)/firmware-$$NEW_VERSION.squashfs"; \
	echo "Building firmware version $$NEW_VERSION ($$BUILD_MODE mode)..."; \
	mkdir -p $(BUILD_DIR); \
	mkdir -p $(ROOTFS_BUILD)/etc/coyote; \
	echo "$$NEW_VERSION" > $(ROOTFS_BUILD)/etc/coyote/version; \
	echo "$$BUILD_MODE" > $(ROOTFS_BUILD)/etc/coyote/build-mode; \
	echo "KERNEL_TYPE=$(CONFIG_KERNEL_TYPE)" > $(ROOTFS_BUILD)/etc/coyote/build-config; \
	echo "DEV_BUILD=$(DEV_BUILD)" >> $(ROOTFS_BUILD)/etc/coyote/build-config; \
	echo "FIRMWARE_SIZE_MB=$(CONFIG_FIRMWARE_SIZE_MB)" >> $(ROOTFS_BUILD)/etc/coyote/build-config; \
	echo "BOOT_PARTITION_SIZE_MIB=$(CONFIG_BOOT_PARTITION_SIZE_MIB)" >> $(ROOTFS_BUILD)/etc/coyote/build-config; \
	echo "DOTNET=$(CONFIG_DOTNET)" >> $(ROOTFS_BUILD)/etc/coyote/build-config; \
	echo "LOADBALANCER=$(CONFIG_LOADBALANCER)" >> $(ROOTFS_BUILD)/etc/coyote/build-config; \
	echo "VPN_IPSEC=$(CONFIG_IPSEC)" >> $(ROOTFS_BUILD)/etc/coyote/build-config; \
	echo "VPN_OPENVPN=$(CONFIG_OPENVPN)" >> $(ROOTFS_BUILD)/etc/coyote/build-config; \
	echo "VPN_WIREGUARD=$(CONFIG_WIREGUARD)" >> $(ROOTFS_BUILD)/etc/coyote/build-config; \
	if [ "$(CONFIG_LOADBALANCER)" = "1" ]; then LB_FEATURE=true; else LB_FEATURE=false; fi; \
	if [ "$(CONFIG_IPSEC)" = "1" ]; then IPSEC_FEATURE=true; else IPSEC_FEATURE=false; fi; \
	if [ "$(CONFIG_OPENVPN)" = "1" ]; then OPENVPN_FEATURE=true; else OPENVPN_FEATURE=false; fi; \
	if [ "$(CONFIG_WIREGUARD)" = "1" ]; then WIREGUARD_FEATURE=true; else WIREGUARD_FEATURE=false; fi; \
	echo '{' > $(ROOTFS_BUILD)/etc/coyote/features.json; \
	echo '    "features": {' >> $(ROOTFS_BUILD)/etc/coyote/features.json; \
	echo '        "load_balancer":' $$LB_FEATURE',' >> $(ROOTFS_BUILD)/etc/coyote/features.json; \
	echo '        "vpn": {' >> $(ROOTFS_BUILD)/etc/coyote/features.json; \
	echo '            "ipsec":' $$IPSEC_FEATURE',' >> $(ROOTFS_BUILD)/etc/coyote/features.json; \
	echo '            "openvpn":' $$OPENVPN_FEATURE',' >> $(ROOTFS_BUILD)/etc/coyote/features.json; \
	echo '            "wireguard":' $$WIREGUARD_FEATURE >> $(ROOTFS_BUILD)/etc/coyote/features.json; \
	echo '        }' >> $(ROOTFS_BUILD)/etc/coyote/features.json; \
	echo '    }' >> $(ROOTFS_BUILD)/etc/coyote/features.json; \
	echo '}' >> $(ROOTFS_BUILD)/etc/coyote/features.json; \
	echo "Coyote Linux $$NEW_VERSION" > $(ROOTFS_BUILD)/etc/issue; \
	echo "" >> $(ROOTFS_BUILD)/etc/issue; \
	cp $(ROOTFS_SRC)/etc/motd $(ROOTFS_BUILD)/etc/motd; \
	sed -i "s/Edge Firewall/$$NEW_VERSION - Edge Firewall/" $(ROOTFS_BUILD)/etc/motd; \
	echo 'NAME="Coyote Linux"' > $(ROOTFS_BUILD)/etc/os-release; \
	echo 'ID=coyote' >> $(ROOTFS_BUILD)/etc/os-release; \
	echo "VERSION=$$NEW_VERSION" >> $(ROOTFS_BUILD)/etc/os-release; \
	echo 'PRETTY_NAME="Coyote Linux $(VERSION_MAJOR).$(VERSION_MINOR)"' >> $(ROOTFS_BUILD)/etc/os-release; \
	echo 'HOME_URL="https://www.coyotelinux.com/"' >> $(ROOTFS_BUILD)/etc/os-release; \
	echo 'BUG_REPORT_URL="https://github.com/coyote-linux/coyote-alpine/issues"' >> $(ROOTFS_BUILD)/etc/os-release; \
	./mksquashfs.sh $(ROOTFS_BUILD) "$$FIRMWARE_FILE" "$(SQUASHFS_OPTS)"; \
	MAX_FIRMWARE_BYTES=$$(( $(CONFIG_FIRMWARE_SIZE_MB) * 1024 * 1024 )); \
	ACTUAL_FIRMWARE_BYTES=$$(stat -c%s "$$FIRMWARE_FILE"); \
	if [ "$$ACTUAL_FIRMWARE_BYTES" -gt "$$MAX_FIRMWARE_BYTES" ]; then \
		echo "Error: Firmware image exceeds configured size limit ($(CONFIG_FIRMWARE_SIZE_MB) MB)"; \
		echo "Actual: $$((ACTUAL_FIRMWARE_BYTES / 1024 / 1024)) MB"; \
		exit 1; \
	fi; \
	sha256sum "$$FIRMWARE_FILE" > "$$FIRMWARE_FILE.sha256"; \
	if [ -f .local-config ]; then \
		. ./.local-config; \
		if [ -n "$$COYOTE_SIGNING_KEY" ] && [ -f "$$COYOTE_SIGNING_KEY" ]; then \
			echo "Signing firmware..."; \
			./sign-firmware.sh "$$FIRMWARE_FILE"; \
		else \
			echo "Note: Firmware not signed (COYOTE_SIGNING_KEY not configured)"; \
		fi \
	else \
		echo "Note: Firmware not signed (no .local-config)"; \
	fi; \
	if [ ! -f "$(BUILD_DIR)/vmlinuz" ] || [ ! -f "$(BUILD_DIR)/initramfs.cpio.gz" ]; then \
		echo "Kernel/initramfs missing, building initramfs for update archive..."; \
		make initramfs; \
	fi; \
	./mkupdate.sh "$$NEW_VERSION" "$$FIRMWARE_FILE" "$(BUILD_DIR)/vmlinuz" "$(BUILD_DIR)/initramfs.cpio.gz" "$(BUILD_DIR)"; \
	echo ""; \
	echo "Firmware image created: $$FIRMWARE_FILE"; \
	echo "Update archive created: $(BUILD_DIR)/coyote-update-$$NEW_VERSION.tar.gz"

update-archive:
	@FIRMWARE_FILE=$$(ls -t $(BUILD_DIR)/firmware-*.squashfs 2>/dev/null | head -1); \
	if [ -z "$$FIRMWARE_FILE" ]; then \
		echo "Error: No firmware image found. Run 'make firmware' first."; \
		exit 1; \
	fi; \
	VERSION=$$(basename "$$FIRMWARE_FILE" | sed 's/firmware-\(.*\)\.squashfs/\1/'); \
	if [ ! -f "$(BUILD_DIR)/vmlinuz" ] || [ ! -f "$(BUILD_DIR)/initramfs.cpio.gz" ]; then \
		echo "Kernel/initramfs missing, building initramfs..."; \
		make initramfs; \
	fi; \
	./mkupdate.sh "$$VERSION" "$$FIRMWARE_FILE" "$(BUILD_DIR)/vmlinuz" "$(BUILD_DIR)/initramfs.cpio.gz" "$(BUILD_DIR)"

# Sign firmware image (manual target - signs the latest firmware)
sign:
	@FIRMWARE_FILE=$$(ls -t $(BUILD_DIR)/firmware-*.squashfs 2>/dev/null | head -1); \
	if [ -z "$$FIRMWARE_FILE" ]; then \
		echo "Error: No firmware image found. Run 'make firmware' first."; \
		exit 1; \
	fi; \
	./sign-firmware.sh "$$FIRMWARE_FILE"

# Build the custom kernel
kernel:
	@if [ "$(CONFIG_KERNEL_TYPE)" = "custom" ]; then \
		echo "Building custom kernel..."; \
		KERNEL_VERSION="$(KERNEL_VERSION)" ../kernel/build-kernel.sh; \
	else \
		echo "Kernel type is alpine-lts; skipping custom kernel build"; \
	fi

initramfs: kernel
	@echo "Building kernel and initramfs..."
	@mkdir -p $(BUILD_DIR)
	KERNEL_TYPE="$(CONFIG_KERNEL_TYPE)" ./mkinitramfs.sh
	@touch $(BUILD_DIR)/.initramfs-stamp
	@echo "Kernel: $(BUILD_DIR)/vmlinuz"
	@echo "Initramfs: $(BUILD_DIR)/initramfs.cpio.gz"

# Convenience targets for the actual files
$(BUILD_DIR)/vmlinuz: $(BUILD_DIR)/.initramfs-stamp
$(BUILD_DIR)/initramfs.cpio.gz: $(BUILD_DIR)/.initramfs-stamp

# Build the installer-specific initramfs (simplified, no firmware/config logic)
initramfs-installer: initramfs
	@echo "Building installer initramfs..."
	@mkdir -p $(BUILD_DIR)
	KERNEL_TYPE="$(CONFIG_KERNEL_TYPE)" ./mkinitramfs-installer.sh
	@touch $(BUILD_DIR)/.initramfs-installer-stamp
	@echo "Installer initramfs: $(BUILD_DIR)/initramfs-installer.cpio.gz"

$(BUILD_DIR)/initramfs-installer.cpio.gz: $(BUILD_DIR)/.initramfs-installer-stamp

# Build the USB installer
installer: rootfs initramfs initramfs-installer firmware
	@echo "Building installer image..."
	./mkinstaller.sh $(BUILD_DIR)
	@echo "Installer created"

# Build the ISO installer
iso: rootfs initramfs initramfs-installer firmware
	@echo "Building ISO image..."
	./mkiso.sh
	@echo "ISO created"

# Clean build outputs but keep cache
clean:
	rm -rf $(BUILD_DIR)
	rm -f ../kernel/output/kernel-*.tar.gz ../kernel/output/modules-*.tar.gz
	@echo "Build directory cleaned"

# Clean everything including cached downloads
distclean: clean
	rm -rf $(CACHE_DIR)
	rm -f ../kernel/output/kernel-*.tar.gz ../kernel/output/modules-*.tar.gz
	@echo "Cache directory cleaned"

# Force rebuild of firmware (removes all firmware files, preserves rootfs)
rebuild-firmware:
	rm -f $(BUILD_DIR)/firmware-*.squashfs $(BUILD_DIR)/firmware-*.squashfs.sha256 $(BUILD_DIR)/firmware-*.squashfs.sig
	rm -f $(BUILD_DIR)/coyote-update-*.tar.gz $(BUILD_DIR)/coyote-update-*.tar.gz.sha256 $(BUILD_DIR)/coyote-update-*.tar.gz.sig
	$(MAKE) firmware

# Force rebuild of ISO (removes firmware, installer initramfs, and ISO)
rebuild-iso:
	rm -f $(BUILD_DIR)/firmware-*.squashfs $(BUILD_DIR)/firmware-*.squashfs.sha256 $(BUILD_DIR)/firmware-*.squashfs.sig
	rm -f $(BUILD_DIR)/coyote-update-*.tar.gz $(BUILD_DIR)/coyote-update-*.tar.gz.sha256 $(BUILD_DIR)/coyote-update-*.tar.gz.sig
	rm -f $(BUILD_DIR)/.initramfs-installer-stamp $(BUILD_DIR)/initramfs-installer.cpio.gz
	rm -f $(BUILD_DIR)/coyote-*.iso $(BUILD_DIR)/coyote-*.iso.sha256
	$(MAKE) iso

# Force rebuild of installer initramfs
rebuild-initramfs-installer:
	rm -f $(BUILD_DIR)/.initramfs-installer-stamp $(BUILD_DIR)/initramfs-installer.cpio.gz
	rm -rf $(BUILD_DIR)/initramfs-installer-build
	$(MAKE) initramfs-installer

# Development helpers
.PHONY: test-boot lint shell

test-boot: firmware kernel initramfs
	@echo "Testing boot with QEMU..."
	qemu-system-x86_64 \
		-kernel $(BUILD_DIR)/vmlinuz \
		-initrd $(BUILD_DIR)/initramfs.cpio.gz \
		-append "console=ttyS0" \
		-nographic \
		-m 512M

lint:
	@echo "Checking shell scripts..."
	shellcheck $(INITRAMFS_DIR)/init || true
	shellcheck $(INITRAMFS_DIR)/init.d/*.sh || true
	shellcheck $(INITRAMFS_DIR)/recovery/*.sh || true
	shellcheck $(INITRAMFS_INSTALLER_DIR)/init || true
	shellcheck $(INITRAMFS_INSTALLER_DIR)/init.d/*.sh || true
	shellcheck mkrootfs.sh mkinstaller.sh mksquashfs.sh mkinitramfs.sh mkinitramfs-installer.sh mkiso.sh || true
	@echo "Checking PHP files..."
	find $(ROOTFS_SRC) -name "*.php" -exec php -l {} \; || true

# Open a shell in the built rootfs (for debugging)
shell:
	@echo "Note: This is a read-only view of the rootfs"
	@echo "Use 'exit' to leave"
	@cd $(ROOTFS_BUILD) && $$SHELL
