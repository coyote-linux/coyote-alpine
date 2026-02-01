<?php

namespace Coyote\Firewall;

use Coyote\Util\Logger;

/**
 * Manages ACL bindings to interface pairs.
 *
 * Handles the application of ACLs to traffic flows between interfaces,
 * with support for interface roles, groups, and wildcards.
 */
class AclBindingService
{
    /** @var InterfaceResolver */
    private InterfaceResolver $resolver;

    /** @var Logger */
    private Logger $logger;

    /** @var array ACL definitions from config */
    private array $acls = [];

    /** @var array ACL bindings from config */
    private array $bindings = [];

    /**
     * Create a new AclBindingService instance.
     *
     * @param InterfaceResolver|null $resolver Optional resolver instance
     */
    public function __construct(?InterfaceResolver $resolver = null)
    {
        $this->resolver = $resolver ?? new InterfaceResolver();
        $this->logger = new Logger('acl-binding');
    }

    /**
     * Load configuration.
     *
     * @param array $firewallConfig Firewall configuration section
     * @param array $networkConfig Network configuration section
     * @return self
     */
    public function loadConfig(array $firewallConfig, array $networkConfig = []): self
    {
        $this->acls = $firewallConfig['acls'] ?? [];
        $this->bindings = $firewallConfig['applied'] ?? [];

        if (!empty($networkConfig)) {
            $this->resolver->loadConfig($networkConfig);
        }

        return $this;
    }

    /**
     * Build user ACL chain rules with resolved interfaces.
     *
     * @return array Chain rules for coyote-user-acls and ACL chains
     */
    public function buildAclBindings(): array
    {
        $chains = [];
        $userAclRules = [];

        // First, create ACL chains from definitions
        foreach ($this->acls as $aclName => $aclRules) {
            // Handle both array formats
            if (is_numeric($aclName)) {
                // Indexed array with 'name' key
                $name = $aclRules['name'] ?? "acl-{$aclName}";
                $rules = $aclRules['rules'] ?? [];
            } else {
                // Associative array keyed by name
                $name = $aclName;
                $rules = $aclRules;
            }

            $chainName = $this->normalizeChainName($name);
            $chains[$chainName] = $this->buildAclChainRules($rules);
        }

        // Process bindings to create routing rules
        foreach ($this->bindings as $binding) {
            $bindingRules = $this->processBinding($binding);
            $userAclRules = array_merge($userAclRules, $bindingRules);
        }

        // Add coyote-user-acls chain
        $chains['coyote-user-acls'] = $userAclRules;

        return $chains;
    }

    /**
     * Process a single ACL binding.
     *
     * @param array $binding Binding configuration
     * @return array nftables rules for the binding
     */
    private function processBinding(array $binding): array
    {
        $rules = [];

        $inInterface = $binding['in_interface'] ?? $binding['source'] ?? '';
        $outInterface = $binding['out_interface'] ?? $binding['destination'] ?? '';
        $aclName = $binding['acl'] ?? '';
        $enabled = $binding['enabled'] ?? true;

        if (!$enabled || empty($aclName)) {
            return [];
        }

        $chainName = $this->normalizeChainName($aclName);

        // Resolve interfaces
        $inInterfaces = !empty($inInterface) ? $this->resolver->resolve($inInterface) : [];
        $outInterfaces = !empty($outInterface) ? $this->resolver->resolve($outInterface) : [];

        // Handle bidirectional bindings
        $bidirectional = $binding['bidirectional'] ?? false;

        // Generate rules for all interface combinations
        if (empty($inInterfaces) && empty($outInterfaces)) {
            // No interface restriction - apply to all traffic
            $rules[] = "jump {$chainName}";
        } elseif (empty($inInterfaces)) {
            // Only output interface specified
            foreach ($outInterfaces as $outIf) {
                $rules[] = "oifname \"{$outIf}\" jump {$chainName}";
            }
        } elseif (empty($outInterfaces)) {
            // Only input interface specified
            foreach ($inInterfaces as $inIf) {
                $rules[] = "iifname \"{$inIf}\" jump {$chainName}";
            }
        } else {
            // Both interfaces specified
            foreach ($inInterfaces as $inIf) {
                foreach ($outInterfaces as $outIf) {
                    $rules[] = "iifname \"{$inIf}\" oifname \"{$outIf}\" jump {$chainName}";

                    // Add reverse direction if bidirectional
                    if ($bidirectional) {
                        $rules[] = "iifname \"{$outIf}\" oifname \"{$inIf}\" jump {$chainName}";
                    }
                }
            }
        }

        return $rules;
    }

    /**
     * Build rules for an ACL chain.
     *
     * @param array $aclRules ACL rule definitions
     * @return array nftables rule strings
     */
    private function buildAclChainRules(array $aclRules): array
    {
        $rules = [];

        foreach ($aclRules as $rule) {
            $nftRule = $this->buildRule($rule);
            if (!empty($nftRule)) {
                $rules[] = $nftRule;
            }
        }

        // Add return at end of ACL chain (fall through to next check)
        $rules[] = 'return';

        return $rules;
    }

    /**
     * Build a single nftables rule from ACL rule definition.
     *
     * @param array $rule Rule definition
     * @return string nftables rule string
     */
    private function buildRule(array $rule): string
    {
        $parts = [];

        // Protocol
        $protocol = strtolower($rule['protocol'] ?? 'any');
        if ($protocol !== 'any' && $protocol !== 'all') {
            if ($protocol === 'icmp') {
                $parts[] = 'ip protocol icmp';
            } elseif ($protocol === 'icmpv6') {
                $parts[] = 'ip6 nexthdr icmpv6';
            } else {
                $parts[] = $protocol;
            }
        }

        // Source address
        $source = $rule['source'] ?? $rule['src'] ?? null;
        if ($source && $source !== 'any' && $source !== '0.0.0.0/0') {
            // Check if it's an interface reference
            if ($this->isInterfaceReference($source)) {
                $resolved = $this->resolver->resolve($source);
                if (!empty($resolved)) {
                    $parts[] = "iifname \"" . $resolved[0] . "\"";
                }
            } else {
                $parts[] = "ip saddr {$source}";
            }
        }

        // Destination address
        $dest = $rule['destination'] ?? $rule['dst'] ?? null;
        if ($dest && $dest !== 'any' && $dest !== '0.0.0.0/0') {
            if ($this->isInterfaceReference($dest)) {
                $resolved = $this->resolver->resolve($dest);
                if (!empty($resolved)) {
                    $parts[] = "oifname \"" . $resolved[0] . "\"";
                }
            } else {
                $parts[] = "ip daddr {$dest}";
            }
        }

        // Source port
        $srcPort = $rule['source_port'] ?? $rule['sport'] ?? null;
        if ($srcPort) {
            $parts[] = "sport {$srcPort}";
        }

        // Destination port
        $dstPort = $rule['destination_port'] ?? $rule['port'] ?? $rule['dport'] ?? null;
        if ($dstPort) {
            $parts[] = "dport {$dstPort}";
        }

        // Counter (optional)
        if ($rule['counter'] ?? false) {
            $parts[] = 'counter';
        }

        // Log (optional)
        if ($rule['log'] ?? false) {
            $logPrefix = $rule['log_prefix'] ?? 'ACL';
            $parts[] = "log prefix \"{$logPrefix}: \"";
        }

        // Action
        $action = strtolower($rule['action'] ?? 'accept');
        switch ($action) {
            case 'accept':
            case 'allow':
                $parts[] = 'accept';
                break;
            case 'drop':
            case 'deny':
                $parts[] = 'drop';
                break;
            case 'reject':
                $rejectWith = $rule['reject_with'] ?? null;
                if ($rejectWith) {
                    $parts[] = "reject with {$rejectWith}";
                } else {
                    $parts[] = 'reject';
                }
                break;
            case 'return':
                $parts[] = 'return';
                break;
            case 'continue':
                // No action - continue to next rule
                break;
            default:
                $parts[] = 'accept';
        }

        return implode(' ', $parts);
    }

    /**
     * Check if a value looks like an interface reference.
     *
     * @param string $value Value to check
     * @return bool True if likely an interface reference
     */
    private function isInterfaceReference(string $value): bool
    {
        // Starts with @ (group)
        if (str_starts_with($value, '@')) {
            return true;
        }

        // Known role names
        if (in_array(strtolower($value), ['wan', 'lan', 'dmz', 'internal', 'external'])) {
            return true;
        }

        // Contains wildcard
        if (str_contains($value, '*')) {
            return true;
        }

        // Looks like an interface name (not an IP)
        if (preg_match('/^[a-zA-Z]/', $value) && !str_contains($value, '.') && !str_contains($value, '/')) {
            // Could be interface name - check if it's in resolver
            $resolved = $this->resolver->resolve($value);
            return !empty($resolved) && $resolved[0] !== $value;
        }

        return false;
    }

    /**
     * Normalize ACL name to chain name.
     *
     * @param string $name ACL name
     * @return string Chain name
     */
    private function normalizeChainName(string $name): string
    {
        // Remove any existing acl- prefix
        $name = preg_replace('/^acl-/i', '', $name);

        // Convert to lowercase and replace spaces/special chars
        $name = strtolower($name);
        $name = preg_replace('/[^a-z0-9-]/', '-', $name);
        $name = preg_replace('/-+/', '-', $name);
        $name = trim($name, '-');

        return "acl-{$name}";
    }

    /**
     * Get all ACL names.
     *
     * @return array ACL names
     */
    public function getAclNames(): array
    {
        $names = [];

        foreach ($this->acls as $key => $value) {
            if (is_numeric($key)) {
                $names[] = $value['name'] ?? "acl-{$key}";
            } else {
                $names[] = $key;
            }
        }

        return $names;
    }

    /**
     * Get bindings for a specific ACL.
     *
     * @param string $aclName ACL name
     * @return array Bindings using this ACL
     */
    public function getBindingsForAcl(string $aclName): array
    {
        $result = [];

        foreach ($this->bindings as $binding) {
            if (($binding['acl'] ?? '') === $aclName) {
                $result[] = $binding;
            }
        }

        return $result;
    }

    /**
     * Validate a binding configuration.
     *
     * @param array $binding Binding to validate
     * @return array Validation errors (empty if valid)
     */
    public function validateBinding(array $binding): array
    {
        $errors = [];

        $aclName = $binding['acl'] ?? '';
        if (empty($aclName)) {
            $errors[] = 'ACL name is required';
        } elseif (!in_array($aclName, $this->getAclNames())) {
            $errors[] = "ACL '{$aclName}' does not exist";
        }

        $inInterface = $binding['in_interface'] ?? $binding['source'] ?? '';
        $outInterface = $binding['out_interface'] ?? $binding['destination'] ?? '';

        if (empty($inInterface) && empty($outInterface)) {
            $errors[] = 'At least one interface (source or destination) must be specified';
        }

        // Validate interface references
        if (!empty($inInterface)) {
            $resolved = $this->resolver->resolve($inInterface);
            if (empty($resolved)) {
                $errors[] = "Cannot resolve source interface: {$inInterface}";
            }
        }

        if (!empty($outInterface)) {
            $resolved = $this->resolver->resolve($outInterface);
            if (empty($resolved)) {
                $errors[] = "Cannot resolve destination interface: {$outInterface}";
            }
        }

        return $errors;
    }

    /**
     * Get the interface resolver instance.
     *
     * @return InterfaceResolver
     */
    public function getResolver(): InterfaceResolver
    {
        return $this->resolver;
    }

    /**
     * Create a quick binding helper.
     *
     * @param string $source Source interface/role
     * @param string $destination Destination interface/role
     * @param string $aclName ACL to apply
     * @param bool $bidirectional Apply in both directions
     * @return array Binding configuration
     */
    public static function createBinding(
        string $source,
        string $destination,
        string $aclName,
        bool $bidirectional = false
    ): array {
        return [
            'in_interface' => $source,
            'out_interface' => $destination,
            'acl' => $aclName,
            'bidirectional' => $bidirectional,
            'enabled' => true,
        ];
    }
}
