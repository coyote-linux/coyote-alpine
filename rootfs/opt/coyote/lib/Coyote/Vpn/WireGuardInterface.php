<?php

namespace Coyote\Vpn;

class WireGuardInterface
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
            'enabled' => true,
            'listen_port' => 51820,
            'address' => '10.0.0.1/24',
            'private_key' => '',
            'public_key' => '',
            'dns' => '',
            'mtu' => 0,
            'peers' => [],
        ];
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function enabled(bool $enabled): static
    {
        $this->config['enabled'] = $enabled;
        return $this;
    }

    public function listenPort(int $listenPort): static
    {
        $this->config['listen_port'] = $listenPort;
        return $this;
    }

    public function address(string $address): static
    {
        $this->config['address'] = trim($address);
        return $this;
    }

    public function privateKey(string $privateKey): static
    {
        $this->config['private_key'] = trim($privateKey);
        return $this;
    }

    public function publicKey(string $publicKey): static
    {
        $this->config['public_key'] = trim($publicKey);
        return $this;
    }

    public function dns(string $dns): static
    {
        $this->config['dns'] = trim($dns);
        return $this;
    }

    public function mtu(int $mtu): static
    {
        $this->config['mtu'] = $mtu;
        return $this;
    }

    public function peers(array $peers): static
    {
        $this->config['peers'] = $peers;
        return $this;
    }

    public function addPeer(array $peer): static
    {
        $peers = is_array($this->config['peers']) ? $this->config['peers'] : [];
        $publicKey = trim((string)($peer['public_key'] ?? ''));

        if ($publicKey === '') {
            return $this;
        }

        $updated = [];

        foreach ($peers as $existingPeer) {
            if (!is_array($existingPeer)) {
                continue;
            }

            if ((string)($existingPeer['public_key'] ?? '') === $publicKey) {
                continue;
            }

            $updated[] = $existingPeer;
        }

        $updated[] = $peer;
        $this->config['peers'] = $updated;

        return $this;
    }

    public function removePeer(string $publicKey): static
    {
        $publicKey = trim($publicKey);
        $peers = is_array($this->config['peers']) ? $this->config['peers'] : [];

        $updated = [];

        foreach ($peers as $peer) {
            if (!is_array($peer)) {
                continue;
            }

            if ((string)($peer['public_key'] ?? '') === $publicKey) {
                continue;
            }

            $updated[] = $peer;
        }

        $this->config['peers'] = array_values($updated);

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
            $errors[] = 'Interface name is required';
        } elseif (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,14}$/', $this->name)) {
            $errors[] = 'Interface name must start with a letter and contain only letters, numbers, underscores, and hyphens';
        }

        if (trim((string)$this->config['address']) === '') {
            $errors[] = 'Address is required';
        }

        $port = (int)$this->config['listen_port'];
        if ($port < 1 || $port > 65535) {
            $errors[] = 'Listen port must be between 1 and 65535';
        }

        if ((bool)$this->config['enabled']) {
            if (trim((string)$this->config['private_key']) === '') {
                $errors[] = 'Private key is required';
            }

            if (trim((string)$this->config['public_key']) === '') {
                $errors[] = 'Public key is required';
            }
        }

        $mtu = (int)$this->config['mtu'];
        if ($mtu < 0 || $mtu > 9000) {
            $errors[] = 'MTU must be between 0 and 9000';
        }

        $peers = is_array($this->config['peers']) ? $this->config['peers'] : [];
        foreach ($peers as $index => $peer) {
            if (!is_array($peer)) {
                $errors[] = 'Peer at index ' . $index . ' is invalid';
                continue;
            }

            $peerErrors = WireGuardPeer::fromArray($peer)->validate();
            foreach ($peerErrors as $peerError) {
                $errors[] = 'Peer ' . ((string)($peer['name'] ?? $index)) . ': ' . $peerError;
            }
        }

        return $errors;
    }
}
