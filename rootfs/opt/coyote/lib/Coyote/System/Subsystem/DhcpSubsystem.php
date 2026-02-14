<?php

namespace Coyote\System\Subsystem;

class DhcpSubsystem extends AbstractSubsystem
{
    public function getName(): string
    {
        return 'dhcp';
    }

    public function requiresCountdown(): bool
    {
        return false;
    }

    public function getConfigKeys(): array
    {
        return [
            'services.dhcpd',
        ];
    }

    public function hasChanges(array $working, array $running): bool
    {
        return $this->valuesChanged($working, $running, $this->getConfigKeys());
    }

    public function apply(array $config): array
    {
        $errors = [];
        $priv = $this->getPrivilegedExecutor();

        $dhcpConfig = $this->getNestedValue($config, 'services.dhcpd', []);
        $enabled = $dhcpConfig['enabled'] ?? false;

        if (!$enabled) {
            $result = $priv->writeFile('/etc/dnsmasq.d/dhcp.conf', '');
            if (!$result['success']) {
                $errors[] = 'Failed to clear DHCP config: ' . $result['output'];
            }

            $result = $priv->rcService('dnsmasq', 'reload');
            if (!$result['success']) {
                $errors[] = 'Failed to reload dnsmasq: ' . $result['output'];
            }

            if (!empty($errors)) {
                return $this->failure('DHCP disable had errors', $errors);
            }

            return $this->success('DHCP server disabled');
        }

        $interface = $dhcpConfig['interface'] ?? '';
        $rangeStart = $dhcpConfig['range_start'] ?? '';
        $rangeEnd = $dhcpConfig['range_end'] ?? '';
        $subnetMask = $dhcpConfig['subnet_mask'] ?? '';
        $gateway = $dhcpConfig['gateway'] ?? '';
        $domain = $dhcpConfig['domain'] ?? '';
        $dnsServers = $dhcpConfig['dns_servers'] ?? [];
        $leaseTime = $dhcpConfig['lease_time'] ?? 86400;
        $reservations = $dhcpConfig['reservations'] ?? [];

        if (empty($interface) || empty($rangeStart) || empty($rangeEnd)) {
            return $this->failure('DHCP configuration incomplete', ['Interface, range_start, and range_end are required']);
        }

        if (!file_exists("/sys/class/net/{$interface}")) {
            return $this->failure('DHCP interface not found', ["Interface {$interface} does not exist"]);
        }

        $confLines = [];
        $confLines[] = "interface={$interface}";
        if (!empty($subnetMask)) {
            $confLines[] = "dhcp-range={$rangeStart},{$rangeEnd},{$subnetMask},{$leaseTime}s";
        } else {
            $confLines[] = "dhcp-range={$rangeStart},{$rangeEnd},{$leaseTime}s";
        }

        if (!empty($gateway)) {
            $confLines[] = "dhcp-option=option:router,{$gateway}";
        }

        if (!empty($dnsServers)) {
            $dnsString = implode(',', $dnsServers);
            $confLines[] = "dhcp-option=option:dns-server,{$dnsString}";
        }

        if (!empty($domain)) {
            $confLines[] = "domain={$domain}";
        }

        foreach ($reservations as $reservation) {
            $mac = $reservation['mac'] ?? '';
            $ip = $reservation['ip'] ?? '';
            $hostname = $reservation['hostname'] ?? '';

            if (empty($mac) || empty($ip)) {
                continue;
            }

            if (!empty($hostname)) {
                $confLines[] = "dhcp-host={$mac},{$ip},{$hostname}";
            } else {
                $confLines[] = "dhcp-host={$mac},{$ip}";
            }
        }

        $confContent = implode("\n", $confLines) . "\n";

        $result = $priv->writeFile('/etc/dnsmasq.d/dhcp.conf', $confContent);
        if (!$result['success']) {
            $errors[] = 'Failed to write DHCP config: ' . $result['output'];
        }

        $result = $priv->rcService('dnsmasq', 'reload');
        if (!$result['success']) {
            $startResult = $priv->rcService('dnsmasq', 'start');
            if (!$startResult['success']) {
                $errors[] = 'Failed to reload or start dnsmasq: ' . $startResult['output'];
            }
        }

        if (!empty($errors)) {
            return $this->failure('DHCP configuration had errors', $errors);
        }

        return $this->success("DHCP server configured on {$interface}: {$rangeStart}-{$rangeEnd}");
    }
}
