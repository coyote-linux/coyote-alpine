# Coyote Linux 4 Build Makefile

# Version with auto-incrementing build number
# Major.Minor are fixed, build number increments with each build
VERSION_MAJOR := 4
VERSION_MINOR := 0
BUILD_NUMBER_FILE := .build-number
BUILD_NUMBER := $(shell cat $(BUILD_NUMBER_FILE) 2>/dev/null || echo 0)
VERSION := $(VERSION_MAJOR).$(VERSION_MINOR).$(BUILD_NUMBER)

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

.PHONY: all clean firmware installer iso initramfs initramfs-installer kernel rootfs distclean help rebuild-firmware rebuild-iso sign bump-version show-version

all: firmware

# Show current version
show-version:
	@echo "Current version: $(VERSION)"
	@echo "Next build will be: $(VERSION_MAJOR).$(VERSION_MINOR).$$(($(BUILD_NUMBER) + 1))"

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
	@echo "  rootfs              - Download Alpine and build rootfs"
	@echo "  firmware            - Build firmware squashfs image (increments build number)"
	@echo "  sign                - Sign the latest firmware image"
	@echo "  kernel              - Download Alpine kernel"
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
	@echo "  make rootfs     # First time or when packages change"
	@echo "  make iso        # Build bootable ISO installer"
	@echo ""
	@echo "After editing rootfs/ files:"
	@echo "  make firmware   # Detects changes, increments build number"
	@echo "  make sign       # Sign firmware (if COYOTE_SIGNING_KEY set in .local-config)"
	@echo "  make iso        # Rebuild ISO with new firmware"

# Build the Alpine rootfs with Coyote overlay
rootfs: $(ROOTFS_BUILD)/.stamp

$(ROOTFS_BUILD)/.stamp: $(ROOTFS_FILES) apk-packages.txt mkrootfs.sh
	@echo "Building rootfs..."
	./mkrootfs.sh
	@touch $@

# Build the main firmware squashfs image
# This target increments the build number and creates the firmware
firmware: $(ROOTFS_BUILD)/.stamp $(ROOTFS_FILES)
	@# Increment build number
	@NEW_BUILD=$$(($(BUILD_NUMBER) + 1)); \
	echo $$NEW_BUILD > $(BUILD_NUMBER_FILE); \
	NEW_VERSION="$(VERSION_MAJOR).$(VERSION_MINOR).$$NEW_BUILD"; \
	FIRMWARE_FILE="$(BUILD_DIR)/firmware-$$NEW_VERSION.squashfs"; \
	echo "Building firmware version $$NEW_VERSION..."; \
	mkdir -p $(BUILD_DIR); \
	mkdir -p $(ROOTFS_BUILD)/etc/coyote; \
	echo "$$NEW_VERSION" > $(ROOTFS_BUILD)/etc/coyote/version; \
	echo "Coyote Linux $$NEW_VERSION" > $(ROOTFS_BUILD)/etc/issue; \
	echo "" >> $(ROOTFS_BUILD)/etc/issue; \
	./mksquashfs.sh $(ROOTFS_BUILD) "$$FIRMWARE_FILE" "$(SQUASHFS_OPTS)"; \
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
	echo ""; \
	echo "Firmware image created: $$FIRMWARE_FILE"

# Sign firmware image (manual target - signs the latest firmware)
sign:
	@FIRMWARE_FILE=$$(ls -t $(BUILD_DIR)/firmware-*.squashfs 2>/dev/null | head -1); \
	if [ -z "$$FIRMWARE_FILE" ]; then \
		echo "Error: No firmware image found. Run 'make firmware' first."; \
		exit 1; \
	fi; \
	./sign-firmware.sh "$$FIRMWARE_FILE"

# Build the kernel and initramfs (mkinitramfs.sh creates both)
kernel: $(BUILD_DIR)/.initramfs-stamp

initramfs: $(BUILD_DIR)/.initramfs-stamp

$(BUILD_DIR)/.initramfs-stamp: mkinitramfs.sh $(INITRAMFS_FILES)
	@echo "Building kernel and initramfs..."
	@mkdir -p $(BUILD_DIR)
	./mkinitramfs.sh
	@touch $@
	@echo "Kernel: $(BUILD_DIR)/vmlinuz"
	@echo "Initramfs: $(BUILD_DIR)/initramfs.cpio.gz"

# Convenience targets for the actual files
$(BUILD_DIR)/vmlinuz: $(BUILD_DIR)/.initramfs-stamp
$(BUILD_DIR)/initramfs.cpio.gz: $(BUILD_DIR)/.initramfs-stamp

# Build the installer-specific initramfs (simplified, no firmware/config logic)
initramfs-installer: $(BUILD_DIR)/.initramfs-installer-stamp

$(BUILD_DIR)/.initramfs-installer-stamp: $(BUILD_DIR)/.initramfs-stamp mkinitramfs-installer.sh $(INITRAMFS_INSTALLER_FILES)
	@echo "Building installer initramfs..."
	@mkdir -p $(BUILD_DIR)
	./mkinitramfs-installer.sh
	@touch $@
	@echo "Installer initramfs: $(BUILD_DIR)/initramfs-installer.cpio.gz"

$(BUILD_DIR)/initramfs-installer.cpio.gz: $(BUILD_DIR)/.initramfs-installer-stamp

# Build the USB installer
installer: firmware initramfs-installer
	@echo "Building installer image..."
	./mkinstaller.sh $(BUILD_DIR)
	@echo "Installer created"

# Build the ISO installer
iso: firmware initramfs-installer
	@echo "Building ISO image..."
	./mkiso.sh
	@echo "ISO created"

# Clean build outputs but keep cache
clean:
	rm -rf $(BUILD_DIR)
	@echo "Build directory cleaned"

# Clean everything including cached downloads
distclean: clean
	rm -rf $(CACHE_DIR)
	@echo "Cache directory cleaned"

# Force rebuild of firmware (removes all firmware files, preserves rootfs)
rebuild-firmware:
	rm -f $(BUILD_DIR)/firmware-*.squashfs $(BUILD_DIR)/firmware-*.squashfs.sha256 $(BUILD_DIR)/firmware-*.squashfs.sig
	$(MAKE) firmware

# Force rebuild of ISO (removes firmware, installer initramfs, and ISO)
rebuild-iso:
	rm -f $(BUILD_DIR)/firmware-*.squashfs $(BUILD_DIR)/firmware-*.squashfs.sha256 $(BUILD_DIR)/firmware-*.squashfs.sig
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

test-boot: firmware kernel
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
