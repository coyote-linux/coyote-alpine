<?php
/**
 * Firewall configuration menu.
 */

use Coyote\Firewall\FirewallManager;
use Coyote\Config\ConfigManager;

/**
 * Display the firewall menu.
 */
function firewallMenu(): void
{
    $items = [
        'status' => ['label' => 'Firewall Status'],
        'toggle' => ['label' => 'Enable/Disable Firewall'],
        'policy' => ['label' => 'Default Policy'],
        'rules' => ['label' => 'View Rules'],
    ];

    while (true) {
        $choice = showMenu($items, 'Firewall Settings');

        if ($choice === null) {
            return;
        }

        switch ($choice) {
            case 'status':
                showFirewallStatus();
                break;
            case 'toggle':
                toggleFirewall();
                break;
            case 'policy':
                setDefaultPolicy();
                break;
            case 'rules':
                viewFirewallRules();
                break;
        }
    }
}

/**
 * Show firewall status.
 */
function showFirewallStatus(): void
{
    clearScreen();
    showHeader();
    echo "Firewall Status\n\n";

    try {
        $manager = new FirewallManager();
        $status = $manager->getStatus();

        echo "Enabled: " . ($status['enabled'] ? 'Yes' : 'No') . "\n";
        echo "Active Connections: " . ($status['connections']['count'] ?? 0) . "\n\n";

        $iptables = $manager->getIptablesService();
        $counts = $iptables->getRuleCounts();

        echo "Rules by chain:\n";
        foreach ($counts as $chain => $count) {
            echo "  {$chain}: {$count}\n";
        }
    } catch (\Exception $e) {
        showError($e->getMessage());
    }

    waitForEnter();
}

/**
 * Toggle firewall on/off.
 */
function toggleFirewall(): void
{
    clearScreen();
    showHeader();
    echo "Toggle Firewall\n\n";

    $configManager = new ConfigManager();
    $configManager->load();
    $config = $configManager->getRunningConfig();

    $enabled = $config->get('firewall.enabled', true);
    $status = $enabled ? 'enabled' : 'disabled';
    $action = $enabled ? 'disable' : 'enable';

    echo "Firewall is currently: {$status}\n\n";

    if (confirm("Do you want to {$action} the firewall?")) {
        $config->set('firewall.enabled', !$enabled);

        if (confirm('Apply changes now?')) {
            exec('/opt/coyote/bin/apply-config');
            showSuccess("Firewall " . ($enabled ? 'disabled' : 'enabled'));
        }
    }

    waitForEnter();
}

/**
 * Set default firewall policy.
 */
function setDefaultPolicy(): void
{
    clearScreen();
    showHeader();
    echo "Default Firewall Policy\n\n";

    $configManager = new ConfigManager();
    $configManager->load();
    $config = $configManager->getRunningConfig();

    $current = $config->get('firewall.default_policy', 'drop');
    echo "Current policy: {$current}\n\n";

    $items = [
        'drop' => ['label' => 'DROP - Silently drop unauthorized traffic'],
        'reject' => ['label' => 'REJECT - Reject with ICMP message'],
        'accept' => ['label' => 'ACCEPT - Allow all traffic (not recommended)'],
    ];

    $choice = showMenu($items, 'Select Default Policy');

    if ($choice !== null && $choice !== '') {
        $config->set('firewall.default_policy', $choice);
        showSuccess("Default policy set to: {$choice}");

        if (confirm('Apply changes now?')) {
            exec('/opt/coyote/bin/apply-config');
            showSuccess("Changes applied");
        }
    }

    waitForEnter();
}

/**
 * View current firewall rules.
 */
function viewFirewallRules(): void
{
    clearScreen();
    showHeader();
    echo "Current Firewall Rules\n\n";

    try {
        $manager = new FirewallManager();
        $iptables = $manager->getIptablesService();
        $rules = $iptables->listRules();

        foreach ($rules as $line) {
            echo $line . "\n";
        }
    } catch (\Exception $e) {
        showError($e->getMessage());
    }

    waitForEnter();
}
