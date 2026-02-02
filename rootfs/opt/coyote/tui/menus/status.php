<?php
/**
 * Status display menu.
 */

use Coyote\System\Hardware;
use Coyote\System\Network;
use Coyote\System\Services;

/**
 * Display the status menu.
 */
function statusMenu(): void
{
    $items = [
        'overview' => ['label' => 'System Overview'],
        'network' => ['label' => 'Network Status'],
        'services' => ['label' => 'Services Status'],
        'logs' => ['label' => 'View System Logs'],
    ];

    while (true) {
        $choice = showMenu($items, 'System Status');

        if ($choice === null) {
            return;
        }

        switch ($choice) {
            case 'overview':
                showOverview();
                break;
            case 'network':
                showNetworkStatus();
                break;
            case 'services':
                showServicesStatus();
                break;
            case 'logs':
                showLogs();
                break;
        }
    }
}

/**
 * Show system overview.
 */
function showOverview(): void
{
    clearScreen();
    showHeader();
    echo "System Overview\n\n";

    $hardware = new Hardware();

    // Hostname and uptime
    echo "Hostname: " . gethostname() . "\n";

    $uptime = @file_get_contents('/proc/uptime');
    if ($uptime) {
        $seconds = (int)explode(' ', $uptime)[0];
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        echo "Uptime: {$days}d {$hours}h {$minutes}m\n";
    }

    echo "\n";

    // CPU
    $cpu = $hardware->getCpuInfo();
    echo "CPU: {$cpu['model']} ({$cpu['cores']} cores)\n";

    // Load
    $load = $hardware->getLoadAverage();
    echo "Load: " . implode(' ', $load) . "\n";

    echo "\n";

    // Memory
    $mem = $hardware->getMemoryInfo();
    $totalMB = round($mem['total'] / 1024 / 1024);
    $availMB = round($mem['available'] / 1024 / 1024);
    $usedMB = $totalMB - $availMB;
    $usedPct = round(($usedMB / $totalMB) * 100);

    echo "Memory: {$usedMB}MB / {$totalMB}MB ({$usedPct}%)\n";

    // Disk
    $diskTotal = disk_total_space('/');
    $diskFree = disk_free_space('/');
    $diskUsed = $diskTotal - $diskFree;
    $diskPct = round(($diskUsed / $diskTotal) * 100);

    $diskTotalGB = round($diskTotal / 1024 / 1024 / 1024, 1);
    $diskUsedGB = round($diskUsed / 1024 / 1024 / 1024, 1);

    echo "Disk: {$diskUsedGB}GB / {$diskTotalGB}GB ({$diskPct}%)\n";

    waitForEnter();
}

/**
 * Show network status.
 */
function showNetworkStatus(): void
{
    clearScreen();
    showHeader();
    echo "Network Status\n\n";

    $network = new Network();
    $interfaces = $network->getInterfaces();

    echo "Interfaces:\n";
    echo str_repeat('-', 70) . "\n";
    printf("%-12s %-8s %-18s %-20s\n", "Interface", "State", "Address", "MAC");
    echo str_repeat('-', 70) . "\n";

    foreach ($interfaces as $name => $info) {
        $state = $info['state'] ?? 'unknown';
        $addr = $info['address'] ?? '-';
        $mac = $info['mac'] ?? '-';
        printf("%-12s %-8s %-18s %-20s\n", $name, $state, $addr, $mac);
    }

    echo "\n";

    // Routes
    echo "Default Route:\n";
    $routes = $network->getRoutes();
    foreach ($routes as $route) {
        if (($route['destination'] ?? '') === 'default') {
            echo "  Gateway: " . ($route['gateway'] ?? '-') . " via " . ($route['interface'] ?? '-') . "\n";
        }
    }

    echo "\n";

    // DNS
    echo "DNS Servers:\n";
    $dns = $network->getDnsServers();
    foreach ($dns as $server) {
        echo "  {$server}\n";
    }

    waitForEnter();
}

/**
 * Show services status.
 */
function showServicesStatus(): void
{
    clearScreen();
    showHeader();
    echo "Services Status\n\n";

    $services = new Services();

    // Service name => display name mapping
    $relevantServices = [
        'dropbear' => 'SSH Server',
        'lighttpd' => 'Web Server',
        'dnsmasq' => 'DNS/DHCP',
        'dhcpcd' => 'DHCP Client',
        'haproxy' => 'Load Balancer',
        'strongswan' => 'VPN Server',
        'coyote-init' => 'Coyote Init',
        'coyote-config' => 'Coyote Config',
    ];

    printf("%-20s %-12s %-12s\n", "Service", "Running", "Enabled");
    echo str_repeat('-', 50) . "\n";

    foreach ($relevantServices as $name => $displayName) {
        $isCore = $services->isCoreService($name);
        $running = $services->isRunning($name);
        $enabled = $isCore ? true : $services->isEnabled($name);

        $runningStr = $running ? "\033[32mYes\033[0m" : "\033[31mNo\033[0m";
        $enabledStr = $isCore ? "Always" : ($enabled ? "Yes" : "No");
        printf("%-20s %-12s %-12s\n", $displayName, $runningStr, $enabledStr);
    }

    waitForEnter();
}

/**
 * Show system logs.
 */
function showLogs(): void
{
    clearScreen();
    showHeader();
    echo "Recent System Logs\n\n";

    passthru("tail -50 /var/log/messages 2>/dev/null || echo 'No logs available'");

    waitForEnter();
}
