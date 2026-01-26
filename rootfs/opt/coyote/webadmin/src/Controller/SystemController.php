<?php

namespace Coyote\WebAdmin\Controller;

use Coyote\Config\ConfigManager;
use Coyote\Config\ConfigWriter;

/**
 * System configuration controller.
 */
class SystemController extends BaseController
{
    /** @var array Allowed services that can be managed */
    private array $allowedServices = [
        'lighttpd', 'dropbear', 'dnsmasq', 'haproxy', 'strongswan'
    ];

    /**
     * Display system settings.
     */
    public function index(array $params = []): void
    {
        $configManager = new ConfigManager();

        try {
            $config = $configManager->load()->toArray();
        } catch (\Exception $e) {
            $config = [];
        }

        // Get list of available timezones
        $timezones = \DateTimeZone::listIdentifiers();

        // Get list of backups
        $backups = $this->listBackups();

        $data = [
            'hostname' => $config['system']['hostname'] ?? 'coyote',
            'domain' => $config['system']['domain'] ?? '',
            'timezone' => $config['system']['timezone'] ?? 'UTC',
            'nameservers' => $config['system']['nameservers'] ?? ['1.1.1.1'],
            'timezones' => $timezones,
            'backups' => $backups,
        ];

        $this->render('pages/system', $data);
    }

    /**
     * Save system settings.
     */
    public function save(array $params = []): void
    {
        $hostname = trim($this->post('hostname', ''));
        $domain = trim($this->post('domain', ''));
        $timezone = trim($this->post('timezone', 'UTC'));
        $nameservers = $this->post('nameservers', '');

        // Validation
        $errors = [];

        if (empty($hostname)) {
            $errors[] = 'Hostname is required';
        } elseif (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$/', $hostname)) {
            $errors[] = 'Invalid hostname format';
        }

        if (!empty($domain) && !preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9.-]*[a-zA-Z0-9])?$/', $domain)) {
            $errors[] = 'Invalid domain format';
        }

        // Parse nameservers (comma or newline separated)
        $dnsServers = [];
        if (!empty($nameservers)) {
            $servers = preg_split('/[\s,]+/', $nameservers);
            foreach ($servers as $server) {
                $server = trim($server);
                if (empty($server)) continue;
                if (!filter_var($server, FILTER_VALIDATE_IP)) {
                    $errors[] = "Invalid DNS server: {$server}";
                } else {
                    $dnsServers[] = $server;
                }
            }
        }

        if (!empty($errors)) {
            $this->flash('error', implode('. ', $errors));
            $this->redirect('/system');
            return;
        }

        // Load and update config
        $configManager = new ConfigManager();
        try {
            $config = $configManager->load();
            $config->set('system.hostname', $hostname);
            $config->set('system.domain', $domain);
            $config->set('system.timezone', $timezone);
            if (!empty($dnsServers)) {
                $config->set('system.nameservers', $dnsServers);
            }

            // Save to persistent storage
            if ($configManager->save()) {
                // Apply hostname immediately
                $this->applyHostname($hostname, $domain);
                // Apply timezone immediately
                $this->applyTimezone($timezone);

                $this->flash('success', 'System settings saved successfully');
            } else {
                $this->flash('error', 'Failed to save configuration');
            }
        } catch (\Exception $e) {
            $this->flash('error', 'Error saving configuration: ' . $e->getMessage());
        }

        $this->redirect('/system');
    }

    /**
     * Apply hostname to running system.
     */
    private function applyHostname(string $hostname, string $domain): void
    {
        // Set hostname (use doas if not root)
        $cmd = $this->getPrivilegedCommand('hostname');
        exec("{$cmd} " . escapeshellarg($hostname) . " 2>&1", $output, $returnCode);

        // Update /etc/hostname and /etc/hosts (these are on tmpfs overlay, writable)
        @file_put_contents('/etc/hostname', $hostname . "\n");

        $fqdn = !empty($domain) ? "{$hostname}.{$domain}" : $hostname;
        $hosts = "127.0.0.1\tlocalhost\n";
        $hosts .= "127.0.1.1\t{$fqdn}\t{$hostname}\n";
        @file_put_contents('/etc/hosts', $hosts);
    }

    /**
     * Apply timezone to running system.
     */
    private function applyTimezone(string $timezone): void
    {
        $zonefile = "/usr/share/zoneinfo/{$timezone}";
        if (file_exists($zonefile)) {
            @copy($zonefile, '/etc/localtime');
            @file_put_contents('/etc/timezone', $timezone . "\n");
        }
    }

    /**
     * Reboot the system.
     */
    public function reboot(array $params = []): void
    {
        $this->flash('info', 'System is rebooting...');

        // Send response before rebooting
        $this->redirect('/system');

        // Give the browser time to receive the redirect
        sleep(1);

        // Execute reboot (use doas if not root)
        $cmd = $this->getPrivilegedCommand('reboot');
        exec("{$cmd} &");
    }

    /**
     * Shutdown the system.
     */
    public function shutdown(array $params = []): void
    {
        $this->flash('info', 'System is shutting down...');

        // Send response before shutdown
        $this->redirect('/system');

        // Give the browser time to receive the redirect
        sleep(1);

        // Execute shutdown (use doas if not root)
        $cmd = $this->getPrivilegedCommand('poweroff');
        exec("{$cmd} &");
    }

    /**
     * Create a configuration backup.
     */
    public function backup(array $params = []): void
    {
        $configManager = new ConfigManager();
        $backupName = 'backup-' . date('Y-m-d-His');

        if ($configManager->backup($backupName)) {
            $this->flash('success', "Backup created: {$backupName}");
        } else {
            $this->flash('error', 'Failed to create backup');
        }

        $this->redirect('/system');
    }

    /**
     * Download configuration as JSON.
     */
    public function downloadBackup(array $params = []): void
    {
        $configManager = new ConfigManager();

        try {
            $config = $configManager->load()->toArray();
            $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            $filename = 'coyote-config-' . date('Y-m-d-His') . '.json';

            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($json));

            echo $json;
            exit;
        } catch (\Exception $e) {
            $this->flash('error', 'Failed to export configuration');
            $this->redirect('/system');
        }
    }

    /**
     * Restore configuration from a backup.
     */
    public function restore(array $params = []): void
    {
        $backupName = $this->post('backup_name', '');

        if (empty($backupName)) {
            $this->flash('error', 'No backup selected');
            $this->redirect('/system');
            return;
        }

        // Sanitize backup name to prevent directory traversal
        $backupName = basename($backupName);
        if (!preg_match('/^backup-[\d-]+$/', $backupName)) {
            $this->flash('error', 'Invalid backup name');
            $this->redirect('/system');
            return;
        }

        $configManager = new ConfigManager();

        // Create a backup of current config before restoring
        $preRestoreBackup = 'pre-restore-' . date('Y-m-d-His');
        $configManager->backup($preRestoreBackup);

        if ($configManager->restore($backupName)) {
            $this->flash('success', "Configuration restored from {$backupName}. Previous config backed up as {$preRestoreBackup}.");
        } else {
            $this->flash('error', 'Failed to restore configuration');
        }

        $this->redirect('/system');
    }

    /**
     * Upload and restore configuration from file.
     */
    public function uploadRestore(array $params = []): void
    {
        if (!isset($_FILES['config_file']) || $_FILES['config_file']['error'] !== UPLOAD_ERR_OK) {
            $this->flash('error', 'No file uploaded or upload error');
            $this->redirect('/system');
            return;
        }

        $uploadedFile = $_FILES['config_file']['tmp_name'];
        $content = file_get_contents($uploadedFile);

        // Validate JSON
        $config = json_decode($content, true);
        if ($config === null) {
            $this->flash('error', 'Invalid JSON file');
            $this->redirect('/system');
            return;
        }

        // Basic validation - must have system section
        if (!isset($config['system'])) {
            $this->flash('error', 'Invalid configuration file: missing system section');
            $this->redirect('/system');
            return;
        }

        // Backup current config
        $configManager = new ConfigManager();
        $preUploadBackup = 'pre-upload-' . date('Y-m-d-His');
        $configManager->backup($preUploadBackup);

        // Write new config (with remount handling)
        $configWriter = new ConfigWriter();
        try {
            $this->remountConfig(true);
            $configWriter->write('/mnt/config/system.json', $config);
            $this->remountConfig(false);
            $this->flash('success', "Configuration uploaded and saved. Previous config backed up as {$preUploadBackup}.");
        } catch (\Exception $e) {
            $this->remountConfig(false);
            $this->flash('error', 'Failed to save uploaded configuration: ' . $e->getMessage());
        }

        $this->redirect('/system');
    }

    /**
     * Delete a backup.
     */
    public function deleteBackup(array $params = []): void
    {
        $backupName = $this->post('backup_name', '');

        if (empty($backupName)) {
            $this->flash('error', 'No backup specified');
            $this->redirect('/system');
            return;
        }

        // Sanitize backup name
        $backupName = basename($backupName);
        $backupFile = '/mnt/config/backups/' . $backupName . '.json';

        $this->remountConfig(true);
        $deleted = file_exists($backupFile) && unlink($backupFile);
        $this->remountConfig(false);

        if ($deleted) {
            $this->flash('success', "Backup deleted: {$backupName}");
        } else {
            $this->flash('error', 'Failed to delete backup');
        }

        $this->redirect('/system');
    }

    /**
     * Remount the config partition.
     *
     * @param bool $writable True for read-write, false for read-only
     * @return bool True if successful
     */
    private function remountConfig(bool $writable): bool
    {
        $mode = $writable ? 'rw' : 'ro';
        $cmd = $this->getPrivilegedCommand('mount');
        exec("{$cmd} -o remount,{$mode} /mnt/config 2>&1", $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * Get a command with privilege escalation if needed.
     *
     * @param string $command The command to run
     * @return string The command, prefixed with doas if not running as root
     */
    private function getPrivilegedCommand(string $command): string
    {
        // If running as root, no need for doas
        if (posix_getuid() === 0) {
            return $command;
        }
        return "doas {$command}";
    }

    /**
     * List available backups.
     */
    private function listBackups(): array
    {
        $backupDir = '/mnt/config/backups';
        $backups = [];

        if (is_dir($backupDir)) {
            foreach (scandir($backupDir) as $file) {
                if (preg_match('/^(.+)\.json$/', $file, $matches)) {
                    $backupPath = $backupDir . '/' . $file;
                    $backups[] = [
                        'name' => $matches[1],
                        'date' => date('Y-m-d H:i:s', filemtime($backupPath)),
                        'size' => filesize($backupPath),
                    ];
                }
            }
        }

        // Sort by date descending
        usort($backups, function ($a, $b) {
            return strcmp($b['date'], $a['date']);
        });

        return $backups;
    }

    /**
     * Apply configuration changes (legacy route).
     */
    public function apply(array $params = []): void
    {
        $this->flash('info', 'Configuration apply triggered');
        $this->redirect('/system');
    }

    /**
     * Confirm configuration changes (legacy route).
     */
    public function confirm(array $params = []): void
    {
        $this->flash('info', 'Configuration confirmed');
        $this->redirect('/system');
    }
}
