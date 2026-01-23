#!/bin/sh
#
# recovery-menu.sh - Recovery menu for boot issues
#

clear

while true; do
    echo ""
    echo "========================================"
    echo "   Coyote Linux 4 - Recovery Menu"
    echo "========================================"
    echo ""
    echo "  1) Rollback to previous firmware"
    echo "  2) Edit configuration"
    echo "  3) Reset configuration to defaults"
    echo "  4) Drop to shell"
    echo "  5) Continue normal boot"
    echo ""
    echo -n "Select option [1-5]: "

    read choice

    case "$choice" in
        1)
            /recovery/rollback-firmware.sh
            ;;
        2)
            /recovery/edit-config.sh
            ;;
        3)
            echo ""
            echo "This will reset all configuration to factory defaults."
            echo -n "Are you sure? [y/N]: "
            read confirm
            if [ "$confirm" = "y" ] || [ "$confirm" = "Y" ]; then
                # Remount config partition read-write
                mount -o remount,rw /mnt/boot
                rm -f /mnt/boot/config/system.json
                mount -o remount,ro /mnt/boot
                echo "Configuration reset. Rebooting..."
                sleep 2
                reboot -f
            fi
            ;;
        4)
            echo ""
            echo "Entering shell. Type 'exit' to return to menu."
            /bin/sh
            ;;
        5)
            echo "Continuing boot..."
            return 0
            ;;
        *)
            echo "Invalid option"
            ;;
    esac
done
