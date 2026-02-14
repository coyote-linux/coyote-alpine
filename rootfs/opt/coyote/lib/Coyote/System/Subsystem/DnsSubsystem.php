<?php

namespace Coyote\System\Subsystem;

/**
 * DNS configuration subsystem.
 *
 * Handles:
 * - DNS nameservers
 * - Search domains
 * - /etc/resolv.conf
 */
class DnsSubsystem extends AbstractSubsystem
{
    public function getName(): string
    {
        return 'dns';
    }

    public function requiresCountdown(): bool
    {
        // DNS changes are generally recoverable - if DNS fails,
        // you can still access by IP address
        return false;
    }

    public function getConfigKeys(): array
    {
        return [
            'system.nameservers',
            'network.dns',
            'network.search',
        ];
    }

    public function hasChanges(array $working, array $running): bool
    {
        return $this->valuesChanged($working, $running, $this->getConfigKeys());
    }

    public function apply(array $config): array
    {
        $priv = $this->getPrivilegedExecutor();

        // Get nameservers from either location (system.nameservers or network.dns)
        $dns = $this->getNestedValue($config, 'system.nameservers')
            ?? $this->getNestedValue($config, 'network.dns', []);
        $search = $this->getNestedValue($config, 'network.search', []);

        // Normalize dns to flat array
        $dnsServers = $this->flattenArray($dns);
        $searchDomains = $this->flattenArray($search);

        // Validate DNS servers
        foreach ($dnsServers as $server) {
            if (!filter_var($server, FILTER_VALIDATE_IP)) {
                return $this->failure('Invalid DNS server', ["Invalid IP address: {$server}"]);
            }
        }

        // Build resolv.conf content
        $resolv = "";

        if (!empty($searchDomains)) {
            $resolv .= "search " . implode(' ', $searchDomains) . "\n";
        }

        foreach ($dnsServers as $ns) {
            $resolv .= "nameserver {$ns}\n";
        }

        if (empty($dnsServers)) {
            $result = $priv->writeFile('/etc/resolv.conf', $resolv);
            if (!$result['success']) {
                return $this->failure('Failed to update /etc/resolv.conf: ' . $result['output']);
            }

            $this->refreshDhcpResolvers($config, $priv);
            return $this->success('Static DNS cleared');
        }

        // Write /etc/resolv.conf via privileged executor
        $result = $priv->writeFile('/etc/resolv.conf', $resolv);
        if (!$result['success']) {
            return $this->failure('Failed to write /etc/resolv.conf: ' . $result['output']);
        }

        $count = count($dnsServers);
        return $this->success("DNS configured with {$count} nameserver(s)");
    }

    private function refreshDhcpResolvers(array $config, $priv): void
    {
        $interfaces = $this->getNestedValue($config, 'network.interfaces', []);

        foreach ($interfaces as $iface) {
            $name = $iface['name'] ?? '';
            $type = $iface['type'] ?? 'static';
            $enabled = $iface['enabled'] ?? true;

            if ($name !== '' && $type === 'dhcp' && $enabled) {
                $priv->dhcpcd('-n', $name);
            }
        }
    }

    /**
     * Flatten a potentially nested array to a simple array of strings.
     *
     * @param mixed $value Value to flatten
     * @return array Flat array of strings
     */
    private function flattenArray($value): array
    {
        if (!is_array($value)) {
            return !empty($value) ? [(string)$value] : [];
        }

        $result = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $result = array_merge($result, $this->flattenArray($item));
            } elseif (!empty($item)) {
                $result[] = (string)$item;
            }
        }

        return $result;
    }
}
