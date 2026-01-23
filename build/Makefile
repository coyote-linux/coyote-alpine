# Coyote Linux 4 Build Makefile

VERSION := 4.0.0
BUILD_DIR := ../output
ROOTFS_DIR := ../rootfs
INITRAMFS_DIR := ../initramfs

SQUASHFS_OPTS := -comp xz -Xdict-size 100%

.PHONY: all clean firmware installer initramfs

all: firmware

# Build the main firmware squashfs image
firmware: $(BUILD_DIR)/firmware-$(VERSION).squashfs

$(BUILD_DIR)/firmware-$(VERSION).squashfs: $(ROOTFS_DIR)
	@echo "Building firmware image..."
	@mkdir -p $(BUILD_DIR)
	./mksquashfs.sh $(ROOTFS_DIR) $@ "$(SQUASHFS_OPTS)"
	sha256sum $@ > $@.sha256
	@echo "Firmware image created: $@"

# Build the initramfs
initramfs: $(BUILD_DIR)/initramfs.cpio.gz

$(BUILD_DIR)/initramfs.cpio.gz: $(INITRAMFS_DIR)
	@echo "Building initramfs..."
	@mkdir -p $(BUILD_DIR)
	cd $(INITRAMFS_DIR) && find . | cpio -o -H newc | gzip > $(BUILD_DIR)/initramfs.cpio.gz
	@echo "Initramfs created: $@"

# Build the USB installer
installer: firmware initramfs
	@echo "Building installer image..."
	./mkinstaller.sh $(BUILD_DIR)
	@echo "Installer created"

clean:
	rm -rf $(BUILD_DIR)
	@echo "Build directory cleaned"

# Development helpers
.PHONY: test-boot lint

test-boot:
	@echo "Testing boot with QEMU..."
	qemu-system-x86_64 \
		-kernel /boot/vmlinuz \
		-initrd $(BUILD_DIR)/initramfs.cpio.gz \
		-append "console=ttyS0" \
		-nographic \
		-m 512M

lint:
	@echo "Checking shell scripts..."
	shellcheck $(INITRAMFS_DIR)/init
	shellcheck $(INITRAMFS_DIR)/init.d/*.sh
	shellcheck $(INITRAMFS_DIR)/recovery/*.sh
	@echo "Checking PHP files..."
	find $(ROOTFS_DIR) -name "*.php" -exec php -l {} \;
