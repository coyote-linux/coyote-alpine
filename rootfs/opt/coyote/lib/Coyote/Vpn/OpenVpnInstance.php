<?php

namespace Coyote\Vpn;

class OpenVpnInstance
{
    private string $name;
    private array $config = [];

    public function __construct(string $name, array $config = [])
    {
        $this->name = $name;
        $this->config = array_merge($this->getDefaults(), $config);
    }

    private function getDefaults(): array
    {
        return [
            'mode' => 'server',
            'enabled' => true,
            'protocol' => 'udp',
            'port' => 1194,
            'device' => 'tun',
            'network' => '10.8.0.0/24',
            'cipher' => 'AES-256-GCM',
            'auth' => 'SHA256',
            'push_routes' => [],
            'push_dns' => [],
            'client_to_client' => false,
            'keepalive_interval' => 10,
            'keepalive_timeout' => 120,
            'remote_host' => '',
            'remote_port' => 1194,
        ];
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function mode(string $mode): static
    {
        $this->config['mode'] = $mode;
        return $this;
    }

    public function enabled(bool $enabled = true): static
    {
        $this->config['enabled'] = $enabled;
        return $this;
    }

    public function protocol(string $protocol): static
    {
        $this->config['protocol'] = $protocol;
        return $this;
    }

    public function port(int $port): static
    {
        $this->config['port'] = $port;
        return $this;
    }

    public function device(string $device): static
    {
        $this->config['device'] = $device;
        return $this;
    }

    public function network(string $network): static
    {
        $this->config['network'] = $network;
        return $this;
    }

    public function cipher(string $cipher): static
    {
        $this->config['cipher'] = $cipher;
        return $this;
    }

    public function auth(string $auth): static
    {
        $this->config['auth'] = $auth;
        return $this;
    }

    public function pushRoutes(array $routes): static
    {
        $this->config['push_routes'] = $routes;
        return $this;
    }

    public function pushDns(array $dns): static
    {
        $this->config['push_dns'] = $dns;
        return $this;
    }

    public function clientToClient(bool $enabled): static
    {
        $this->config['client_to_client'] = $enabled;
        return $this;
    }

    public function keepaliveInterval(int $seconds): static
    {
        $this->config['keepalive_interval'] = $seconds;
        return $this;
    }

    public function keepaliveTimeout(int $seconds): static
    {
        $this->config['keepalive_timeout'] = $seconds;
        return $this;
    }

    public function remoteHost(string $host): static
    {
        $this->config['remote_host'] = $host;
        return $this;
    }

    public function remotePort(int $port): static
    {
        $this->config['remote_port'] = $port;
        return $this;
    }

    public function toArray(): array
    {
        return $this->config;
    }

    public static function fromArray(string $name, array $config): static
    {
        return new static($name, $config);
    }

    public function validate(): array
    {
        $errors = [];

        if ($this->name === '') {
            $errors[] = 'Instance name is required';
        } elseif (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $this->name)) {
            $errors[] = 'Instance name must start with a letter and contain only letters, numbers, underscores, and hyphens';
        }

        $mode = strtolower((string)($this->config['mode'] ?? 'server'));
        if (!in_array($mode, ['server', 'client'], true)) {
            $errors[] = 'Mode must be server or client';
        }

        $protocol = strtolower((string)($this->config['protocol'] ?? 'udp'));
        if (!in_array($protocol, ['udp', 'tcp'], true)) {
            $errors[] = 'Protocol must be UDP or TCP';
        }

        $device = strtolower((string)($this->config['device'] ?? 'tun'));
        if (!in_array($device, ['tun', 'tap'], true)) {
            $errors[] = 'Device must be TUN or TAP';
        }

        $port = (int)($this->config['port'] ?? 0);
        if ($port < 1 || $port > 65535) {
            $errors[] = 'Port must be between 1 and 65535';
        }

        $remotePort = (int)($this->config['remote_port'] ?? 0);
        if ($remotePort < 1 || $remotePort > 65535) {
            $errors[] = 'Remote port must be between 1 and 65535';
        }

        if ($mode === 'server') {
            $network = trim((string)($this->config['network'] ?? ''));
            if ($network === '') {
                $errors[] = 'VPN network is required for server mode';
            } elseif (!$this->isValidCidr($network)) {
                $errors[] = 'VPN network must be a valid IPv4 CIDR';
            }
        }

        if ($mode === 'client') {
            $remoteHost = trim((string)($this->config['remote_host'] ?? ''));
            if ($remoteHost === '') {
                $errors[] = 'Remote host is required for client mode';
            }
        }

        $cipher = (string)($this->config['cipher'] ?? '');
        if (!in_array($cipher, ['AES-256-GCM', 'AES-128-GCM', 'AES-256-CBC'], true)) {
            $errors[] = 'Unsupported cipher selected';
        }

        $auth = (string)($this->config['auth'] ?? '');
        if (!in_array($auth, ['SHA256', 'SHA384', 'SHA512'], true)) {
            $errors[] = 'Unsupported HMAC auth selected';
        }

        $keepaliveInterval = (int)($this->config['keepalive_interval'] ?? 0);
        if ($keepaliveInterval < 1) {
            $errors[] = 'Keepalive interval must be greater than 0';
        }

        $keepaliveTimeout = (int)($this->config['keepalive_timeout'] ?? 0);
        if ($keepaliveTimeout < 1) {
            $errors[] = 'Keepalive timeout must be greater than 0';
        }

        if (!is_array($this->config['push_routes'] ?? null)) {
            $errors[] = 'Push routes must be an array';
        }

        if (!is_array($this->config['push_dns'] ?? null)) {
            $errors[] = 'Push DNS must be an array';
        }

        return $errors;
    }

    private function isValidCidr(string $value): bool
    {
        $parts = explode('/', $value);
        if (count($parts) !== 2) {
            return false;
        }

        $network = trim($parts[0]);
        $prefix = (int)$parts[1];

        if (filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        return $prefix >= 0 && $prefix <= 32;
    }
}
