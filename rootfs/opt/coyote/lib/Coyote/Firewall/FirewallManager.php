<?php

namespace Coyote\Firewall;

use Coyote\Util\Logger;

/**
 * Central firewall management for Coyote Linux.
 *
 * Orchestrates all firewall-related services using nftables.
 * Handles atomic ruleset application with rollback support.
 */
class FirewallManager
{
    /** @var NftablesService */
    private NftablesService $nftables;

    /** @var RulesetBuilder */
    private RulesetBuilder $builder;

    /** @var SetManager */
    private SetManager $setManager;

    /** @var ServiceAclService */
    private ServiceAclService $serviceAcl;

    /** @var IcmpService */
    private IcmpService $icmpService;

    /** @var InterfaceResolver */
    private InterfaceResolver $interfaceResolver;

    /** @var AclBindingService */
    private AclBindingService $aclBinding;

    /** @var Logger */
    private Logger $logger;

    /** @var bool Whether the firewall is enabled */
    private bool $enabled = false;

    /** @var string State directory for ruleset files */
    private string $stateDir = '/var/lib/coyote/firewall';

    /** @var string Path to current active ruleset */
    private string $currentRuleset;

    /** @var string Path to previous ruleset (for rollback) */
    private string $previousRuleset;

    /**
     * Create a new FirewallManager instance.
     */
    public function __construct()
    {
        $this->nftables = new NftablesService();
        $this->builder = new RulesetBuilder();
        $this->setManager = new SetManager($this->nftables);
        $this->serviceAcl = new ServiceAclService();
        $this->icmpService = new IcmpService();
        $this->interfaceResolver = new InterfaceResolver();
        $this->aclBinding = new AclBindingService($this->interfaceResolver);
        $this->logger = new Logger('coyote-firewall');
        $this->currentRuleset = $this->stateDir . '/current.nft';
        $this->previousRuleset = $this->stateDir . '/previous.nft';

        // Ensure state directory exists
        if (!is_dir($this->stateDir)) {
            mkdir($this->stateDir, 0750, true);
        }
    }

    /**
     * Apply firewall configuration.
     *
     * @param array $config Full system configuration
     * @return bool True if successful
     */
    public function applyConfig(array $config): bool
    {
        $firewallConfig = $config['firewall'] ?? [];
        $this->enabled = $firewallConfig['enabled'] ?? true;

        if (!$this->enabled) {
            $this->logger->info('Firewall disabled, flushing rules');
            return $this->nftables->flush();
        }

        $this->logger->info('Building nftables ruleset');

        // Build the complete ruleset
        $ruleset = $this->buildRuleset($config);

        // Write to temporary file
        $tempFile = $this->stateDir . '/pending.nft';
        if (file_put_contents($tempFile, $ruleset) === false) {
            $this->logger->error('Failed to write ruleset to temp file');
            return false;
        }

        // Validate the ruleset
        if (!$this->nftables->validateRuleset($tempFile)) {
            $this->logger->error('Ruleset validation failed');
            unlink($tempFile);
            return false;
        }

        // Save current ruleset for rollback
        if (file_exists($this->currentRuleset)) {
            $this->nftables->saveRuleset($this->previousRuleset);
        }

        // Apply atomically
        if (!$this->nftables->loadRuleset($tempFile)) {
            $this->logger->error('Failed to apply ruleset');
            unlink($tempFile);
            return false;
        }

        // Save as current
        rename($tempFile, $this->currentRuleset);

        $this->logger->info('Firewall configuration applied successfully');
        return true;
    }

    /**
     * Build complete nftables ruleset from configuration.
     *
     * @param array $config Full system configuration
     * @return string Complete .nft ruleset
     */
    private function buildRuleset(array $config): string
    {
        $this->builder->reset();

        $firewallConfig = $config['firewall'] ?? [];
        $servicesConfig = $config['services'] ?? [];
        $networkConfig = $config['network'] ?? [];

        // Initialize interface resolver with network config
        $this->interfaceResolver->loadConfig($networkConfig);

        // Set default policy
        $defaultPolicy = $firewallConfig['default_policy'] ?? 'drop';
        $this->builder->setPolicies($defaultPolicy, $defaultPolicy, 'accept');

        // Build sets for ACLs
        $this->buildSets($firewallConfig, $servicesConfig);

        // Build service ACL rules
        $this->buildServiceAcls($servicesConfig, $firewallConfig);

        // Build ICMP rules
        $this->buildIcmpRules($firewallConfig);

        // Build NAT rules
        $this->buildNatRules($firewallConfig);

        // Build port forward rules
        $this->buildPortForwardRules($firewallConfig);

        // Build user-defined ACLs with interface resolution
        $this->buildUserAcls($firewallConfig, $networkConfig);

        return $this->builder->buildFromConfig($config);
    }

    /**
     * Build named sets from configuration.
     *
     * @param array $firewallConfig Firewall configuration
     * @param array $servicesConfig Services configuration
     */
    private function buildSets(array $firewallConfig, array $servicesConfig): void
    {
        // Delegate to SetManager for set definitions
        $sets = $this->setManager->buildSetDefinitions([
            'services' => $servicesConfig,
            'firewall' => $firewallConfig,
        ]);

        // Add sets to the ruleset builder
        foreach ($sets as $name => $setDef) {
            $this->builder->addSet(
                $name,
                $setDef['type'],
                $setDef['flags'] ?? ['interval'],
                $setDef['elements'] ?? []
            );
        }
    }

    /**
     * Build service-specific ACL rules.
     *
     * @param array $servicesConfig Services configuration
     * @param array $firewallConfig Firewall configuration
     */
    private function buildServiceAcls(array $servicesConfig, array $firewallConfig = []): void
    {
        // Delegate to ServiceAclService
        $chainRules = $this->serviceAcl->buildServiceAcls($servicesConfig, $firewallConfig);

        // Add all chain rules to the builder
        foreach ($chainRules as $chainName => $rules) {
            $this->builder->addChainRules('inet filter', $chainName, $rules);
        }
    }

    /**
     * Build ICMP rules.
     *
     * @param array $firewallConfig Firewall configuration
     */
    private function buildIcmpRules(array $firewallConfig): void
    {
        $icmpConfig = $firewallConfig['icmp'] ?? [];

        // Delegate to IcmpService for granular rule generation
        $icmpRules = $this->icmpService->buildIcmpRules($icmpConfig);

        $this->builder->addChainRules('inet filter', 'icmp-rules', $icmpRules);
    }

    /**
     * Build NAT/masquerade rules.
     *
     * @param array $firewallConfig Firewall configuration
     */
    private function buildNatRules(array $firewallConfig): void
    {
        $natConfig = $firewallConfig['nat'] ?? [];

        // NAT bypass rules
        $bypassRules = [];
        foreach ($natConfig['bypass'] ?? [] as $bypass) {
            $src = $bypass['source'] ?? '';
            $dst = $bypass['destination'] ?? '';
            if ($src && $dst) {
                $bypassRules[] = "ip saddr {$src} ip daddr {$dst} return";
            }
        }
        if (!empty($bypassRules)) {
            $this->builder->addChainRules('inet nat', 'postrouting-bypass', $bypassRules);
        }

        // Masquerade rules
        $masqRules = [];
        foreach ($natConfig['masquerade'] ?? [] as $masq) {
            $interface = $masq['interface'] ?? '';
            $source = $masq['source'] ?? '';

            if ($interface) {
                $rule = "oifname \"{$interface}\"";
                if ($source) {
                    $rule .= " ip saddr {$source}";
                }
                $rule .= ' masquerade';
                $masqRules[] = $rule;
            }
        }

        // Legacy format support (array of interface=>source)
        if (empty($masqRules) && is_array($natConfig) && !isset($natConfig['masquerade'])) {
            foreach ($natConfig as $nat) {
                if (is_array($nat) && isset($nat['interface'])) {
                    $interface = $nat['interface'];
                    $source = $nat['source'] ?? '';

                    $rule = "oifname \"{$interface}\"";
                    if ($source) {
                        $rule .= " ip saddr {$source}";
                    }
                    $rule .= ' masquerade';
                    $masqRules[] = $rule;
                }
            }
        }

        if (!empty($masqRules)) {
            $this->builder->addChainRules('inet nat', 'postrouting-masq', $masqRules);
        }
    }

    /**
     * Build port forwarding rules.
     *
     * @param array $firewallConfig Firewall configuration
     */
    private function buildPortForwardRules(array $firewallConfig): void
    {
        $portForwards = $firewallConfig['port_forwards'] ?? [];

        $dnatRules = [];
        $filterRules = [];

        foreach ($portForwards as $fwd) {
            $protocol = $fwd['protocol'] ?? 'tcp';
            $extPort = $fwd['external_port'] ?? null;
            $intIp = $fwd['internal_ip'] ?? null;
            $intPort = $fwd['internal_port'] ?? $extPort;
            $interface = $fwd['interface'] ?? null;

            if (!$extPort || !$intIp) {
                continue;
            }

            // Build DNAT rule
            $dnatRule = '';
            if ($interface) {
                $dnatRule .= "iifname \"{$interface}\" ";
            }
            $dnatRule .= "{$protocol} dport {$extPort} dnat to {$intIp}";
            if ($intPort !== $extPort) {
                $dnatRule .= ":{$intPort}";
            }
            $dnatRules[] = $dnatRule;

            // Build filter ACL to allow forwarded traffic
            $filterRule = "{$protocol} dport {$intPort} ip daddr {$intIp} accept";
            $filterRules[] = $filterRule;
        }

        if (!empty($dnatRules)) {
            $this->builder->addChainRules('inet nat', 'port-forward', $dnatRules);
        }

        if (!empty($filterRules)) {
            $this->builder->addChainRules('inet filter', 'auto-forward-acl', $filterRules);
        }
    }

    /**
     * Build user-defined ACL rules.
     *
     * @param array $firewallConfig Firewall configuration
     */
    private function buildUserAcls(array $firewallConfig, array $networkConfig = []): void
    {
        // Load configuration into ACL binding service
        $this->aclBinding->loadConfig($firewallConfig, $networkConfig);

        // Build ACL chains and bindings with interface resolution
        $chainRules = $this->aclBinding->buildAclBindings();

        // Add all chain rules to the builder
        foreach ($chainRules as $chainName => $rules) {
            $this->builder->addChainRules('inet filter', $chainName, $rules);
        }

        // Direct firewall rules (not ACL-based)
        $directRules = $firewallConfig['rules'] ?? [];
        foreach ($directRules as $rule) {
            $chain = $rule['chain'] ?? 'input';
            $nftRule = $this->buildAclRule($rule);

            $this->builder->addChainRules('inet filter', strtolower($chain), [$nftRule]);
        }
    }

    /**
     * Convert an ACL rule to nftables syntax.
     *
     * @param array $rule ACL rule definition
     * @return string nftables rule string
     */
    private function buildAclRule(array $rule): string
    {
        $parts = [];

        // Protocol
        if (isset($rule['protocol']) && $rule['protocol'] !== 'any') {
            $proto = strtolower($rule['protocol']);
            if ($proto === 'icmp') {
                $parts[] = 'ip protocol icmp';
            } elseif ($proto === 'icmpv6') {
                $parts[] = 'ip6 nexthdr icmpv6';
            } else {
                $parts[] = $proto;
            }
        }

        // Source address
        if (isset($rule['source']) && $rule['source'] !== 'any') {
            $parts[] = "ip saddr {$rule['source']}";
        }

        // Destination address
        if (isset($rule['destination']) && $rule['destination'] !== 'any') {
            $parts[] = "ip daddr {$rule['destination']}";
        }

        // Source port
        if (isset($rule['source_port'])) {
            $parts[] = "sport {$rule['source_port']}";
        }

        // Destination port
        if (isset($rule['port']) || isset($rule['destination_port'])) {
            $port = $rule['port'] ?? $rule['destination_port'];
            $parts[] = "dport {$port}";
        }

        // Interface (for input/output chains)
        if (isset($rule['interface'])) {
            $parts[] = "iifname \"{$rule['interface']}\"";
        }

        // Action
        $action = strtolower($rule['action'] ?? 'accept');
        if ($action === 'deny' || $action === 'reject') {
            $action = 'drop';
        }
        $parts[] = $action;

        return implode(' ', $parts);
    }

    /**
     * Rollback to previous ruleset.
     *
     * @return bool True if successful
     */
    public function rollback(): bool
    {
        if (!file_exists($this->previousRuleset)) {
            $this->logger->warning('No previous ruleset available for rollback');
            return false;
        }

        $this->logger->info('Rolling back to previous ruleset');

        if (!$this->nftables->loadRuleset($this->previousRuleset)) {
            $this->logger->error('Rollback failed');
            return false;
        }

        // Swap current and previous
        $temp = $this->currentRuleset . '.tmp';
        rename($this->currentRuleset, $temp);
        rename($this->previousRuleset, $this->currentRuleset);
        rename($temp, $this->previousRuleset);

        $this->logger->info('Rollback successful');
        return true;
    }

    /**
     * Get firewall status.
     *
     * @return array Status information
     */
    public function getStatus(): array
    {
        return [
            'enabled' => $this->enabled,
            'backend' => 'nftables',
            'version' => $this->nftables->getVersion(),
            'counters' => $this->nftables->getCounters(),
            'connections' => [
                'count' => $this->nftables->getConnectionCount(),
                'max' => $this->nftables->getConnectionMax(),
            ],
        ];
    }

    /**
     * Get active connection count.
     *
     * @return array Connection tracking info
     */
    public function getActiveConnections(): array
    {
        return [
            'count' => $this->nftables->getConnectionCount(),
            'max' => $this->nftables->getConnectionMax(),
        ];
    }

    /**
     * Emergency stop - flush all rules and set permissive policy.
     *
     * @return bool True if successful
     */
    public function emergencyStop(): bool
    {
        $this->logger->warning('Emergency firewall stop initiated');

        // Save current ruleset before flushing
        if ($this->enabled) {
            $this->nftables->saveRuleset($this->previousRuleset);
        }

        $result = $this->nftables->flush();
        $this->enabled = false;

        return $result;
    }

    /**
     * Check if firewall is enabled.
     *
     * @return bool True if enabled
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the nftables service instance.
     *
     * @return NftablesService
     */
    public function getNftablesService(): NftablesService
    {
        return $this->nftables;
    }

    /**
     * Get the ruleset builder instance.
     *
     * @return RulesetBuilder
     */
    public function getRulesetBuilder(): RulesetBuilder
    {
        return $this->builder;
    }

    /**
     * Get the set manager instance.
     *
     * @return SetManager
     */
    public function getSetManager(): SetManager
    {
        return $this->setManager;
    }

    /**
     * Get the service ACL service instance.
     *
     * @return ServiceAclService
     */
    public function getServiceAclService(): ServiceAclService
    {
        return $this->serviceAcl;
    }

    /**
     * Get the ICMP service instance.
     *
     * @return IcmpService
     */
    public function getIcmpService(): IcmpService
    {
        return $this->icmpService;
    }

    /**
     * Get the interface resolver instance.
     *
     * @return InterfaceResolver
     */
    public function getInterfaceResolver(): InterfaceResolver
    {
        return $this->interfaceResolver;
    }

    /**
     * Get the ACL binding service instance.
     *
     * @return AclBindingService
     */
    public function getAclBindingService(): AclBindingService
    {
        return $this->aclBinding;
    }

    /**
     * Resolve an interface name to physical interface(s).
     *
     * Convenience method for interface resolution.
     *
     * @param string $name Interface identifier (role, alias, or physical name)
     * @return array Array of physical interface names
     */
    public function resolveInterface(string $name): array
    {
        return $this->interfaceResolver->resolve($name);
    }

    /**
     * Get WAN interface(s).
     *
     * @return array WAN interface names
     */
    public function getWanInterfaces(): array
    {
        return $this->interfaceResolver->getExternalInterfaces();
    }

    /**
     * Get LAN interface(s).
     *
     * @return array LAN/internal interface names
     */
    public function getLanInterfaces(): array
    {
        return $this->interfaceResolver->getInternalInterfaces();
    }

    /**
     * Add an element to a live set.
     *
     * Convenience method for dynamic set updates.
     *
     * @param string $setName Set name
     * @param string $element Element to add
     * @return bool True if successful
     */
    public function addToSet(string $setName, string $element): bool
    {
        return $this->setManager->addElement($setName, $element);
    }

    /**
     * Remove an element from a live set.
     *
     * Convenience method for dynamic set updates.
     *
     * @param string $setName Set name
     * @param string $element Element to remove
     * @return bool True if successful
     */
    public function removeFromSet(string $setName, string $element): bool
    {
        return $this->setManager->removeElement($setName, $element);
    }

    /**
     * Block a host by adding to blocked_hosts set.
     *
     * @param string $host IP address or CIDR to block
     * @return bool True if successful
     */
    public function blockHost(string $host): bool
    {
        $this->logger->info("Blocking host: {$host}");
        return $this->setManager->addElement('blocked_hosts', $host);
    }

    /**
     * Unblock a host by removing from blocked_hosts set.
     *
     * @param string $host IP address or CIDR to unblock
     * @return bool True if successful
     */
    public function unblockHost(string $host): bool
    {
        $this->logger->info("Unblocking host: {$host}");
        return $this->setManager->removeElement('blocked_hosts', $host);
    }

    /**
     * Get list of blocked hosts.
     *
     * @return array Blocked host addresses
     */
    public function getBlockedHosts(): array
    {
        return $this->setManager->getElements('blocked_hosts');
    }
}
