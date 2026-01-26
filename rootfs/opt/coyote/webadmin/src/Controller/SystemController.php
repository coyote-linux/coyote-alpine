<?php

namespace Coyote\WebAdmin\Controller;

use Coyote\Config\ConfigManager;
use Coyote\WebAdmin\Service\ConfigService;
use Coyote\WebAdmin\Service\ApplyService;

/**
 * System configuration controller.
 */
class SystemController extends BaseController
{
    /** @var ConfigService */
    private ConfigService $configService;

    /** @var ApplyService */
    private ApplyService $applyService;

    public function __construct()
    {
        parent::__construct();
        $this->configService = new ConfigService();
        $this->applyService = new ApplyService();
    }

    /**
     * Display system settings.
     */
    public function index(array $params = []): void
    {
        // Get working config (includes uncommitted changes)
        $config = $this->configService->getWorkingConfig()->toArray();

        // Get list of available timezones
        $timezones = \DateTimeZone::listIdentifiers();

        // Get list of backups
        $backups = $this->listBackups();

        // Get apply status
        $applyStatus = $this->applyService->getStatus();

        $data = [
            'hostname' => $config['system']['hostname'] ?? 'coyote',
            'domain' => $config['system']['domain'] ?? '',
            'timezone' => $config['system']['timezone'] ?? 'UTC',
            'nameservers' => $config['system']['nameservers'] ?? ['1.1.1.1'],
            'timezones' => $timezones,
            'backups' => $backups,
            'applyStatus' => $applyStatus,
        ];

        $this->render('pages/system', $data);
    }

    /**
     * Save system settings to working configuration.
     *
     * Note: This saves to working-config only. Changes are NOT applied to
     * the running system until the user clicks "Apply Configuration".
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

        // Load working config (or running config if no working config exists)
        $config = $this->configService->getWorkingConfig();
        $config->set('system.hostname', $hostname);
        $config->set('system.domain', $domain);
        $config->set('system.timezone', $timezone);
        if (!empty($dnsServers)) {
            $config->set('system.nameservers', $dnsServers);
        }

        // Save to working configuration
        if ($this->configService->saveWorkingConfig($config)) {
            $this->flash('success', 'Settings saved. Click "Apply Configuration" to activate changes.');
        } else {
            $this->flash('error', 'Failed to save configuration');
        }

        $this->redirect('/system');
    }

    /**
     * Apply working configuration to the system.
     *
     * Starts the 60-second confirmation countdown.
     */
    public function applyConfig(array $params = []): void
    {
        $result = $this->applyService->apply();

        if ($result['success']) {
            $this->flash('warning', $result['message']);
        } else {
            $this->flash('error', $result['message']);
        }

        $this->redirect('/system');
    }

    /**
     * Confirm the applied configuration.
     *
     * Saves the configuration to persistent storage.
     */
    public function confirmConfig(array $params = []): void
    {
        $result = $this->applyService->confirm();

        if ($result['success']) {
            $this->flash('success', $result['message']);
        } else {
            $this->flash('error', $result['message']);
        }

        $this->redirect('/system');
    }

    /**
     * Cancel the pending configuration and rollback.
     */
    public function cancelConfig(array $params = []): void
    {
        $result = $this->applyService->cancel();

        if ($result['success']) {
            $this->flash('info', $result['message']);
        } else {
            $this->flash('error', $result['message']);
        }

        $this->redirect('/system');
    }

    /**
     * Discard uncommitted changes in working config.
     */
    public function discardChanges(array $params = []): void
    {
        if ($this->configService->discardWorkingConfig()) {
            $this->flash('info', 'Uncommitted changes discarded');
        } else {
            $this->flash('error', 'Failed to discard changes');
        }

        $this->redirect('/system');
    }

    /**
     * Get configuration status (AJAX endpoint).
     */
    public function configStatus(array $params = []): void
    {
        header('Content-Type: application/json');
        echo json_encode($this->applyService->getStatus());
        exit;
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
     *
     * The backup is loaded into working-config. The user must then click
     * "Apply Configuration" to activate it.
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

        // Allow backup names with various prefixes (backup-, pre-restore-, pre-upload-, etc.)
        if (!preg_match('/^[a-z]+-[\d-]+$/i', $backupName)) {
            $this->flash('error', 'Invalid backup name');
            $this->redirect('/system');
            return;
        }

        // Load the backup file
        $backupFile = '/mnt/config/backups/' . $backupName . '.json';
        if (!file_exists($backupFile)) {
            $this->flash('error', 'Backup file not found');
            $this->redirect('/system');
            return;
        }

        $loader = new \Coyote\Config\ConfigLoader();
        try {
            $configData = $loader->load($backupFile);
            $config = new \Coyote\Config\RunningConfig($configData);

            if ($this->configService->saveWorkingConfig($config)) {
                $this->flash('success', "Backup {$backupName} loaded. Click \"Apply Configuration\" to activate it.");
            } else {
                $this->flash('error', 'Failed to load backup into working configuration');
            }
        } catch (\Exception $e) {
            $this->flash('error', 'Failed to load backup: ' . $e->getMessage());
        }

        $this->redirect('/system');
    }

    /**
     * Upload and restore configuration from file.
     *
     * The uploaded configuration is saved to working-config. The user must
     * then click "Apply Configuration" to activate it.
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
        $configData = json_decode($content, true);
        if ($configData === null) {
            $this->flash('error', 'Invalid JSON file');
            $this->redirect('/system');
            return;
        }

        // Basic validation - must have system section
        if (!isset($configData['system'])) {
            $this->flash('error', 'Invalid configuration file: missing system section');
            $this->redirect('/system');
            return;
        }

        // Save to working configuration
        $config = new \Coyote\Config\RunningConfig($configData);
        if ($this->configService->saveWorkingConfig($config)) {
            $this->flash('success', 'Configuration uploaded. Click "Apply Configuration" to activate it.');
        } else {
            $this->flash('error', 'Failed to save uploaded configuration');
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
