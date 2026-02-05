<?php

namespace Coyote\Firewall;

use Coyote\Util\Logger;

/**
 * Manages nftables named sets.
 *
 * Provides high-level operations for creating, populating, and querying
 * nftables sets used for ACLs and dynamic firewall rules.
 */
class SetManager
{
    /** @var NftablesService */
    private NftablesService $nftables;

    /** @var Logger */
    private Logger $logger;

    /** @var string Default table family */
    private string $family = 'inet';

    /** @var string Default table name */
    private string $table = 'filter';

    /** @var array Set type mappings */
    private const SET_TYPES = [
        'ipv4' => 'ipv4_addr',
        'ipv6' => 'ipv6_addr',
        'port' => 'inet_service',
        'interface' => 'ifname',
        'mac' => 'ether_addr',
    ];

    /** @var array Standard set definitions */
    private const STANDARD_SETS = [
        'ssh_allowed' => [
            'type' => 'ipv4_addr',
            'flags' => ['interval'],
            'description' => 'Hosts allowed SSH access',
        ],
        'snmp_allowed' => [
            'type' => 'ipv4_addr',
            'flags' => ['interval'],
            'description' => 'Hosts allowed SNMP access',
        ],
        'blocked_hosts' => [
            'type' => 'ipv4_addr',
            'flags' => ['interval'],
            'description' => 'Blocked hosts (blacklist)',
        ],
        'trusted_hosts' => [
            'type' => 'ipv4_addr',
            'flags' => ['interval'],
            'description' => 'Trusted hosts (whitelist)',
        ],
        'admin_hosts' => [
            'type' => 'ipv4_addr',
            'flags' => ['interval'],
            'description' => 'Hosts with administrative access',
        ],
    ];

    /**
     * Prefix for address list sets.
     */
    private const ADDRESS_LIST_PREFIX = 'addrlist';

    /**
     * Create a new SetManager instance.
     *
     * @param NftablesService|null $nftables Optional NftablesService instance
     */
    public function __construct(?NftablesService $nftables = null)
    {
        $this->nftables = $nftables ?? new NftablesService();
        $this->logger = new Logger('set-manager');
    }

    /**
     * Get set definitions for RulesetBuilder.
     *
     * Returns an array of set configurations to be included in the ruleset.
     *
     * @param array $config Services and firewall configuration
     * @return array Set definitions keyed by name
     */
    public function buildSetDefinitions(array $config): array
    {
        $sets = [];
        $servicesConfig = $config['services'] ?? [];
        $firewallConfig = $config['firewall'] ?? [];

        // Address lists (IPv4/IPv6 split)
        $addressLists = $firewallConfig['address_lists'] ?? [];
        foreach ($this->normalizeAddressLists($addressLists) as $name => $list) {
            $normalizedName = $this->normalizeSetName($name);

            if (!empty($list['ipv4'])) {
                $sets[$this->buildAddressListSetName($normalizedName, 'v4')] = [
                    'type' => 'ipv4_addr',
                    'flags' => ['interval'],
                    'elements' => $this->normalizeAddresses($list['ipv4']),
                ];
            }

            if (!empty($list['ipv6'])) {
                $sets[$this->buildAddressListSetName($normalizedName, 'v6')] = [
                    'type' => 'ipv6_addr',
                    'flags' => ['interval'],
                    'elements' => $this->normalizeAddresses($list['ipv6']),
                ];
            }
        }

        // SSH allowed hosts
        $sshHosts = $servicesConfig['ssh']['allowed_hosts'] ?? [];
        $sets['ssh_allowed'] = [
            'type' => 'ipv4_addr',
            'flags' => ['interval'],
            'elements' => $this->normalizeAddresses($sshHosts),
        ];

        // SNMP allowed hosts
        $snmpHosts = $servicesConfig['snmp']['allowed_hosts'] ?? [];
        $sets['snmp_allowed'] = [
            'type' => 'ipv4_addr',
            'flags' => ['interval'],
            'elements' => $this->normalizeAddresses($snmpHosts),
        ];

        // Blocked hosts
        $blockedHosts = $firewallConfig['sets']['blocked_hosts'] ?? [];
        if (is_array($blockedHosts) && !isset($blockedHosts['elements'])) {
            // Simple array of addresses
            $sets['blocked_hosts'] = [
                'type' => 'ipv4_addr',
                'flags' => ['interval'],
                'elements' => $this->normalizeAddresses($blockedHosts),
            ];
        } elseif (isset($blockedHosts['elements'])) {
            // Full set definition
            $sets['blocked_hosts'] = [
                'type' => $blockedHosts['type'] ?? 'ipv4_addr',
                'flags' => $blockedHosts['flags'] ?? ['interval'],
                'elements' => $this->normalizeAddresses($blockedHosts['elements']),
            ];
        }

        // Trusted hosts
        $trustedHosts = $firewallConfig['sets']['trusted_hosts'] ?? [];
        if (!empty($trustedHosts)) {
            if (is_array($trustedHosts) && !isset($trustedHosts['elements'])) {
                $sets['trusted_hosts'] = [
                    'type' => 'ipv4_addr',
                    'flags' => ['interval'],
                    'elements' => $this->normalizeAddresses($trustedHosts),
                ];
            } elseif (isset($trustedHosts['elements'])) {
                $sets['trusted_hosts'] = [
                    'type' => $trustedHosts['type'] ?? 'ipv4_addr',
                    'flags' => $trustedHosts['flags'] ?? ['interval'],
                    'elements' => $this->normalizeAddresses($trustedHosts['elements']),
                ];
            }
        }

        // Admin hosts - merge from firewall.sets.admin_hosts and services.webadmin.allowed_hosts
        $adminHosts = [];

        // Get from firewall sets config
        $firewallAdminHosts = $firewallConfig['sets']['admin_hosts'] ?? [];
        if (is_array($firewallAdminHosts) && !isset($firewallAdminHosts['elements'])) {
            $adminHosts = array_merge($adminHosts, $firewallAdminHosts);
        } elseif (isset($firewallAdminHosts['elements'])) {
            $adminHosts = array_merge($adminHosts, $firewallAdminHosts['elements']);
        }

        // Get from services.webadmin.allowed_hosts (TUI configuration)
        $webadminAllowedHosts = $servicesConfig['webadmin']['allowed_hosts'] ?? [];
        if (!empty($webadminAllowedHosts)) {
            $adminHosts = array_merge($adminHosts, $webadminAllowedHosts);
        }

        // Remove duplicates and create the set
        $adminHosts = array_unique($adminHosts);
        if (!empty($adminHosts)) {
            $sets['admin_hosts'] = [
                'type' => 'ipv4_addr',
                'flags' => ['interval'],
                'elements' => $this->normalizeAddresses($adminHosts),
            ];
        }

        // User-defined sets
        $userSets = $firewallConfig['sets'] ?? [];
        foreach ($userSets as $name => $setConfig) {
            // Skip standard sets already handled
            if (in_array($name, ['blocked_hosts', 'trusted_hosts', 'admin_hosts'])) {
                continue;
            }

            if (is_array($setConfig) && isset($setConfig['type'])) {
                $sets[$name] = [
                    'type' => $this->resolveSetType($setConfig['type']),
                    'flags' => $setConfig['flags'] ?? ['interval'],
                    'elements' => $this->normalizeElements($setConfig['elements'] ?? [], $setConfig['type']),
                ];
            }
        }

        return $sets;
    }

    /**
     * Normalize address list configuration into a consistent structure.
     *
     * @param array $lists Address lists config
     * @return array Normalized lists keyed by name
     */
    private function normalizeAddressLists(array $lists): array
    {
        $normalized = [];

        foreach ($lists as $key => $list) {
            $name = is_numeric($key) ? ($list['name'] ?? null) : $key;
            if (!$name) {
                continue;
            }

            $ipv4 = $list['ipv4'] ?? $list['elements_ipv4'] ?? [];
            $ipv6 = $list['ipv6'] ?? $list['elements_ipv6'] ?? [];
            $elements = $list['elements'] ?? [];

            if (!empty($elements)) {
                foreach ($elements as $entry) {
                    if (filter_var($entry, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) || str_contains($entry, ':')) {
                        $ipv6[] = $entry;
                    } else {
                        $ipv4[] = $entry;
                    }
                }
            }

            $normalized[$name] = [
                'ipv4' => array_values(array_filter($ipv4)),
                'ipv6' => array_values(array_filter($ipv6)),
            ];
        }

        return $normalized;
    }

    /**
     * Normalize a set name to a safe nftables identifier.
     */
    private function normalizeSetName(string $name): string
    {
        $normalized = strtolower(trim($name));
        $normalized = preg_replace('/[^a-z0-9_-]/', '_', $normalized);
        $normalized = preg_replace('/_+/', '_', $normalized);
        return trim($normalized, '_');
    }

    private function buildAddressListSetName(string $name, string $suffix): string
    {
        return sprintf('%s_%s_%s', self::ADDRESS_LIST_PREFIX, $name, $suffix);
    }

    /**
     * Add an element to a live set.
     *
     * @param string $setName Set name
     * @param string $element Element to add
     * @return bool True if successful
     */
    public function addElement(string $setName, string $element): bool
    {
        $element = $this->normalizeAddress($element);

        if ($this->nftables->addSetElement($this->family, $this->table, $setName, $element)) {
            $this->logger->info("Added {$element} to set {$setName}");
            return true;
        }

        $this->logger->error("Failed to add {$element} to set {$setName}");
        return false;
    }

    /**
     * Remove an element from a live set.
     *
     * @param string $setName Set name
     * @param string $element Element to remove
     * @return bool True if successful
     */
    public function removeElement(string $setName, string $element): bool
    {
        $element = $this->normalizeAddress($element);

        if ($this->nftables->deleteSetElement($this->family, $this->table, $setName, $element)) {
            $this->logger->info("Removed {$element} from set {$setName}");
            return true;
        }

        $this->logger->error("Failed to remove {$element} from set {$setName}");
        return false;
    }

    /**
     * Get elements in a set.
     *
     * @param string $setName Set name
     * @return array Set elements
     */
    public function getElements(string $setName): array
    {
        return $this->nftables->listSetElements($this->family, $this->table, $setName);
    }

    /**
     * Check if an element exists in a set.
     *
     * @param string $setName Set name
     * @param string $element Element to check
     * @return bool True if element exists
     */
    public function hasElement(string $setName, string $element): bool
    {
        $element = $this->normalizeAddress($element);
        $elements = $this->getElements($setName);

        foreach ($elements as $el) {
            // Handle both simple elements and range/prefix elements
            if (is_array($el)) {
                if (isset($el['prefix']) && $el['prefix']['addr'] === $element) {
                    return true;
                }
                if (isset($el['range']) && ($el['range'][0] === $element || $el['range'][1] === $element)) {
                    return true;
                }
            } elseif ($el === $element) {
                return true;
            }
        }

        return false;
    }

    /**
     * Clear all elements from a set.
     *
     * @param string $setName Set name
     * @return bool True if successful
     */
    public function clearSet(string $setName): bool
    {
        $elements = $this->getElements($setName);

        foreach ($elements as $element) {
            $elementStr = is_array($element) ? json_encode($element) : $element;
            $this->nftables->deleteSetElement($this->family, $this->table, $setName, $elementStr);
        }

        $this->logger->info("Cleared set {$setName}");
        return true;
    }

    /**
     * Replace all elements in a set.
     *
     * @param string $setName Set name
     * @param array $elements New elements
     * @return bool True if successful
     */
    public function replaceElements(string $setName, array $elements): bool
    {
        // Clear existing
        $this->clearSet($setName);

        // Add new elements
        $success = true;
        foreach ($elements as $element) {
            if (!$this->addElement($setName, $element)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Get information about a standard set.
     *
     * @param string $setName Set name
     * @return array|null Set information or null if not a standard set
     */
    public function getStandardSetInfo(string $setName): ?array
    {
        return self::STANDARD_SETS[$setName] ?? null;
    }

    /**
     * Get all standard set names.
     *
     * @return array Standard set names
     */
    public function getStandardSetNames(): array
    {
        return array_keys(self::STANDARD_SETS);
    }

    /**
     * Normalize an IP address or CIDR.
     *
     * @param string $address Address to normalize
     * @return string Normalized address
     */
    private function normalizeAddress(string $address): string
    {
        $address = trim($address);

        // Add /32 to bare IP addresses for set compatibility
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $address;
        }

        // Validate CIDR notation
        if (preg_match('/^(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})\/(\d{1,2})$/', $address, $matches)) {
            if (filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && $matches[2] >= 0 && $matches[2] <= 32) {
                return $address;
            }
        }

        // IPv6
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $address;
        }

        return $address;
    }

    /**
     * Normalize an array of addresses.
     *
     * @param array $addresses Addresses to normalize
     * @return array Normalized addresses
     */
    private function normalizeAddresses(array $addresses): array
    {
        return array_map([$this, 'normalizeAddress'], array_filter($addresses));
    }

    /**
     * Normalize elements based on set type.
     *
     * @param array $elements Elements to normalize
     * @param string $type Set type
     * @return array Normalized elements
     */
    private function normalizeElements(array $elements, string $type): array
    {
        $normalized = [];

        foreach ($elements as $element) {
            if (empty($element)) {
                continue;
            }

            switch ($type) {
                case 'ipv4':
                case 'ipv4_addr':
                case 'ipv6':
                case 'ipv6_addr':
                    $normalized[] = $this->normalizeAddress($element);
                    break;

                case 'port':
                case 'inet_service':
                    // Ports can be numbers or ranges (e.g., "80", "1024-65535")
                    $normalized[] = trim($element);
                    break;

                case 'interface':
                case 'ifname':
                    $normalized[] = trim($element);
                    break;

                case 'mac':
                case 'ether_addr':
                    // Normalize MAC address format
                    $mac = strtolower(trim($element));
                    $mac = preg_replace('/[^0-9a-f]/', ':', $mac);
                    $normalized[] = $mac;
                    break;

                default:
                    $normalized[] = trim($element);
            }
        }

        return $normalized;
    }

    /**
     * Resolve a set type alias to nftables type.
     *
     * @param string $type Type name or alias
     * @return string nftables type name
     */
    private function resolveSetType(string $type): string
    {
        return self::SET_TYPES[$type] ?? $type;
    }

    /**
     * Set the default table family.
     *
     * @param string $family Table family (inet, ip, ip6)
     * @return self
     */
    public function setFamily(string $family): self
    {
        $this->family = $family;
        return $this;
    }

    /**
     * Set the default table name.
     *
     * @param string $table Table name
     * @return self
     */
    public function setTable(string $table): self
    {
        $this->table = $table;
        return $this;
    }
}
