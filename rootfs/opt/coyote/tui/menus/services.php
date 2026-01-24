<?php
/**
 * Services management menu.
 */

use Coyote\System\Services;

/**
 * Display the services menu.
 */
function servicesMenu(): void
{
    $services = new Services();
    $allServices = $services->listAll();

    // Filter to Coyote-relevant services
    $relevantServices = ['sshd', 'lighttpd', 'dnsmasq', 'dhcpcd', 'haproxy', 'strongswan', 'iptables'];

    $items = [];
    foreach ($relevantServices as $name) {
        if (isset($allServices[$name])) {
            $info = $allServices[$name];
            $status = $info['running'] ? "\033[32mrunning\033[0m" : "\033[31mstopped\033[0m";
            $items[$name] = ['label' => "{$name} - {$status}"];
        }
    }

    while (true) {
        $choice = showMenu($items, 'Services Management');

        if ($choice === null) {
            return;
        }

        if (isset($items[$choice])) {
            manageService($choice, $services);
            // Refresh status
            $allServices = $services->listAll();
            foreach ($relevantServices as $name) {
                if (isset($allServices[$name])) {
                    $info = $allServices[$name];
                    $status = $info['running'] ? "\033[32mrunning\033[0m" : "\033[31mstopped\033[0m";
                    $items[$name] = ['label' => "{$name} - {$status}"];
                }
            }
        }
    }
}

/**
 * Manage a single service.
 */
function manageService(string $name, Services $services): void
{
    $status = $services->status($name);

    clearScreen();
    showHeader();
    echo "Service: {$name}\n\n";
    echo "Status: " . ($status['running'] ? 'Running' : 'Stopped') . "\n";
    echo "Enabled at boot: " . ($services->isEnabled($name) ? 'Yes' : 'No') . "\n\n";

    $items = [];
    if ($status['running']) {
        $items['stop'] = ['label' => 'Stop Service'];
        $items['restart'] = ['label' => 'Restart Service'];
    } else {
        $items['start'] = ['label' => 'Start Service'];
    }

    if ($services->isEnabled($name)) {
        $items['disable'] = ['label' => 'Disable at Boot'];
    } else {
        $items['enable'] = ['label' => 'Enable at Boot'];
    }

    $items['logs'] = ['label' => 'View Logs'];

    $choice = showMenu($items, "Manage {$name}");

    if ($choice === null) {
        return;
    }

    switch ($choice) {
        case 'start':
            if ($services->start($name)) {
                showSuccess("Service {$name} started");
            } else {
                showError("Failed to start {$name}");
            }
            break;

        case 'stop':
            if ($services->stop($name)) {
                showSuccess("Service {$name} stopped");
            } else {
                showError("Failed to stop {$name}");
            }
            break;

        case 'restart':
            if ($services->restart($name)) {
                showSuccess("Service {$name} restarted");
            } else {
                showError("Failed to restart {$name}");
            }
            break;

        case 'enable':
            if ($services->enable($name)) {
                showSuccess("Service {$name} enabled at boot");
            } else {
                showError("Failed to enable {$name}");
            }
            break;

        case 'disable':
            if ($services->disable($name)) {
                showSuccess("Service {$name} disabled at boot");
            } else {
                showError("Failed to disable {$name}");
            }
            break;

        case 'logs':
            clearScreen();
            showHeader();
            echo "Recent logs for {$name}:\n\n";
            passthru("tail -50 /var/log/messages 2>/dev/null | grep -i {$name} | tail -20");
            waitForEnter();
            return;
    }

    waitForEnter();
}
