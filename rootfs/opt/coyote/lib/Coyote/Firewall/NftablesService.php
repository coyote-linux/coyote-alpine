<?php

namespace Coyote\Firewall;

use Coyote\Util\Logger;

/**
 * Core interface to nftables command.
 *
 * Handles atomic ruleset loading, JSON queries, validation, and basic operations.
 * This replaces the legacy iptables approach with nftables for Coyote Linux 4.
 */
class NftablesService
{
    /** @var string Path to nft binary */
    private string $nft = '/usr/sbin/nft';

    /** @var string Directory for storing ruleset files */
    private string $rulesetDir = '/var/lib/coyote/firewall';

    /** @var Logger */
    private Logger $logger;

    /** @var bool Whether to use doas for privilege escalation */
    private bool $useDoas = false;

    private string $lastError = '';

    /**
     * Create a new NftablesService instance.
     */
    public function __construct()
    {
        $this->logger = new Logger('coyote-nftables');

        // Use doas if not running as root
        if (posix_getuid() !== 0) {
            $this->useDoas = true;
        }
    }

    /**
     * Get the nft command with optional doas prefix.
     *
     * @return string Command string
     */
    private function getNftCommand(): string
    {
        if ($this->useDoas) {
            return 'doas ' . $this->nft;
        }
        return $this->nft;
    }

    /**
     * Load a complete ruleset file atomically.
     *
     * @param string $path Path to the .nft ruleset file
     * @return bool True if successful
     */
    public function loadRuleset(string $path): bool
    {
        $this->lastError = '';

        if (!file_exists($path)) {
            $this->logger->error("Ruleset file not found: {$path}");
            $this->lastError = "Ruleset file not found: {$path}";
            return false;
        }

        $cmd = sprintf('%s -f %s 2>&1', $this->getNftCommand(), escapeshellarg($path));
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            $this->lastError = trim(implode("\n", $output));
            $this->logger->error("Failed to load ruleset: " . $this->lastError);
            return false;
        }

        $this->logger->info("Ruleset loaded successfully from: {$path}");
        return true;
    }

    /**
     * Validate a ruleset file without applying it.
     *
     * @param string $path Path to the .nft ruleset file
     * @return bool True if valid
     */
    public function validateRuleset(string $path): bool
    {
        $this->lastError = '';

        if (!file_exists($path)) {
            $this->lastError = "Ruleset file not found: {$path}";
            return false;
        }

        // Use -c flag to check syntax without applying
        $cmd = sprintf('%s -c -f %s 2>&1', $this->getNftCommand(), escapeshellarg($path));
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            $this->lastError = trim(implode("\n", $output));
            $this->logger->warning("Ruleset validation failed: " . $this->lastError);
            return false;
        }

        return true;
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }

    /**
     * Flush all rules (emergency/reset).
     *
     * @return bool True if successful
     */
    public function flush(): bool
    {
        $cmd = sprintf('%s flush ruleset 2>&1', $this->getNftCommand());
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            $this->logger->error("Failed to flush ruleset: " . implode("\n", $output));
            return false;
        }

        $this->logger->info("Ruleset flushed");
        return true;
    }

    /**
     * Get current ruleset as JSON.
     *
     * @return array Ruleset as associative array, empty on failure
     */
    public function getRulesetJson(): array
    {
        $cmd = sprintf('%s -j list ruleset 2>&1', $this->getNftCommand());
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            $this->logger->error("Failed to get ruleset JSON");
            return [];
        }

        $json = implode("\n", $output);
        $data = json_decode($json, true);

        return $data ?? [];
    }

    /**
     * Get current ruleset as nft format text.
     *
     * @return string Ruleset text, empty on failure
     */
    public function getRulesetText(): string
    {
        $cmd = sprintf('%s list ruleset 2>&1', $this->getNftCommand());
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            return '';
        }

        return implode("\n", $output);
    }

    /**
     * Check if nftables is available and working.
     *
     * @return bool True if available
     */
    public function isAvailable(): bool
    {
        if (!file_exists($this->nft)) {
            return false;
        }

        $cmd = sprintf('%s -v 2>&1', $this->getNftCommand());
        exec($cmd, $output, $returnCode);

        return $returnCode === 0;
    }

    /**
     * Get nftables version.
     *
     * @return string Version string or empty on failure
     */
    public function getVersion(): string
    {
        $cmd = sprintf('%s -v 2>&1', $this->getNftCommand());
        exec($cmd, $output, $returnCode);

        if ($returnCode === 0 && !empty($output)) {
            return trim($output[0]);
        }

        return '';
    }

    /**
     * Save current ruleset to file.
     *
     * @param string $path File path to save to
     * @return bool True if successful
     */
    public function saveRuleset(string $path): bool
    {
        $ruleset = $this->getRulesetText();

        if (empty($ruleset)) {
            // Empty ruleset is valid (no rules)
            $ruleset = "# Empty ruleset\n";
        }

        $header = sprintf(
            "#!/usr/sbin/nft -f\n# Coyote Linux Firewall - Saved ruleset\n# Date: %s\n\n",
            date('Y-m-d H:i:s')
        );

        $result = file_put_contents($path, $header . $ruleset);

        if ($result === false) {
            $this->logger->error("Failed to save ruleset to: {$path}");
            return false;
        }

        return true;
    }

    /**
     * Get counters/statistics for monitoring.
     *
     * @return array Statistics array
     */
    public function getCounters(): array
    {
        $stats = [
            'tables' => 0,
            'chains' => 0,
            'rules' => 0,
            'sets' => 0,
        ];

        $ruleset = $this->getRulesetJson();

        if (empty($ruleset) || !isset($ruleset['nftables'])) {
            return $stats;
        }

        foreach ($ruleset['nftables'] as $item) {
            if (isset($item['table'])) {
                $stats['tables']++;
            } elseif (isset($item['chain'])) {
                $stats['chains']++;
            } elseif (isset($item['rule'])) {
                $stats['rules']++;
            } elseif (isset($item['set'])) {
                $stats['sets']++;
            }
        }

        return $stats;
    }

    /**
     * Get rule counts by chain.
     *
     * @return array Chain names mapped to rule counts
     */
    public function getRuleCountsByChain(): array
    {
        $counts = [];
        $ruleset = $this->getRulesetJson();

        if (empty($ruleset) || !isset($ruleset['nftables'])) {
            return $counts;
        }

        foreach ($ruleset['nftables'] as $item) {
            if (isset($item['rule'])) {
                $chain = $item['rule']['chain'] ?? 'unknown';
                $table = $item['rule']['table'] ?? 'unknown';
                $key = "{$table}/{$chain}";

                if (!isset($counts[$key])) {
                    $counts[$key] = 0;
                }
                $counts[$key]++;
            }
        }

        return $counts;
    }

    /**
     * List all tables.
     *
     * @return array Table information
     */
    public function listTables(): array
    {
        $tables = [];
        $ruleset = $this->getRulesetJson();

        if (empty($ruleset) || !isset($ruleset['nftables'])) {
            return $tables;
        }

        foreach ($ruleset['nftables'] as $item) {
            if (isset($item['table'])) {
                $tables[] = [
                    'family' => $item['table']['family'],
                    'name' => $item['table']['name'],
                ];
            }
        }

        return $tables;
    }

    /**
     * List all chains in a table.
     *
     * @param string $family Address family (inet, ip, ip6)
     * @param string $table Table name
     * @return array Chain information
     */
    public function listChains(string $family, string $table): array
    {
        $chains = [];
        $ruleset = $this->getRulesetJson();

        if (empty($ruleset) || !isset($ruleset['nftables'])) {
            return $chains;
        }

        foreach ($ruleset['nftables'] as $item) {
            if (isset($item['chain'])) {
                $chain = $item['chain'];
                if ($chain['family'] === $family && $chain['table'] === $table) {
                    $chains[] = [
                        'name' => $chain['name'],
                        'type' => $chain['type'] ?? null,
                        'hook' => $chain['hook'] ?? null,
                        'priority' => $chain['prio'] ?? null,
                        'policy' => $chain['policy'] ?? null,
                    ];
                }
            }
        }

        return $chains;
    }

    /**
     * List elements in a set.
     *
     * @param string $family Address family
     * @param string $table Table name
     * @param string $set Set name
     * @return array Set elements
     */
    public function listSetElements(string $family, string $table, string $set): array
    {
        $cmd = sprintf(
            '%s -j list set %s %s %s 2>&1',
            $this->getNftCommand(),
            escapeshellarg($family),
            escapeshellarg($table),
            escapeshellarg($set)
        );

        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            return [];
        }

        $json = implode("\n", $output);
        $data = json_decode($json, true);

        if (!isset($data['nftables'])) {
            return [];
        }

        foreach ($data['nftables'] as $item) {
            if (isset($item['set']['elem'])) {
                return $item['set']['elem'];
            }
        }

        return [];
    }

    /**
     * Add an element to a set dynamically.
     *
     * @param string $family Address family
     * @param string $table Table name
     * @param string $set Set name
     * @param string $element Element to add
     * @return bool True if successful
     */
    public function addSetElement(string $family, string $table, string $set, string $element): bool
    {
        $cmd = sprintf(
            '%s add element %s %s %s { %s } 2>&1',
            $this->getNftCommand(),
            escapeshellarg($family),
            escapeshellarg($table),
            escapeshellarg($set),
            escapeshellarg($element)
        );

        exec($cmd, $output, $returnCode);

        return $returnCode === 0;
    }

    /**
     * Delete an element from a set dynamically.
     *
     * @param string $family Address family
     * @param string $table Table name
     * @param string $set Set name
     * @param string $element Element to delete
     * @return bool True if successful
     */
    public function deleteSetElement(string $family, string $table, string $set, string $element): bool
    {
        $cmd = sprintf(
            '%s delete element %s %s %s { %s } 2>&1',
            $this->getNftCommand(),
            escapeshellarg($family),
            escapeshellarg($table),
            escapeshellarg($set),
            escapeshellarg($element)
        );

        exec($cmd, $output, $returnCode);

        return $returnCode === 0;
    }

    /**
     * Get active connection count from conntrack.
     *
     * @return int Connection count
     */
    public function getConnectionCount(): int
    {
        $conntrackFile = '/proc/sys/net/netfilter/nf_conntrack_count';

        if (file_exists($conntrackFile)) {
            return (int) trim(file_get_contents($conntrackFile));
        }

        return 0;
    }

    /**
     * Get conntrack max connections.
     *
     * @return int Max connections
     */
    public function getConnectionMax(): int
    {
        $conntrackFile = '/proc/sys/net/netfilter/nf_conntrack_max';

        if (file_exists($conntrackFile)) {
            return (int) trim(file_get_contents($conntrackFile));
        }

        return 0;
    }

    /**
     * Get the ruleset directory path.
     *
     * @return string Directory path
     */
    public function getRulesetDir(): string
    {
        return $this->rulesetDir;
    }

    /**
     * Ensure the ruleset directory exists.
     *
     * @return bool True if directory exists or was created
     */
    public function ensureRulesetDir(): bool
    {
        if (is_dir($this->rulesetDir)) {
            return true;
        }

        return mkdir($this->rulesetDir, 0750, true);
    }
}
