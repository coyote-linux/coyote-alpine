<?php

namespace Coyote\Certificate;

class AcmeService
{
    public const UACME_BIN = '/usr/bin/uacme';
    public const ACME_DIR = '/mnt/config/acme';
    public const CHALLENGE_DIR = '/tmp/acme-challenge';
    public const CERT_DIR = '/mnt/config/acme/certs';
    public const HOOK_SCRIPT = '/opt/coyote/bin/acme-challenge-hook';

    private CertificateStore $store;

    public function __construct(CertificateStore $store)
    {
        $this->store = $store;
    }

    public function isRegistered(): bool
    {
        return is_file(self::ACME_DIR . '/private/key.pem');
    }

    public function register(string $email): bool
    {
        $email = trim($email);

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        if (!$this->ensureDirectories()) {
            return false;
        }

        if (!$this->remountConfig(true)) {
            return false;
        }

        $registered = false;
        $remountedReadOnly = false;

        try {
            $result = $this->runUacme(
                '-c ' . escapeshellarg(self::ACME_DIR)
                . ' -h ' . escapeshellarg(self::HOOK_SCRIPT)
                . ' -y new ' . escapeshellarg($email)
            );
            $registered = $result['success'];

            if ($registered) {
                file_put_contents(self::ACME_DIR . '/private/contact.txt', $email . "\n");
                chmod(self::ACME_DIR . '/private/contact.txt', 0600);
            }
        } finally {
            $remountedReadOnly = $this->remountConfig(false);
        }

        return $registered && $remountedReadOnly;
    }

    public function requestCertificate(string $domain): array
    {
        $domain = strtolower(trim($domain));

        if ($domain === '') {
            return [
                'success' => false,
                'message' => 'Domain is required',
                'cert_id' => null,
            ];
        }

        if (!$this->ensureDirectories()) {
            return [
                'success' => false,
                'message' => 'Failed to prepare ACME directories',
                'cert_id' => null,
            ];
        }

        if (!$this->store->initialize()) {
            return [
                'success' => false,
                'message' => 'Unable to initialize certificate store',
                'cert_id' => null,
            ];
        }

        if (!$this->remountConfig(true)) {
            return [
                'success' => false,
                'message' => 'Unable to remount config as writable',
                'cert_id' => null,
            ];
        }

        $issueResult = [
            'success' => false,
            'output' => '',
            'returnCode' => 1,
        ];
        $remountedReadOnly = false;

        try {
            $issueResult = $this->runUacme(
                '-c ' . escapeshellarg(self::ACME_DIR)
                . ' -h ' . escapeshellarg(self::HOOK_SCRIPT)
                . ' -y issue ' . escapeshellarg($domain)
            );
        } finally {
            $remountedReadOnly = $this->remountConfig(false);
        }

        if (!$issueResult['success']) {
            return [
                'success' => false,
                'message' => trim($issueResult['output']) !== '' ? trim($issueResult['output']) : 'uacme failed to issue certificate',
                'cert_id' => null,
            ];
        }

        if (!$remountedReadOnly) {
            return [
                'success' => false,
                'message' => 'Certificate issued, but failed to remount config as read-only',
                'cert_id' => null,
            ];
        }

        $certId = $this->importCertToStore($domain);
        if ($certId === false) {
            return [
                'success' => false,
                'message' => 'Certificate issued but could not be imported into certificate store',
                'cert_id' => null,
            ];
        }

        return [
            'success' => true,
            'message' => 'Certificate issued successfully',
            'cert_id' => $certId,
        ];
    }

    public function renewCertificate(string $domain): array
    {
        $result = $this->requestCertificate($domain);
        if ($result['success']) {
            $result['message'] = 'Certificate renewed successfully';
        }

        return $result;
    }

    public function renewAll(): array
    {
        $managedEntries = $this->listManagedCertificates();
        $domains = [];

        foreach ($managedEntries as $entry) {
            if (($entry['type'] ?? '') !== CertificateStore::DIR_SERVER) {
                continue;
            }

            $metadata = $entry['metadata'] ?? [];
            if (!is_array($metadata)) {
                continue;
            }

            $domain = strtolower(trim((string)($metadata['domain'] ?? '')));
            if ($domain === '') {
                continue;
            }

            $domains[$domain] = $entry;
        }

        $result = [
            'renewed' => [],
            'skipped' => [],
            'failed' => [],
        ];

        foreach ($domains as $domain => $entry) {
            $daysUntilExpiry = null;
            $entryId = (string)($entry['id'] ?? '');

            if ($entryId !== '') {
                $content = $this->store->getContent($entryId);
                if (is_string($content)) {
                    $info = CertificateInfo::parse($content);
                    if (is_array($info) && isset($info['days_until_expiry'])) {
                        $daysUntilExpiry = (int)$info['days_until_expiry'];
                    }
                }
            }

            if ($daysUntilExpiry !== null && $daysUntilExpiry >= 30) {
                $result['skipped'][] = [
                    'domain' => $domain,
                    'days_until_expiry' => $daysUntilExpiry,
                ];
                continue;
            }

            $renewResult = $this->renewCertificate($domain);
            if ($renewResult['success']) {
                $result['renewed'][] = [
                    'domain' => $domain,
                    'cert_id' => $renewResult['cert_id'],
                ];
            } else {
                $result['failed'][] = [
                    'domain' => $domain,
                    'message' => (string)($renewResult['message'] ?? 'Renewal failed'),
                ];
            }
        }

        return $result;
    }

    public function revokeCertificate(string $domain): bool
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return false;
        }

        if (!$this->remountConfig(true)) {
            return false;
        }

        $revoked = false;
        $remountedReadOnly = false;

        try {
            $result = $this->runUacme(
                '-c ' . escapeshellarg(self::ACME_DIR)
                . ' -h ' . escapeshellarg(self::HOOK_SCRIPT)
                . ' -y revoke ' . escapeshellarg($domain)
            );
            $revoked = $result['success'];

            if ($revoked) {
                foreach ($this->store->list() as $entry) {
                    $metadata = $entry['metadata'] ?? [];
                    if (!is_array($metadata)) {
                        continue;
                    }

                    if (($metadata['acme_managed'] ?? false) !== true) {
                        continue;
                    }

                    if (strtolower((string)($metadata['domain'] ?? '')) !== $domain) {
                        continue;
                    }

                    $entryId = (string)($entry['id'] ?? '');
                    if ($entryId !== '') {
                        $this->store->delete($entryId);
                    }
                }
            }
        } finally {
            $remountedReadOnly = $this->remountConfig(false);
        }

        return $revoked && $remountedReadOnly;
    }

    public function getAccountInfo(): ?array
    {
        if (!$this->isRegistered()) {
            return null;
        }

        $keyPath = self::ACME_DIR . '/private/key.pem';
        $timestamp = @filemtime($keyPath);
        $contactPath = self::ACME_DIR . '/private/contact.txt';
        $email = '';

        if (is_file($contactPath)) {
            $contact = file_get_contents($contactPath);
            if (is_string($contact)) {
                $email = trim($contact);
            }
        }

        return [
            'registered' => true,
            'email' => $email,
            'registered_at' => is_int($timestamp) ? date('Y-m-d H:i:s', $timestamp) : '',
            'key_path' => $keyPath,
            'acme_dir' => self::ACME_DIR,
        ];
    }

    public function listManagedCertificates(): array
    {
        if (!$this->store->initialize()) {
            return [];
        }

        $managed = [];

        foreach ($this->store->list() as $entry) {
            $metadata = $entry['metadata'] ?? [];
            if (!is_array($metadata) || ($metadata['acme_managed'] ?? false) !== true) {
                continue;
            }

            $entryId = (string)($entry['id'] ?? '');
            if ($entryId !== '' && ($entry['type'] ?? '') === CertificateStore::DIR_SERVER) {
                $content = $this->store->getContent($entryId);
                if (is_string($content)) {
                    $entry['info'] = CertificateInfo::parse($content);
                }
            }

            $managed[] = $entry;
        }

        usort($managed, static function (array $left, array $right): int {
            $leftDomain = strtolower((string)(($left['metadata'] ?? [])['domain'] ?? $left['name'] ?? ''));
            $rightDomain = strtolower((string)(($right['metadata'] ?? [])['domain'] ?? $right['name'] ?? ''));
            return strcmp($leftDomain, $rightDomain);
        });

        return $managed;
    }

    public function getCertificatePath(string $domain): ?string
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return null;
        }

        return $this->findIssuedFile($domain, ['cert.pem', 'fullchain.pem', 'crt.pem', 'cert']);
    }

    public function getKeyPath(string $domain): ?string
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return null;
        }

        return $this->findIssuedFile($domain, ['key.pem', 'privkey.pem', 'privatekey.pem', 'key']);
    }

    private function runUacme(string $arguments): array
    {
        $prefix = posix_getuid() === 0 ? '' : 'doas ';
        $command = $prefix . escapeshellarg(self::UACME_BIN) . ' ' . $arguments . ' 2>&1';
        $output = [];
        $returnCode = 1;
        exec($command, $output, $returnCode);

        return [
            'success' => $returnCode === 0,
            'output' => implode("\n", $output),
            'returnCode' => $returnCode,
        ];
    }

    private function remountConfig(bool $writable): bool
    {
        $mode = $writable ? 'rw' : 'ro';
        $command = (posix_getuid() === 0) ? 'mount' : 'doas mount';
        exec("{$command} -o remount,{$mode} " . escapeshellarg('/mnt/config') . ' 2>&1', $output, $returnCode);

        return $returnCode === 0;
    }

    private function ensureDirectories(): bool
    {
        if (!is_dir(self::CHALLENGE_DIR) && !mkdir(self::CHALLENGE_DIR, 0755, true)) {
            return false;
        }

        $needsPersistentDirectories = !is_dir(self::ACME_DIR)
            || !is_dir(self::ACME_DIR . '/private')
            || !is_dir(self::CERT_DIR);

        if (!$needsPersistentDirectories) {
            return true;
        }

        if (!$this->remountConfig(true)) {
            return false;
        }

        $prepared = false;
        $remountedReadOnly = false;

        try {
            if (!is_dir(self::ACME_DIR) && !mkdir(self::ACME_DIR, 0700, true)) {
                return false;
            }

            if (!is_dir(self::ACME_DIR . '/private') && !mkdir(self::ACME_DIR . '/private', 0700, true)) {
                return false;
            }

            if (!is_dir(self::CERT_DIR) && !mkdir(self::CERT_DIR, 0700, true)) {
                return false;
            }

            chmod(self::ACME_DIR, 0700);
            chmod(self::ACME_DIR . '/private', 0700);
            chmod(self::CERT_DIR, 0700);
            $prepared = true;
        } finally {
            $remountedReadOnly = $this->remountConfig(false);
        }

        return $prepared && $remountedReadOnly;
    }

    private function importCertToStore(string $domain): string|false
    {
        $certPath = $this->getCertificatePath($domain);
        $keyPath = $this->getKeyPath($domain);

        if ($certPath === null || $keyPath === null) {
            return false;
        }

        $certContent = file_get_contents($certPath);
        $keyContent = file_get_contents($keyPath);

        if (!is_string($certContent) || trim($certContent) === '') {
            return false;
        }

        if (!is_string($keyContent) || trim($keyContent) === '') {
            return false;
        }

        if (!CertificateInfo::isPemCertificate($certContent) || !CertificateInfo::isPemPrivateKey($keyContent)) {
            return false;
        }

        foreach ($this->store->list() as $entry) {
            $metadata = $entry['metadata'] ?? [];
            if (!is_array($metadata)) {
                continue;
            }

            if (($metadata['acme_managed'] ?? false) !== true) {
                continue;
            }

            if (strtolower((string)($metadata['domain'] ?? '')) !== strtolower($domain)) {
                continue;
            }

            $entryId = (string)($entry['id'] ?? '');
            if ($entryId !== '') {
                $this->store->delete($entryId);
            }
        }

        $parsed = CertificateInfo::parse($certContent);

        $certMetadata = [
            'source' => 'acme',
            'domain' => $domain,
            'acme_managed' => true,
            'acme_cert_path' => $certPath,
            'acme_key_path' => $keyPath,
        ];

        if (is_array($parsed)) {
            $certMetadata = array_merge($certMetadata, $parsed);
        }

        $certId = $this->store->store(
            CertificateStore::DIR_SERVER,
            $domain . ' (ACME)',
            trim($certContent) . "\n",
            $certMetadata
        );

        if ($certId === false) {
            return false;
        }

        $keyMetadata = [
            'source' => 'acme',
            'domain' => $domain,
            'acme_managed' => true,
            'acme_cert_path' => $certPath,
            'acme_key_path' => $keyPath,
            'key_fingerprint' => (string)(CertificateInfo::getKeyFingerprint($keyContent) ?? ''),
        ];

        $keyId = $this->store->store(
            CertificateStore::DIR_PRIVATE,
            $domain . ' (ACME key)',
            trim($keyContent) . "\n",
            $keyMetadata
        );

        if ($keyId === false) {
            $this->store->delete($certId);
            return false;
        }

        return $certId;
    }

    private function findIssuedFile(string $domain, array $candidateFilenames): ?string
    {
        $domainDirs = [
            $domain,
            str_replace('*.', '_wildcard_.', $domain),
            str_replace('*', '_', $domain),
        ];

        $baseDirs = [
            self::CERT_DIR,
            self::ACME_DIR . '/certs',
        ];

        foreach ($baseDirs as $baseDir) {
            foreach ($domainDirs as $domainDir) {
                foreach ($candidateFilenames as $filename) {
                    $path = $baseDir . '/' . $domainDir . '/' . $filename;
                    if (is_file($path)) {
                        return $path;
                    }
                }
            }
        }

        foreach ($candidateFilenames as $filename) {
            $matches = glob(self::CERT_DIR . '/*/' . $filename);
            if (!is_array($matches)) {
                continue;
            }

            foreach ($matches as $match) {
                if (!is_file($match)) {
                    continue;
                }

                if (basename(dirname($match)) === $domain) {
                    return $match;
                }
            }
        }

        return null;
    }
}
