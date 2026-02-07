<?php

namespace Coyote\Vpn;

use Coyote\Util\Filesystem;

class WireGuardService
{
    public const CONFIG_DIR = '/etc/wireguard';
    public const KEY_DIR = '/mnt/config/certificates/private';

    private string $wgBinary = '/usr/bin/wg';
    private string $wgQuickBinary = '/usr/bin/wg-quick';
    private string $ipBinary = '/sbin/ip';

    public function applyConfig(array $config): bool
    {
        $globalEnabled = (bool)($config['enabled'] ?? true);
        $interfaces = is_array($config['interfaces'] ?? null) ? $config['interfaces'] : [];

        if (!Filesystem::ensureDir(self::CONFIG_DIR, 0700)) {
            return false;
        }

        $enabledInterfaces = [];

        foreach ($interfaces as $name => $interface) {
            $name = (string)$name;

            if (!is_array($interface)) {
                continue;
            }

            if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,14}$/', $name)) {
                continue;
            }

            $enabled = $globalEnabled && (bool)($interface['enabled'] ?? true);

            if (!$enabled) {
                if ($this->isInterfaceUp($name) && !$this->interfaceDown($name)) {
                    return false;
                }
                continue;
            }

            $privateKey = trim((string)($interface['private_key'] ?? ''));
            if ($privateKey === '') {
                $privateKey = (string)($this->loadPrivateKey($name) ?? '');
            }

            if ($privateKey === '') {
                $pair = $this->generateKeyPair();
                $privateKey = trim((string)($pair['private_key'] ?? ''));
                if ($privateKey === '') {
                    return false;
                }
            }

            $interface['private_key'] = $privateKey;

            $publicKey = trim((string)($interface['public_key'] ?? ''));
            if ($publicKey === '') {
                $publicKey = $this->getPublicKey($privateKey);
                $interface['public_key'] = $publicKey;
            }

            if (!$this->savePrivateKey($name, $privateKey)) {
                return false;
            }

            $content = $this->generateConfig($name, $interface);
            if (!$this->writeConfigFile($name, $content)) {
                return false;
            }

            if ($this->isInterfaceUp($name) && !$this->interfaceDown($name)) {
                return false;
            }

            if (!$this->interfaceUp($name)) {
                return false;
            }

            $enabledInterfaces[$name] = true;
        }

        $existingConfigs = glob(self::CONFIG_DIR . '/*.conf');
        if (is_array($existingConfigs)) {
            foreach ($existingConfigs as $configFile) {
                $name = basename($configFile, '.conf');

                if (isset($enabledInterfaces[$name])) {
                    continue;
                }

                if ($this->isInterfaceUp($name) && !$this->interfaceDown($name)) {
                    return false;
                }
            }
        }

        return true;
    }

    public function generateConfig(string $name, array $interface): string
    {
        $privateKey = trim((string)($interface['private_key'] ?? ''));

        if ($privateKey === '') {
            $privateKey = (string)($this->loadPrivateKey($name) ?? '');
        }

        $lines = [
            '[Interface]',
            'PrivateKey = ' . $privateKey,
            'Address = ' . trim((string)($interface['address'] ?? '')),
        ];

        $listenPort = (int)($interface['listen_port'] ?? 0);
        if ($listenPort > 0) {
            $lines[] = 'ListenPort = ' . $listenPort;
        }

        $dns = trim((string)($interface['dns'] ?? ''));
        if ($dns !== '') {
            $lines[] = 'DNS = ' . $dns;
        }

        $mtu = (int)($interface['mtu'] ?? 0);
        if ($mtu > 0) {
            $lines[] = 'MTU = ' . $mtu;
        }

        $peers = is_array($interface['peers'] ?? null) ? $interface['peers'] : [];

        foreach ($peers as $peer) {
            if (!is_array($peer)) {
                continue;
            }

            $publicKey = trim((string)($peer['public_key'] ?? ''));
            if ($publicKey === '') {
                continue;
            }

            $lines[] = '';
            $lines[] = '[Peer]';
            $lines[] = 'PublicKey = ' . $publicKey;

            $allowedIps = trim((string)($peer['allowed_ips'] ?? ''));
            if ($allowedIps !== '') {
                $lines[] = 'AllowedIPs = ' . $allowedIps;
            }

            $endpoint = trim((string)($peer['endpoint'] ?? ''));
            if ($endpoint !== '') {
                $lines[] = 'Endpoint = ' . $endpoint;
            }

            $keepalive = (int)($peer['persistent_keepalive'] ?? 0);
            if ($keepalive > 0) {
                $lines[] = 'PersistentKeepalive = ' . $keepalive;
            }

            $presharedKey = trim((string)($peer['preshared_key'] ?? ''));
            if ($presharedKey !== '') {
                $lines[] = 'PresharedKey = ' . $presharedKey;
            }
        }

        return implode("\n", $lines) . "\n";
    }

    public function generateKeyPair(): array
    {
        $privateKey = '';
        exec($this->wgBinary . ' genkey 2>&1', $output, $returnCode);

        if ($returnCode === 0 && !empty($output)) {
            $privateKey = trim((string)$output[0]);
        }

        if ($privateKey === '') {
            return [
                'private_key' => '',
                'public_key' => '',
            ];
        }

        return [
            'private_key' => $privateKey,
            'public_key' => $this->getPublicKey($privateKey),
        ];
    }

    public function generatePresharedKey(): string
    {
        exec($this->wgBinary . ' genpsk 2>&1', $output, $returnCode);

        if ($returnCode !== 0 || empty($output)) {
            return '';
        }

        return trim((string)$output[0]);
    }

    public function getPublicKey(string $privateKey): string
    {
        $privateKey = trim($privateKey);

        if ($privateKey === '') {
            return '';
        }

        $command = 'printf %s ' . escapeshellarg($privateKey) . ' | ' . $this->wgBinary . ' pubkey 2>&1';
        exec($command, $output, $returnCode);

        if ($returnCode !== 0 || empty($output)) {
            return '';
        }

        return trim((string)$output[0]);
    }

    public function interfaceUp(string $name): bool
    {
        $command = $this->wgQuickBinary . ' up ' . escapeshellarg($name) . ' 2>&1';
        exec($command, $output, $returnCode);
        return $returnCode === 0;
    }

    public function interfaceDown(string $name): bool
    {
        $command = $this->wgQuickBinary . ' down ' . escapeshellarg($name) . ' 2>&1';
        exec($command, $output, $returnCode);
        return $returnCode === 0;
    }

    public function isInterfaceUp(string $name): bool
    {
        $command = $this->ipBinary . ' link show ' . escapeshellarg($name) . ' 2>&1';
        exec($command, $output, $returnCode);
        return $returnCode === 0;
    }

    public function getStatus(string $name): array
    {
        $command = $this->wgBinary . ' show ' . escapeshellarg($name) . ' 2>&1';
        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            return [
                'name' => $name,
                'up' => $this->isInterfaceUp($name),
                'interface' => ['name' => $name],
                'peers' => [],
            ];
        }

        $status = $this->parseWgShowOutput($output);
        $status['name'] = $name;
        $status['up'] = $this->isInterfaceUp($name);

        return $status;
    }

    public function getInterfaceStatus(): array
    {
        $names = [];

        $configFiles = glob(self::CONFIG_DIR . '/*.conf');
        if (is_array($configFiles)) {
            foreach ($configFiles as $configFile) {
                $names[basename($configFile, '.conf')] = true;
            }
        }

        exec($this->wgBinary . ' show interfaces 2>&1', $output, $returnCode);
        if ($returnCode === 0 && !empty($output)) {
            $joined = trim(implode(' ', $output));
            $runningInterfaces = preg_split('/\s+/', $joined);

            if (is_array($runningInterfaces)) {
                foreach ($runningInterfaces as $runningInterface) {
                    $runningInterface = trim($runningInterface);
                    if ($runningInterface !== '') {
                        $names[$runningInterface] = true;
                    }
                }
            }
        }

        $status = [];

        foreach (array_keys($names) as $name) {
            $status[$name] = $this->getStatus($name);
        }

        ksort($status);

        return $status;
    }

    public function getPeerStatus(string $interfaceName, string $publicKey): array
    {
        $status = $this->getStatus($interfaceName);
        $peers = is_array($status['peers'] ?? null) ? $status['peers'] : [];

        foreach ($peers as $key => $peerStatus) {
            if ($key === $publicKey || (string)($peerStatus['public_key'] ?? '') === $publicKey) {
                return $peerStatus;
            }
        }

        return [
            'public_key' => $publicKey,
            'latest_handshake' => 'never',
            'transfer' => '0 B received, 0 B sent',
        ];
    }

    private function writeConfigFile(string $name, string $content): bool
    {
        $path = self::CONFIG_DIR . '/' . $name . '.conf';
        return Filesystem::writeAtomic($path, $content, 0600);
    }

    private function savePrivateKey(string $name, string $key): bool
    {
        if (!$this->remountConfig(true)) {
            return false;
        }

        try {
            if (!Filesystem::ensureDir(self::KEY_DIR, 0700)) {
                return false;
            }

            $path = self::KEY_DIR . '/wireguard-' . $name . '.key';
            return Filesystem::writeAtomic($path, trim($key) . "\n", 0600);
        } finally {
            $this->remountConfig(false);
        }
    }

    private function loadPrivateKey(string $name): ?string
    {
        $path = self::KEY_DIR . '/wireguard-' . $name . '.key';
        $content = Filesystem::read($path);

        if ($content === null) {
            return null;
        }

        $key = trim($content);
        return $key !== '' ? $key : null;
    }

    private function parseWgShowOutput(array $lines): array
    {
        $status = [
            'interface' => [],
            'peers' => [],
        ];

        $currentPeer = '';

        foreach ($lines as $line) {
            $line = trim((string)$line);

            if ($line === '') {
                continue;
            }

            if (strpos($line, 'interface: ') === 0) {
                $status['interface']['name'] = trim(substr($line, 11));
                $currentPeer = '';
                continue;
            }

            if (strpos($line, 'peer: ') === 0) {
                $currentPeer = trim(substr($line, 6));
                $status['peers'][$currentPeer] = [
                    'public_key' => $currentPeer,
                    'latest_handshake' => 'never',
                    'transfer' => '0 B received, 0 B sent',
                ];
                continue;
            }

            if ($currentPeer === '') {
                if (strpos($line, 'public key: ') === 0) {
                    $status['interface']['public_key'] = trim(substr($line, 12));
                    continue;
                }

                if (strpos($line, 'listening port: ') === 0) {
                    $status['interface']['listening_port'] = (int)trim(substr($line, 16));
                    continue;
                }

                continue;
            }

            if (strpos($line, 'endpoint: ') === 0) {
                $status['peers'][$currentPeer]['endpoint'] = trim(substr($line, 10));
                continue;
            }

            if (strpos($line, 'allowed ips: ') === 0) {
                $status['peers'][$currentPeer]['allowed_ips'] = trim(substr($line, 13));
                continue;
            }

            if (strpos($line, 'latest handshake: ') === 0) {
                $status['peers'][$currentPeer]['latest_handshake'] = trim(substr($line, 18));
                continue;
            }

            if (strpos($line, 'transfer: ') === 0) {
                $status['peers'][$currentPeer]['transfer'] = trim(substr($line, 10));
                continue;
            }

            if (strpos($line, 'persistent keepalive: ') === 0) {
                $status['peers'][$currentPeer]['persistent_keepalive'] = trim(substr($line, 22));
            }
        }

        return $status;
    }

    private function remountConfig(bool $writable): bool
    {
        $mode = $writable ? 'rw' : 'ro';
        $command = (posix_getuid() === 0) ? 'mount' : 'doas mount';
        exec("{$command} -o remount,{$mode} " . escapeshellarg('/mnt/config') . ' 2>&1', $output, $returnCode);

        return $returnCode === 0;
    }
}
