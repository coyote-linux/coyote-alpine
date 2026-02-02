<?php

namespace Coyote\System\Subsystem;

use Coyote\Firewall\FirewallManager;

/**
 * Firewall configuration subsystem.
 *
 * Handles:
 * - nftables ruleset generation and application
 * - NAT/masquerade configuration
 * - Port forwarding rules
 * - ICMP policy
 * - Service ACLs (SSH, SNMP, DHCP, DNS, web admin)
 * - User-defined ACLs
 * - QoS/traffic classification
 * - UPnP/miniupnpd service
 * - Firewall logging
 *
 * REQUIRES COUNTDOWN: Firewall changes can cause loss of remote access.
 */
class FirewallSubsystem extends AbstractSubsystem
{
    /** @var FirewallManager */
    private FirewallManager $firewallManager;

    /**
     * Create a new FirewallSubsystem instance.
     */
    public function __construct()
    {
        $this->firewallManager = new FirewallManager();
    }

    public function getName(): string
    {
        return 'firewall';
    }

    public function requiresCountdown(): bool
    {
        // Firewall changes CAN cause loss of remote access
        return true;
    }

    public function getConfigKeys(): array
    {
        return [
            'firewall.enabled',
            'firewall.default_policy',
            'firewall.options',
            'firewall.logging',
            'firewall.icmp',
            'firewall.sets',
            'firewall.acls',
            'firewall.applied',
            'firewall.nat',
            'firewall.rules',
            'firewall.port_forwards',
            'firewall.qos',
            'services.ssh.enabled',
            'services.ssh.port',
            'services.ssh.allowed_hosts',
            'services.snmp.enabled',
            'services.snmp.allowed_hosts',
            'services.dhcpd.enabled',
            'services.dhcpd.interface',
            'services.dns.enabled',
            'services.upnp',
            'services.webadmin.enabled',
            'services.webadmin.allowed_hosts',
            'services.webadmin.http_port',
            'services.webadmin.https_port',
        ];
    }

    public function hasChanges(array $working, array $running): bool
    {
        return $this->valuesChanged($working, $running, $this->getConfigKeys());
    }

    public function apply(array $config): array
    {
        $errors = [];

        // Check if firewall is enabled
        $firewallEnabled = $this->getNestedValue($config, 'firewall.enabled', true);

        if (!$firewallEnabled) {
            // Firewall disabled - flush rules and stop services
            $this->disableFirewall($errors);

            if (!empty($errors)) {
                return $this->failure('Failed to disable firewall', $errors);
            }

            return $this->success('Firewall disabled');
        }

        // Apply firewall configuration
        $this->applyFirewallRules($config, $errors);

        // Apply UPnP configuration
        $this->applyUpnp($config, $errors);

        // Apply QoS tc rules if enabled
        $this->applyQos($config, $errors);

        if (!empty($errors)) {
            return $this->failure('Firewall configuration had errors', $errors);
        }

        return $this->success('Firewall configuration applied');
    }

    /**
     * Apply nftables firewall rules.
     *
     * @param array $config Full configuration
     * @param array $errors Error accumulator
     */
    private function applyFirewallRules(array $config, array &$errors): void
    {
        try {
            $result = $this->firewallManager->applyConfig($config);

            if (!$result) {
                $errors[] = 'Failed to apply nftables ruleset';
            }
        } catch (\Exception $e) {
            $errors[] = 'Firewall error: ' . $e->getMessage();
        }
    }

    /**
     * Apply UPnP/miniupnpd configuration.
     *
     * @param array $config Full configuration
     * @param array $errors Error accumulator
     */
    private function applyUpnp(array $config, array &$errors): void
    {
        $upnpConfig = $this->getNestedValue($config, 'services.upnp', []);
        $networkConfig = $this->getNestedValue($config, 'network', []);

        $upnpService = $this->firewallManager->getUpnpService();
        $upnpService->loadConfig($upnpConfig, $networkConfig);

        try {
            $result = $upnpService->apply();

            if (!$result && ($upnpConfig['enabled'] ?? false)) {
                $errors[] = 'Failed to apply UPnP configuration';
            }
        } catch (\Exception $e) {
            $errors[] = 'UPnP error: ' . $e->getMessage();
        }
    }

    /**
     * Apply QoS traffic control rules.
     *
     * @param array $config Full configuration
     * @param array $errors Error accumulator
     */
    private function applyQos(array $config, array &$errors): void
    {
        $qosConfig = $this->getNestedValue($config, 'firewall.qos', []);

        if (!($qosConfig['enabled'] ?? false)) {
            // QoS disabled - clear any existing tc rules
            $this->clearQosRules();
            return;
        }

        $qosManager = $this->firewallManager->getQosManager();
        $interfaceConfigs = $qosManager->getInterfaceConfigs();

        // Apply tc rules to each configured interface
        foreach ($interfaceConfigs as $iface => $ifaceConfig) {
            $bandwidth = $ifaceConfig['bandwidth'] ?? null;

            if (!$bandwidth) {
                continue;
            }

            $commands = $qosManager->generateTcCommands($iface, $bandwidth);

            foreach ($commands as $cmd) {
                $result = $this->exec($cmd, true);

                if (!$result['success'] && strpos($result['output'], 'Cannot delete qdisc') === false) {
                    // Don't report errors for "cannot delete" when qdisc doesn't exist
                    if (strpos($cmd, 'del') === false) {
                        $errors[] = "QoS tc command failed: {$cmd}";
                    }
                }
            }
        }
    }

    /**
     * Clear QoS rules from all interfaces.
     */
    private function clearQosRules(): void
    {
        // Get list of interfaces with qdisc configured
        $result = $this->exec('tc qdisc show');

        if (!$result['success']) {
            return;
        }

        // Parse interfaces from output
        $lines = explode("\n", $result['output']);
        $interfaces = [];

        foreach ($lines as $line) {
            if (preg_match('/qdisc htb.*dev (\S+)/', $line, $matches)) {
                $interfaces[] = $matches[1];
            }
        }

        // Clear qdisc from each interface
        foreach ($interfaces as $iface) {
            $this->exec("tc qdisc del dev {$iface} root 2>/dev/null", true);
        }
    }

    /**
     * Disable the firewall completely.
     *
     * @param array $errors Error accumulator
     */
    private function disableFirewall(array &$errors): void
    {
        // Stop UPnP service
        $upnpService = $this->firewallManager->getUpnpService();
        $upnpService->stop();

        // Clear QoS rules
        $this->clearQosRules();

        // Flush nftables rules
        try {
            $result = $this->firewallManager->emergencyStop();

            if (!$result) {
                $errors[] = 'Failed to flush firewall rules';
            }
        } catch (\Exception $e) {
            $errors[] = 'Error disabling firewall: ' . $e->getMessage();
        }
    }

    /**
     * Get the FirewallManager instance.
     *
     * @return FirewallManager
     */
    public function getFirewallManager(): FirewallManager
    {
        return $this->firewallManager;
    }

    /**
     * Get current firewall status.
     *
     * @return array Status information
     */
    public function getStatus(): array
    {
        return [
            'subsystem' => $this->getName(),
            'firewall' => $this->firewallManager->getStatus(),
            'upnp' => $this->firewallManager->getUpnpStatus(),
        ];
    }

    /**
     * Emergency firewall disable.
     *
     * Flushes all rules and stops related services immediately.
     *
     * @return bool True if successful
     */
    public function emergencyDisable(): bool
    {
        $errors = [];
        $this->disableFirewall($errors);
        return empty($errors);
    }

    /**
     * Rollback to previous firewall configuration.
     *
     * @return bool True if successful
     */
    public function rollback(): bool
    {
        return $this->firewallManager->rollback();
    }

    /**
     * Block a host immediately.
     *
     * @param string $host IP address or CIDR
     * @return bool True if successful
     */
    public function blockHost(string $host): bool
    {
        return $this->firewallManager->blockHost($host);
    }

    /**
     * Unblock a host.
     *
     * @param string $host IP address or CIDR
     * @return bool True if successful
     */
    public function unblockHost(string $host): bool
    {
        return $this->firewallManager->unblockHost($host);
    }

    /**
     * Get list of blocked hosts.
     *
     * @return array Blocked host addresses
     */
    public function getBlockedHosts(): array
    {
        return $this->firewallManager->getBlockedHosts();
    }

    /**
     * Get active UPnP leases.
     *
     * @return array Lease information
     */
    public function getUpnpLeases(): array
    {
        return $this->firewallManager->getUpnpLeases();
    }

    /**
     * Get active connection count.
     *
     * @return array Connection tracking information
     */
    public function getActiveConnections(): array
    {
        return $this->firewallManager->getActiveConnections();
    }
}
