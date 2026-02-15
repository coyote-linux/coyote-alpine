<?php

namespace Coyote\System;

/**
 * Network configuration and management.
 */
class Network
{
    private const NON_ETHERNET_ALLOWLIST = [];
    /**
     * Configure an interface with the given settings.
     *
     * @param string $interface Interface name
     * @param array $config Interface configuration
     * @return bool True if successful
     */
    public function configureInterface(string $interface, array $config): bool
    {
        // Bring interface down first
        $this->setInterfaceState($interface, false);

        // Configure IPv4 if specified
        if (isset($config['ipv4'])) {
            $this->configureIPv4($interface, $config['ipv4']);
        }

        // Configure IPv6 if specified
        if (isset($config['ipv6'])) {
            $this->configureIPv6($interface, $config['ipv6']);
        }

        // Bring interface up
        $this->setInterfaceState($interface, true);

        return true;
    }

    /**
     * Configure IPv4 for an interface.
     *
     * @param string $interface Interface name
     * @param array $config IPv4 configuration
     * @return bool True if successful
     */
    private function configureIPv4(string $interface, array $config): bool
    {
        if (isset($config['method']) && $config['method'] === 'dhcp') {
            // Start DHCP client
            exec("dhcpcd -b {$interface} 2>&1", $output, $returnCode);
            return $returnCode === 0;
        }

        if (isset($config['address']) && isset($config['netmask'])) {
            $address = $config['address'];
            $netmask = $config['netmask'];

            // Calculate prefix length from netmask
            $prefix = $this->netmaskToPrefix($netmask);

            exec("ip addr add {$address}/{$prefix} dev {$interface} 2>&1", $output, $returnCode);
            return $returnCode === 0;
        }

        return false;
    }

    /**
     * Configure IPv6 for an interface.
     *
     * @param string $interface Interface name
     * @param array $config IPv6 configuration
     * @return bool True if successful
     */
    private function configureIPv6(string $interface, array $config): bool
    {
        if (isset($config['method']) && $config['method'] === 'auto') {
            // Enable SLAAC
            exec("sysctl -w net.ipv6.conf.{$interface}.autoconf=1 2>&1");
            exec("sysctl -w net.ipv6.conf.{$interface}.accept_ra=1 2>&1");
            return true;
        }

        if (isset($config['address']) && isset($config['prefix'])) {
            $address = $config['address'];
            $prefix = $config['prefix'];

            exec("ip -6 addr add {$address}/{$prefix} dev {$interface} 2>&1", $output, $returnCode);
            return $returnCode === 0;
        }

        return false;
    }

    /**
     * Set interface administrative state.
     *
     * @param string $interface Interface name
     * @param bool $up True to bring up, false to bring down
     * @return bool True if successful
     */
    public function setInterfaceState(string $interface, bool $up): bool
    {
        $state = $up ? 'up' : 'down';
        exec("ip link set {$interface} {$state} 2>&1", $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * Add a static route.
     *
     * @param array $route Route configuration
     * @return bool True if successful
     */
    public function addRoute(array $route): bool
    {
        $cmd = 'ip route add';

        $destination = $route['destination'] ?? 'default';
        $cmd .= " {$destination}";

        if (isset($route['gateway'])) {
            $cmd .= " via {$route['gateway']}";
        }

        if (isset($route['interface'])) {
            $cmd .= " dev {$route['interface']}";
        }

        if (isset($route['metric'])) {
            $cmd .= " metric {$route['metric']}";
        }

        exec($cmd . ' 2>&1', $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * Remove a route.
     *
     * @param array $route Route configuration
     * @return bool True if successful
     */
    public function removeRoute(array $route): bool
    {
        $destination = $route['destination'] ?? 'default';
        exec("ip route del {$destination} 2>&1", $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * Get current routing table.
     *
     * @return array List of routes
     */
    public function getRoutes(): array
    {
        $routes = [];
        exec('ip route show 2>&1', $output, $returnCode);

        if ($returnCode !== 0) {
            return $routes;
        }

        foreach ($output as $line) {
            if (preg_match('/^(\S+)\s+(?:via\s+(\S+)\s+)?dev\s+(\S+)/', $line, $m)) {
                $routes[] = [
                    'destination' => $m[1],
                    'gateway' => $m[2] ?? null,
                    'interface' => $m[3],
                ];
            }
        }

        return $routes;
    }

    public function getDnsServers(): array
    {
        $servers = [];
        $lines = @file('/etc/resolv.conf', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return $servers;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if (strpos($line, 'nameserver ') !== 0) {
                continue;
            }

            $parts = preg_split('/\s+/', $line);
            if (isset($parts[1]) && $parts[1] !== '') {
                $servers[] = $parts[1];
            }
        }

        return $servers;
    }

    /**
     * Get current IP addresses for all interfaces.
     *
     * @return array Interface addresses indexed by interface name
     */
    public function getAddresses(): array
    {
        $addresses = [];
        exec('ip addr show 2>&1', $output, $returnCode);

        if ($returnCode !== 0) {
            return $addresses;
        }

        $currentIface = null;
        foreach ($output as $line) {
            if (preg_match('/^\d+:\s+(\S+):/', $line, $m)) {
                $currentIface = $m[1];
                $addresses[$currentIface] = ['ipv4' => [], 'ipv6' => []];
            } elseif ($currentIface && preg_match('/inet\s+(\S+)/', $line, $m)) {
                $addresses[$currentIface]['ipv4'][] = $m[1];
            } elseif ($currentIface && preg_match('/inet6\s+(\S+)/', $line, $m)) {
                $addresses[$currentIface]['ipv6'][] = $m[1];
            }
        }

        return $addresses;
    }

    /**
     * Get all network interfaces with their status.
     *
     * @return array List of interfaces with status info
     */
    public function getInterfaces(): array
    {
        $interfaces = [];
        $sysNetDir = '/sys/class/net';

        if (!is_dir($sysNetDir)) {
            return $interfaces;
        }

        // Get addresses for all interfaces
        $addresses = $this->getAddresses();

        foreach (scandir($sysNetDir) as $iface) {
            if ($iface === '.' || $iface === '..') {
                continue;
            }

            if (!$this->isEthernetInterface($iface) && !$this->isAllowlistedNonEthernet($iface)) {
                continue;
            }

            $interfaces[$iface] = [
                'name' => $iface,
                'mac' => $this->getMacAddress($iface),
                'state' => $this->getOperState($iface),
                'ipv4' => $addresses[$iface]['ipv4'] ?? [],
                'ipv6' => $addresses[$iface]['ipv6'] ?? [],
                'mtu' => $this->getMtu($iface),
                'stats' => $this->getInterfaceStats($iface),
            ];
        }

        return $interfaces;
    }

    /**
     * Check if interface is Ethernet class.
     *
     * @param string $interface Interface name
     * @return bool True if Ethernet
     */
    private function isEthernetInterface(string $interface): bool
    {
        $path = "/sys/class/net/{$interface}/type";
        if (!file_exists($path)) {
            return false;
        }

        return trim(file_get_contents($path)) === '1';
    }

    private function isAllowlistedNonEthernet(string $interface): bool
    {
        return in_array($interface, self::NON_ETHERNET_ALLOWLIST, true);
    }

    /**
     * Get MAC address of an interface.
     *
     * @param string $interface Interface name
     * @return string|null MAC address or null
     */
    private function getMacAddress(string $interface): ?string
    {
        $path = "/sys/class/net/{$interface}/address";
        if (file_exists($path)) {
            return trim(file_get_contents($path));
        }
        return null;
    }

    /**
     * Get operational state of an interface.
     *
     * @param string $interface Interface name
     * @return string Operational state (up/down/unknown)
     */
    private function getOperState(string $interface): string
    {
        $path = "/sys/class/net/{$interface}/operstate";
        if (file_exists($path)) {
            return trim(file_get_contents($path));
        }
        return 'unknown';
    }

    /**
     * Get MTU of an interface.
     *
     * @param string $interface Interface name
     * @return int|null MTU or null
     */
    private function getMtu(string $interface): ?int
    {
        $path = "/sys/class/net/{$interface}/mtu";
        if (file_exists($path)) {
            return (int)trim(file_get_contents($path));
        }
        return null;
    }

    /**
     * Get interface statistics (bytes/packets rx/tx).
     *
     * @param string $interface Interface name
     * @return array Interface statistics
     */
    private function getInterfaceStats(string $interface): array
    {
        $stats = [
            'rx_bytes' => 0,
            'tx_bytes' => 0,
            'rx_packets' => 0,
            'tx_packets' => 0,
        ];

        $statsDir = "/sys/class/net/{$interface}/statistics";
        if (is_dir($statsDir)) {
            foreach (['rx_bytes', 'tx_bytes', 'rx_packets', 'tx_packets'] as $stat) {
                $path = "{$statsDir}/{$stat}";
                if (file_exists($path)) {
                    $stats[$stat] = (int)trim(file_get_contents($path));
                }
            }
        }

        return $stats;
    }

    /**
     * Convert netmask to CIDR prefix length.
     *
     * @param string $netmask Dotted decimal netmask
     * @return int CIDR prefix length
     */
    private function netmaskToPrefix(string $netmask): int
    {
        return strlen(preg_replace('/0/', '', decbin(ip2long($netmask))));
    }

    /**
     * Enable IP forwarding.
     *
     * @param bool $ipv4 Enable IPv4 forwarding
     * @param bool $ipv6 Enable IPv6 forwarding
     * @return bool True if successful
     */
    public function enableForwarding(bool $ipv4 = true, bool $ipv6 = true): bool
    {
        $success = true;

        if ($ipv4) {
            exec('sysctl -w net.ipv4.ip_forward=1 2>&1', $output, $returnCode);
            $success = $success && ($returnCode === 0);
        }

        if ($ipv6) {
            exec('sysctl -w net.ipv6.conf.all.forwarding=1 2>&1', $output, $returnCode);
            $success = $success && ($returnCode === 0);
        }

        return $success;
    }
}
