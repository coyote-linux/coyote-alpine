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

    // Get existing interfaces array
    $interfaces = $config->get('network.interfaces', []);

    // Find existing config for this interface, or prepare to add new
    $ifIndex = null;
    foreach ($interfaces as $i => $iface) {
        if (($iface['name'] ?? '') === $name) {
            $ifIndex = $i;
            break;
        }
    }

    $ifConfig = null;

    switch ($choice) {
        case 'dhcp':
            $ifConfig = [
                'name' => $name,
                'type' => 'dhcp',
                'enabled' => true,
            ];
            showSuccess("Interface {$name} set to DHCP");
            break;

        case 'static':
            $address = prompt('IP Address (CIDR notation)', '192.168.1.1/24');
            $gateway = prompt('Gateway (optional)');

            $ifConfig = [
                'name' => $name,
                'type' => 'static',
                'enabled' => true,
                'addresses' => [$address],
            ];

            // Add route for gateway if specified
            if ($gateway) {
                $routes = $config->get('network.routes', []);
                // Check if default route already exists for this interface
                $routeExists = false;
                foreach ($routes as $i => $route) {
                    if (($route['destination'] ?? '') === 'default' &&
                        ($route['interface'] ?? '') === $name) {
                        $routes[$i]['gateway'] = $gateway;
                        $routeExists = true;
                        break;
                    }
                }
                if (!$routeExists) {
                    $routes[] = [
                        'destination' => 'default',
                        'gateway' => $gateway,
                        'interface' => $name,
                    ];
                }
                $config->set('network.routes', $routes);
            }

            showSuccess("Interface {$name} configured with {$address}");
            break;

        case 'disable':
            $ifConfig = [
                'name' => $name,
                'type' => 'disabled',
                'enabled' => false,
            ];
            showSuccess("Interface {$name} disabled");
            break;
    }

    // Update or add interface config
    if ($ifConfig !== null) {
        if ($ifIndex !== null) {
            $interfaces[$ifIndex] = $ifConfig;
        } else {
            $interfaces[] = $ifConfig;
        }
        $config->set('network.interfaces', $interfaces);
    }

    if (confirm('Apply changes now?')) {
        // Save to running config before applying
        $configManager->saveRunning();

        // Apply the configuration from running-config
        $runningConfigFile = '/tmp/running-config/system.json';
        exec("COYOTE_CONFIG_FILE={$runningConfigFile} /opt/coyote/bin/apply-config 2>&1", $output, $ret);

        if ($ret === 0) {
            showSuccess("Changes applied");

            if (confirm('Save changes permanently?')) {
                if ($configManager->save()) {
                    showSuccess("Configuration saved to disk");
                } else {
                    showError("Failed to save configuration to disk");
                }
            }
        } else {
            showError("Failed to apply configuration");
        }
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

    // Support both array format and nested format for DNS
    $currentDns = $config->get('network.dns', []);
    if (is_array($currentDns) && !isset($currentDns['nameservers'])) {
        // Flat array format
        $current = $currentDns;
    } else {
        // Nested format with 'nameservers' key
        $current = $currentDns['nameservers'] ?? $currentDns;
    }
    if (!is_array($current)) {
        $current = [];
    }

    echo "Current nameservers: " . (empty($current) ? 'none' : implode(', ', $current)) . "\n\n";

    $ns1 = prompt('Primary DNS', $current[0] ?? '8.8.8.8');
    $ns2 = prompt('Secondary DNS (optional)', $current[1] ?? '');

    $nameservers = [$ns1];
    if ($ns2) {
        $nameservers[] = $ns2;
    }

    // Use flat array format to match apply-config expectations
    $config->set('network.dns', $nameservers);
    showSuccess("DNS servers updated");

    if (confirm('Apply changes now?')) {
        // Save to running config before applying
        $configManager->saveRunning();

        // Apply the configuration from running-config
        $runningConfigFile = '/tmp/running-config/system.json';
        exec("COYOTE_CONFIG_FILE={$runningConfigFile} /opt/coyote/bin/apply-config 2>&1", $output, $ret);

        if ($ret === 0) {
            showSuccess("Changes applied");

            if (confirm('Save changes permanently?')) {
                if ($configManager->save()) {
                    showSuccess("Configuration saved to disk");
                } else {
                    showError("Failed to save configuration to disk");
                }
            }
        } else {
            showError("Failed to apply configuration");
        }
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
            // Save to running config before applying
            $configManager->saveRunning();

            // Apply the configuration from running-config
            $runningConfigFile = '/tmp/running-config/system.json';
            exec("COYOTE_CONFIG_FILE={$runningConfigFile} /opt/coyote/bin/apply-config 2>&1", $output, $ret);

            if ($ret === 0) {
                showSuccess("Changes applied");

                if (confirm('Save changes permanently?')) {
                    if ($configManager->save()) {
                        showSuccess("Configuration saved to disk");
                    } else {
                        showError("Failed to save configuration to disk");
                    }
                }
            } else {
                showError("Failed to apply configuration");
            }
        }
    }

    waitForEnter();
}
