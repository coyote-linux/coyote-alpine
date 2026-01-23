#!/bin/sh
#
# edit-config.sh - Edit system configuration
#

CONFIG_FILE="/mnt/boot/config/system.json"

echo ""
echo "Configuration Editor"
echo "--------------------"

# Check if config exists
if [ ! -f "$CONFIG_FILE" ]; then
    echo "No configuration file found."
    echo ""
    echo "Press Enter to continue..."
    read dummy
    return 1
fi

# Check if nano is available
if ! command -v nano >/dev/null 2>&1; then
    echo "Editor not available in recovery environment."
    echo ""
    echo "Current configuration:"
    echo "----------------------"
    cat "$CONFIG_FILE"
    echo ""
    echo "Press Enter to continue..."
    read dummy
    return 0
fi

echo ""
echo "Opening configuration in editor..."
echo "Save with Ctrl+O, Exit with Ctrl+X"
echo ""
echo "Press Enter to continue..."
read dummy

# Remount boot partition read-write
mount -o remount,rw /mnt/boot

# Edit config
nano "$CONFIG_FILE"

# Remount read-only
mount -o remount,ro /mnt/boot

echo ""
echo "Configuration saved."
echo "Changes will take effect on next boot."
echo ""
echo "Press Enter to continue..."
read dummy
