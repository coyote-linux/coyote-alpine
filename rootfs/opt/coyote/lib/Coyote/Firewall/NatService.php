<?php

namespace Coyote\Firewall;

/**
 * Service for managing NAT rules.
 *
 * Handles masquerading (SNAT for dynamic IPs), DNAT (port forwarding),
 * and static SNAT rules.
 */
class NatService
{
    /** @var string Path to iptables binary */
    private string $iptables = '/sbin/iptables';

    /**
     * Apply NAT configuration.
     *
     * @param array $config NAT configuration
     * @return bool True if successful
     */
    public function applyConfig(array $config): bool
    {
        $success = true;

        // Enable IP forwarding
        $this->enableForwarding();

        // Apply masquerade rules
        if (isset($config['masquerade'])) {
            foreach ($config['masquerade'] as $rule) {
                $success = $success && $this->addMasquerade($rule);
            }
        }

        // Apply DNAT rules (port forwarding)
        if (isset($config['dnat'])) {
            foreach ($config['dnat'] as $rule) {
                $success = $success && $this->addDnat($rule);
            }
        }

        // Apply SNAT rules
        if (isset($config['snat'])) {
            foreach ($config['snat'] as $rule) {
                $success = $success && $this->addSnat($rule);
            }
        }

        return $success;
    }

    /**
     * Add a masquerade rule.
     *
     * @param array $rule Rule definition with 'interface' and optional 'source'
     * @return bool True if successful
     */
    public function addMasquerade(array $rule): bool
    {
        $cmd = "{$this->iptables} -t nat -A POSTROUTING";

        if (isset($rule['source'])) {
            $cmd .= ' -s ' . escapeshellarg($rule['source']);
        }

        $cmd .= ' -o ' . escapeshellarg($rule['interface']);
        $cmd .= ' -j MASQUERADE';

        exec($cmd . ' 2>&1', $output, $returnCode);

        // Also add FORWARD rule to allow the traffic
        if ($returnCode === 0 && isset($rule['source'])) {
            $fwdCmd = "{$this->iptables} -A FORWARD -s " . escapeshellarg($rule['source']);
            $fwdCmd .= ' -o ' . escapeshellarg($rule['interface']) . ' -j ACCEPT';
            exec($fwdCmd . ' 2>&1');
        }

        return $returnCode === 0;
    }

    /**
     * Add a DNAT (port forwarding) rule.
     *
     * @param array $rule Rule definition
     * @return bool True if successful
     */
    public function addDnat(array $rule): bool
    {
        // PREROUTING rule for DNAT
        $cmd = "{$this->iptables} -t nat -A PREROUTING";

        if (isset($rule['interface'])) {
            $cmd .= ' -i ' . escapeshellarg($rule['interface']);
        }

        if (isset($rule['protocol'])) {
            $cmd .= ' -p ' . escapeshellarg($rule['protocol']);
        }

        if (isset($rule['port'])) {
            $cmd .= ' --dport ' . escapeshellarg($rule['port']);
        }

        $destination = $rule['to_address'];
        if (isset($rule['to_port'])) {
            $destination .= ':' . $rule['to_port'];
        }

        $cmd .= ' -j DNAT --to-destination ' . escapeshellarg($destination);

        exec($cmd . ' 2>&1', $output, $returnCode);

        // Add FORWARD rule to allow the forwarded traffic
        if ($returnCode === 0) {
            $fwdCmd = "{$this->iptables} -A FORWARD";
            if (isset($rule['protocol'])) {
                $fwdCmd .= ' -p ' . escapeshellarg($rule['protocol']);
            }
            $fwdCmd .= ' -d ' . escapeshellarg($rule['to_address']);
            if (isset($rule['to_port'])) {
                $fwdCmd .= ' --dport ' . escapeshellarg($rule['to_port']);
            }
            $fwdCmd .= ' -j ACCEPT';
            exec($fwdCmd . ' 2>&1');
        }

        return $returnCode === 0;
    }

    /**
     * Add a SNAT rule.
     *
     * @param array $rule Rule definition
     * @return bool True if successful
     */
    public function addSnat(array $rule): bool
    {
        $cmd = "{$this->iptables} -t nat -A POSTROUTING";

        if (isset($rule['source'])) {
            $cmd .= ' -s ' . escapeshellarg($rule['source']);
        }

        if (isset($rule['destination'])) {
            $cmd .= ' -d ' . escapeshellarg($rule['destination']);
        }

        if (isset($rule['interface'])) {
            $cmd .= ' -o ' . escapeshellarg($rule['interface']);
        }

        $cmd .= ' -j SNAT --to-source ' . escapeshellarg($rule['to_address']);

        exec($cmd . ' 2>&1', $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * Enable IP forwarding for IPv4.
     *
     * @return bool True if successful
     */
    public function enableForwarding(): bool
    {
        $result = file_put_contents('/proc/sys/net/ipv4/ip_forward', '1');
        return $result !== false;
    }

    /**
     * Disable IP forwarding.
     *
     * @return bool True if successful
     */
    public function disableForwarding(): bool
    {
        $result = file_put_contents('/proc/sys/net/ipv4/ip_forward', '0');
        return $result !== false;
    }

    /**
     * Check if IP forwarding is enabled.
     *
     * @return bool True if enabled
     */
    public function isForwardingEnabled(): bool
    {
        $value = trim(file_get_contents('/proc/sys/net/ipv4/ip_forward'));
        return $value === '1';
    }

    /**
     * Get current NAT rules.
     *
     * @return array Current NAT rules
     */
    public function listRules(): array
    {
        $output = [];
        exec("{$this->iptables} -t nat -L -n -v --line-numbers 2>&1", $output);
        return $output;
    }

    /**
     * Flush NAT rules.
     *
     * @return void
     */
    public function flush(): void
    {
        exec("{$this->iptables} -t nat -F 2>&1");
        exec("{$this->iptables} -t nat -X 2>&1");
    }

    /**
     * Delete a specific DNAT rule.
     *
     * @param array $rule Rule to delete
     * @return bool True if successful
     */
    public function deleteDnat(array $rule): bool
    {
        $cmd = "{$this->iptables} -t nat -D PREROUTING";

        if (isset($rule['interface'])) {
            $cmd .= ' -i ' . escapeshellarg($rule['interface']);
        }

        if (isset($rule['protocol'])) {
            $cmd .= ' -p ' . escapeshellarg($rule['protocol']);
        }

        if (isset($rule['port'])) {
            $cmd .= ' --dport ' . escapeshellarg($rule['port']);
        }

        $destination = $rule['to_address'];
        if (isset($rule['to_port'])) {
            $destination .= ':' . $rule['to_port'];
        }

        $cmd .= ' -j DNAT --to-destination ' . escapeshellarg($destination);

        exec($cmd . ' 2>&1', $output, $returnCode);
        return $returnCode === 0;
    }
}
