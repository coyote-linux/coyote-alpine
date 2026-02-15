<?php
/**
 * Main menu for the console TUI.
 */

require_once TUI_ROOT . '/menus/network.php';
require_once TUI_ROOT . '/menus/firewall.php';
require_once TUI_ROOT . '/menus/services.php';
require_once TUI_ROOT . '/menus/system.php';
require_once TUI_ROOT . '/menus/firmware.php';
require_once TUI_ROOT . '/menus/status.php';

/**
 * Display the main menu.
 */
function mainMenu(): void
{
    $items = [
        'status' => ['label' => 'System Status'],
        'network' => ['label' => 'Network Configuration'],
        'firewall' => ['label' => 'Firewall Settings'],
        'nat' => ['label' => 'NAT / Port Forwarding'],
        'vpn' => ['label' => 'VPN Tunnels'],
        'loadbalancer' => ['label' => 'Load Balancer'],
        'services' => ['label' => 'Services'],
        'system' => ['label' => 'System Settings'],
        'shell' => ['label' => 'Drop to Shell'],
        'reboot' => ['label' => 'Reboot System'],
    ];

    while (true) {
        $choice = showMenu($items, 'Main Menu');

        if ($choice === null) {
            if (confirm('Exit console menu?')) {
                clearScreen();
                exit(0);
            }
            continue;
        }

        switch ($choice) {
            case 'status':
                statusMenu();
                break;
            case 'network':
                networkMenu();
                break;
            case 'firewall':
                firewallMenu();
                break;
            case 'nat':
                natMenu();
                break;
            case 'vpn':
                vpnMenu();
                break;
            case 'loadbalancer':
                loadbalancerMenu();
                break;
            case 'services':
                servicesMenu();
                break;
            case 'system':
                systemMenu();
                break;
            case 'shell':
                clearScreen();
                echo "Type 'exit' to return to the menu.\n\n";
                passthru('/bin/sh');
                break;
            case 'reboot':
                if (confirm('Are you sure you want to reboot?')) {
                    exec('reboot');
                }
                break;
        }
    }
}

/**
 * NAT menu placeholder.
 */
function natMenu(): void
{
    clearScreen();
    showHeader();
    echo "NAT / Port Forwarding\n\n";
    showInfo("NAT configuration not yet implemented in TUI.");
    showInfo("Please use the web admin interface.");
    waitForEnter();
}

/**
 * VPN menu placeholder.
 */
function vpnMenu(): void
{
    clearScreen();
    showHeader();
    echo "VPN Tunnels\n\n";
    showInfo("VPN configuration not yet implemented in TUI.");
    showInfo("Please use the web admin interface.");
    waitForEnter();
}

/**
 * Load balancer menu placeholder.
 */
function loadbalancerMenu(): void
{
    clearScreen();
    showHeader();
    echo "Load Balancer\n\n";
    showInfo("Load balancer configuration not yet implemented in TUI.");
    showInfo("Please use the web admin interface.");
    waitForEnter();
}
