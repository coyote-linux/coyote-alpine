<?php

namespace Coyote\WebAdmin\Controller;

use Coyote\Certificate\CertificateInfo;
use Coyote\Certificate\CertificateStore;
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

    public function sslCertificate(array $params = []): void
    {
        $this->render('pages/system', $this->buildSystemPageData());
    }

    public function saveSslCertificate(array $params = []): void
    {
        $certificateId = trim((string)$this->post('ssl_cert_id', ''));
        if ($certificateId === '') {
            $this->flash('error', 'Please select a server certificate');
            $this->redirect('/system#ssl-certificate');
            return;
        }

        $store = new CertificateStore();
        if (!$store->initialize()) {
            $this->flash('error', 'Unable to initialize certificate store');
            $this->redirect('/system#ssl-certificate');
            return;
        }

        $entry = $store->get($certificateId);
        if ($entry === null || ($entry['type'] ?? '') !== CertificateStore::DIR_SERVER) {
            $this->flash('error', 'Selected certificate is not available');
            $this->redirect('/system#ssl-certificate');
            return;
        }

        $certificatePath = (string)($store->getPath($certificateId) ?? '');
        if ($certificatePath === '' || !file_exists($certificatePath)) {
            $this->flash('error', 'Selected certificate path could not be resolved');
            $this->redirect('/system#ssl-certificate');
            return;
        }

        $certContent = file_get_contents($certificatePath);
        if (!is_string($certContent) || trim($certContent) === '') {
            $this->flash('error', 'Unable to read selected certificate');
            $this->redirect('/system#ssl-certificate');
            return;
        }

        $combinedPem = $this->buildCombinedPem($store, $certContent);
        if ($combinedPem === '') {
            $this->flash('error', 'No matching private key was found for the selected certificate');
            $this->redirect('/system#ssl-certificate');
            return;
        }

        if (!$this->writeWebAdminSslPem($combinedPem)) {
            $this->flash('error', 'Failed to write SSL certificate file');
            $this->redirect('/system#ssl-certificate');
            return;
        }

        $config = $this->configService->getWorkingConfig();
        $config->set('services.webadmin.ssl_cert_id', $certificateId);
        $config->set('services.webadmin.ssl_cert_path', '/mnt/config/ssl/server.pem');
        if (!$this->configService->saveWorkingConfig($config)) {
            $this->flash('warning', 'SSL certificate applied, but selection could not be saved to configuration');
            $this->redirect('/system#ssl-certificate');
            return;
        }

        if (!$this->reloadLighttpd()) {
            $this->flash('error', 'Certificate updated, but failed to reload lighttpd');
            $this->redirect('/system#ssl-certificate');
            return;
        }

        $this->flash('success', 'Web admin SSL certificate updated');
        $this->redirect('/system#ssl-certificate');
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
        $auth = new Auth();
        $configService = new ConfigService();
        $config = $configService->getWorkingConfig();
        $users = $config->get('users', []);

        if (!empty($users) && !empty($currentPassword)) {
            // Existing password set — verify it
            $verified = false;
            foreach ($users as $user) {
                if ($user['username'] === 'admin') {
                    $verified = password_verify($currentPassword, $user['password_hash'] ?? '');
                    break;
                }
            }

            if (!$verified) {
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

        // Save to both working config and running config so the password
        // takes effect immediately without requiring an apply cycle
        $saved = $configService->saveWorkingConfig($config);

        // Also update running config directly for immediate effect
        $runningConfig = $configService->getRunningConfig();
        $runningConfig->set('users', $users);
        $runningFile = '/tmp/running-config/system.json';
        if (is_dir('/tmp/running-config')) {
            $writer = new \Coyote\Config\ConfigWriter();
            $writer->write($runningFile, $runningConfig->toArray());
        }

        if ($saved) {
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

        // Send response before rebooting
        $this->redirect('/system');

        // Flush output to browser
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        // Give the browser time to receive the redirect
        sleep(1);

        // Execute reboot via privileged helper
        $executor = new PrivilegedExecutor();
        $executor->reboot();
    }

    /**
     * Shutdown the system.
     */
    public function shutdown(array $params = []): void
    {
        $this->flash('info', 'System is shutting down...');

        // Send response before shutdown
        $this->redirect('/system');

        // Flush output to browser
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        // Give the browser time to receive the redirect
        sleep(1);

        // Execute shutdown via privileged helper
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

    private function buildSystemPageData(): array
    {
        $config = $this->configService->getWorkingConfig()->toArray();

        $data = [
            'hostname' => $config['system']['hostname'] ?? 'coyote',
            'domain' => $config['system']['domain'] ?? '',
            'timezone' => $config['system']['timezone'] ?? 'UTC',
            'nameservers' => $config['system']['nameservers'] ?? ['1.1.1.1'],
            'timezones' => \DateTimeZone::listIdentifiers(),
            'backups' => $this->listBackups(),
            'applyStatus' => $this->applyService->getStatus(),
        ];

        return array_merge($data, $this->buildSslCertificateData($config));
    }

    private function buildSslCertificateData(array $config): array
    {
        $store = new CertificateStore();
        $storeReady = $store->initialize();
        $serverCerts = [];

        if ($storeReady) {
            foreach ($store->listByType(CertificateStore::DIR_SERVER) as $entry) {
                $id = (string)($entry['id'] ?? '');
                if ($id === '') {
                    continue;
                }

                $content = $store->getContent($id);
                $entry['info'] = is_string($content) ? CertificateInfo::parse($content) : null;
                $serverCerts[] = $entry;
            }
        }

        usort($serverCerts, static function (array $left, array $right): int {
            return strcmp((string)($left['name'] ?? ''), (string)($right['name'] ?? ''));
        });

        $currentSslCertId = (string)($config['services']['webadmin']['ssl_cert_id'] ?? '');
        $currentSslCertPath = (string)($config['services']['webadmin']['ssl_cert_path'] ?? '');

        if ($currentSslCertPath === '') {
            $currentSslCertPath = $this->readLighttpdPemFilePath();
        }
        if ($currentSslCertPath === '' && file_exists('/mnt/config/ssl/server.pem')) {
            $currentSslCertPath = '/mnt/config/ssl/server.pem';
        }

        $currentSslCertInfo = null;
        if ($currentSslCertPath !== '' && file_exists($currentSslCertPath)) {
            $currentContent = file_get_contents($currentSslCertPath);
            if (is_string($currentContent)) {
                $currentSslCertInfo = CertificateInfo::parse($currentContent);
            }
        }

        if (!is_array($currentSslCertInfo) && $currentSslCertId !== '' && $storeReady) {
            $selectedContent = $store->getContent($currentSslCertId);
            if (is_string($selectedContent)) {
                $currentSslCertInfo = CertificateInfo::parse($selectedContent);
            }
        }

        return [
            'serverCerts' => $serverCerts,
            'currentSslCertId' => $currentSslCertId,
            'currentSslCertPath' => $currentSslCertPath,
            'currentSslCertInfo' => $currentSslCertInfo,
        ];
    }

    private function buildCombinedPem(CertificateStore $store, string $certificateContent): string
    {
        $certificateContent = trim($certificateContent);
        if ($certificateContent === '') {
            return '';
        }

        if (CertificateInfo::isPemPrivateKey($certificateContent)) {
            return $certificateContent . "\n";
        }

        foreach ($store->listByType(CertificateStore::DIR_PRIVATE) as $entry) {
            $keyId = (string)($entry['id'] ?? '');
            if ($keyId === '') {
                continue;
            }

            $keyContent = $store->getContent($keyId);
            if (!is_string($keyContent) || !CertificateInfo::isPemPrivateKey($keyContent)) {
                continue;
            }

            if (CertificateInfo::certMatchesKey($certificateContent, $keyContent)) {
                return $certificateContent . "\n" . trim($keyContent) . "\n";
            }
        }

        return '';
    }

    private function writeWebAdminSslPem(string $pemContent): bool
    {
        if (!$this->remountConfig(true)) {
            return false;
        }

        $written = false;
        $remountedReadOnly = false;

        try {
            if (!is_dir('/mnt/config/ssl') && !mkdir('/mnt/config/ssl', 0700, true)) {
                return false;
            }

            if (file_put_contents('/mnt/config/ssl/server.pem', $pemContent) === false) {
                return false;
            }

            chmod('/mnt/config/ssl', 0700);
            chmod('/mnt/config/ssl/server.pem', 0600);
            $written = true;
        } finally {
            $remountedReadOnly = $this->remountConfig(false);
        }

        return $written && $remountedReadOnly;
    }

    private function reloadLighttpd(): bool
    {
        $command = (posix_getuid() === 0) ? 'rc-service lighttpd reload' : 'doas rc-service lighttpd reload';
        exec($command . ' 2>&1', $output, $returnCode);
        return $returnCode === 0;
    }

    private function readLighttpdPemFilePath(): string
    {
        if (!is_readable('/etc/lighttpd/lighttpd.conf')) {
            return '';
        }

        $contents = file_get_contents('/etc/lighttpd/lighttpd.conf');
        if (!is_string($contents)) {
            return '';
        }

        if (preg_match('/^\s*ssl\.pemfile\s*=\s*"([^"]+)"/m', $contents, $matches) !== 1) {
            return '';
        }

        return trim((string)$matches[1]);
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
