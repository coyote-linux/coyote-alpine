<?php

namespace Coyote\System\Subsystem;

use Coyote\System\PrivilegedExecutor;

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
        $priv = $this->getPrivilegedExecutor();
        $interfaces = $this->getNestedValue($config, 'network.interfaces', []);
        $routes = $this->getNestedValue($config, 'network.routes', []);

        // Configure loopback first
        $this->configureLoopback($priv, $errors);

        // Configure each interface
        foreach ($interfaces as $ifaceConfig) {
            $this->configureInterface($priv, $ifaceConfig, $errors);
        }

        // Configure additional routes
        foreach ($routes as $route) {
            $this->configureRoute($priv, $route, $errors);
        }

        // Enable IP forwarding
        $priv->sysctl('-w', 'net.ipv4.ip_forward=1');
        $priv->sysctl('-w', 'net.ipv6.conf.all.forwarding=1');

        if (!empty($errors)) {
            return $this->failure('Network configuration had errors', $errors);
        }

        return $this->success('Network configuration applied');
    }

    /**
     * Configure the loopback interface.
     */
    private function configureLoopback(PrivilegedExecutor $priv, array &$errors): void
    {
        // Check if loopback already has the address (read-only operation, no privilege needed)
        $result = $this->exec('ip addr show lo');
        if (strpos($result['output'], '127.0.0.1/8') !== false) {
            // Already configured
            return;
        }

        $result = $priv->ip('addr', 'add', '127.0.0.1/8', 'dev', 'lo');
        if (!$result['success'] && strpos($result['output'], 'RTNETLINK answers: File exists') === false) {
            $errors[] = 'Failed to configure loopback: ' . $result['output'];
        }

        $priv->ip('link', 'set', 'lo', 'up');
    }

    /**
     * Configure a network interface.
     */
    private function configureInterface(PrivilegedExecutor $priv, array $ifaceConfig, array &$errors): void
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
            $this->stopDhcp($priv, $name);
            $this->stopPppoe($priv, $name);
            $priv->ip('addr', 'flush', 'dev', $name);
            $priv->ip('link', 'set', $name, 'down');
            return;
        }

        // Stop any running clients first
        $this->stopDhcp($priv, $name);
        $this->stopPppoe($priv, $name);

        // Set MTU
        if ($mtu && $mtu != 1500) {
            $priv->ip('link', 'set', $name, 'mtu', (string)$mtu);
        }

        // Set MAC address override if specified
        if (!empty($macOverride)) {
            // Interface must be down to change MAC
            $priv->ip('link', 'set', $name, 'down');
            $result = $priv->ip('link', 'set', $name, 'address', $macOverride);
            if (!$result['success']) {
                $errors[] = "Failed to set MAC address on {$name}: " . $result['output'];
            }
        }

        // Handle by type
        switch ($type) {
            case 'static':
                $this->configureStatic($priv, $name, $ifaceConfig, $errors);
                break;

            case 'dhcp':
                $this->configureDhcp($priv, $name, $ifaceConfig, $errors);
                break;

            case 'pppoe':
                $this->configurePppoe($priv, $name, $ifaceConfig, $errors);
                break;

            case 'bridge':
                $this->configureBridge($priv, $name, $ifaceConfig, $errors);
                break;
        }

        // Configure VLANs (only for static interfaces)
        if ($type === 'static' && !empty($ifaceConfig['vlans'])) {
            $this->configureVlans($priv, $name, $ifaceConfig['vlans'], $errors);
        }
    }

    /**
     * Configure static IP addressing.
     */
    private function configureStatic(PrivilegedExecutor $priv, string $name, array $config, array &$errors): void
    {
        $addresses = $config['addresses'] ?? [];

        // Flush existing addresses
        $priv->ip('addr', 'flush', 'dev', $name);

        // Bring interface up
        $priv->ip('link', 'set', $name, 'up');

        // Add all addresses
        foreach ($addresses as $address) {
            if (empty($address)) continue;

            $result = $priv->ip('addr', 'add', $address, 'dev', $name);

            if (!$result['success'] && strpos($result['output'], 'RTNETLINK answers: File exists') === false) {
                $errors[] = "Failed to set address {$address} on {$name}: " . $result['output'];
            }
        }
    }

    /**
     * Configure DHCP client.
     */
    private function configureDhcp(PrivilegedExecutor $priv, string $name, array $config, array &$errors): void
    {
        $hostname = $config['dhcp_hostname'] ?? '';

        // Flush any existing addresses
        $priv->ip('addr', 'flush', 'dev', $name);

        // Bring interface up
        $priv->ip('link', 'set', $name, 'up');

        // Build udhcpc arguments
        $pidFile = "/var/run/udhcpc.{$name}.pid";
        $args = ['-i', $name, '-b', '-p', $pidFile, '-S'];

        if (!empty($hostname)) {
            $args[] = '-H';
            $args[] = $hostname;
        }

        $result = $priv->udhcpc(...$args);

        if (!$result['success']) {
            $errors[] = "Failed to start DHCP on {$name}: " . $result['output'];
        }
    }

    /**
     * Configure PPPoE client.
     */
    private function configurePppoe(PrivilegedExecutor $priv, string $name, array $config, array &$errors): void
    {
        $username = $config['pppoe_username'] ?? '';
        $password = $config['pppoe_password'] ?? '';

        if (empty($username) || empty($password)) {
            $errors[] = "PPPoE username and password required for {$name}";
            return;
        }

        // Bring interface up (PPPoE needs raw access)
        $priv->ip('addr', 'flush', 'dev', $name);
        $priv->ip('link', 'set', $name, 'up');

        // Write PPPoE peer configuration via privileged executor
        $peerConfig = "plugin pppoe.so {$name}\n";
        $peerConfig .= "user \"{$username}\"\n";
        $peerConfig .= "password \"{$password}\"\n";
        $peerConfig .= "persist\n";
        $peerConfig .= "defaultroute\n";
        $peerConfig .= "usepeerdns\n";
        $peerConfig .= "noauth\n";
        $peerConfig .= "hide-password\n";

        $peerFile = "/etc/ppp/peers/pppoe-{$name}";

        $result = $priv->writeFile($peerFile, $peerConfig);
        if (!$result['success']) {
            $errors[] = "Failed to write PPPoE configuration for {$name}: " . $result['output'];
            return;
        }

        // Start pppd
        $result = $priv->pppd('call', "pppoe-{$name}");

        if (!$result['success']) {
            $errors[] = "Failed to start PPPoE on {$name}: " . $result['output'];
        }
    }

    /**
     * Configure interface for bridging.
     */
    private function configureBridge(PrivilegedExecutor $priv, string $name, array $config, array &$errors): void
    {
        // For bridge member, just bring the interface up with no address
        $priv->ip('addr', 'flush', 'dev', $name);
        $priv->ip('link', 'set', $name, 'up');

        // Note: Actual bridge configuration (brctl addif) would be done
        // when configuring the bridge interface itself
    }

    /**
     * Configure VLAN sub-interfaces.
     */
    private function configureVlans(PrivilegedExecutor $priv, string $parentName, array $vlanIds, array &$errors): void
    {
        // Load 8021q module if not loaded
        $priv->modprobe('8021q');

        foreach ($vlanIds as $vlanId) {
            $vlanName = "{$parentName}.{$vlanId}";

            // Check if VLAN interface already exists
            if (!file_exists("/sys/class/net/{$vlanName}")) {
                // Create VLAN interface
                $result = $priv->ip(
                    'link', 'add', 'link', $parentName,
                    'name', $vlanName,
                    'type', 'vlan', 'id', (string)$vlanId
                );

                if (!$result['success']) {
                    $errors[] = "Failed to create VLAN {$vlanId} on {$parentName}: " . $result['output'];
                    continue;
                }
            }

            // Bring VLAN interface up
            $priv->ip('link', 'set', $vlanName, 'up');
        }
    }

    /**
     * Configure a static route.
     */
    private function configureRoute(PrivilegedExecutor $priv, array $route, array &$errors): void
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
            $priv->ip('route', 'del', 'default');

            $args = ['route', 'add', 'default'];
        } else {
            $args = ['route', 'replace', $dest];
        }

        if ($gateway) {
            $args[] = 'via';
            $args[] = $gateway;
        }

        if ($dev) {
            $args[] = 'dev';
            $args[] = $dev;
        }

        $result = $priv->ip(...$args);

        if (!$result['success']) {
            $errors[] = "Failed to add route to {$dest}: " . $result['output'];
        }
    }

    /**
     * Stop DHCP client on an interface.
     */
    private function stopDhcp(PrivilegedExecutor $priv, string $iface): void
    {
        $pidFile = "/var/run/udhcpc.{$iface}.pid";

        if (file_exists($pidFile)) {
            $pid = trim(file_get_contents($pidFile));
            if ($pid && is_numeric($pid)) {
                $priv->killPid((int)$pid);
            }
            @unlink($pidFile);
        }

        // Also try to kill by process name pattern (backup method)
        $priv->pkillPattern("udhcpc.*-i {$iface}");
    }

    /**
     * Stop PPPoE client on an interface.
     */
    private function stopPppoe(PrivilegedExecutor $priv, string $iface): void
    {
        // Kill pppd using this peer config
        $priv->pkillPattern("pppd call pppoe-{$iface}");

        // Also kill any pppoe-related processes for this interface
        $priv->pkillPattern("pppoe.*{$iface}");
    }

    /**
     * Get the current IP address on an interface.
     */
    private function getCurrentAddress(string $iface): ?string
    {
        // Read-only operation, no privilege needed
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
        // Read-only operation, no privilege needed
        $result = $this->exec('ip route show default');

        if (preg_match('/default via (\S+)/', $result['output'], $matches)) {
            return $matches[1];
        }

        return null;
    }
}
