<?php
/**
 * Network configuration menu.
 */

use Coyote\System\Network;
use Coyote\Config\ConfigManager;

/**
 * Display the network menu.
 */
function networkMenu(): void
{
    $items = [
        'interfaces' => ['label' => 'Configure Interfaces'],
        'routes' => ['label' => 'Static Routes'],
        'dns' => ['label' => 'DNS Settings'],
        'hostname' => ['label' => 'Hostname'],
    ];

    while (true) {
        $choice = showMenu($items, 'Network Configuration');

        if ($choice === null) {
            return;
        }

        switch ($choice) {
            case 'interfaces':
                configureInterfaces();
                break;
            case 'routes':
                configureRoutes();
                break;
            case 'dns':
                configureDns();
                break;
            case 'hostname':
                configureHostname();
                break;
        }
    }
}

/**
 * Configure network interfaces.
 */
function configureInterfaces(): void
{
    $network = new Network();
    $interfaces = $network->getInterfaces();

    clearScreen();
    showHeader();
    echo "Network Interfaces\n\n";

    // Build menu from interfaces
    $items = [];
    foreach ($interfaces as $name => $info) {
        $state = $info['state'] ?? 'unknown';
        $addr = $info['address'] ?? 'not configured';
        $items[$name] = ['label' => "{$name} ({$state}) - {$addr}"];
    }

    while (true) {
        $choice = showMenu($items, 'Select Interface to Configure');

        if ($choice === null) {
            return;
        }

        if (isset($interfaces[$choice])) {
            configureInterface($choice, $interfaces[$choice]);
        }
    }
}

/**
 * Configure a single interface.
 */
function configureInterface(string $name, array $current): void
{
    clearScreen();
    showHeader();
    echo "Configure Interface: {$name}\n\n";

    echo "Current configuration:\n";
    echo "  State: " . ($current['state'] ?? 'unknown') . "\n";
    echo "  Address: " . ($current['address'] ?? 'not configured') . "\n";
    echo "  MAC: " . ($current['mac'] ?? 'unknown') . "\n\n";

    $items = [
        'dhcp' => ['label' => 'Configure as DHCP'],
        'static' => ['label' => 'Configure Static IP'],
        'disable' => ['label' => 'Disable Interface'],
    ];

    $choice = showMenu($items, 'Configuration Type');

    if ($choice === null) {
        return;
    }

    $configManager = new ConfigManager();
    $configManager->load();
    $config = $configManager->getRunningConfig();

    switch ($choice) {
        case 'dhcp':
            $config->set("network.interfaces.{$name}", [
                'device' => $name,
                'type' => 'dhcp',
            ]);
            showSuccess("Interface {$name} set to DHCP");
            break;

        case 'static':
            $address = prompt('IP Address (CIDR notation)', '192.168.1.1/24');
            $gateway = prompt('Gateway (optional)');

            $ifConfig = [
                'device' => $name,
                'type' => 'static',
                'address' => $address,
            ];

            if ($gateway) {
                $ifConfig['gateway'] = $gateway;
            }

            $config->set("network.interfaces.{$name}", $ifConfig);
            showSuccess("Interface {$name} configured with {$address}");
            break;

        case 'disable':
            $config->set("network.interfaces.{$name}", [
                'device' => $name,
                'type' => 'disabled',
            ]);
            showSuccess("Interface {$name} disabled");
            break;
    }

    if (confirm('Apply changes now?')) {
        exec('/opt/coyote/bin/apply-config');
        showSuccess("Changes applied");
    }

    waitForEnter();
}

/**
 * Configure static routes.
 */
function configureRoutes(): void
{
    clearScreen();
    showHeader();
    echo "Static Routes\n\n";
    showInfo("Route configuration not yet implemented in TUI.");
    waitForEnter();
}

/**
 * Configure DNS settings.
 */
function configureDns(): void
{
    clearScreen();
    showHeader();
    echo "DNS Settings\n\n";

    $configManager = new ConfigManager();
    $configManager->load();
    $config = $configManager->getRunningConfig();

    $current = $config->get('network.dns.nameservers', []);
    echo "Current nameservers: " . (empty($current) ? 'none' : implode(', ', $current)) . "\n\n";

    $ns1 = prompt('Primary DNS', $current[0] ?? '8.8.8.8');
    $ns2 = prompt('Secondary DNS (optional)', $current[1] ?? '');

    $nameservers = [$ns1];
    if ($ns2) {
        $nameservers[] = $ns2;
    }

    $config->set('network.dns.nameservers', $nameservers);
    showSuccess("DNS servers updated");

    if (confirm('Apply changes now?')) {
        exec('/opt/coyote/bin/apply-config');
        showSuccess("Changes applied");
    }

    waitForEnter();
}

/**
 * Configure hostname.
 */
function configureHostname(): void
{
    clearScreen();
    showHeader();
    echo "Hostname Configuration\n\n";

    $current = gethostname();
    echo "Current hostname: {$current}\n\n";

    $hostname = prompt('New hostname', $current);

    if ($hostname !== $current) {
        $configManager = new ConfigManager();
        $configManager->load();
        $config = $configManager->getRunningConfig();
        $config->set('system.hostname', $hostname);

        showSuccess("Hostname will be changed to: {$hostname}");

        if (confirm('Apply changes now?')) {
            exec('/opt/coyote/bin/apply-config');
            showSuccess("Changes applied");
        }
    }

    waitForEnter();
}
