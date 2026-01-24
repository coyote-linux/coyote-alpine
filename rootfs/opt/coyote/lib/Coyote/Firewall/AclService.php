<?php

namespace Coyote\Firewall;

/**
 * Access Control List (ACL) service.
 *
 * Manages named ACLs that can be referenced by firewall rules.
 * ACLs group IP addresses, networks, or ports for easier rule management.
 */
class AclService
{
    /** @var array Loaded ACL definitions */
    private array $acls = [];

    /** @var string Path to iptables */
    private string $iptables = '/sbin/iptables';

    /**
     * Apply ACL configuration.
     *
     * @param array $config ACL configuration
     * @return bool True if successful
     */
    public function applyConfig(array $config): bool
    {
        $this->acls = $config;

        foreach ($config as $name => $acl) {
            $type = $acl['type'] ?? 'ipset';

            switch ($type) {
                case 'ipset':
                    $this->createIpset($name, $acl);
                    break;
                case 'chain':
                    $this->createChain($name, $acl);
                    break;
            }
        }

        return true;
    }

    /**
     * Create an ipset for an ACL.
     *
     * @param string $name ACL name
     * @param array $acl ACL definition
     * @return bool True if successful
     */
    private function createIpset(string $name, array $acl): bool
    {
        $setName = 'coyote_' . $name;
        $setType = $this->getIpsetType($acl);

        // Destroy existing set if present
        exec("ipset destroy {$setName} 2>/dev/null");

        // Create the set
        $cmd = "ipset create {$setName} {$setType}";
        exec($cmd . ' 2>&1', $output, $returnCode);

        if ($returnCode !== 0) {
            return false;
        }

        // Add entries
        foreach ($acl['entries'] ?? [] as $entry) {
            exec("ipset add {$setName} " . escapeshellarg($entry) . ' 2>&1');
        }

        return true;
    }

    /**
     * Determine the ipset type based on ACL entries.
     *
     * @param array $acl ACL definition
     * @return string ipset type
     */
    private function getIpsetType(array $acl): string
    {
        $entryType = $acl['entry_type'] ?? 'ip';

        switch ($entryType) {
            case 'ip':
                return 'hash:ip';
            case 'net':
                return 'hash:net';
            case 'port':
                return 'bitmap:port range 1-65535';
            case 'ip,port':
                return 'hash:ip,port';
            default:
                return 'hash:ip';
        }
    }

    /**
     * Create an iptables chain for an ACL.
     *
     * @param string $name ACL name
     * @param array $acl ACL definition
     * @return bool True if successful
     */
    private function createChain(string $name, array $acl): bool
    {
        $chainName = 'ACL_' . strtoupper($name);

        // Delete existing chain
        exec("{$this->iptables} -F {$chainName} 2>/dev/null");
        exec("{$this->iptables} -X {$chainName} 2>/dev/null");

        // Create chain
        exec("{$this->iptables} -N {$chainName} 2>&1", $output, $returnCode);

        if ($returnCode !== 0) {
            return false;
        }

        // Add rules for each entry
        $action = $acl['action'] ?? 'ACCEPT';
        foreach ($acl['entries'] ?? [] as $entry) {
            $cmd = "{$this->iptables} -A {$chainName}";

            if (isset($entry['source'])) {
                $cmd .= ' -s ' . escapeshellarg($entry['source']);
            }
            if (isset($entry['destination'])) {
                $cmd .= ' -d ' . escapeshellarg($entry['destination']);
            }
            if (isset($entry['protocol'])) {
                $cmd .= ' -p ' . escapeshellarg($entry['protocol']);
            }
            if (isset($entry['port'])) {
                $cmd .= ' --dport ' . escapeshellarg($entry['port']);
            }

            $cmd .= ' -j ' . $action;
            exec($cmd . ' 2>&1');
        }

        // Add default return at end
        exec("{$this->iptables} -A {$chainName} -j RETURN 2>&1");

        return true;
    }

    /**
     * Add an entry to an ACL.
     *
     * @param string $name ACL name
     * @param string $entry Entry to add
     * @return bool True if successful
     */
    public function addEntry(string $name, string $entry): bool
    {
        $setName = 'coyote_' . $name;
        exec("ipset add {$setName} " . escapeshellarg($entry) . ' 2>&1', $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * Remove an entry from an ACL.
     *
     * @param string $name ACL name
     * @param string $entry Entry to remove
     * @return bool True if successful
     */
    public function removeEntry(string $name, string $entry): bool
    {
        $setName = 'coyote_' . $name;
        exec("ipset del {$setName} " . escapeshellarg($entry) . ' 2>&1', $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * List entries in an ACL.
     *
     * @param string $name ACL name
     * @return array List of entries
     */
    public function listEntries(string $name): array
    {
        $setName = 'coyote_' . $name;
        exec("ipset list {$setName} 2>&1", $output, $returnCode);

        if ($returnCode !== 0) {
            return [];
        }

        // Parse ipset output to extract entries
        $entries = [];
        $inMembers = false;

        foreach ($output as $line) {
            if (strpos($line, 'Members:') === 0) {
                $inMembers = true;
                continue;
            }
            if ($inMembers && !empty(trim($line))) {
                $entries[] = trim($line);
            }
        }

        return $entries;
    }

    /**
     * Check if an ACL exists.
     *
     * @param string $name ACL name
     * @return bool True if exists
     */
    public function exists(string $name): bool
    {
        $setName = 'coyote_' . $name;
        exec("ipset list {$setName} 2>&1", $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * Delete an ACL.
     *
     * @param string $name ACL name
     * @return bool True if successful
     */
    public function delete(string $name): bool
    {
        $setName = 'coyote_' . $name;
        exec("ipset destroy {$setName} 2>&1", $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * Get all defined ACLs.
     *
     * @return array ACL definitions
     */
    public function getAll(): array
    {
        return $this->acls;
    }
}
