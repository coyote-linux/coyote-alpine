<?php

namespace Coyote\WebAdmin;

class FeatureFlags
{
    private const FEATURES_FILE = '/etc/coyote/features.json';

    private const LOAD_BALANCER_BINARIES = [
        '/usr/sbin/haproxy',
        '/usr/bin/haproxy',
    ];

    private const IPSEC_BINARIES = [
        '/usr/sbin/swanctl',
        '/usr/sbin/ipsec',
        '/usr/sbin/charon',
    ];

    private const OPENVPN_BINARIES = [
        '/usr/sbin/openvpn',
        '/usr/bin/openvpn',
    ];

    private const WIREGUARD_BINARIES = [
        '/usr/bin/wg',
        '/usr/sbin/wg',
        '/usr/bin/wg-quick',
        '/usr/sbin/wg-quick',
    ];

    private ?array $fileConfig = null;
    private ?array $resolved = null;

    public function isLoadBalancerAvailable(): bool
    {
        return $this->getResolved()['loadbalancer'];
    }

    public function isIpsecAvailable(): bool
    {
        return $this->getResolved()['vpn_ipsec'];
    }

    public function isOpenVpnAvailable(): bool
    {
        return $this->getResolved()['vpn_openvpn'];
    }

    public function isWireGuardAvailable(): bool
    {
        return $this->getResolved()['vpn_wireguard'];
    }

    public function isVpnAvailable(): bool
    {
        $resolved = $this->getResolved();

        return $resolved['vpn_ipsec'] || $resolved['vpn_openvpn'] || $resolved['vpn_wireguard'];
    }

    public function toArray(): array
    {
        $resolved = $this->getResolved();

        return [
            'loadbalancer' => $resolved['loadbalancer'],
            'vpn' => $this->isVpnAvailable(),
            'vpn_ipsec' => $resolved['vpn_ipsec'],
            'vpn_openvpn' => $resolved['vpn_openvpn'],
            'vpn_wireguard' => $resolved['vpn_wireguard'],
        ];
    }

    private function getResolved(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $this->resolved = [
            'loadbalancer' => $this->resolveFeature(
                ['features.load_balancer', 'features.loadbalancer', 'load_balancer', 'loadbalancer', 'CONFIG_FEATURE_LOADBALANCER'],
                self::LOAD_BALANCER_BINARIES
            ),
            'vpn_ipsec' => $this->resolveFeature(
                ['features.vpn.ipsec', 'features.ipsec', 'vpn.ipsec', 'ipsec', 'ipsec_vpn', 'CONFIG_FEATURE_IPSEC'],
                self::IPSEC_BINARIES
            ),
            'vpn_openvpn' => $this->resolveFeature(
                ['features.vpn.openvpn', 'features.openvpn', 'vpn.openvpn', 'openvpn', 'openvpn_server', 'CONFIG_FEATURE_OPENVPN'],
                self::OPENVPN_BINARIES
            ),
            'vpn_wireguard' => $this->resolveFeature(
                ['features.vpn.wireguard', 'features.wireguard', 'vpn.wireguard', 'wireguard', 'wireguard_server', 'CONFIG_FEATURE_WIREGUARD'],
                self::WIREGUARD_BINARIES
            ),
        ];

        return $this->resolved;
    }

    private function resolveFeature(array $overridePaths, array $binaryPaths): bool
    {
        $detected = $this->hasAnyExecutable($binaryPaths);
        $override = $this->readOverride($overridePaths);

        if ($override === null) {
            return $detected;
        }

        if ($override === false) {
            return false;
        }

        return $detected;
    }

    private function hasAnyExecutable(array $paths): bool
    {
        foreach ($paths as $path) {
            if (is_string($path) && $path !== '' && is_executable($path)) {
                return true;
            }
        }

        return false;
    }

    private function readOverride(array $paths): ?bool
    {
        $config = $this->readFeatureConfig();

        foreach ($paths as $path) {
            $value = $this->readByPath($config, $path);
            $normalized = $this->normalizeBool($value);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    private function readFeatureConfig(): array
    {
        if ($this->fileConfig !== null) {
            return $this->fileConfig;
        }

        if (!is_readable(self::FEATURES_FILE)) {
            $this->fileConfig = [];
            return $this->fileConfig;
        }

        $contents = file_get_contents(self::FEATURES_FILE);
        if (!is_string($contents) || trim($contents) === '') {
            $this->fileConfig = [];
            return $this->fileConfig;
        }

        $decoded = json_decode($contents, true);
        $this->fileConfig = is_array($decoded) ? $decoded : [];

        return $this->fileConfig;
    }

    private function readByPath(array $config, string $path)
    {
        $cursor = $config;
        $parts = explode('.', $path);

        foreach ($parts as $part) {
            if (!is_array($cursor) || !array_key_exists($part, $cursor)) {
                return null;
            }

            $cursor = $cursor[$part];
        }

        return $cursor;
    }

    private function normalizeBool($value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            if ($value === 1) {
                return true;
            }

            if ($value === 0) {
                return false;
            }
        }

        if (!is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));

        if (in_array($normalized, ['1', 'true', 'yes', 'y', 'on', 'enabled'], true)) {
            return true;
        }

        if (in_array($normalized, ['0', 'false', 'no', 'n', 'off', 'disabled'], true)) {
            return false;
        }

        return null;
    }
}
