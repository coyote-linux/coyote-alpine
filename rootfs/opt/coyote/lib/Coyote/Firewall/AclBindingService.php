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

    /** @var array Address lists from config */
    private array $addressLists = [];

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
        $this->addressLists = $firewallConfig['address_lists'] ?? [];

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
            $nftRules = $this->buildRule($rule);
            foreach ($nftRules as $nftRule) {
                if (!empty($nftRule)) {
                    $rules[] = $nftRule;
                }
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
     * @return array nftables rule strings
     */
    private function buildRule(array $rule): array
    {
        $rules = [];
        $families = $this->resolveRuleFamilies($rule);

        foreach ($families as $family) {
            $parts = [];

            // Protocol
            $protocol = strtolower($rule['protocol'] ?? 'any');
            $transportProtocol = null;
            if ($protocol !== 'any' && $protocol !== 'all') {
                if ($protocol === 'icmp') {
                    $parts[] = $family === 'ip6' ? 'ip6 nexthdr icmpv6' : 'ip protocol icmp';
                } elseif ($protocol === 'icmpv6') {
                    if ($family === 'ip6') {
                        $parts[] = 'ip6 nexthdr icmpv6';
                    } else {
                        continue;
                    }
                } elseif (in_array($protocol, ['tcp', 'udp', 'sctp'], true)) {
                    $transportProtocol = $protocol;
                } elseif (in_array($protocol, ['gre', 'esp', 'ah'], true)) {
                    $parts[] = "meta l4proto {$protocol}";
                } else {
                    $this->logger->warning("Skipping unsupported ACL protocol '{$protocol}'");
                    continue;
                }
            }

            // Source address
            $sourceList = $rule['source_list'] ?? null;
            $source = $rule['source'] ?? $rule['src'] ?? null;
            if ($source && $source !== 'any' && $source !== '0.0.0.0/0') {
                // Check if it's an interface reference
                if ($this->isInterfaceReference($source)) {
                    $resolved = $this->resolver->resolve($source);
                    if (!empty($resolved)) {
                        $parts[] = "iifname \"" . $resolved[0] . "\"";
                    }
                } elseif ($this->isValidAddressSpec($source, $family)) {
                    $parts[] = $family === 'ip6' ? "ip6 saddr {$source}" : "ip saddr {$source}";
                } else {
                    $this->logger->warning("Skipping invalid ACL source '{$source}' for family {$family}");
                }
            } elseif (!empty($sourceList)) {
                $setName = $this->buildAddressListSetName($sourceList, $family);
                if ($setName) {
                    $parts[] = $family === 'ip6' ? "ip6 saddr @{$setName}" : "ip saddr @{$setName}";
                }
            }

            // Destination address
            $destList = $rule['destination_list'] ?? null;
            $dest = $rule['destination'] ?? $rule['dst'] ?? null;
            if ($dest && $dest !== 'any' && $dest !== '0.0.0.0/0') {
                if ($this->isInterfaceReference($dest)) {
                    $resolved = $this->resolver->resolve($dest);
                    if (!empty($resolved)) {
                        $parts[] = "oifname \"" . $resolved[0] . "\"";
                    }
                } elseif ($this->isValidAddressSpec($dest, $family)) {
                    $parts[] = $family === 'ip6' ? "ip6 daddr {$dest}" : "ip daddr {$dest}";
                } else {
                    $this->logger->warning("Skipping invalid ACL destination '{$dest}' for family {$family}");
                }
            } elseif (!empty($destList)) {
                $setName = $this->buildAddressListSetName($destList, $family);
                if ($setName) {
                    $parts[] = $family === 'ip6' ? "ip6 daddr @{$setName}" : "ip daddr @{$setName}";
                }
            }

            if ($transportProtocol !== null) {
                $hasPortMatch = false;

                // Source port
                $srcPort = $rule['source_port'] ?? $rule['sport'] ?? null;
                $srcPortExpr = $this->buildPortExpression($srcPort);
                if ($srcPortExpr !== null) {
                    $parts[] = "{$transportProtocol} sport {$srcPortExpr}";
                    $hasPortMatch = true;
                }

                // Destination port
                $dstPort = $rule['destination_port'] ?? $rule['port'] ?? $rule['dport'] ?? $rule['ports'] ?? null;
                $dstPortExpr = $this->buildPortExpression($dstPort);
                if ($dstPortExpr !== null) {
                    $parts[] = "{$transportProtocol} dport {$dstPortExpr}";
                    $hasPortMatch = true;
                }

                if (!$hasPortMatch) {
                    $parts[] = "meta l4proto {$transportProtocol}";
                }
            } elseif (!empty($rule['source_port']) || !empty($rule['sport']) || !empty($rule['destination_port']) || !empty($rule['port']) || !empty($rule['dport']) || !empty($rule['ports'])) {
                $this->logger->warning("Ignoring ACL port specification for non-port protocol '{$protocol}'");
            }

            // Counter (optional)
            if ($rule['counter'] ?? false) {
                $parts[] = 'counter';
            }

            // Log (optional)
            if ($rule['log'] ?? false) {
                $logPrefix = $this->sanitizeLogPrefix($rule['log_prefix'] ?? 'ACL');
                $parts[] = "log prefix \"{$logPrefix}: \"";
            }

            // Action
            $action = strtolower($rule['action'] ?? 'accept');
            switch ($action) {
                case 'accept':
                case 'allow':
                case 'permit':
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

            $rules[] = implode(' ', $parts);
        }

        return $rules;
    }

    private function resolveRuleFamilies(array $rule): array
    {
        $families = ['ip', 'ip6'];

        $source = $rule['source'] ?? $rule['src'] ?? null;
        $dest = $rule['destination'] ?? $rule['dst'] ?? null;
        $sourceList = $rule['source_list'] ?? null;
        $destList = $rule['destination_list'] ?? null;

        $protocol = strtolower($rule['protocol'] ?? 'any');
        if ($protocol === 'icmpv6') {
            $families = ['ip6'];
        }

        $sourceFamily = $this->getAddressFamily($source);
        if ($sourceFamily) {
            $families = array_intersect($families, [$sourceFamily]);
        }

        $destFamily = $this->getAddressFamily($dest);
        if ($destFamily) {
            $families = array_intersect($families, [$destFamily]);
        }

        if (!empty($sourceList)) {
            $families = array_intersect($families, $this->getListFamilies($sourceList));
        }

        if (!empty($destList)) {
            $families = array_intersect($families, $this->getListFamilies($destList));
        }

        return array_values(array_unique($families));
    }

    private function getAddressFamily(?string $value): ?string
    {
        if (!$value || $value === 'any' || $this->isInterfaceReference($value)) {
            return null;
        }

        if (str_contains($value, ':')) {
            return 'ip6';
        }

        return 'ip';
    }

    private function getListFamilies(string $name): array
    {
        $list = $this->addressLists[$name] ?? null;
        if (!$list && is_array($this->addressLists)) {
            foreach ($this->addressLists as $key => $entry) {
                if (is_numeric($key) && ($entry['name'] ?? '') === $name) {
                    $list = $entry;
                    break;
                }
            }
        }

        if (!$list) {
            return [];
        }

        $families = [];
        if (!empty($list['ipv4']) || !empty($list['elements_ipv4'])) {
            $families[] = 'ip';
        }
        if (!empty($list['ipv6']) || !empty($list['elements_ipv6'])) {
            $families[] = 'ip6';
        }

        if (!empty($list['elements'])) {
            foreach ($list['elements'] as $entry) {
                if (str_contains($entry, ':')) {
                    $families[] = 'ip6';
                } else {
                    $families[] = 'ip';
                }
            }
        }

        return array_values(array_unique($families));
    }

    private function buildAddressListSetName(string $name, string $family): ?string
    {
        if (empty($name)) {
            return null;
        }

        $normalized = strtolower(trim($name));
        $normalized = preg_replace('/[^a-z0-9_-]/', '_', $normalized);
        $normalized = preg_replace('/_+/', '_', $normalized);
        $normalized = trim($normalized, '_');

        return sprintf('addrlist_%s_%s', $normalized, $family === 'ip6' ? 'v6' : 'v4');
    }

    private function isValidAddressSpec(string $value, string $family): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        if ($family === 'ip') {
            if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return true;
            }
            return $this->isValidCidr($value, FILTER_FLAG_IPV4, 32);
        }

        if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return true;
        }
        return $this->isValidCidr($value, FILTER_FLAG_IPV6, 128);
    }

    private function isValidCidr(string $value, int $flag, int $maxPrefix): bool
    {
        if (!str_contains($value, '/')) {
            return false;
        }

        [$ip, $prefix] = explode('/', $value, 2);
        if (!filter_var($ip, FILTER_VALIDATE_IP, $flag)) {
            return false;
        }

        if (!is_numeric($prefix)) {
            return false;
        }

        $prefixInt = (int) $prefix;
        return $prefixInt >= 0 && $prefixInt <= $maxPrefix;
    }

    private function buildPortExpression(mixed $portSpec): ?string
    {
        if ($portSpec === null) {
            return null;
        }

        $spec = preg_replace('/\s+/', '', (string) $portSpec);
        if ($spec === '') {
            return null;
        }

        $normalized = [];
        foreach (explode(',', $spec) as $part) {
            if ($part === '') {
                return null;
            }

            if (str_contains($part, '-')) {
                [$start, $end] = explode('-', $part, 2);
                if (!$this->isValidPort($start) || !$this->isValidPort($end) || (int) $start >= (int) $end) {
                    return null;
                }
                $normalized[] = sprintf('%d-%d', (int) $start, (int) $end);
                continue;
            }

            if (!$this->isValidPort($part)) {
                return null;
            }

            $normalized[] = (string) ((int) $part);
        }

        if (count($normalized) === 1) {
            return $normalized[0];
        }

        return sprintf('{ %s }', implode(', ', $normalized));
    }

    private function isValidPort(string $port): bool
    {
        if (!preg_match('/^[0-9]+$/', $port)) {
            return false;
        }

        $portInt = (int) $port;
        return $portInt >= 1 && $portInt <= 65535;
    }

    private function sanitizeLogPrefix(string $prefix): string
    {
        $sanitized = str_replace(['\\', '"'], '', $prefix);
        $sanitized = trim($sanitized);
        if ($sanitized === '') {
            return 'ACL';
        }

        return substr($sanitized, 0, 48);
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
