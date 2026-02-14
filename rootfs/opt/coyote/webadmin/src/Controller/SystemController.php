<?php

namespace Coyote\WebAdmin\Controller;

use Coyote\Config\ConfigManager;
use Coyote\System\PrivilegedExecutor;
use Coyote\WebAdmin\Auth;
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
        $this->render('pages/system', $this->buildSystemPageData());
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

        $errors = [];

        if (empty($hostname)) {
            $errors[] = 'Hostname is required';
        } elseif (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$/', $hostname)) {
            $errors[] = 'Invalid hostname format';
        }

        if (!empty($domain) && !preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9.-]*[a-zA-Z0-9])?$/', $domain)) {
            $errors[] = 'Invalid domain format';
        }

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

        $config = $this->configService->getWorkingConfig();
        $config->set('system.hostname', $hostname);
        $config->set('system.domain', $domain);
        $config->set('system.timezone', $timezone);
        $config->set('system.nameservers', $dnsServers);

        if ($this->configService->saveWorkingConfig($config)) {
            $this->flash('success', 'Settings saved. Click "Apply Configuration" to activate changes.');
        } else {
            $this->flash('error', 'Failed to save configuration');
        }

        $this->redirect('/system');
    }

    public function saveSyslog(array $params = []): void
    {
        $enabled = (bool)$this->post('syslog_remote_enabled', false);
        $host = trim($this->post('syslog_remote_host', ''));
        $port = (int)$this->post('syslog_remote_port', 514);
        $protocol = trim($this->post('syslog_remote_protocol', 'udp'));

        if ($port < 1 || $port > 65535) {
            $port = 514;
        }
        if (!in_array($protocol, ['udp', 'tcp'], true)) {
            $protocol = 'udp';
        }

        $config = $this->configService->getWorkingConfig();
        $config->set('services.syslog.remote_enabled', $enabled);
        $config->set('services.syslog.remote_host', $host);
        $config->set('services.syslog.remote_port', $port);
        $config->set('services.syslog.remote_protocol', $protocol);

        if ($this->configService->saveWorkingConfig($config)) {
            $this->flash('success', 'Syslog settings saved. Click "Apply Configuration" to activate changes.');
        } else {
            $this->flash('error', 'Failed to save configuration');
        }

        $this->redirect('/system');
    }

    public function saveNtp(array $params = []): void
    {
        $enabled = (bool)$this->post('ntp_enabled', false);
        $ntpServers = $this->post('ntp_servers', '');

        $errors = [];
        $serverList = [];
        if (!empty($ntpServers)) {
            $servers = preg_split('/[\s,]+/', $ntpServers);
            foreach ($servers as $server) {
                $server = trim($server);
                if (empty($server)) continue;
                if (!filter_var($server, FILTER_VALIDATE_IP) && !preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9.-]*[a-zA-Z0-9])?$/', $server)) {
                    $errors[] = "Invalid NTP server: {$server}";
                } else {
                    $serverList[] = $server;
                }
            }
        }

        if (!empty($errors)) {
            $this->flash('error', implode('. ', $errors));
            $this->redirect('/system');
            return;
        }

        $config = $this->configService->getWorkingConfig();
        $config->set('services.ntp.enabled', $enabled);
        if (!empty($serverList)) {
            $config->set('services.ntp.servers', $serverList);
        }

        if ($this->configService->saveWorkingConfig($config)) {
            $this->flash('success', 'NTP settings saved. Click "Apply Configuration" to activate changes.');
        } else {
            $this->flash('error', 'Failed to save configuration');
        }

        $this->redirect('/system');
    }

    /**
     * Display the password change form.
     *
     * Renders the system page scrolled to the password card.
     */
    public function showPasswordForm(array $params = []): void
    {
        $this->flash('info', 'Please set a new admin password');
        $this->redirect('/system#password');
    }

    /**
     * Process a password change request.
     *
     * Validates the new password, hashes it, and stores it in the
     * working configuration. The password takes effect immediately
     * for new login sessions without requiring an apply cycle.
     */
    public function changePassword(array $params = []): void
    {
        $currentPassword = $this->post('current_password', '');
        $newPassword = $this->post('new_password', '');
        $confirmPassword = $this->post('confirm_password', '');

        // Validate inputs
        if (empty($newPassword) || empty($confirmPassword)) {
            $this->flash('error', 'New password and confirmation are required');
            $this->redirect('/system#password');
            return;
        }

        if ($newPassword !== $confirmPassword) {
            $this->flash('error', 'Passwords do not match');
            $this->redirect('/system#password');
            return;
        }

        if (strlen($newPassword) < 8) {
            $this->flash('error', 'Password must be at least 8 characters');
            $this->redirect('/system#password');
            return;
        }

        // Verify current password (unless using default credentials)
        $configService = new ConfigService();
        $config = $configService->getWorkingConfig();
        $users = $config->get('users', []);

        $adminPasswordHash = '';
        foreach ($users as $user) {
            if (($user['username'] ?? '') === 'admin') {
                $adminPasswordHash = (string)($user['password_hash'] ?? '');
                break;
            }
        }

        if ($adminPasswordHash !== '') {
            if ($currentPassword === '') {
                $this->flash('error', 'Current password is required');
                $this->redirect('/system#password');
                return;
            }

            if (!password_verify($currentPassword, $adminPasswordHash)) {
                $this->flash('error', 'Current password is incorrect');
                $this->redirect('/system#password');
                return;
            }
        }

        // Hash and store the new password
        $hash = Auth::hashPassword($newPassword);

        $found = false;
        foreach ($users as &$user) {
            if ($user['username'] === 'admin') {
                $user['password_hash'] = $hash;
                $found = true;
                break;
            }
        }
        unset($user);

        if (!$found) {
            $users[] = [
                'username' => 'admin',
                'password_hash' => $hash,
            ];
        }

        $config->set('users', $users);

        $configService->saveWorkingConfig($config);
        $configService->promoteWorkingToRunning();
        $persisted = $configService->saveRunningToPersistent();

        if ($persisted) {
            $this->flash('success', 'Admin password changed successfully');
        } else {
            $this->flash('error', 'Failed to save password');
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

        $this->sendRedirectAndFinish('/system');

        $executor = new PrivilegedExecutor();
        $executor->reboot();
    }

    /**
     * Shutdown the system.
     */
    public function shutdown(array $params = []): void
    {
        $this->flash('info', 'System is shutting down...');

        $this->sendRedirectAndFinish('/system');

        $executor = new PrivilegedExecutor();
        $executor->poweroff();
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
     * Send a redirect response and flush it to the client without exiting.
     *
     * Used by reboot/shutdown so the browser receives the redirect
     * before the system goes down.
     *
     * @param string $uri Target URI
     * @return void
     */
    private function sendRedirectAndFinish(string $uri): void
    {
        header('Location: ' . $uri);
        http_response_code(302);

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            if (ob_get_level() > 0) {
                ob_end_flush();
            }
            flush();
        }

        sleep(1);
    }

    private function buildSystemPageData(): array
    {
        $auth = new Auth();
        $forcePasswordChange = $auth->needsPasswordChange();

        $config = $this->configService->getWorkingConfig()->toArray();
        $nameservers = $config['system']['nameservers'] ?? ($config['network']['dns'] ?? ['1.1.1.1']);
        if (is_array($nameservers) && isset($nameservers['nameservers']) && is_array($nameservers['nameservers'])) {
            $nameservers = $nameservers['nameservers'];
        }
        if (!is_array($nameservers)) {
            $nameservers = [$nameservers];
        }

        return [
            'hostname' => $config['system']['hostname'] ?? 'coyote',
            'domain' => $config['system']['domain'] ?? '',
            'timezone' => $config['system']['timezone'] ?? 'UTC',
            'nameservers' => $nameservers,
            'timezones' => \DateTimeZone::listIdentifiers(),
            'backups' => $this->listBackups(),
            'applyStatus' => $this->applyService->getStatus(),
            'forcePasswordChange' => $forcePasswordChange,
            'syslogRemoteEnabled' => $config['services']['syslog']['remote_enabled'] ?? false,
            'syslogRemoteHost' => $config['services']['syslog']['remote_host'] ?? '',
            'syslogRemotePort' => $config['services']['syslog']['remote_port'] ?? 514,
            'syslogRemoteProtocol' => $config['services']['syslog']['remote_protocol'] ?? 'udp',
            'ntpEnabled' => $config['services']['ntp']['enabled'] ?? true,
            'ntpServers' => $config['services']['ntp']['servers'] ?? ['pool.ntp.org'],
        ];
    }

    /**
     * Remount the config partition.
     *
     * @param bool $writable True for read-write, false for read-only
     * @return bool True if successful
     */
    private function remountConfig(bool $writable): bool
    {
        $executor = new PrivilegedExecutor();

        if ($writable) {
            $result = $executor->mountConfigRw();
        } else {
            $result = $executor->mountConfigRo();
        }

        return $result['success'];
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
