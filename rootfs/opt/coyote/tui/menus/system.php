<?php
/**
 * System settings menu.
 */

use Coyote\Config\ConfigManager;
use Coyote\WebAdmin\Auth;

/**
 * Display the system menu.
 */
function systemMenu(): void
{
    $items = [
        'time' => ['label' => 'Time & Timezone'],
        'password' => ['label' => 'Change Admin Password'],
        'backup' => ['label' => 'Backup Configuration'],
        'restore' => ['label' => 'Restore Configuration'],
        'factory' => ['label' => 'Factory Reset'],
    ];

    while (true) {
        $choice = showMenu($items, 'System Settings');

        if ($choice === null) {
            return;
        }

        switch ($choice) {
            case 'time':
                configureTime();
                break;
            case 'password':
                changePassword();
                break;
            case 'backup':
                backupConfig();
                break;
            case 'restore':
                restoreConfig();
                break;
            case 'factory':
                factoryReset();
                break;
        }
    }
}

/**
 * Configure time and timezone.
 */
function configureTime(): void
{
    clearScreen();
    showHeader();
    echo "Time & Timezone Configuration\n\n";

    $configManager = new ConfigManager();
    $configManager->load();
    $config = $configManager->getRunningConfig();

    $currentTz = $config->get('system.timezone', 'UTC');
    $currentTime = date('Y-m-d H:i:s');

    echo "Current time: {$currentTime}\n";
    echo "Current timezone: {$currentTz}\n\n";

    $tz = prompt('Timezone', $currentTz);

    if ($tz !== $currentTz) {
        // Validate timezone
        if (!in_array($tz, timezone_identifiers_list())) {
            showError("Invalid timezone: {$tz}");
            waitForEnter();
            return;
        }

        $config->set('system.timezone', $tz);
        showSuccess("Timezone set to: {$tz}");

        if (confirm('Apply changes now?')) {
            exec('/opt/coyote/bin/apply-config');
            showSuccess("Changes applied");
        }
    }

    waitForEnter();
}

/**
 * Change admin password.
 */
function changePassword(): void
{
    clearScreen();
    showHeader();
    echo "Change Admin Password\n\n";

    echo "Enter new password: ";
    system('stty -echo');
    $password1 = trim(fgets(STDIN));
    system('stty echo');
    echo "\n";

    echo "Confirm password: ";
    system('stty -echo');
    $password2 = trim(fgets(STDIN));
    system('stty echo');
    echo "\n\n";

    if ($password1 !== $password2) {
        showError("Passwords do not match");
        waitForEnter();
        return;
    }

    if (strlen($password1) < 8) {
        showError("Password must be at least 8 characters");
        waitForEnter();
        return;
    }

    $hash = Auth::hashPassword($password1);

    $configManager = new ConfigManager();
    $configManager->load();
    $config = $configManager->getRunningConfig();

    $users = $config->get('users', []);

    // Find or create admin user
    $found = false;
    foreach ($users as &$user) {
        if ($user['username'] === 'admin') {
            $user['password_hash'] = $hash;
            $found = true;
            break;
        }
    }

    if (!$found) {
        $users[] = [
            'username' => 'admin',
            'password_hash' => $hash,
        ];
    }

    $config->set('users', $users);

    if ($configManager->save()) {
        showSuccess("Password changed successfully");
    } else {
        showError("Failed to save password");
    }

    waitForEnter();
}

/**
 * Backup configuration.
 */
function backupConfig(): void
{
    clearScreen();
    showHeader();
    echo "Backup Configuration\n\n";

    $name = prompt('Backup name', date('Y-m-d-His'));

    $configManager = new ConfigManager();

    if ($configManager->backup($name)) {
        showSuccess("Configuration backed up as: {$name}");
    } else {
        showError("Failed to create backup");
    }

    waitForEnter();
}

/**
 * Restore configuration from backup.
 */
function restoreConfig(): void
{
    clearScreen();
    showHeader();
    echo "Restore Configuration\n\n";

    $backupDir = '/mnt/config/backups';
    if (!is_dir($backupDir)) {
        showInfo("No backups found");
        waitForEnter();
        return;
    }

    $backups = glob($backupDir . '/*.json');
    if (empty($backups)) {
        showInfo("No backups found");
        waitForEnter();
        return;
    }

    echo "Available backups:\n";
    foreach ($backups as $i => $backup) {
        $name = basename($backup, '.json');
        $time = date('Y-m-d H:i:s', filemtime($backup));
        echo "  " . ($i + 1) . ". {$name} ({$time})\n";
    }

    echo "\nSelect backup number (0 to cancel): ";
    $input = trim(fgets(STDIN));

    if ($input === '0' || $input === '') {
        return;
    }

    $index = (int)$input - 1;
    if ($index < 0 || $index >= count($backups)) {
        showError("Invalid selection");
        waitForEnter();
        return;
    }

    $backupName = basename($backups[$index], '.json');

    if (confirm("Restore from backup '{$backupName}'?")) {
        $configManager = new ConfigManager();

        if ($configManager->restore($backupName)) {
            showSuccess("Configuration restored from: {$backupName}");

            if (confirm('Apply restored configuration now?')) {
                exec('/opt/coyote/bin/apply-config');
                showSuccess("Configuration applied");
            }
        } else {
            showError("Failed to restore backup");
        }
    }

    waitForEnter();
}

/**
 * Factory reset.
 */
function factoryReset(): void
{
    clearScreen();
    showHeader();
    echo "Factory Reset\n\n";

    showError("WARNING: This will erase all configuration!");
    echo "\n";

    if (!confirm("Are you sure you want to factory reset?")) {
        return;
    }

    if (!confirm("This cannot be undone. Continue?")) {
        return;
    }

    // Remove configuration file
    $configFile = '/mnt/config/system.json';
    if (file_exists($configFile)) {
        unlink($configFile);
    }

    showSuccess("Configuration reset to defaults");
    showInfo("System will reboot in 5 seconds...");

    sleep(5);
    exec('reboot');
}
