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

        // Set default policy
        $defaultPolicy = $firewallConfig['default_policy'] ?? 'drop';
        $this->builder->setPolicies($defaultPolicy, $defaultPolicy, 'accept');

        // Build sets for ACLs
        $this->buildSets($firewallConfig, $servicesConfig);

        // Build service ACL rules
        $this->buildServiceAcls($servicesConfig);

        // Build ICMP rules
        $this->buildIcmpRules($firewallConfig);

        // Build NAT rules
        $this->buildNatRules($firewallConfig);

        // Build port forward rules
        $this->buildPortForwardRules($firewallConfig);

        // Build user-defined ACLs
        $this->buildUserAcls($firewallConfig);

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
        // SSH allowed hosts set
        $sshHosts = $servicesConfig['ssh']['allowed_hosts'] ?? [];
        if (!empty($sshHosts)) {
            $this->builder->addSet('ssh_allowed', 'ipv4_addr', ['interval'], $sshHosts);
        }

        // SNMP allowed hosts set
        $snmpHosts = $servicesConfig['snmp']['allowed_hosts'] ?? [];
        if (!empty($snmpHosts)) {
            $this->builder->addSet('snmp_allowed', 'ipv4_addr', ['interval'], $snmpHosts);
        }

        // Blocked hosts set
        $blockedHosts = $firewallConfig['sets']['blocked_hosts'] ?? [];
        if (!empty($blockedHosts)) {
            $this->builder->addSet('blocked_hosts', 'ipv4_addr', ['interval'], $blockedHosts);
        }

        // User-defined sets
        $userSets = $firewallConfig['sets'] ?? [];
        foreach ($userSets as $name => $setConfig) {
            if ($name === 'blocked_hosts') {
                continue; // Already handled
            }
            $type = $setConfig['type'] ?? 'ipv4_addr';
            $flags = $setConfig['flags'] ?? ['interval'];
            $elements = $setConfig['elements'] ?? [];
            $this->builder->addSet($name, $type, $flags, $elements);
        }
    }

    /**
     * Build service-specific ACL rules.
     *
     * @param array $servicesConfig Services configuration
     */
    private function buildServiceAcls(array $servicesConfig): void
    {
        $localAclRules = [];

        // SSH access
        $ssh = $servicesConfig['ssh'] ?? [];
        if ($ssh['enabled'] ?? false) {
            $port = $ssh['port'] ?? 22;
            $localAclRules[] = "tcp dport {$port} jump ssh-hosts";

            // Build ssh-hosts chain rules
            $sshRules = [];
            if (!empty($ssh['allowed_hosts'] ?? [])) {
                $sshRules[] = 'ip saddr @ssh_allowed accept';
            } else {
                // No restrictions - accept all
                $sshRules[] = 'accept';
            }
            $this->builder->addChainRules('inet filter', 'ssh-hosts', $sshRules);
        }

        // SNMP access
        $snmp = $servicesConfig['snmp'] ?? [];
        if ($snmp['enabled'] ?? false) {
            $localAclRules[] = 'udp dport 161 jump snmp-hosts';

            // Build snmp-hosts chain rules
            $snmpRules = [];
            if (!empty($snmp['allowed_hosts'] ?? [])) {
                $snmpRules[] = 'ip saddr @snmp_allowed accept';
            }
            // SNMP requires explicit host list - no fallback accept
            $this->builder->addChainRules('inet filter', 'snmp-hosts', $snmpRules);
        }

        // ICMP
        $localAclRules[] = 'ip protocol icmp jump icmp-rules';
        $localAclRules[] = 'ip6 nexthdr icmpv6 jump icmp-rules';

        // DHCP server access
        $dhcpd = $servicesConfig['dhcpd'] ?? [];
        if ($dhcpd['enabled'] ?? false) {
            $localAclRules[] = 'udp dport { 67, 68 } jump dhcp-server';

            // Build dhcp-server chain rules
            $interface = $dhcpd['interface'] ?? 'lan';
            $dhcpRules = [
                "iifname \"{$interface}\" accept",
            ];
            $this->builder->addChainRules('inet filter', 'dhcp-server', $dhcpRules);
        }

        // UPnP
        $upnp = $servicesConfig['upnp'] ?? [];
        if ($upnp['enabled'] ?? false) {
            $localAclRules[] = 'jump igd-input';
        }

        $this->builder->addChainRules('inet filter', 'coyote-local-acls', $localAclRules);
    }

    /**
     * Build ICMP rules.
     *
     * @param array $firewallConfig Firewall configuration
     */
    private function buildIcmpRules(array $firewallConfig): void
    {
        $icmpConfig = $firewallConfig['icmp'] ?? [];

        $icmpRules = [];

        // Ping (echo-request)
        if ($icmpConfig['allow_ping'] ?? true) {
            $icmpRules[] = 'ip protocol icmp icmp type echo-request accept';
        }

        // Standard allowed types
        $icmpRules[] = 'ip protocol icmp icmp type destination-unreachable accept';
        $icmpRules[] = 'ip protocol icmp icmp type time-exceeded accept';
        $icmpRules[] = 'ip protocol icmp icmp type parameter-problem accept';

        // ICMPv6 (essential for IPv6 operation)
        $icmpRules[] = 'ip6 nexthdr icmpv6 icmpv6 type { echo-request, echo-reply, nd-neighbor-solicit, nd-neighbor-advert, nd-router-solicit, nd-router-advert, destination-unreachable, packet-too-big, time-exceeded, parameter-problem } accept';

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
    private function buildUserAcls(array $firewallConfig): void
    {
        $acls = $firewallConfig['acls'] ?? [];
        $applied = $firewallConfig['applied'] ?? [];

        // First, create ACL chains
        foreach ($acls as $aclName => $aclRules) {
            $chainName = "acl-{$aclName}";
            $rules = [];

            foreach ($aclRules as $rule) {
                $rules[] = $this->buildAclRule($rule);
            }

            // Add return at end of ACL chain
            $rules[] = 'return';

            $this->builder->addChainRules('inet filter', $chainName, $rules);
        }

        // Apply ACL bindings (interface pairs)
        $userAclRules = [];
        foreach ($applied as $binding) {
            $inIf = $binding['in_interface'] ?? '';
            $outIf = $binding['out_interface'] ?? '';
            $acl = $binding['acl'] ?? '';

            if (!$acl) {
                continue;
            }

            $chainName = "acl-{$acl}";
            $rule = '';

            if ($inIf) {
                $rule .= "iifname \"{$inIf}\" ";
            }
            if ($outIf) {
                $rule .= "oifname \"{$outIf}\" ";
            }

            $rule .= "jump {$chainName}";
            $userAclRules[] = $rule;
        }

        if (!empty($userAclRules)) {
            $this->builder->addChainRules('inet filter', 'coyote-user-acls', $userAclRules);
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
}
