<?php

namespace Coyote\System;

class FirmwareManager
{
    private const VERSION_FILE = '/etc/coyote/version';
    private const STAGING_DIR = '/mnt/config/firmware-staging';
    private const DOWNLOAD_PROGRESS_FILE = '/tmp/coyote-firmware-download-progress.json';
    private const SIGNING_PUBLIC_KEY = '/etc/coyote/keys/firmware-signing.pub';
    private const UPDATE_CHECK_URL = 'https://update.coyotelinux.com/latest.json';
    private const DOWNLOAD_CHUNK_SIZE = 1048576;
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
            'checksum_url' => $data['checksum_url'] ?? (($data['url'] ?? '') !== '' ? ($data['url'] . '.sha256') : ''),
            'signature_url' => $data['signature_url'] ?? (($data['url'] ?? '') !== '' ? ($data['url'] . '.sig') : ''),
            'size' => $data['size'] ?? 0,
        ];
    }

    public function getDownloadProgress(): array
    {
        $default = [
            'active' => false,
            'success' => null,
            'phase' => 'idle',
            'message' => 'No firmware download in progress',
            'percent' => 0.0,
            'downloaded_bytes' => 0,
            'total_bytes' => 0,
            'error' => null,
            'updated_at' => null,
        ];

        if (!is_file(self::DOWNLOAD_PROGRESS_FILE)) {
            return $default;
        }

        $raw = @file_get_contents(self::DOWNLOAD_PROGRESS_FILE);
        if (!is_string($raw) || $raw === '') {
            return $default;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $default;
        }

        return array_merge($default, $decoded);
    }

    public function downloadUpdate(
        string $url,
        ?string $expectedChecksum = null,
        ?int $expectedSize = null,
        ?string $checksumUrl = null,
        ?string $signatureUrl = null,
        ?callable $progressCallback = null
    ): array
    {
        if (empty($url)) {
            return $this->downloadFailure('Download URL is required', $progressCallback);
        }

        $knownTotal = $expectedSize !== null && $expectedSize > 0 ? $expectedSize : 0;
        $this->publishDownloadProgress([
            'active' => true,
            'success' => null,
            'phase' => 'preparing',
            'message' => 'Preparing firmware download',
            'percent' => 1.0,
            'downloaded_bytes' => 0,
            'total_bytes' => $knownTotal,
            'error' => null,
            'started_at' => date('c'),
        ], $progressCallback);

        $expectedSha256 = null;
        if ($expectedChecksum !== null && $expectedChecksum !== '') {
            if (!str_starts_with($expectedChecksum, 'sha256:')) {
                return $this->downloadFailure('Unsupported checksum format', $progressCallback, 0, $knownTotal);
            }

            $expectedSha256 = strtolower(substr($expectedChecksum, 7));
            if (!preg_match('/^[a-f0-9]{64}$/', $expectedSha256)) {
                return $this->downloadFailure('Checksum verification failed', $progressCallback, 0, $knownTotal);
            }
        }

        if (!$this->ensureStagingDir()) {
            return $this->downloadFailure('Failed to create staging directory', $progressCallback, 0, $knownTotal);
        }

        $filename = basename(parse_url($url, PHP_URL_PATH));
        if (empty($filename) || !str_ends_with($filename, '.tar.gz')) {
            $filename = 'firmware-update.tar.gz';
        }

        $stagingPath = self::STAGING_DIR . '/' . $filename;
        $stagingChecksumPath = $stagingPath . '.sha256';
        $stagingSignaturePath = $stagingPath . '.sig';
        $tempArchivePath = $stagingPath . '.part';
        $tempChecksumPath = $stagingChecksumPath . '.part';
        $tempSignaturePath = $stagingSignaturePath . '.part';

        $checksumUrl = ($checksumUrl !== null && $checksumUrl !== '') ? $checksumUrl : ($url . '.sha256');
        $signatureUrl = ($signatureUrl !== null && $signatureUrl !== '') ? $signatureUrl : ($url . '.sig');

        $this->publishDownloadProgress([
            'active' => true,
            'success' => null,
            'phase' => 'preparing',
            'message' => 'Downloading checksum and signature metadata',
            'percent' => 3.0,
            'downloaded_bytes' => 0,
            'total_bytes' => $knownTotal,
            'error' => null,
        ], $progressCallback);

        $checksumContent = $this->downloadSmallFile($checksumUrl, 30);
        if ($checksumContent === null) {
            return $this->downloadFailure('Failed to download firmware checksum file', $progressCallback, 0, $knownTotal);
        }

        $signatureContent = $this->downloadSmallFile($signatureUrl, 30);
        if ($signatureContent === null || $signatureContent === '') {
            return $this->downloadFailure('Failed to download firmware signature file', $progressCallback, 0, $knownTotal);
        }

        $checksumFromSidecar = $this->extractSha256FromSidecar($checksumContent);
        if ($checksumFromSidecar === null) {
            return $this->downloadFailure('Invalid firmware checksum file', $progressCallback, 0, $knownTotal);
        }

        if ($expectedSha256 !== null && !hash_equals($expectedSha256, $checksumFromSidecar)) {
            return $this->downloadFailure('Update metadata checksum does not match server checksum file', $progressCallback, 0, $knownTotal);
        }

        if (!is_file(self::SIGNING_PUBLIC_KEY)) {
            return $this->downloadFailure('Firmware signing public key is missing', $progressCallback, 0, $knownTotal);
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 300,
                'user_agent' => 'CoyoteLinux/' . $this->getCurrentVersion(),
            ],
        ]);

        $size = 0;
        $checksumLine = $checksumFromSidecar . '  ' . basename($stagingPath) . PHP_EOL;
        $downloadError = null;
        $saved = $this->withConfigWritable(function () use (
            $context,
            $checksumFromSidecar,
            $checksumLine,
            $knownTotal,
            $progressCallback,
            $signatureContent,
            $tempArchivePath,
            $tempChecksumPath,
            $tempSignaturePath,
            $url,
            &$downloadError,
            &$size
        ): bool {
            @unlink($tempArchivePath);
            @unlink($tempChecksumPath);
            @unlink($tempSignaturePath);

            $this->publishDownloadProgress([
                'active' => true,
                'success' => null,
                'phase' => 'downloading',
                'message' => 'Downloading firmware archive',
                'percent' => 5.0,
                'downloaded_bytes' => 0,
                'total_bytes' => $knownTotal,
                'error' => null,
            ], $progressCallback);

            $readHandle = @fopen($url, 'rb', false, $context);
            if ($readHandle === false) {
                $downloadError = 'Failed to download firmware';
                return false;
            }

            $writeHandle = @fopen($tempArchivePath, 'wb');
            if ($writeHandle === false) {
                fclose($readHandle);
                $downloadError = 'Failed to write firmware to staging directory';
                return false;
            }

            $hashContext = hash_init('sha256');
            $lastPublishedAt = 0.0;
            while (!feof($readHandle)) {
                $chunk = fread($readHandle, self::DOWNLOAD_CHUNK_SIZE);
                if ($chunk === false) {
                    $downloadError = 'Failed to download firmware';
                    break;
                }

                if ($chunk === '') {
                    continue;
                }

                $chunkSize = strlen($chunk);
                $size += $chunkSize;
                if ($size > self::MAX_SIZE) {
                    $downloadError = 'Downloaded file size is invalid';
                    break;
                }

                hash_update($hashContext, $chunk);

                $written = fwrite($writeHandle, $chunk);
                if ($written === false || $written !== $chunkSize) {
                    $downloadError = 'Failed to write firmware to staging directory';
                    break;
                }

                $now = microtime(true);
                if (($now - $lastPublishedAt) >= 0.25) {
                    $percent = 5.0;
                    if ($knownTotal > 0) {
                        $percent = min(85.0, round(5 + (($size / $knownTotal) * 80), 1));
                    }

                    $this->publishDownloadProgress([
                        'active' => true,
                        'success' => null,
                        'phase' => 'downloading',
                        'message' => 'Downloading firmware archive',
                        'percent' => $percent,
                        'downloaded_bytes' => $size,
                        'total_bytes' => $knownTotal,
                        'error' => null,
                    ], $progressCallback);
                    $lastPublishedAt = $now;
                }
            }

            fclose($writeHandle);
            fclose($readHandle);

            if ($downloadError !== null) {
                @unlink($tempArchivePath);
                return false;
            }

            if ($size < self::MIN_SIZE || $size > self::MAX_SIZE) {
                $downloadError = 'Downloaded file size is invalid';
                @unlink($tempArchivePath);
                return false;
            }

            $this->publishDownloadProgress([
                'active' => true,
                'success' => null,
                'phase' => 'downloading',
                'message' => 'Download complete, verifying checksum',
                'percent' => 86.0,
                'downloaded_bytes' => $size,
                'total_bytes' => $knownTotal,
                'error' => null,
            ], $progressCallback);

            $actualSha256 = hash_final($hashContext);
            if (!hash_equals($checksumFromSidecar, $actualSha256)) {
                $downloadError = 'Checksum verification failed';
                @unlink($tempArchivePath);
                return false;
            }

            if (file_put_contents($tempChecksumPath, $checksumLine) === false) {
                $downloadError = 'Failed to write firmware checksum file';
                @unlink($tempArchivePath);
                return false;
            }

            if (file_put_contents($tempSignaturePath, $signatureContent) === false) {
                $downloadError = 'Failed to write firmware signature file';
                @unlink($tempArchivePath);
                @unlink($tempChecksumPath);
                return false;
            }

            if (!chmod($tempArchivePath, 0644) || !chmod($tempChecksumPath, 0644) || !chmod($tempSignaturePath, 0644)) {
                $downloadError = 'Failed to write firmware to staging directory';
                @unlink($tempArchivePath);
                @unlink($tempChecksumPath);
                @unlink($tempSignaturePath);
                return false;
            }

            return true;
        });

        if (!$saved) {
            return $this->downloadFailure($downloadError ?? 'Failed to write firmware to staging directory', $progressCallback, $size, $knownTotal);
        }

        $this->publishDownloadProgress([
            'active' => true,
            'success' => null,
            'phase' => 'verifying_signature',
            'message' => 'Verifying firmware signature',
            'percent' => 90.0,
            'downloaded_bytes' => $size,
            'total_bytes' => $knownTotal,
            'error' => null,
        ], $progressCallback);

        if (!$this->verifySignature($tempArchivePath, $tempSignaturePath)) {
            $this->cleanupFiles([$tempArchivePath, $tempChecksumPath, $tempSignaturePath]);
            return $this->downloadFailure('Firmware signature verification failed', $progressCallback, $size, $knownTotal);
        }

        $this->publishDownloadProgress([
            'active' => true,
            'success' => null,
            'phase' => 'validating_archive',
            'message' => 'Validating firmware archive contents',
            'percent' => 94.0,
            'downloaded_bytes' => $size,
            'total_bytes' => $knownTotal,
            'error' => null,
        ], $progressCallback);

        if (!$this->validateArchive($tempArchivePath)) {
            $this->cleanupFiles([$tempArchivePath, $tempChecksumPath, $tempSignaturePath]);
            return $this->downloadFailure('Firmware archive validation failed', $progressCallback, $size, $knownTotal);
        }

        $this->publishDownloadProgress([
            'active' => true,
            'success' => null,
            'phase' => 'finalizing',
            'message' => 'Finalizing staged firmware files',
            'percent' => 97.0,
            'downloaded_bytes' => $size,
            'total_bytes' => $knownTotal,
            'error' => null,
        ], $progressCallback);

        $finalized = $this->withConfigWritable(static function () use (
            $stagingChecksumPath,
            $stagingPath,
            $stagingSignaturePath,
            $tempArchivePath,
            $tempChecksumPath,
            $tempSignaturePath
        ): bool {
            @unlink($stagingPath);
            @unlink($stagingChecksumPath);
            @unlink($stagingSignaturePath);

            if (!rename($tempArchivePath, $stagingPath)) {
                return false;
            }

            if (!rename($tempChecksumPath, $stagingChecksumPath)) {
                @unlink($stagingPath);
                return false;
            }

            if (!rename($tempSignaturePath, $stagingSignaturePath)) {
                @unlink($stagingPath);
                @unlink($stagingChecksumPath);
                return false;
            }

            return true;
        });

        if (!$finalized) {
            $this->cleanupFiles([$tempArchivePath, $tempChecksumPath, $tempSignaturePath]);
            return $this->downloadFailure('Failed to finalize staged firmware files', $progressCallback, $size, $knownTotal);
        }

        $this->publishDownloadProgress([
            'active' => false,
            'success' => true,
            'phase' => 'completed',
            'message' => 'Firmware downloaded and staged successfully',
            'percent' => 100.0,
            'downloaded_bytes' => $size,
            'total_bytes' => $knownTotal,
            'error' => null,
        ], $progressCallback);

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
                $archiveRemoved = !file_exists($stagingPath) || @unlink($stagingPath);
                $checksumPath = $stagingPath . '.sha256';
                $checksumRemoved = !file_exists($checksumPath) || @unlink($checksumPath);
                return $archiveRemoved && $checksumRemoved;
            });

            return ['success' => false, 'error' => 'Archive validation failed - missing required components'];
        }

        $archiveChecksum = hash_file('sha256', $stagingPath);
        if (!is_string($archiveChecksum) || $archiveChecksum === '') {
            $this->withConfigWritable(static function () use ($stagingPath): bool {
                return !file_exists($stagingPath) || @unlink($stagingPath);
            });

            return ['success' => false, 'error' => 'Failed to compute archive checksum'];
        }

        $checksumPath = $stagingPath . '.sha256';
        $checksumLine = $archiveChecksum . '  ' . basename($stagingPath) . PHP_EOL;
        $checksumWritten = $this->withConfigWritable(static function () use ($checksumLine, $checksumPath): bool {
            if (file_put_contents($checksumPath, $checksumLine) === false) {
                return false;
            }

            return chmod($checksumPath, 0644);
        });

        if (!$checksumWritten) {
            $this->withConfigWritable(static function () use ($checksumPath, $stagingPath): bool {
                $archiveRemoved = !file_exists($stagingPath) || @unlink($stagingPath);
                $checksumRemoved = !file_exists($checksumPath) || @unlink($checksumPath);
                return $archiveRemoved && $checksumRemoved;
            });

            return ['success' => false, 'error' => 'Failed to write archive checksum'];
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

    private function publishDownloadProgress(array $progress, ?callable $progressCallback = null): void
    {
        $progress['updated_at'] = date('c');

        $json = json_encode($progress);
        if (!is_string($json)) {
            return;
        }

        $tempPath = self::DOWNLOAD_PROGRESS_FILE . '.tmp';
        if (@file_put_contents($tempPath, $json, LOCK_EX) !== false) {
            @rename($tempPath, self::DOWNLOAD_PROGRESS_FILE);
        }

        if ($progressCallback !== null) {
            $progressCallback($progress);
        }
    }

    private function downloadFailure(
        string $error,
        ?callable $progressCallback,
        int $downloadedBytes = 0,
        int $totalBytes = 0
    ): array {
        $this->publishDownloadProgress([
            'active' => false,
            'success' => false,
            'phase' => 'error',
            'message' => $error,
            'percent' => 0.0,
            'downloaded_bytes' => $downloadedBytes,
            'total_bytes' => $totalBytes,
            'error' => $error,
        ], $progressCallback);

        return ['success' => false, 'error' => $error];
    }

    private function downloadSmallFile(string $url, int $timeoutSeconds): ?string
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => $timeoutSeconds,
                'user_agent' => 'CoyoteLinux/' . $this->getCurrentVersion(),
            ],
        ]);

        $data = @file_get_contents($url, false, $context);
        if ($data === false) {
            return null;
        }

        return $data;
    }

    private function extractSha256FromSidecar(string $content): ?string
    {
        if (!preg_match('/\b([a-fA-F0-9]{64})\b/', $content, $matches)) {
            return null;
        }

        return strtolower($matches[1]);
    }

    private function verifySignature(string $filePath, string $signaturePath): bool
    {
        if (!is_file(self::SIGNING_PUBLIC_KEY)) {
            return false;
        }

        $output = [];
        $returnCode = 0;
        exec(
            'openssl pkeyutl -verify -pubin -inkey ' . escapeshellarg(self::SIGNING_PUBLIC_KEY) .
            ' -rawin -in ' . escapeshellarg($filePath) .
            ' -sigfile ' . escapeshellarg($signaturePath) . ' 2>&1',
            $output,
            $returnCode
        );

        return $returnCode === 0;
    }

    private function cleanupFiles(array $paths): void
    {
        $this->withConfigWritable(static function () use ($paths): bool {
            $success = true;

            foreach ($paths as $path) {
                if (!is_string($path) || $path === '') {
                    continue;
                }

                if (file_exists($path) && !@unlink($path)) {
                    $success = false;
                }
            }

            return $success;
        });
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
