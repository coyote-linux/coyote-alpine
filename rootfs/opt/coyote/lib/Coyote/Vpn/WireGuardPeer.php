<?php

namespace Coyote\Vpn;

class WireGuardPeer
{
    private array $config = [];

    public function __construct(array $config = [])
    {
        $this->config = array_merge($this->getDefaults(), $config);
    }

    private function getDefaults(): array
    {
        return [
            'name' => '',
            'public_key' => '',
            'preshared_key' => '',
            'allowed_ips' => '10.0.0.2/32',
            'endpoint' => '',
            'persistent_keepalive' => 25,
        ];
    }

    public function getName(): string
    {
        return (string)$this->config['name'];
    }

    public function getPublicKey(): string
    {
        return (string)$this->config['public_key'];
    }

    public function name(string $name): static
    {
        $this->config['name'] = trim($name);
        return $this;
    }

    public function publicKey(string $publicKey): static
    {
        $this->config['public_key'] = trim($publicKey);
        return $this;
    }

    public function privateKey(string $privateKey): static
    {
        $privateKey = trim($privateKey);

        if ($privateKey === '') {
            unset($this->config['private_key']);
            return $this;
        }

        $this->config['private_key'] = $privateKey;
        return $this;
    }

    public function presharedKey(string $presharedKey): static
    {
        $this->config['preshared_key'] = trim($presharedKey);
        return $this;
    }

    public function allowedIps(string $allowedIps): static
    {
        $this->config['allowed_ips'] = trim($allowedIps);
        return $this;
    }

    public function endpoint(string $endpoint): static
    {
        $this->config['endpoint'] = trim($endpoint);
        return $this;
    }

    public function persistentKeepalive(int $persistentKeepalive): static
    {
        $this->config['persistent_keepalive'] = $persistentKeepalive;
        return $this;
    }

    public function toArray(): array
    {
        return $this->config;
    }

    public static function fromArray(array $config): static
    {
        return new static($config);
    }

    public function validate(): array
    {
        $errors = [];

        if (trim((string)$this->config['name']) === '') {
            $errors[] = 'Peer name is required';
        }

        if (trim((string)$this->config['public_key']) === '') {
            $errors[] = 'Peer public key is required';
        }

        if (trim((string)$this->config['allowed_ips']) === '') {
            $errors[] = 'Allowed IPs is required';
        }

        $keepalive = (int)$this->config['persistent_keepalive'];
        if ($keepalive < 0 || $keepalive > 65535) {
            $errors[] = 'Persistent keepalive must be between 0 and 65535';
        }

        return $errors;
    }

    public function generateClientConfig(
        string $serverPublicKey,
        string $serverEndpoint,
        int $serverPort,
        string $dns = ''
    ): string {
        $privateKey = trim((string)($this->config['private_key'] ?? ''));
        if ($privateKey === '') {
            $privateKey = '<client_private_key>';
        }

        $lines = [
            '[Interface]',
            'PrivateKey = ' . $privateKey,
            'Address = ' . trim((string)$this->config['allowed_ips']),
        ];

        $dns = trim($dns);
        if ($dns !== '') {
            $lines[] = 'DNS = ' . $dns;
        }

        $lines[] = '';
        $lines[] = '[Peer]';
        $lines[] = 'PublicKey = ' . trim($serverPublicKey);

        $presharedKey = trim((string)$this->config['preshared_key']);
        if ($presharedKey !== '') {
            $lines[] = 'PresharedKey = ' . $presharedKey;
        }

        $lines[] = 'AllowedIPs = 0.0.0.0/0, ::/0';

        $endpoint = trim($serverEndpoint);
        if ($endpoint !== '') {
            $lines[] = 'Endpoint = ' . $endpoint . ':' . $serverPort;
        }

        $keepalive = (int)$this->config['persistent_keepalive'];
        if ($keepalive > 0) {
            $lines[] = 'PersistentKeepalive = ' . $keepalive;
        }

        return implode("\n", $lines) . "\n";
    }
}
