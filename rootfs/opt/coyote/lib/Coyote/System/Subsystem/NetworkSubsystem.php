<?php

namespace Coyote\System\Subsystem;

/**
 * Network interface configuration subsystem.
 *
 * Handles:
 * - Interface IP addresses
 * - Interface state (up/down)
 * - Default gateway
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
        $address = $ifaceConfig['address'] ?? null;
        $gateway = $ifaceConfig['gateway'] ?? null;

        if (!$name) {
            return;
        }

        // Check if interface exists
        if (!file_exists("/sys/class/net/{$name}")) {
            $errors[] = "Interface {$name} not found";
            return;
        }

        // Get current interface state
        $currentAddress = $this->getCurrentAddress($name);

        // Only reconfigure if address is different
        if ($address && $currentAddress !== $address) {
            // Flush existing addresses
            $this->exec("ip addr flush dev " . escapeshellarg($name), true);

            // Add new address
            $result = $this->exec(
                "ip addr add " . escapeshellarg($address) . " dev " . escapeshellarg($name),
                true
            );

            if (!$result['success']) {
                $errors[] = "Failed to set address on {$name}: " . $result['output'];
            }
        }

        // Bring interface up
        $this->exec("ip link set " . escapeshellarg($name) . " up", true);

        // Set default gateway if specified
        if ($gateway) {
            $currentGateway = $this->getCurrentGateway();

            if ($currentGateway !== $gateway) {
                // Remove existing default route
                $this->exec('ip route del default', true);

                // Add new default route
                $result = $this->exec(
                    "ip route add default via " . escapeshellarg($gateway) . " dev " . escapeshellarg($name),
                    true
                );

                if (!$result['success']) {
                    $errors[] = "Failed to set gateway: " . $result['output'];
                }
            }
        }
    }

    /**
     * Configure a static route.
     */
    private function configureRoute(array $route, array &$errors): void
    {
        $dest = $route['destination'] ?? null;
        $via = $route['gateway'] ?? null;
        $dev = $route['device'] ?? null;

        if (!$dest) {
            return;
        }

        $cmd = "ip route replace " . escapeshellarg($dest);

        if ($via) {
            $cmd .= " via " . escapeshellarg($via);
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
