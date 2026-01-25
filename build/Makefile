# Coyote Linux 4 Build Makefile

VERSION := 4.0.0
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

.PHONY: all clean firmware installer iso initramfs initramfs-installer kernel rootfs distclean help rebuild-firmware rebuild-iso sign

all: firmware

help:
	@echo "Coyote Linux Build System"
	@echo ""
	@echo "Targets:"
	@echo "  rootfs              - Download Alpine and build rootfs"
	@echo "  firmware            - Build firmware squashfs image (requires rootfs)"
	@echo "  sign                - Sign the firmware image (requires COYOTE_SIGNING_KEY)"
	@echo "  kernel              - Download Alpine kernel"
	@echo "  initramfs           - Build initramfs for running system"
	@echo "  initramfs-installer - Build initramfs for installer (simplified)"
	@echo "  installer           - Build USB installer image"
	@echo "  iso                 - Build ISO installer image (for CD/VM boot)"
	@echo "  rebuild-firmware    - Force rebuild firmware (preserves rootfs)"
	@echo "  rebuild-iso         - Force rebuild firmware and ISO"
	@echo "  clean               - Remove build outputs"
	@echo "  distclean           - Remove build outputs and cached downloads"
	@echo ""
	@echo "Typical build sequence:"
	@echo "  make rootfs     # First time or when packages change"
	@echo "  make iso        # Build bootable ISO installer"
	@echo ""
	@echo "After editing rootfs/ files:"
	@echo "  make firmware   # Detects changes automatically"
	@echo "  make sign       # Sign firmware (if COYOTE_SIGNING_KEY set in .local-config)"
	@echo "  make iso        # Rebuild ISO with new firmware"

# Build the Alpine rootfs with Coyote overlay
rootfs: $(ROOTFS_BUILD)/.stamp

$(ROOTFS_BUILD)/.stamp: $(ROOTFS_FILES) apk-packages.txt mkrootfs.sh
	@echo "Building rootfs..."
	./mkrootfs.sh
	@touch $@

# Build the main firmware squashfs image
firmware: $(BUILD_DIR)/firmware-$(VERSION).squashfs

# Firmware depends on rootfs stamp AND all source files (for overlay changes)
$(BUILD_DIR)/firmware-$(VERSION).squashfs: $(ROOTFS_BUILD)/.stamp $(ROOTFS_FILES)
	@echo "Building firmware image..."
	@mkdir -p $(BUILD_DIR)
	./mksquashfs.sh $(ROOTFS_BUILD) $@ "$(SQUASHFS_OPTS)"
	sha256sum $@ > $@.sha256
	@# Auto-sign if signing key is configured
	@if [ -f .local-config ]; then \
		. ./.local-config; \
		if [ -n "$$COYOTE_SIGNING_KEY" ] && [ -f "$$COYOTE_SIGNING_KEY" ]; then \
			echo "Signing firmware..."; \
			./sign-firmware.sh $@; \
		else \
			echo "Note: Firmware not signed (COYOTE_SIGNING_KEY not configured)"; \
		fi \
	else \
		echo "Note: Firmware not signed (no .local-config)"; \
	fi
	@echo "Firmware image created: $@"

# Sign firmware image (manual target)
sign: $(BUILD_DIR)/firmware-$(VERSION).squashfs
	@./sign-firmware.sh $<

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

# Force rebuild of firmware (removes only squashfs, preserves rootfs)
rebuild-firmware:
	rm -f $(BUILD_DIR)/firmware-$(VERSION).squashfs $(BUILD_DIR)/firmware-$(VERSION).squashfs.sha256 $(BUILD_DIR)/firmware-$(VERSION).squashfs.sig
	$(MAKE) firmware

# Force rebuild of ISO (removes squashfs, installer initramfs, and ISO)
rebuild-iso:
	rm -f $(BUILD_DIR)/firmware-$(VERSION).squashfs $(BUILD_DIR)/firmware-$(VERSION).squashfs.sha256 $(BUILD_DIR)/firmware-$(VERSION).squashfs.sig
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
