<?php

namespace Coyote\Firewall;

use Coyote\Util\Logger;

/**
 * Resolves logical interface names to physical interfaces.
 *
 * Maps interface roles (wan, lan, dmz) and aliases to actual
 * system interface names for firewall rule generation.
 */
class InterfaceResolver
{
    /** @var Logger */
    private Logger $logger;

    /** @var array Interface configuration from system config */
    private array $interfaces = [];

    /** @var array Role to interface mapping */
    private array $roleMap = [];

    /** @var array Alias to interface mapping */
    private array $aliasMap = [];

    /** @var array Interface groups */
    private array $groups = [];

    /** @var array Cached physical interface list */
    private ?array $physicalInterfaces = null;

    /**
     * Create a new InterfaceResolver instance.
     */
    public function __construct()
    {
        $this->logger = new Logger('interface-resolver');
    }

    /**
     * Load interface configuration.
     *
     * @param array $networkConfig Network configuration section
     * @return self
     */
    public function loadConfig(array $networkConfig): self
    {
        $this->interfaces = $networkConfig['interfaces'] ?? [];
        $this->groups = $networkConfig['interface_groups'] ?? [];

        // Build role and alias maps
        $this->roleMap = [];
        $this->aliasMap = [];

        foreach ($this->interfaces as $ifConfig) {
            $name = $ifConfig['name'] ?? '';
            if (empty($name)) {
                continue;
            }

            // Map by role (wan, lan, dmz, etc.)
            $role = $ifConfig['role'] ?? null;
            if ($role) {
                $this->roleMap[strtolower($role)] = $name;
            }

            // Map by alias
            $alias = $ifConfig['alias'] ?? null;
            if ($alias) {
                $this->aliasMap[strtolower($alias)] = $name;
            }

            // Auto-detect role from common naming patterns
            if (!$role) {
                $this->autoDetectRole($name, $ifConfig);
            }
        }

        return $this;
    }

    /**
     * Resolve an interface name to physical interface(s).
     *
     * Accepts:
     * - Physical interface names (eth0, ens3)
     * - Role names (wan, lan, dmz)
     * - Aliases defined in config
     * - Group names (@internal, @external)
     * - Wildcards (eth*, vlan*)
     *
     * @param string $name Interface identifier
     * @return array Array of physical interface names
     */
    public function resolve(string $name): array
    {
        $name = trim($name);

        if (empty($name)) {
            return [];
        }

        // Check for group reference (@groupname)
        if (str_starts_with($name, '@')) {
            return $this->resolveGroup(substr($name, 1));
        }

        // Check for wildcard
        if (str_contains($name, '*')) {
            return $this->resolveWildcard($name);
        }

        // Check role map
        $lowerName = strtolower($name);
        if (isset($this->roleMap[$lowerName])) {
            return [$this->roleMap[$lowerName]];
        }

        // Check alias map
        if (isset($this->aliasMap[$lowerName])) {
            return [$this->aliasMap[$lowerName]];
        }

        // Check if it's a configured interface name
        foreach ($this->interfaces as $ifConfig) {
            if (($ifConfig['name'] ?? '') === $name) {
                return [$name];
            }
        }

        // Check if it's a physical interface on the system
        if ($this->isPhysicalInterface($name)) {
            return [$name];
        }

        // Not found - return as-is (may be a valid interface not yet configured)
        $this->logger->warning("Interface not found in config: {$name}");
        return [$name];
    }

    /**
     * Resolve multiple interface names.
     *
     * @param array $names Array of interface identifiers
     * @return array Flattened array of physical interface names
     */
    public function resolveMultiple(array $names): array
    {
        $resolved = [];

        foreach ($names as $name) {
            $resolved = array_merge($resolved, $this->resolve($name));
        }

        return array_unique($resolved);
    }

    /**
     * Resolve an interface group.
     *
     * @param string $groupName Group name (without @)
     * @return array Array of physical interface names
     */
    private function resolveGroup(string $groupName): array
    {
        $groupName = strtolower($groupName);

        // Check configured groups
        if (isset($this->groups[$groupName])) {
            return $this->resolveMultiple($this->groups[$groupName]);
        }

        // Built-in groups
        switch ($groupName) {
            case 'internal':
            case 'lan':
                // All non-WAN interfaces
                return $this->getInternalInterfaces();

            case 'external':
            case 'wan':
                // WAN interface(s)
                return $this->getExternalInterfaces();

            case 'all':
                // All configured interfaces
                return $this->getAllInterfaces();

            default:
                $this->logger->warning("Unknown interface group: @{$groupName}");
                return [];
        }
    }

    /**
     * Resolve a wildcard pattern.
     *
     * @param string $pattern Wildcard pattern (e.g., eth*, vlan*)
     * @return array Matching interface names
     */
    private function resolveWildcard(string $pattern): array
    {
        $matched = [];
        $regex = '/^' . str_replace('*', '.*', preg_quote($pattern, '/')) . '$/';

        // Match against configured interfaces
        foreach ($this->interfaces as $ifConfig) {
            $name = $ifConfig['name'] ?? '';
            if (preg_match($regex, $name)) {
                $matched[] = $name;
            }
        }

        // Also match against physical interfaces
        foreach ($this->getPhysicalInterfaces() as $ifName) {
            if (preg_match($regex, $ifName) && !in_array($ifName, $matched)) {
                $matched[] = $ifName;
            }
        }

        return $matched;
    }

    /**
     * Auto-detect interface role from naming patterns.
     *
     * @param string $name Interface name
     * @param array $config Interface configuration
     */
    private function autoDetectRole(string $name, array $config): void
    {
        $lowerName = strtolower($name);

        // Common WAN interface patterns
        if (preg_match('/^(wan|ppp|pppoe|eth0|ens\d+)$/i', $name)) {
            if (!isset($this->roleMap['wan'])) {
                $this->roleMap['wan'] = $name;
            }
        }

        // Common LAN interface patterns
        if (preg_match('/^(lan|eth1|br0|bridge)$/i', $name)) {
            if (!isset($this->roleMap['lan'])) {
                $this->roleMap['lan'] = $name;
            }
        }

        // DMZ patterns
        if (preg_match('/^(dmz|eth2)$/i', $name)) {
            if (!isset($this->roleMap['dmz'])) {
                $this->roleMap['dmz'] = $name;
            }
        }
    }

    /**
     * Get all internal (non-WAN) interfaces.
     *
     * @return array Internal interface names
     */
    public function getInternalInterfaces(): array
    {
        $internal = [];
        $wanInterfaces = $this->getExternalInterfaces();

        foreach ($this->interfaces as $ifConfig) {
            $name = $ifConfig['name'] ?? '';
            $role = strtolower($ifConfig['role'] ?? '');

            if (empty($name)) {
                continue;
            }

            // Skip WAN interfaces
            if ($role === 'wan' || in_array($name, $wanInterfaces)) {
                continue;
            }

            // Skip disabled interfaces
            if (($ifConfig['enabled'] ?? true) === false) {
                continue;
            }

            $internal[] = $name;
        }

        return $internal;
    }

    /**
     * Get external (WAN) interfaces.
     *
     * @return array WAN interface names
     */
    public function getExternalInterfaces(): array
    {
        $external = [];

        foreach ($this->interfaces as $ifConfig) {
            $name = $ifConfig['name'] ?? '';
            $role = strtolower($ifConfig['role'] ?? '');
            $type = strtolower($ifConfig['type'] ?? '');

            if (empty($name)) {
                continue;
            }

            // Explicit WAN role
            if ($role === 'wan' || $role === 'external') {
                $external[] = $name;
                continue;
            }

            // PPPoE interfaces are typically WAN
            if ($type === 'pppoe') {
                $external[] = $name;
            }
        }

        // Fallback to role map
        if (empty($external) && isset($this->roleMap['wan'])) {
            $external[] = $this->roleMap['wan'];
        }

        return $external;
    }

    /**
     * Get all configured interfaces.
     *
     * @return array All interface names
     */
    public function getAllInterfaces(): array
    {
        $all = [];

        foreach ($this->interfaces as $ifConfig) {
            $name = $ifConfig['name'] ?? '';
            if (!empty($name)) {
                $all[] = $name;
            }
        }

        return $all;
    }

    /**
     * Check if a name is a physical interface on the system.
     *
     * @param string $name Interface name
     * @return bool True if physical interface exists
     */
    public function isPhysicalInterface(string $name): bool
    {
        return in_array($name, $this->getPhysicalInterfaces());
    }

    /**
     * Get list of physical interfaces on the system.
     *
     * @return array Physical interface names
     */
    public function getPhysicalInterfaces(): array
    {
        if ($this->physicalInterfaces !== null) {
            return $this->physicalInterfaces;
        }

        $this->physicalInterfaces = [];
        $netDir = '/sys/class/net';

        if (is_dir($netDir)) {
            $entries = scandir($netDir);
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $this->physicalInterfaces[] = $entry;
            }
        }

        return $this->physicalInterfaces;
    }

    /**
     * Get the role of an interface.
     *
     * @param string $name Interface name
     * @return string|null Role name or null if not assigned
     */
    public function getRole(string $name): ?string
    {
        foreach ($this->interfaces as $ifConfig) {
            if (($ifConfig['name'] ?? '') === $name) {
                return $ifConfig['role'] ?? null;
            }
        }

        // Check reverse role map
        foreach ($this->roleMap as $role => $ifName) {
            if ($ifName === $name) {
                return $role;
            }
        }

        return null;
    }

    /**
     * Get interface by role.
     *
     * @param string $role Role name (wan, lan, dmz)
     * @return string|null Interface name or null if not found
     */
    public function getByRole(string $role): ?string
    {
        return $this->roleMap[strtolower($role)] ?? null;
    }

    /**
     * Check if an interface is a WAN interface.
     *
     * @param string $name Interface name
     * @return bool True if WAN interface
     */
    public function isWanInterface(string $name): bool
    {
        return in_array($name, $this->getExternalInterfaces());
    }

    /**
     * Check if an interface is a LAN interface.
     *
     * @param string $name Interface name
     * @return bool True if LAN/internal interface
     */
    public function isLanInterface(string $name): bool
    {
        return in_array($name, $this->getInternalInterfaces());
    }

    /**
     * Clear cached physical interface list.
     *
     * @return self
     */
    public function clearCache(): self
    {
        $this->physicalInterfaces = null;
        return $this;
    }
}
