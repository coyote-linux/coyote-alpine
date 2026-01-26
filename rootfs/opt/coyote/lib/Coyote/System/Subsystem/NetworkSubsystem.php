<?php

namespace Coyote\System\Subsystem;

/**
 * Network interface configuration subsystem.
 *
 * Handles:
 * - Interface IP addresses (static, multiple)
 * - DHCP client
 * - PPPoE client
 * - Interface state (up/down)
 * - MTU configuration
 * - MAC address override
 * - VLAN sub-interfaces
 * - Static routes
 * - IP forwarding
 *
 * REQUIRES COUNTDOWN: Network changes can cause loss of remote access.
 */
class NetworkSubsystem extends AbstractSubsystem
{
    public function getName(): string
    {
        return 'network';
    }

    public function requiresCountdown(): bool
    {
        // Network changes CAN cause loss of remote access
        return true;
    }

    public function getConfigKeys(): array
    {
        return [
            'network.interfaces',
            'network.routes',
        ];
    }

    public function hasChanges(array $working, array $running): bool
    {
        return $this->valuesChanged($working, $running, $this->getConfigKeys());
    }

    public function apply(array $config): array
    {
        $errors = [];
        $interfaces = $this->getNestedValue($config, 'network.interfaces', []);
        $routes = $this->getNestedValue($config, 'network.routes', []);

        // Configure loopback first
        $this->configureLoopback($errors);

        // Configure each interface
        foreach ($interfaces as $ifaceConfig) {
            $this->configureInterface($ifaceConfig, $errors);
        }

        // Configure additional routes
        foreach ($routes as $route) {
            $this->configureRoute($route, $errors);
        }

        // Enable IP forwarding
        $this->exec('sysctl -w net.ipv4.ip_forward=1', true);
        $this->exec('sysctl -w net.ipv6.conf.all.forwarding=1', true);

        if (!empty($errors)) {
            return $this->failure('Network configuration had errors', $errors);
        }

        return $this->success('Network configuration applied');
    }

    /**
     * Configure the loopback interface.
     */
    private function configureLoopback(array &$errors): void
    {
        // Check if loopback already has the address
        $result = $this->exec('ip addr show lo');
        if (strpos($result['output'], '127.0.0.1/8') !== false) {
            // Already configured
            return;
        }

        $result = $this->exec('ip addr add 127.0.0.1/8 dev lo', true);
        if (!$result['success'] && strpos($result['output'], 'RTNETLINK answers: File exists') === false) {
            $errors[] = 'Failed to configure loopback: ' . $result['output'];
        }

        $this->exec('ip link set lo up', true);
    }

    /**
     * Configure a network interface.
     */
    private function configureInterface(array $ifaceConfig, array &$errors): void
    {
        $name = $ifaceConfig['name'] ?? null;
        $type = $ifaceConfig['type'] ?? 'disabled';
        $enabled = $ifaceConfig['enabled'] ?? true;
        $mtu = $ifaceConfig['mtu'] ?? 1500;
        $macOverride = $ifaceConfig['mac_override'] ?? null;

        if (!$name) {
            return;
        }

        // Check if interface exists
        if (!file_exists("/sys/class/net/{$name}")) {
            $errors[] = "Interface {$name} not found";
            return;
        }

        // Handle disabled or not enabled interfaces
        if ($type === 'disabled' || !$enabled) {
            $this->stopDhcp($name);
            $this->stopPppoe($name);
            $this->exec("ip addr flush dev " . escapeshellarg($name), true);
            $this->exec("ip link set " . escapeshellarg($name) . " down", true);
            return;
        }

        // Stop any running clients first
        $this->stopDhcp($name);
        $this->stopPppoe($name);

        // Set MTU
        if ($mtu && $mtu != 1500) {
            $this->exec("ip link set " . escapeshellarg($name) . " mtu " . escapeshellarg($mtu), true);
        }

        // Set MAC address override if specified
        if (!empty($macOverride)) {
            // Interface must be down to change MAC
            $this->exec("ip link set " . escapeshellarg($name) . " down", true);
            $result = $this->exec("ip link set " . escapeshellarg($name) . " address " . escapeshellarg($macOverride), true);
            if (!$result['success']) {
                $errors[] = "Failed to set MAC address on {$name}: " . $result['output'];
            }
        }

        // Handle by type
        switch ($type) {
            case 'static':
                $this->configureStatic($name, $ifaceConfig, $errors);
                break;

            case 'dhcp':
                $this->configureDhcp($name, $ifaceConfig, $errors);
                break;

            case 'pppoe':
                $this->configurePppoe($name, $ifaceConfig, $errors);
                break;

            case 'bridge':
                $this->configureBridge($name, $ifaceConfig, $errors);
                break;
        }

        // Configure VLANs (only for static interfaces)
        if ($type === 'static' && !empty($ifaceConfig['vlans'])) {
            $this->configureVlans($name, $ifaceConfig['vlans'], $errors);
        }
    }

    /**
     * Configure static IP addressing.
     */
    private function configureStatic(string $name, array $config, array &$errors): void
    {
        $addresses = $config['addresses'] ?? [];

        // Flush existing addresses
        $this->exec("ip addr flush dev " . escapeshellarg($name), true);

        // Bring interface up
        $this->exec("ip link set " . escapeshellarg($name) . " up", true);

        // Add all addresses
        foreach ($addresses as $address) {
            if (empty($address)) continue;

            $result = $this->exec(
                "ip addr add " . escapeshellarg($address) . " dev " . escapeshellarg($name),
                true
            );

            if (!$result['success'] && strpos($result['output'], 'RTNETLINK answers: File exists') === false) {
                $errors[] = "Failed to set address {$address} on {$name}: " . $result['output'];
            }
        }
    }

    /**
     * Configure DHCP client.
     */
    private function configureDhcp(string $name, array $config, array &$errors): void
    {
        $hostname = $config['dhcp_hostname'] ?? '';

        // Flush any existing addresses
        $this->exec("ip addr flush dev " . escapeshellarg($name), true);

        // Bring interface up
        $this->exec("ip link set " . escapeshellarg($name) . " up", true);

        // Build udhcpc command
        $pidFile = "/var/run/udhcpc.{$name}.pid";
        $cmd = "udhcpc -i " . escapeshellarg($name) . " -b -p " . escapeshellarg($pidFile) . " -S";

        if (!empty($hostname)) {
            $cmd .= " -H " . escapeshellarg($hostname);
        }

        $result = $this->exec($cmd, true);

        if (!$result['success']) {
            $errors[] = "Failed to start DHCP on {$name}: " . $result['output'];
        }
    }

    /**
     * Configure PPPoE client.
     */
    private function configurePppoe(string $name, array $config, array &$errors): void
    {
        $username = $config['pppoe_username'] ?? '';
        $password = $config['pppoe_password'] ?? '';

        if (empty($username) || empty($password)) {
            $errors[] = "PPPoE username and password required for {$name}";
            return;
        }

        // Bring interface up (PPPoE needs raw access)
        $this->exec("ip addr flush dev " . escapeshellarg($name), true);
        $this->exec("ip link set " . escapeshellarg($name) . " up", true);

        // Write PPPoE peer configuration
        $peerConfig = "plugin pppoe.so {$name}\n";
        $peerConfig .= "user \"{$username}\"\n";
        $peerConfig .= "password \"{$password}\"\n";
        $peerConfig .= "persist\n";
        $peerConfig .= "defaultroute\n";
        $peerConfig .= "usepeerdns\n";
        $peerConfig .= "noauth\n";
        $peerConfig .= "hide-password\n";

        $peerFile = "/etc/ppp/peers/pppoe-{$name}";
        @mkdir('/etc/ppp/peers', 0755, true);

        if (file_put_contents($peerFile, $peerConfig) === false) {
            $errors[] = "Failed to write PPPoE configuration for {$name}";
            return;
        }

        // Start pppd
        $result = $this->exec("pppd call pppoe-{$name}", true);

        if (!$result['success']) {
            $errors[] = "Failed to start PPPoE on {$name}: " . $result['output'];
        }
    }

    /**
     * Configure interface for bridging.
     */
    private function configureBridge(string $name, array $config, array &$errors): void
    {
        // For bridge member, just bring the interface up with no address
        $this->exec("ip addr flush dev " . escapeshellarg($name), true);
        $this->exec("ip link set " . escapeshellarg($name) . " up", true);

        // Note: Actual bridge configuration (brctl addif) would be done
        // when configuring the bridge interface itself
    }

    /**
     * Configure VLAN sub-interfaces.
     */
    private function configureVlans(string $parentName, array $vlanIds, array &$errors): void
    {
        // Load 8021q module if not loaded
        $this->exec('modprobe 8021q', true);

        foreach ($vlanIds as $vlanId) {
            $vlanName = "{$parentName}.{$vlanId}";

            // Check if VLAN interface already exists
            if (!file_exists("/sys/class/net/{$vlanName}")) {
                // Create VLAN interface
                $result = $this->exec(
                    "ip link add link " . escapeshellarg($parentName) .
                    " name " . escapeshellarg($vlanName) .
                    " type vlan id " . escapeshellarg($vlanId),
                    true
                );

                if (!$result['success']) {
                    $errors[] = "Failed to create VLAN {$vlanId} on {$parentName}: " . $result['output'];
                    continue;
                }
            }

            // Bring VLAN interface up
            $this->exec("ip link set " . escapeshellarg($vlanName) . " up", true);
        }
    }

    /**
     * Configure a static route.
     */
    private function configureRoute(array $route, array &$errors): void
    {
        $dest = $route['destination'] ?? null;
        $gateway = $route['gateway'] ?? null;
        $dev = $route['interface'] ?? $route['device'] ?? null;

        if (!$dest) {
            return;
        }

        // Handle default route specially
        if ($dest === 'default') {
            // Remove existing default route first
            $this->exec('ip route del default', true);

            $cmd = "ip route add default";
        } else {
            $cmd = "ip route replace " . escapeshellarg($dest);
        }

        if ($gateway) {
            $cmd .= " via " . escapeshellarg($gateway);
        }

        if ($dev) {
            $cmd .= " dev " . escapeshellarg($dev);
        }

        $result = $this->exec($cmd, true);

        if (!$result['success']) {
            $errors[] = "Failed to add route to {$dest}: " . $result['output'];
        }
    }

    /**
     * Stop DHCP client on an interface.
     */
    private function stopDhcp(string $iface): void
    {
        $pidFile = "/var/run/udhcpc.{$iface}.pid";

        if (file_exists($pidFile)) {
            $pid = trim(file_get_contents($pidFile));
            if ($pid && is_numeric($pid)) {
                $this->exec("kill " . escapeshellarg($pid), true);
            }
            @unlink($pidFile);
        }

        // Also try to kill by process name pattern (backup method)
        $this->exec("pkill -f 'udhcpc.*-i " . $iface . "'", true);
    }

    /**
     * Stop PPPoE client on an interface.
     */
    private function stopPppoe(string $iface): void
    {
        // Kill pppd using this peer config
        $this->exec("pkill -f 'pppd call pppoe-" . $iface . "'", true);

        // Also kill any pppoe-related processes for this interface
        $this->exec("pkill -f 'pppoe.*" . $iface . "'", true);
    }

    /**
     * Get the current IP address on an interface.
     */
    private function getCurrentAddress(string $iface): ?string
    {
        $result = $this->exec("ip -o addr show dev " . escapeshellarg($iface) . " scope global");

        if (preg_match('/inet\s+(\S+)/', $result['output'], $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Get the current default gateway.
     */
    private function getCurrentGateway(): ?string
    {
        $result = $this->exec('ip route show default');

        if (preg_match('/default via (\S+)/', $result['output'], $matches)) {
            return $matches[1];
        }

        return null;
    }
}
