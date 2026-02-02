<?php
/**
 * Services management menu.
 */

use Coyote\System\Services;

/**
 * Core services that are always running and cannot be managed.
 * Access is controlled via firewall ACLs.
 */
const CORE_SERVICES = ['dropbear', 'lighttpd'];

/**
 * Display the services menu.
 */
function servicesMenu(): void
{
    $services = new Services();

    // Service name => display name mapping
    $relevantServices = [
        'dropbear' => 'SSH Server',
        'lighttpd' => 'Web Server',
        'dnsmasq' => 'DNS/DHCP',
        'dhcpcd' => 'DHCP Client',
        'haproxy' => 'Load Balancer',
        'strongswan' => 'VPN Server',
    ];

    while (true) {
        $items = [];
        foreach ($relevantServices as $name => $displayName) {
            $isCore = in_array($name, CORE_SERVICES);
            $running = $services->isRunning($name);
            $status = $running ? "\033[32mrunning\033[0m" : "\033[31mstopped\033[0m";
            $coreLabel = $isCore ? " [Core]" : "";
            $items[$name] = ['label' => "{$displayName}{$coreLabel} - {$status}"];
        }

        $choice = showMenu($items, 'Services Management');

        if ($choice === null) {
            return;
        }

        if (isset($items[$choice])) {
            manageService($choice, $services, $relevantServices[$choice] ?? $choice);
        }
    }
}

/**
 * Manage a single service.
 */
function manageService(string $name, Services $services, string $displayName = ''): void
{
    $isCore = in_array($name, CORE_SERVICES);
    $running = $services->isRunning($name);
    $enabled = $isCore ? true : $services->isEnabled($name);

    if ($displayName === '') {
        $displayName = $name;
    }

    clearScreen();
    showHeader();
    echo "Service: {$displayName}\n\n";
    echo "Status: " . ($running ? 'Running' : 'Stopped') . "\n";
    if ($isCore) {
        echo "Type: Core Service (always enabled)\n";
        echo "\nCore services cannot be stopped or disabled.\n";
        echo "Use Firewall > Access Controls to manage access.\n\n";
    } else {
        echo "Enabled at boot: " . ($enabled ? 'Yes' : 'No') . "\n\n";
    }

    $items = [];

    if (!$isCore) {
        if ($running) {
            $items['stop'] = ['label' => 'Stop Service'];
            $items['restart'] = ['label' => 'Restart Service'];
        } else {
            $items['start'] = ['label' => 'Start Service'];
        }

        if ($enabled) {
            $items['disable'] = ['label' => 'Disable at Boot'];
        } else {
            $items['enable'] = ['label' => 'Enable at Boot'];
        }
    }

    $items['logs'] = ['label' => 'View Logs'];

    $choice = showMenu($items, "Manage {$displayName}");

    if ($choice === null) {
        return;
    }

    switch ($choice) {
        case 'start':
            if ($services->start($name)) {
                showSuccess("Service {$displayName} started");
            } else {
                showError("Failed to start {$displayName}");
            }
            break;

        case 'stop':
            if ($services->stop($name)) {
                showSuccess("Service {$displayName} stopped");
            } else {
                showError("Failed to stop {$displayName}");
            }
            break;

        case 'restart':
            if ($services->restart($name)) {
                showSuccess("Service {$displayName} restarted");
            } else {
                showError("Failed to restart {$displayName}");
            }
            break;

        case 'enable':
            if ($services->enable($name)) {
                showSuccess("Service {$displayName} enabled at boot");
            } else {
                showError("Failed to enable {$displayName}");
            }
            break;

        case 'disable':
            if ($services->disable($name)) {
                showSuccess("Service {$displayName} disabled at boot");
            } else {
                showError("Failed to disable {$displayName}");
            }
            break;

        case 'logs':
            clearScreen();
            showHeader();
            echo "Recent logs for {$displayName}:\n\n";
            passthru("tail -50 /var/log/messages 2>/dev/null | grep -i {$name} | tail -20");
            waitForEnter();
            return;
    }

    waitForEnter();
}
