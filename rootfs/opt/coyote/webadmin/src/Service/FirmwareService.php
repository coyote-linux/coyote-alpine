<?php

namespace Coyote\WebAdmin\Service;

use Coyote\System\PrivilegedExecutor;

class FirmwareService
{
    private const VERSION_FILE = '/etc/coyote/version';
    private const STAGING_DIR = '/mnt/config/firmware-staging';
    private const UPDATE_CHECK_URL = 'https://update.coyotelinux.com/latest.json';
    private const MIN_SIZE = 10485760;
    private const MAX_SIZE = 524288000;

    private PrivilegedExecutor $priv;

    public function __construct()
    {
        $this->priv = new PrivilegedExecutor();
    }

    public function getCurrentVersion(): string
    {
        if (file_exists(self::VERSION_FILE)) {
            $version = trim(file_get_contents(self::VERSION_FILE));
            return $version ?: '4.0.0';
        }
        return '4.0.0';
    }

    public function checkForUpdates(): array
    {
        $currentVersion = $this->getCurrentVersion();

        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'user_agent' => 'CoyoteLinux/' . $currentVersion,
            ],
        ]);

        $response = @file_get_contents(self::UPDATE_CHECK_URL, false, $context);

        if ($response === false) {
            return [
                'success' => false,
                'error' => 'Failed to connect to update server',
            ];
        }

        $data = json_decode($response, true);

        if (!is_array($data) || !isset($data['version'])) {
            return [
                'success' => false,
                'error' => 'Invalid response from update server',
            ];
        }

        $available = version_compare($data['version'], $currentVersion, '>');

        return [
            'success' => true,
            'available' => $available,
            'current_version' => $currentVersion,
            'latest_version' => $data['version'],
            'url' => $data['url'] ?? '',
            'checksum' => $data['checksum'] ?? '',
            'size' => $data['size'] ?? 0,
        ];
    }

    public function downloadUpdate(string $url, ?string $expectedChecksum = null): array
    {
        if (empty($url)) {
            return ['success' => false, 'error' => 'Download URL is required'];
        }

        if (!$this->ensureStagingDir()) {
            return ['success' => false, 'error' => 'Failed to create staging directory'];
        }

        $filename = basename(parse_url($url, PHP_URL_PATH));
        if (empty($filename) || !str_ends_with($filename, '.tar.gz')) {
            $filename = 'firmware-update.tar.gz';
        }

        $stagingPath = self::STAGING_DIR . '/' . $filename;

        $context = stream_context_create([
            'http' => [
                'timeout' => 300,
                'user_agent' => 'CoyoteLinux/' . $this->getCurrentVersion(),
            ],
        ]);

        $data = @file_get_contents($url, false, $context);

        if ($data === false) {
            return ['success' => false, 'error' => 'Failed to download firmware'];
        }

        $size = strlen($data);
        if ($size < self::MIN_SIZE || $size > self::MAX_SIZE) {
            return ['success' => false, 'error' => 'Downloaded file size is invalid'];
        }

        if ($expectedChecksum && !$this->verifyChecksum($data, $expectedChecksum)) {
            return ['success' => false, 'error' => 'Checksum verification failed'];
        }

        $saved = $this->withConfigWritable(static function () use ($stagingPath, $data): bool {
            if (file_put_contents($stagingPath, $data) === false) {
                return false;
            }

            chmod($stagingPath, 0644);
            return true;
        });

        if (!$saved) {
            return ['success' => false, 'error' => 'Failed to write firmware to staging directory'];
        }

        if (!$this->validateArchive($stagingPath)) {
            $this->withConfigWritable(static function () use ($stagingPath): bool {
                return !file_exists($stagingPath) || @unlink($stagingPath);
            });

            return ['success' => false, 'error' => 'Firmware archive validation failed'];
        }

        return [
            'success' => true,
            'path' => $stagingPath,
            'size' => $size,
        ];
    }

    public function uploadUpdate(array $uploadedFile): array
    {
        if (!isset($uploadedFile['tmp_name']) || !isset($uploadedFile['name'])) {
            return ['success' => false, 'error' => 'No file uploaded'];
        }

        if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => 'File upload error: ' . $uploadedFile['error']];
        }

        $tmpPath = $uploadedFile['tmp_name'];
        $size = filesize($tmpPath);

        if ($size < self::MIN_SIZE || $size > self::MAX_SIZE) {
            return ['success' => false, 'error' => 'File size must be between 10MB and 500MB'];
        }

        if (!$this->ensureStagingDir()) {
            return ['success' => false, 'error' => 'Failed to create staging directory'];
        }

        $filename = basename($uploadedFile['name']);
        if (!str_ends_with($filename, '.tar.gz')) {
            return ['success' => false, 'error' => 'File must be a .tar.gz archive'];
        }

        $stagingPath = self::STAGING_DIR . '/' . $filename;

        $moved = $this->withConfigWritable(static function () use ($tmpPath, $stagingPath): bool {
            if (!move_uploaded_file($tmpPath, $stagingPath)) {
                return false;
            }

            chmod($stagingPath, 0644);
            return true;
        });

        if (!$moved) {
            return ['success' => false, 'error' => 'Failed to move uploaded file to staging'];
        }

        if (!$this->validateArchive($stagingPath)) {
            $this->withConfigWritable(static function () use ($stagingPath): bool {
                return !file_exists($stagingPath) || @unlink($stagingPath);
            });

            return ['success' => false, 'error' => 'Archive validation failed - missing required components'];
        }

        return [
            'success' => true,
            'path' => $stagingPath,
            'size' => $size,
        ];
    }

    public function getStagedUpdate(): ?array
    {
        if (!is_dir(self::STAGING_DIR)) {
            return null;
        }

        $files = glob(self::STAGING_DIR . '/*.tar.gz');

        if (empty($files)) {
            return null;
        }

        $file = $files[0];

        return [
            'path' => $file,
            'filename' => basename($file),
            'size' => filesize($file),
            'date' => date('Y-m-d H:i:s', filemtime($file)),
        ];
    }

    public function clearStaged(): bool
    {
        if (!is_dir(self::STAGING_DIR)) {
            return true;
        }

        $files = glob(self::STAGING_DIR . '/*');
        if ($files === false || empty($files)) {
            return true;
        }

        return $this->withConfigWritable(static function () use ($files): bool {
            $success = true;
            foreach ($files as $file) {
                if (is_file($file) && !@unlink($file)) {
                    $success = false;
                }
            }

            return $success;
        });
    }

    public function applyUpdate(): array
    {
        $staged = $this->getStagedUpdate();

        if (!$staged) {
            return ['success' => false, 'error' => 'No staged firmware update found'];
        }

        if (!$this->validateArchive($staged['path'])) {
            return ['success' => false, 'error' => 'Staged firmware failed validation'];
        }

        $flagFile = self::STAGING_DIR . '/.apply';
        $flagWritten = $this->withConfigWritable(static function () use ($flagFile): bool {
            return file_put_contents($flagFile, date('c')) !== false;
        });

        if (!$flagWritten) {
            return ['success' => false, 'error' => 'Failed to write apply flag'];
        }

        $result = $this->priv->reboot();

        if (!$result['success']) {
            $this->withConfigWritable(static function () use ($flagFile): bool {
                return !file_exists($flagFile) || @unlink($flagFile);
            });

            return ['success' => false, 'error' => 'Failed to trigger reboot: ' . $result['output']];
        }

        return ['success' => true];
    }

    private function ensureStagingDir(): bool
    {
        if (is_dir(self::STAGING_DIR) && is_writable(self::STAGING_DIR)) {
            return true;
        }

        if (!$this->remountConfig(true)) {
            return false;
        }

        $initialized = false;
        $remountedReadOnly = false;

        try {
            $result = $this->priv->initFirmwareStaging();
            $initialized = $result['success'] && is_dir(self::STAGING_DIR) && is_writable(self::STAGING_DIR);
        } finally {
            $remountedReadOnly = $this->remountConfig(false);
        }

        return $initialized && $remountedReadOnly;
    }

    private function withConfigWritable(callable $operation): bool
    {
        if (!$this->remountConfig(true)) {
            return false;
        }

        $operationResult = false;
        $remountedReadOnly = false;

        try {
            $operationResult = (bool)$operation();
        } finally {
            $remountedReadOnly = $this->remountConfig(false);
        }

        return $operationResult && $remountedReadOnly;
    }

    private function remountConfig(bool $writable): bool
    {
        $result = $writable
            ? $this->priv->mountConfigRw()
            : $this->priv->mountConfigRo();

        return $result['success'];
    }

    private function verifyChecksum(string $data, string $checksum): bool
    {
        if (str_starts_with($checksum, 'sha256:')) {
            $expected = substr($checksum, 7);
            $actual = hash('sha256', $data);
            return hash_equals($expected, $actual);
        }

        return false;
    }

    private function validateArchive(string $path): bool
    {
        if (!file_exists($path)) {
            return false;
        }

        $output = [];
        $returnCode = 0;
        exec('tar -tzf ' . escapeshellarg($path) . ' 2>&1', $output, $returnCode);

        if ($returnCode !== 0) {
            return false;
        }

        $contents = implode("\n", $output);

        $requiredFiles = ['vmlinuz', 'initramfs.img', 'firmware.squashfs'];
        foreach ($requiredFiles as $required) {
            if (strpos($contents, $required) === false) {
                return false;
            }
        }

        return true;
    }
}
