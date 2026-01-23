#!/bin/sh
#
# tty1-handler.sh - Checks for installer mode and runs appropriate program
#

if grep -q "installer" /proc/cmdline 2>/dev/null; then
    # Installer mode - run the installer
    export BOOT_MEDIA="/mnt/boot"
    exec /usr/bin/installer.sh
else
    # Normal mode - run getty for login
    exec /sbin/getty 38400 tty1
fi
