<?php

namespace Coyote\Vpn;

class EasyRsaService
{
    public const EASYRSA_BIN = '/usr/share/easy-rsa/easyrsa';
    public const PKI_DIR = '/mnt/config/certificates/openvpn-pki';

    public function isInitialized(): bool
    {
        return is_dir(self::PKI_DIR) && file_exists(self::PKI_DIR . '/ca.crt');
    }

    public function initializePki(): bool
    {
        if ($this->isInitialized()) {
            return true;
        }

        if (!$this->remountConfig(true)) {
            return false;
        }

        $initialized = false;
        $remountedReadOnly = false;

        try {
            $parentDir = dirname(self::PKI_DIR);
            if (!is_dir($parentDir) && !mkdir($parentDir, 0755, true)) {
                return false;
            }

            if (!$this->runEasyRsa(['init-pki'])) {
                return false;
            }

            if (!$this->runEasyRsa(['build-ca', 'nopass'], true, 'CoyoteVPN')) {
                return false;
            }

            $initialized = true;
        } finally {
            $remountedReadOnly = $this->remountConfig(false);
        }

        return $initialized && $remountedReadOnly;
    }

    public function generateServerCert(string $name): bool
    {
        return $this->runWriteOperation(static function (self $service) use ($name): bool {
            return $service->runEasyRsa(['build-server-full', $name, 'nopass'], true);
        });
    }

    public function generateClientCert(string $name): bool
    {
        return $this->runWriteOperation(static function (self $service) use ($name): bool {
            return $service->runEasyRsa(['build-client-full', $name, 'nopass'], true);
        });
    }

    public function generateDhParams(): bool
    {
        return $this->runWriteOperation(static function (self $service): bool {
            return $service->runEasyRsa(['gen-dh']);
        });
    }

    public function revokeCert(string $name): bool
    {
        return $this->runWriteOperation(static function (self $service) use ($name): bool {
            if (!$service->runEasyRsa(['revoke', $name])) {
                return false;
            }

            return $service->runEasyRsa(['gen-crl']);
        });
    }

    public function getCaCertPath(): ?string
    {
        $path = self::PKI_DIR . '/ca.crt';
        return file_exists($path) ? $path : null;
    }

    public function getServerCertPath(string $name): ?string
    {
        $path = self::PKI_DIR . '/issued/' . $name . '.crt';
        return file_exists($path) ? $path : null;
    }

    public function getServerKeyPath(string $name): ?string
    {
        $path = self::PKI_DIR . '/private/' . $name . '.key';
        return file_exists($path) ? $path : null;
    }

    public function getClientCertPath(string $name): ?string
    {
        $path = self::PKI_DIR . '/issued/' . $name . '.crt';
        return file_exists($path) ? $path : null;
    }

    public function getClientKeyPath(string $name): ?string
    {
        $path = self::PKI_DIR . '/private/' . $name . '.key';
        return file_exists($path) ? $path : null;
    }

    public function getDhPath(): ?string
    {
        $path = self::PKI_DIR . '/dh.pem';
        return file_exists($path) ? $path : null;
    }

    public function getCrlPath(): ?string
    {
        $path = self::PKI_DIR . '/crl.pem';
        return file_exists($path) ? $path : null;
    }

    public function getCaCertContent(): ?string
    {
        return $this->readFile($this->getCaCertPath());
    }

    public function getClientCertContent(string $name): ?string
    {
        return $this->readFile($this->getClientCertPath($name));
    }

    public function getClientKeyContent(string $name): ?string
    {
        return $this->readFile($this->getClientKeyPath($name));
    }

    public function listClientCerts(): array
    {
        return $this->listCertificatesByPurpose(X509_PURPOSE_SSL_CLIENT);
    }

    public function listServerCerts(): array
    {
        return $this->listCertificatesByPurpose(X509_PURPOSE_SSL_SERVER);
    }

    private function runWriteOperation(callable $operation): bool
    {
        if (!$this->remountConfig(true)) {
            return false;
        }

        $result = false;
        $remountedReadOnly = false;

        try {
            $result = (bool)$operation($this);
        } finally {
            $remountedReadOnly = $this->remountConfig(false);
        }

        return $result && $remountedReadOnly;
    }

    private function runEasyRsa(array $arguments, bool $noPass = false, ?string $requestCn = null): bool
    {
        $commandPrefix = posix_getuid() === 0 ? '' : 'doas ';
        $commandParts = [
            escapeshellarg(self::EASYRSA_BIN),
            escapeshellarg('--batch'),
            escapeshellarg('--pki-dir=' . self::PKI_DIR),
        ];

        if ($requestCn !== null && $requestCn !== '') {
            $commandParts[] = escapeshellarg('--req-cn=' . $requestCn);
        }

        if ($noPass) {
            $commandParts[] = escapeshellarg('--nopass');
        }

        $escapedArguments = array_map(static fn(string $arg): string => escapeshellarg($arg), $arguments);
        $command = $commandPrefix . implode(' ', array_merge($commandParts, $escapedArguments)) . ' 2>&1';

        exec($command, $output, $returnCode);

        return $returnCode === 0;
    }

    private function remountConfig(bool $writable): bool
    {
        $mode = $writable ? 'rw' : 'ro';
        $command = posix_getuid() === 0 ? 'mount' : 'doas mount';
        exec("{$command} -o remount,{$mode} " . escapeshellarg('/mnt/config') . ' 2>&1', $output, $returnCode);
        return $returnCode === 0;
    }

    private function readFile(?string $path): ?string
    {
        if ($path === null || !file_exists($path)) {
            return null;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return null;
        }

        return $content;
    }

    private function listCertificatesByPurpose(int $purpose): array
    {
        $issuedPath = self::PKI_DIR . '/issued';
        if (!is_dir($issuedPath)) {
            return [];
        }

        $files = glob($issuedPath . '/' . '*.crt');
        if (!is_array($files)) {
            return [];
        }

        $revoked = $this->getRevokedCertificates();
        $certificates = [];

        foreach ($files as $path) {
            $name = basename($path, '.crt');
            if ($name === '' || isset($revoked[$name])) {
                continue;
            }

            $certificateContent = file_get_contents($path);
            if ($certificateContent === false) {
                continue;
            }

            $purposeResult = openssl_x509_checkpurpose($certificateContent, $purpose);
            if ($purposeResult !== true && $purposeResult !== 1) {
                continue;
            }

            $generatedAt = @filemtime($path);
            $certificates[] = [
                'name' => $name,
                'path' => $path,
                'generated_at' => $generatedAt === false ? 0 : $generatedAt,
            ];
        }

        usort($certificates, static function (array $left, array $right): int {
            return strcmp((string)($left['name'] ?? ''), (string)($right['name'] ?? ''));
        });

        return $certificates;
    }

    private function getRevokedCertificates(): array
    {
        $indexPath = self::PKI_DIR . '/index.txt';
        if (!file_exists($indexPath)) {
            return [];
        }

        $lines = file($indexPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }

        $revoked = [];

        foreach ($lines as $line) {
            if ($line === '' || $line[0] !== 'R') {
                continue;
            }

            if (preg_match('/\/CN=([^\n\/]+)/', $line, $matches)) {
                $revoked[$matches[1]] = true;
            }
        }

        return $revoked;
    }
}
