# Coyote Linux 4 Build Makefile

VERSION := 4.0.0
BUILD_DIR := ../output
ROOTFS_SRC := ../rootfs
ROOTFS_BUILD := ../output/rootfs
INITRAMFS_DIR := ../initramfs
CACHE_DIR := ../.cache

SQUASHFS_OPTS := -comp xz -Xdict-size 100%

.PHONY: all clean firmware installer initramfs rootfs distclean help

all: firmware

help:
	@echo "Coyote Linux Build System"
	@echo ""
	@echo "Targets:"
	@echo "  rootfs      - Download Alpine and build rootfs"
	@echo "  firmware    - Build firmware squashfs image (requires rootfs)"
	@echo "  initramfs   - Build initramfs"
	@echo "  installer   - Build USB installer image"
	@echo "  clean       - Remove build outputs"
	@echo "  distclean   - Remove build outputs and cached downloads"
	@echo ""
	@echo "Typical build sequence:"
	@echo "  make rootfs     # First time or when packages change"
	@echo "  make installer  # Build bootable installer"

# Build the Alpine rootfs with Coyote overlay
rootfs: $(ROOTFS_BUILD)/.stamp

$(ROOTFS_BUILD)/.stamp: $(ROOTFS_SRC) apk-packages.txt mkrootfs.sh
	@echo "Building rootfs..."
	./mkrootfs.sh
	@touch $@

# Build the main firmware squashfs image
firmware: $(BUILD_DIR)/firmware-$(VERSION).squashfs

$(BUILD_DIR)/firmware-$(VERSION).squashfs: $(ROOTFS_BUILD)/.stamp
	@echo "Building firmware image..."
	@mkdir -p $(BUILD_DIR)
	./mksquashfs.sh $(ROOTFS_BUILD) $@ "$(SQUASHFS_OPTS)"
	sha256sum $@ > $@.sha256
	@echo "Firmware image created: $@"

# Build the initramfs
initramfs: $(BUILD_DIR)/initramfs.cpio.gz

$(BUILD_DIR)/initramfs.cpio.gz: $(INITRAMFS_DIR)
	@echo "Building initramfs..."
	@mkdir -p $(BUILD_DIR)
	cd $(INITRAMFS_DIR) && find . | cpio -o -H newc | gzip > $(abspath $(BUILD_DIR))/initramfs.cpio.gz
	@echo "Initramfs created: $@"

# Build the USB installer
installer: firmware initramfs
	@echo "Building installer image..."
	./mkinstaller.sh $(BUILD_DIR)
	@echo "Installer created"

# Clean build outputs but keep cache
clean:
	rm -rf $(BUILD_DIR)
	@echo "Build directory cleaned"

# Clean everything including cached downloads
distclean: clean
	rm -rf $(CACHE_DIR)
	@echo "Cache directory cleaned"

# Development helpers
.PHONY: test-boot lint shell

test-boot: firmware initramfs
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
	shellcheck mkrootfs.sh mkinstaller.sh mksquashfs.sh || true
	@echo "Checking PHP files..."
	find $(ROOTFS_SRC) -name "*.php" -exec php -l {} \; || true

# Open a shell in the built rootfs (for debugging)
shell:
	@echo "Note: This is a read-only view of the rootfs"
	@echo "Use 'exit' to leave"
	@cd $(ROOTFS_BUILD) && $$SHELL
