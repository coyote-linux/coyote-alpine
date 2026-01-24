<?php

namespace Coyote\Firewall;

use Coyote\Firewall\Rules\RuleBuilder;

/**
 * Service for managing iptables firewall rules.
 *
 * Provides methods to add, remove, and list firewall rules
 * for both IPv4 (iptables) and IPv6 (ip6tables).
 */
class IptablesService
{
    /** @var string Path to iptables binary */
    private string $iptables = '/sbin/iptables';

    /** @var string Path to ip6tables binary */
    private string $ip6tables = '/sbin/ip6tables';

    /** @var bool Enable IPv6 support */
    private bool $ipv6Enabled = true;

    /**
     * Apply firewall rules from configuration.
     *
     * @param array $rules Firewall rules
     * @return bool True if successful
     */
    public function applyRules(array $rules): bool
    {
        $this->flush();
        $this->setDefaultPolicies();

        foreach ($rules as $rule) {
            if (!$this->addRule($rule)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Add a single firewall rule.
     *
     * @param array $rule Rule definition
     * @return bool True if successful
     */
    public function addRule(array $rule): bool
    {
        $cmd = $this->buildRuleCommand($rule);
        exec($cmd . ' 2>&1', $output, $returnCode);

        // Apply to IPv6 if enabled and appropriate
        if ($this->ipv6Enabled && $this->isIpv6Compatible($rule)) {
            $cmd6 = $this->buildRuleCommand($rule, true);
            exec($cmd6 . ' 2>&1', $output6, $returnCode6);
        }

        return $returnCode === 0;
    }

    /**
     * Delete a firewall rule.
     *
     * @param array $rule Rule definition
     * @return bool True if successful
     */
    public function deleteRule(array $rule): bool
    {
        $cmd = $this->buildRuleCommand($rule, false, '-D');
        exec($cmd . ' 2>&1', $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * Build iptables command from rule definition.
     *
     * @param array $rule Rule definition
     * @param bool $ipv6 Build for IPv6
     * @param string $action Rule action (-A, -D, -I)
     * @return string iptables command
     */
    private function buildRuleCommand(array $rule, bool $ipv6 = false, string $action = '-A'): string
    {
        $binary = $ipv6 ? $this->ip6tables : $this->iptables;
        $cmd = $binary . ' ' . $action;

        // Chain
        $chain = strtoupper($rule['chain'] ?? 'INPUT');
        $cmd .= ' ' . $chain;

        // Protocol
        if (isset($rule['protocol'])) {
            $cmd .= ' -p ' . escapeshellarg($rule['protocol']);
        }

        // Source
        if (isset($rule['source'])) {
            $cmd .= ' -s ' . escapeshellarg($rule['source']);
        }

        // Destination
        if (isset($rule['destination'])) {
            $cmd .= ' -d ' . escapeshellarg($rule['destination']);
        }

        // Input interface
        if (isset($rule['interface'])) {
            if ($chain === 'INPUT' || $chain === 'FORWARD') {
                $cmd .= ' -i ' . escapeshellarg($rule['interface']);
            } else {
                $cmd .= ' -o ' . escapeshellarg($rule['interface']);
            }
        }

        // Output interface
        if (isset($rule['out_interface'])) {
            $cmd .= ' -o ' . escapeshellarg($rule['out_interface']);
        }

        // State match
        if (isset($rule['match']) && $rule['match'] === 'state') {
            $cmd .= ' -m state --state ' . escapeshellarg($rule['state']);
        }

        // Port (destination)
        if (isset($rule['port'])) {
            $cmd .= ' --dport ' . escapeshellarg($rule['port']);
        }

        // Source port
        if (isset($rule['sport'])) {
            $cmd .= ' --sport ' . escapeshellarg($rule['sport']);
        }

        // ICMP type
        if (isset($rule['icmp_type'])) {
            $cmd .= ' --icmp-type ' . escapeshellarg($rule['icmp_type']);
        }

        // Log prefix
        if (isset($rule['log_prefix'])) {
            $cmd .= ' -j LOG --log-prefix ' . escapeshellarg($rule['log_prefix']);
            return $cmd;
        }

        // Action
        $target = strtoupper($rule['action'] ?? 'DROP');
        $cmd .= ' -j ' . $target;

        // Reject with
        if ($target === 'REJECT' && isset($rule['reject_with'])) {
            $cmd .= ' --reject-with ' . escapeshellarg($rule['reject_with']);
        }

        return $cmd;
    }

    /**
     * Check if a rule can be applied to IPv6.
     *
     * @param array $rule Rule definition
     * @return bool True if IPv6 compatible
     */
    private function isIpv6Compatible(array $rule): bool
    {
        // Rules with IPv4-specific addresses are not IPv6 compatible
        if (isset($rule['source']) && strpos($rule['source'], '.') !== false) {
            return false;
        }
        if (isset($rule['destination']) && strpos($rule['destination'], '.') !== false) {
            return false;
        }
        return true;
    }

    /**
     * Flush all rules.
     *
     * @return void
     */
    public function flush(): void
    {
        // Flush filter table
        exec("{$this->iptables} -F 2>&1");
        exec("{$this->iptables} -X 2>&1");

        // Flush NAT table
        exec("{$this->iptables} -t nat -F 2>&1");
        exec("{$this->iptables} -t nat -X 2>&1");

        // Flush mangle table
        exec("{$this->iptables} -t mangle -F 2>&1");
        exec("{$this->iptables} -t mangle -X 2>&1");

        if ($this->ipv6Enabled) {
            exec("{$this->ip6tables} -F 2>&1");
            exec("{$this->ip6tables} -X 2>&1");
        }
    }

    /**
     * Set default chain policies.
     *
     * @param string $input Input policy
     * @param string $forward Forward policy
     * @param string $output Output policy
     * @return void
     */
    public function setDefaultPolicies(
        string $input = 'DROP',
        string $forward = 'DROP',
        string $output = 'ACCEPT'
    ): void {
        exec("{$this->iptables} -P INPUT {$input} 2>&1");
        exec("{$this->iptables} -P FORWARD {$forward} 2>&1");
        exec("{$this->iptables} -P OUTPUT {$output} 2>&1");

        if ($this->ipv6Enabled) {
            exec("{$this->ip6tables} -P INPUT {$input} 2>&1");
            exec("{$this->ip6tables} -P FORWARD {$forward} 2>&1");
            exec("{$this->ip6tables} -P OUTPUT {$output} 2>&1");
        }
    }

    /**
     * Save current rules to file.
     *
     * @param string $path File path
     * @return bool True if successful
     */
    public function saveRules(string $path): bool
    {
        exec("{$this->iptables}-save > " . escapeshellarg($path) . ' 2>&1', $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * Restore rules from file.
     *
     * @param string $path File path
     * @return bool True if successful
     */
    public function restoreRules(string $path): bool
    {
        if (!file_exists($path)) {
            return false;
        }
        exec("{$this->iptables}-restore < " . escapeshellarg($path) . ' 2>&1', $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * Get current rules as array.
     *
     * @return array Current rules
     */
    public function listRules(): array
    {
        $output = [];
        exec("{$this->iptables} -L -n -v --line-numbers 2>&1", $output);
        return $output;
    }

    /**
     * Get rule count by chain.
     *
     * @return array Rule counts per chain
     */
    public function getRuleCounts(): array
    {
        $counts = [];
        foreach (['INPUT', 'FORWARD', 'OUTPUT'] as $chain) {
            $output = [];
            exec("{$this->iptables} -L {$chain} --line-numbers 2>&1", $output);
            $counts[$chain] = max(0, count($output) - 2); // Subtract header lines
        }
        return $counts;
    }

    /**
     * Enable or disable IPv6 support.
     *
     * @param bool $enabled Whether to enable IPv6
     * @return void
     */
    public function setIpv6Enabled(bool $enabled): void
    {
        $this->ipv6Enabled = $enabled;
    }
}
