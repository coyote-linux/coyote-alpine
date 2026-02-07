<?php

namespace Coyote\System\Subsystem;

use Coyote\Vpn\VpnManager;

class VpnSubsystem extends AbstractSubsystem
{
    public function getName(): string
    {
        return 'vpn';
    }

    public function requiresCountdown(): bool
    {
        return false;
    }

    public function getConfigKeys(): array
    {
        return [
            'vpn.ipsec.enabled',
            'vpn.ipsec.tunnels',
            'vpn.wireguard.enabled',
            'vpn.wireguard.interfaces',
            'vpn.openvpn.enabled',
            'vpn.openvpn.instances',
        ];
    }

    public function hasChanges(array $working, array $running): bool
    {
        return $this->valuesChanged($working, $running, $this->getConfigKeys());
    }

    public function apply(array $config): array
    {
        $vpnConfig = $this->getNestedValue($config, 'vpn', []);

        if (!is_array($vpnConfig)) {
            $vpnConfig = [];
        }

        try {
            $manager = new VpnManager();

            if (!$manager->applyConfig($vpnConfig)) {
                return $this->failure('Failed to apply VPN configuration');
            }

            return $this->success('VPN configuration applied');
        } catch (\Throwable $e) {
            return $this->failure('Failed to apply VPN configuration', [$e->getMessage()]);
        }
    }
}
