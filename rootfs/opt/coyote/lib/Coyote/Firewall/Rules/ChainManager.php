<?php

namespace Coyote\Firewall\Rules;

/**
 * Manages custom iptables chains.
 *
 * Provides functionality to create, delete, and manage custom chains
 * for organizing firewall rules.
 */
class ChainManager
{
    /** @var string Path to iptables binary */
    private string $iptables = '/sbin/iptables';

    /** @var string Path to ip6tables binary */
    private string $ip6tables = '/sbin/ip6tables';

    /** @var array List of managed chains */
    private array $chains = [];

    /**
     * Create a new custom chain.
     *
     * @param string $name Chain name
     * @param string $table Table name (filter, nat, mangle)
     * @param bool $ipv6 Also create for IPv6
     * @return bool True if successful
     */
    public function createChain(string $name, string $table = 'filter', bool $ipv6 = true): bool
    {
        $cmd = $this->iptables;
        if ($table !== 'filter') {
            $cmd .= ' -t ' . escapeshellarg($table);
        }
        $cmd .= ' -N ' . escapeshellarg($name);

        exec($cmd . ' 2>&1', $output, $returnCode);

        if ($returnCode === 0) {
            $this->chains[$name] = [
                'table' => $table,
                'rules' => [],
            ];

            if ($ipv6) {
                $cmd6 = $this->ip6tables;
                if ($table !== 'filter') {
                    $cmd6 .= ' -t ' . escapeshellarg($table);
                }
                $cmd6 .= ' -N ' . escapeshellarg($name);
                exec($cmd6 . ' 2>&1');
            }
        }

        return $returnCode === 0;
    }

    /**
     * Delete a custom chain.
     *
     * @param string $name Chain name
     * @param string $table Table name
     * @param bool $ipv6 Also delete for IPv6
     * @return bool True if successful
     */
    public function deleteChain(string $name, string $table = 'filter', bool $ipv6 = true): bool
    {
        // First flush the chain
        $this->flushChain($name, $table, $ipv6);

        // Remove any references to the chain
        $this->removeChainReferences($name, $table, $ipv6);

        // Delete the chain
        $cmd = $this->iptables;
        if ($table !== 'filter') {
            $cmd .= ' -t ' . escapeshellarg($table);
        }
        $cmd .= ' -X ' . escapeshellarg($name);

        exec($cmd . ' 2>&1', $output, $returnCode);

        if ($returnCode === 0 && $ipv6) {
            $cmd6 = $this->ip6tables;
            if ($table !== 'filter') {
                $cmd6 .= ' -t ' . escapeshellarg($table);
            }
            $cmd6 .= ' -X ' . escapeshellarg($name);
            exec($cmd6 . ' 2>&1');
        }

        unset($this->chains[$name]);

        return $returnCode === 0;
    }

    /**
     * Flush all rules in a chain.
     *
     * @param string $name Chain name
     * @param string $table Table name
     * @param bool $ipv6 Also flush for IPv6
     * @return bool True if successful
     */
    public function flushChain(string $name, string $table = 'filter', bool $ipv6 = true): bool
    {
        $cmd = $this->iptables;
        if ($table !== 'filter') {
            $cmd .= ' -t ' . escapeshellarg($table);
        }
        $cmd .= ' -F ' . escapeshellarg($name);

        exec($cmd . ' 2>&1', $output, $returnCode);

        if ($returnCode === 0 && $ipv6) {
            $cmd6 = $this->ip6tables;
            if ($table !== 'filter') {
                $cmd6 .= ' -t ' . escapeshellarg($table);
            }
            $cmd6 .= ' -F ' . escapeshellarg($name);
            exec($cmd6 . ' 2>&1');
        }

        if (isset($this->chains[$name])) {
            $this->chains[$name]['rules'] = [];
        }

        return $returnCode === 0;
    }

    /**
     * Remove all references to a chain from built-in chains.
     *
     * @param string $name Chain name
     * @param string $table Table name
     * @param bool $ipv6 Also remove for IPv6
     * @return void
     */
    private function removeChainReferences(string $name, string $table, bool $ipv6): void
    {
        $builtinChains = ['INPUT', 'OUTPUT', 'FORWARD'];
        if ($table === 'nat') {
            $builtinChains = ['PREROUTING', 'POSTROUTING', 'OUTPUT'];
        }

        foreach ($builtinChains as $builtin) {
            // Get rules jumping to this chain and delete them
            $cmd = "{$this->iptables} -t {$table} -S {$builtin} 2>&1";
            $output = [];
            exec($cmd, $output);

            foreach ($output as $rule) {
                if (strpos($rule, "-j {$name}") !== false) {
                    $deleteCmd = str_replace('-A', '-D', $rule);
                    exec("{$this->iptables} -t {$table} {$deleteCmd} 2>&1");
                }
            }

            if ($ipv6) {
                $output6 = [];
                exec("{$this->ip6tables} -t {$table} -S {$builtin} 2>&1", $output6);
                foreach ($output6 as $rule) {
                    if (strpos($rule, "-j {$name}") !== false) {
                        $deleteCmd = str_replace('-A', '-D', $rule);
                        exec("{$this->ip6tables} -t {$table} {$deleteCmd} 2>&1");
                    }
                }
            }
        }
    }

    /**
     * Add a jump from a built-in chain to a custom chain.
     *
     * @param string $fromChain Source chain (INPUT, FORWARD, etc.)
     * @param string $toChain Target chain
     * @param array $match Optional match criteria
     * @param string $table Table name
     * @return bool True if successful
     */
    public function addJump(
        string $fromChain,
        string $toChain,
        array $match = [],
        string $table = 'filter'
    ): bool {
        $cmd = $this->iptables;
        if ($table !== 'filter') {
            $cmd .= ' -t ' . escapeshellarg($table);
        }
        $cmd .= ' -A ' . escapeshellarg($fromChain);

        // Add match criteria
        foreach ($match as $key => $value) {
            switch ($key) {
                case 'source':
                    $cmd .= ' -s ' . escapeshellarg($value);
                    break;
                case 'destination':
                    $cmd .= ' -d ' . escapeshellarg($value);
                    break;
                case 'interface':
                    $cmd .= ' -i ' . escapeshellarg($value);
                    break;
                case 'protocol':
                    $cmd .= ' -p ' . escapeshellarg($value);
                    break;
            }
        }

        $cmd .= ' -j ' . escapeshellarg($toChain);

        exec($cmd . ' 2>&1', $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * Check if a chain exists.
     *
     * @param string $name Chain name
     * @param string $table Table name
     * @return bool True if chain exists
     */
    public function chainExists(string $name, string $table = 'filter'): bool
    {
        $cmd = $this->iptables;
        if ($table !== 'filter') {
            $cmd .= ' -t ' . escapeshellarg($table);
        }
        $cmd .= ' -L ' . escapeshellarg($name) . ' -n 2>&1';

        exec($cmd, $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * List all chains in a table.
     *
     * @param string $table Table name
     * @return array List of chain names
     */
    public function listChains(string $table = 'filter'): array
    {
        $cmd = $this->iptables;
        if ($table !== 'filter') {
            $cmd .= ' -t ' . escapeshellarg($table);
        }
        $cmd .= ' -L -n 2>&1';

        $output = [];
        exec($cmd, $output);

        $chains = [];
        foreach ($output as $line) {
            if (preg_match('/^Chain\s+(\S+)/', $line, $matches)) {
                $chains[] = $matches[1];
            }
        }

        return $chains;
    }

    /**
     * Get rules in a chain.
     *
     * @param string $name Chain name
     * @param string $table Table name
     * @return array List of rules
     */
    public function getChainRules(string $name, string $table = 'filter'): array
    {
        $cmd = $this->iptables;
        if ($table !== 'filter') {
            $cmd .= ' -t ' . escapeshellarg($table);
        }
        $cmd .= ' -S ' . escapeshellarg($name) . ' 2>&1';

        $output = [];
        exec($cmd, $output);

        return $output;
    }
}
