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
        'webadmin' => ['label' => 'Web Admin Hosts'],
        'ssh' => ['label' => 'SSH Access Hosts'],
        'blocked' => ['label' => 'Blocked Hosts'],
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
            case 'webadmin':
                editWebAdminHosts();
                break;
            case 'ssh':
                editSshHosts();
                break;
            case 'blocked':
                manageBlockedHosts();
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
    echo "Firewall Status\n";
    echo "===============\n\n";

    try {
        $manager = new FirewallManager();
        $status = $manager->getStatus();

        echo "Backend: " . ($status['backend'] ?? 'nftables') . "\n";
        echo "Enabled: " . ($status['enabled'] ? 'Yes' : 'No') . "\n";
        echo "Version: " . ($status['version'] ?? 'unknown') . "\n\n";

        // Connection tracking
        $connCount = $status['connections']['count'] ?? 0;
        $connMax = $status['connections']['max'] ?? 0;
        echo "Connections: {$connCount} / {$connMax}\n\n";

        // Show chain counts from nftables
        exec('nft list tables 2>/dev/null', $tables);
        if (!empty($tables)) {
            echo "Active tables:\n";
            foreach ($tables as $table) {
                echo "  {$table}\n";
            }
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
    echo "Toggle Firewall\n";
    echo "===============\n\n";

    $configManager = new ConfigManager();
    $configManager->load();
    $config = $configManager->getRunningConfig();

    $enabled = $config->get('firewall.enabled', true);
    $status = $enabled ? 'enabled' : 'disabled';
    $action = $enabled ? 'disable' : 'enable';

    echo "Firewall is currently: {$status}\n\n";

    if (confirm("Do you want to {$action} the firewall?")) {
        $config->set('firewall.enabled', !$enabled);
        $configManager->saveRunning();

        if (confirm('Apply changes now?')) {
            exec('/opt/coyote/bin/apply-config 2>&1', $output, $result);
            if ($result === 0) {
                showSuccess("Firewall " . ($enabled ? 'disabled' : 'enabled'));
            } else {
                showError("Failed to apply changes");
            }
        } else {
            showInfo("Changes saved. Run 'Apply Configuration' to activate.");
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
    echo "Default Firewall Policy\n";
    echo "=======================\n\n";

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
        $configManager->saveRunning();
        showSuccess("Default policy set to: {$choice}");

        if (confirm('Apply changes now?')) {
            exec('/opt/coyote/bin/apply-config 2>&1', $output, $result);
            if ($result === 0) {
                showSuccess("Changes applied");
            } else {
                showError("Failed to apply changes");
            }
        }
    }

    waitForEnter();
}

/**
 * Edit web admin allowed hosts.
 */
function editWebAdminHosts(): void
{
    while (true) {
        clearScreen();
        showHeader();
        echo "Web Admin Hosts\n";
        echo "===============\n\n";

        $configManager = new ConfigManager();
        $configManager->load();
        $config = $configManager->getRunningConfig();

        // Get current allowed hosts
        $allowedHosts = $config->get('services.webadmin.allowed_hosts', []);

        if (empty($allowedHosts)) {
            echo "Currently: \033[1;31mNo hosts allowed\033[0m (all access blocked)\n\n";
            echo "You must add at least one host/network to allow access\n";
            echo "to the web administration interface.\n\n";
        } else {
            echo "Allowed hosts:\n";
            foreach ($allowedHosts as $i => $host) {
                $num = $i + 1;
                echo "  {$num}. {$host}\n";
            }
            echo "\n";
        }

        $items = [
            'add' => ['label' => 'Add Host/Network'],
            'remove' => ['label' => 'Remove Host/Network'],
            'clear' => ['label' => 'Clear All (block all access)'],
        ];

        $choice = showMenu($items, 'Manage Web Admin Access');

        if ($choice === null) {
            return;
        }

        switch ($choice) {
            case 'add':
                addWebAdminHost($configManager, $config, $allowedHosts);
                break;
            case 'remove':
                removeWebAdminHost($configManager, $config, $allowedHosts);
                break;
            case 'clear':
                clearWebAdminHosts($configManager, $config);
                break;
        }
    }
}

/**
 * Add a web admin allowed host.
 */
function addWebAdminHost(ConfigManager $configManager, $config, array $currentHosts): void
{
    clearScreen();
    showHeader();
    echo "Add Web Admin Host\n";
    echo "==================\n\n";

    echo "Enter an IP address or network in CIDR notation.\n";
    echo "Examples:\n";
    echo "  192.168.1.100     - Single host\n";
    echo "  192.168.1.0/24    - Entire /24 network\n";
    echo "  10.0.0.0/8        - All 10.x.x.x addresses\n\n";

    $host = prompt("IP address or network");

    if (empty($host)) {
        return;
    }

    // Validate format
    if (!isValidCidr($host)) {
        showError("Invalid format. Use IP address or CIDR notation.");
        waitForEnter();
        return;
    }

    // Add /32 if no mask specified
    if (strpos($host, '/') === false) {
        $host = "{$host}/32";
    }

    // Check for duplicates
    if (in_array($host, $currentHosts)) {
        showError("This host is already in the list.");
        waitForEnter();
        return;
    }

    $currentHosts[] = $host;
    $config->set('services.webadmin.allowed_hosts', $currentHosts);
    $configManager->saveRunning();

    showSuccess("Added: {$host}");
    echo "\n";

    if (confirm('Apply changes now?')) {
        applyFirewallChanges();
    } else {
        showInfo("Changes saved. Apply configuration to activate.");
    }

    waitForEnter();
}

/**
 * Remove a web admin allowed host.
 */
function removeWebAdminHost(ConfigManager $configManager, $config, array $currentHosts): void
{
    if (empty($currentHosts)) {
        clearScreen();
        showHeader();
        showInfo("No hosts configured to remove.");
        waitForEnter();
        return;
    }

    clearScreen();
    showHeader();
    echo "Remove Web Admin Host\n";
    echo "=====================\n\n";

    echo "Current hosts:\n";
    foreach ($currentHosts as $i => $host) {
        $num = $i + 1;
        echo "  {$num}. {$host}\n";
    }
    echo "\n";

    $input = prompt("Enter number to remove (or 0 to cancel)");
    $index = (int)$input - 1;

    if ($index < 0 || $index >= count($currentHosts)) {
        return;
    }

    $removed = $currentHosts[$index];
    array_splice($currentHosts, $index, 1);

    $config->set('services.webadmin.allowed_hosts', $currentHosts);
    $configManager->saveRunning();

    showSuccess("Removed: {$removed}");
    echo "\n";

    if (confirm('Apply changes now?')) {
        applyFirewallChanges();
    } else {
        showInfo("Changes saved. Apply configuration to activate.");
    }

    waitForEnter();
}

/**
 * Clear all web admin host restrictions.
 */
function clearWebAdminHosts(ConfigManager $configManager, $config): void
{
    clearScreen();
    showHeader();
    echo "Clear Web Admin Restrictions\n";
    echo "============================\n\n";

    echo "\033[1;31mWARNING:\033[0m This will BLOCK all access to the web admin.\n";
    echo "You will need console access to restore web admin access.\n\n";

    if (confirm("Are you sure you want to block all web admin access?")) {
        $config->set('services.webadmin.allowed_hosts', []);
        $configManager->saveRunning();

        showSuccess("All restrictions cleared.");
        echo "\n";

        if (confirm('Apply changes now?')) {
            applyFirewallChanges();
        } else {
            showInfo("Changes saved. Apply configuration to activate.");
        }
    }

    waitForEnter();
}

/**
 * Edit SSH allowed hosts.
 */
function editSshHosts(): void
{
    while (true) {
        clearScreen();
        showHeader();
        echo "SSH Access Hosts\n";
        echo "================\n\n";

        $configManager = new ConfigManager();
        $configManager->load();
        $config = $configManager->getRunningConfig();

        // Get current allowed hosts
        $allowedHosts = $config->get('services.ssh.allowed_hosts', []);
        $sshEnabled = $config->get('services.ssh.enabled', true);
        $sshPort = $config->get('services.ssh.port', 22);

        echo "SSH Service: " . ($sshEnabled ? "\033[1;32mEnabled\033[0m" : "\033[1;31mDisabled\033[0m") . "\n";
        echo "SSH Port: {$sshPort}\n\n";

        if (empty($allowedHosts)) {
            echo "Currently: \033[1;31mNo hosts allowed\033[0m (all SSH access blocked)\n\n";
            echo "You must add at least one host/network to allow SSH access\n";
            echo "to this firewall.\n\n";
        } else {
            echo "Allowed hosts:\n";
            foreach ($allowedHosts as $i => $host) {
                $num = $i + 1;
                echo "  {$num}. {$host}\n";
            }
            echo "\n";
        }

        $items = [
            'add' => ['label' => 'Add Host/Network'],
            'remove' => ['label' => 'Remove Host/Network'],
            'clear' => ['label' => 'Clear All (block all access)'],
        ];

        $choice = showMenu($items, 'Manage SSH Access');

        if ($choice === null) {
            return;
        }

        switch ($choice) {
            case 'add':
                addSshHost($configManager, $config, $allowedHosts);
                break;
            case 'remove':
                removeSshHost($configManager, $config, $allowedHosts);
                break;
            case 'clear':
                clearSshHosts($configManager, $config);
                break;
        }
    }
}

/**
 * Add an SSH allowed host.
 */
function addSshHost(ConfigManager $configManager, $config, array $currentHosts): void
{
    clearScreen();
    showHeader();
    echo "Add SSH Access Host\n";
    echo "===================\n\n";

    echo "Enter an IP address or network in CIDR notation.\n";
    echo "Examples:\n";
    echo "  192.168.1.100     - Single host\n";
    echo "  192.168.1.0/24    - Entire /24 network\n";
    echo "  10.0.0.0/8        - All 10.x.x.x addresses\n\n";

    $host = prompt("IP address or network");

    if (empty($host)) {
        return;
    }

    // Validate format
    if (!isValidCidr($host)) {
        showError("Invalid format. Use IP address or CIDR notation.");
        waitForEnter();
        return;
    }

    // Add /32 if no mask specified
    if (strpos($host, '/') === false) {
        $host = "{$host}/32";
    }

    // Check for duplicates
    if (in_array($host, $currentHosts)) {
        showError("This host is already in the list.");
        waitForEnter();
        return;
    }

    $currentHosts[] = $host;
    $config->set('services.ssh.allowed_hosts', $currentHosts);
    $configManager->saveRunning();

    showSuccess("Added: {$host}");
    echo "\n";

    if (confirm('Apply changes now?')) {
        applyFirewallChanges();
    } else {
        showInfo("Changes saved. Apply configuration to activate.");
    }

    waitForEnter();
}

/**
 * Remove an SSH allowed host.
 */
function removeSshHost(ConfigManager $configManager, $config, array $currentHosts): void
{
    if (empty($currentHosts)) {
        clearScreen();
        showHeader();
        showInfo("No hosts configured to remove.");
        waitForEnter();
        return;
    }

    clearScreen();
    showHeader();
    echo "Remove SSH Access Host\n";
    echo "======================\n\n";

    echo "Current hosts:\n";
    foreach ($currentHosts as $i => $host) {
        $num = $i + 1;
        echo "  {$num}. {$host}\n";
    }
    echo "\n";

    $input = prompt("Enter number to remove (or 0 to cancel)");
    $index = (int)$input - 1;

    if ($index < 0 || $index >= count($currentHosts)) {
        return;
    }

    $removed = $currentHosts[$index];
    array_splice($currentHosts, $index, 1);

    $config->set('services.ssh.allowed_hosts', $currentHosts);
    $configManager->saveRunning();

    showSuccess("Removed: {$removed}");
    echo "\n";

    if (confirm('Apply changes now?')) {
        applyFirewallChanges();
    } else {
        showInfo("Changes saved. Apply configuration to activate.");
    }

    waitForEnter();
}

/**
 * Clear all SSH host restrictions.
 */
function clearSshHosts(ConfigManager $configManager, $config): void
{
    clearScreen();
    showHeader();
    echo "Clear SSH Restrictions\n";
    echo "======================\n\n";

    echo "\033[1;31mWARNING:\033[0m This will BLOCK all SSH access to the firewall.\n";
    echo "You will need console access to restore SSH access.\n\n";

    if (confirm("Are you sure you want to block all SSH access?")) {
        $config->set('services.ssh.allowed_hosts', []);
        $configManager->saveRunning();

        showSuccess("All restrictions cleared.");
        echo "\n";

        if (confirm('Apply changes now?')) {
            applyFirewallChanges();
        } else {
            showInfo("Changes saved. Apply configuration to activate.");
        }
    }

    waitForEnter();
}

/**
 * Manage blocked hosts.
 */
function manageBlockedHosts(): void
{
    while (true) {
        clearScreen();
        showHeader();
        echo "Blocked Hosts\n";
        echo "=============\n\n";

        // Get current blocked hosts from nftables
        $blockedHosts = [];
        exec('nft list set inet filter blocked_hosts 2>/dev/null', $output);
        $inElements = false;
        foreach ($output as $line) {
            if (strpos($line, 'elements') !== false) {
                $inElements = true;
            }
            if ($inElements && preg_match_all('/[\d.]+(?:\/\d+)?/', $line, $matches)) {
                $blockedHosts = array_merge($blockedHosts, $matches[0]);
            }
        }

        if (empty($blockedHosts)) {
            echo "No hosts currently blocked.\n\n";
        } else {
            echo "Currently blocked:\n";
            foreach ($blockedHosts as $i => $host) {
                $num = $i + 1;
                echo "  {$num}. {$host}\n";
            }
            echo "\n";
        }

        $items = [
            'block' => ['label' => 'Block Host/Network'],
            'unblock' => ['label' => 'Unblock Host/Network'],
        ];

        $choice = showMenu($items, 'Manage Blocked Hosts');

        if ($choice === null) {
            return;
        }

        switch ($choice) {
            case 'block':
                blockHost();
                break;
            case 'unblock':
                unblockHost($blockedHosts);
                break;
        }
    }
}

/**
 * Block a host.
 */
function blockHost(): void
{
    clearScreen();
    showHeader();
    echo "Block Host\n";
    echo "==========\n\n";

    $host = prompt("IP address or network to block");

    if (empty($host)) {
        return;
    }

    if (!isValidCidr($host)) {
        showError("Invalid format. Use IP address or CIDR notation.");
        waitForEnter();
        return;
    }

    // Add to nftables set immediately
    exec("nft add element inet filter blocked_hosts { {$host} } 2>&1", $output, $result);

    if ($result === 0) {
        showSuccess("Blocked: {$host}");
    } else {
        showError("Failed to block: " . implode(' ', $output));
    }

    waitForEnter();
}

/**
 * Unblock a host.
 */
function unblockHost(array $blockedHosts): void
{
    if (empty($blockedHosts)) {
        clearScreen();
        showHeader();
        showInfo("No hosts to unblock.");
        waitForEnter();
        return;
    }

    clearScreen();
    showHeader();
    echo "Unblock Host\n";
    echo "============\n\n";

    echo "Currently blocked:\n";
    foreach ($blockedHosts as $i => $host) {
        $num = $i + 1;
        echo "  {$num}. {$host}\n";
    }
    echo "\n";

    $input = prompt("Enter number to unblock (or 0 to cancel)");
    $index = (int)$input - 1;

    if ($index < 0 || $index >= count($blockedHosts)) {
        return;
    }

    $host = $blockedHosts[$index];

    // Remove from nftables set immediately
    exec("nft delete element inet filter blocked_hosts { {$host} } 2>&1", $output, $result);

    if ($result === 0) {
        showSuccess("Unblocked: {$host}");
    } else {
        showError("Failed to unblock: " . implode(' ', $output));
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
    echo "Current Firewall Rules (nftables)\n";
    echo "=================================\n\n";

    // List all nftables rules
    exec('nft list ruleset 2>/dev/null', $output, $result);

    if ($result !== 0 || empty($output)) {
        showInfo("No nftables rules loaded or firewall not active.");
    } else {
        // Paginate output
        $lines = $output;
        $perPage = 20;
        $total = count($lines);
        $page = 0;

        while (true) {
            clearScreen();
            showHeader();
            echo "Firewall Rules (page " . ($page + 1) . "/" . ceil($total / $perPage) . ")\n";
            echo str_repeat("=", 50) . "\n\n";

            $start = $page * $perPage;
            $chunk = array_slice($lines, $start, $perPage);

            foreach ($chunk as $line) {
                echo "{$line}\n";
            }

            echo "\n";

            if ($total > $perPage) {
                echo "[N]ext  [P]rev  [Q]uit: ";
                $input = strtolower(trim(fgets(STDIN)));

                if ($input === 'n' && ($page + 1) * $perPage < $total) {
                    $page++;
                } elseif ($input === 'p' && $page > 0) {
                    $page--;
                } elseif ($input === 'q' || $input === '') {
                    break;
                }
            } else {
                waitForEnter();
                break;
            }
        }
    }
}

/**
 * Validate IP address or CIDR notation.
 */
function isValidCidr(string $input): bool
{
    // Check for CIDR notation
    if (strpos($input, '/') !== false) {
        list($ip, $mask) = explode('/', $input, 2);
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }
        $mask = (int)$mask;
        return $mask >= 0 && $mask <= 32;
    }

    // Plain IP address
    return filter_var($input, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
}

/**
 * Check if we're running on the local console (not SSH).
 */
function isConsoleSession(): bool
{
    $tty = trim(shell_exec('tty 2>/dev/null') ?? '');

    // Console sessions are /dev/tty1, /dev/tty2, etc.
    // SSH sessions use /dev/pts/0, /dev/pts/1, etc.
    if (preg_match('#^/dev/tty\d+$#', $tty)) {
        return true;
    }

    // Also check for serial console
    if (preg_match('#^/dev/ttyS\d+$#', $tty)) {
        return true;
    }

    return false;
}

/**
 * Apply firewall configuration changes with safety rollback for SSH sessions.
 */
function applyFirewallChanges(): void
{
    $isConsole = isConsoleSession();

    echo "\nApplying firewall configuration...\n";

    // Apply the firewall rules
    exec('/opt/coyote/bin/firewall-apply reload 2>&1', $output, $result);

    if ($result !== 0) {
        showError("Failed to apply firewall changes.");
        foreach ($output as $line) {
            echo "  {$line}\n";
        }
        return;
    }

    showSuccess("Firewall rules applied.");

    if ($isConsole) {
        // On console - safe to save immediately
        echo "\nSaving to persistent storage...\n";
        $configManager = new ConfigManager();
        // Load from running-config (RAM), not persistent - changes were saved there via saveRunning()
        $configManager->loadRunning();
        if ($configManager->save()) {
            showSuccess("Configuration saved to persistent storage.");
        } else {
            showError("Failed to save to persistent storage.");
        }
    } else {
        // SSH session - use 60-second countdown for safety
        echo "\n\033[1;33mSSH SESSION DETECTED\033[0m\n";
        echo "You have 60 seconds to confirm the changes work correctly.\n";
        echo "If you lose connectivity, the firewall will automatically rollback.\n\n";

        $confirmed = false;
        $startTime = time();
        $timeout = 60;

        // Set terminal to non-blocking for countdown
        system('stty -icanon min 0 time 0 2>/dev/null');

        while ((time() - $startTime) < $timeout) {
            $remaining = $timeout - (time() - $startTime);
            echo "\r\033[K  Press 'y' to confirm and save, 'n' to rollback now [{$remaining}s]: ";

            $char = fread(STDIN, 1);

            if ($char === 'y' || $char === 'Y') {
                $confirmed = true;
                break;
            } elseif ($char === 'n' || $char === 'N') {
                break;
            }

            usleep(100000); // 100ms
        }

        // Restore terminal
        system('stty sane 2>/dev/null');
        echo "\n";

        if ($confirmed) {
            echo "\nSaving to persistent storage...\n";
            $configManager = new ConfigManager();
            // Load from running-config (RAM), not persistent - changes were saved there via saveRunning()
            $configManager->loadRunning();
            if ($configManager->save()) {
                showSuccess("Configuration saved to persistent storage.");
            } else {
                showError("Failed to save to persistent storage.");
            }
        } else {
            echo "\n\033[1;31mRolling back firewall changes...\033[0m\n";

            // Rollback by reloading from persistent storage
            exec('/opt/coyote/bin/firewall-apply rollback 2>&1', $rollbackOutput, $rollbackResult);

            if ($rollbackResult === 0) {
                showInfo("Firewall rolled back to previous configuration.");

                // Also restore the running config from persistent storage
                $configManager = new ConfigManager();
                $configManager->load(); // This loads from persistent and writes to running
            } else {
                showError("Rollback failed! You may need to access the console.");
                foreach ($rollbackOutput as $line) {
                    echo "  {$line}\n";
                }
            }
        }
    }
}
