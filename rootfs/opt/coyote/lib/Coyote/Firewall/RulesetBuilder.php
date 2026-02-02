<?php

namespace Coyote\Firewall;

/**
 * Builds complete nftables ruleset files from configuration.
 *
 * Generates atomic .nft files that can be loaded with `nft -f`.
 * Supports the inet family for unified IPv4/IPv6 handling.
 */
class RulesetBuilder
{
    /** @var array Set definitions */
    private array $sets = [];

    /** @var array Chain definitions and rules */
    private array $chains = [];

    /** @var array Custom chain rules (keyed by "table/chain") */
    private array $chainRules = [];

    /** @var string Default INPUT policy */
    private string $inputPolicy = 'drop';

    /** @var string Default FORWARD policy */
    private string $forwardPolicy = 'drop';

    /** @var string Default OUTPUT policy */
    private string $outputPolicy = 'accept';

    /** @var array Rules for INPUT chain final section */
    private array $inputFinal = ['drop'];

    /** @var array Rules for FORWARD chain final section */
    private array $forwardFinal = ['drop'];

    /** @var string|null MSS clamp rule */
    private ?string $mssClamp = null;

    /** @var string|null Invalid packet action */
    private ?string $invalidAction = 'drop';

    /** @var bool Whether to include mangle table for QoS */
    private bool $includeMangle = false;

    /** @var array Mangle table rules */
    private array $mangleRules = [];

    /**
     * Build complete ruleset from firewall config.
     *
     * @param array $config Full system configuration
     * @return string Complete .nft ruleset content
     */
    public function buildFromConfig(array $config): string
    {
        // NOTE: Do NOT call reset() here - sets and rules may have been added
        // by FirewallManager before calling this method. Use reset() explicitly
        // when starting a fresh build.

        $firewallConfig = $config['firewall'] ?? [];

        // Set policies
        $defaultPolicy = strtolower($firewallConfig['default_policy'] ?? 'drop');
        $this->inputPolicy = $defaultPolicy;
        $this->forwardPolicy = $defaultPolicy;

        // Process options
        $options = $firewallConfig['options'] ?? [];
        if (isset($options['clamp_mss'])) {
            $this->setMssClamp($options['clamp_mss']);
        }
        if (isset($options['log_invalid']) && $options['log_invalid']) {
            $this->invalidAction = 'log prefix "NFT-INVALID: " drop';
        }

        // Process logging
        $logging = $firewallConfig['logging'] ?? [];
        if ($logging['enabled'] ?? false) {
            $prefix = $logging['prefix'] ?? 'COYOTE';
            $level = $logging['level'] ?? 'info';

            if ($logging['local_deny'] ?? false) {
                $this->inputFinal = [
                    "log prefix \"{$prefix}-DROP-LOCAL: \" level {$level}",
                    'drop',
                ];
            }
            if ($logging['forward_deny'] ?? false) {
                $this->forwardFinal = [
                    "log prefix \"{$prefix}-DROP-FWD: \" level {$level}",
                    'drop',
                ];
            }
        }

        return $this->generate();
    }

    /**
     * Reset builder state.
     */
    public function reset(): void
    {
        $this->sets = [];
        $this->chains = [];
        $this->chainRules = [];
        $this->inputPolicy = 'drop';
        $this->forwardPolicy = 'drop';
        $this->outputPolicy = 'accept';
        $this->inputFinal = ['drop'];
        $this->forwardFinal = ['drop'];
        $this->mssClamp = null;
        $this->invalidAction = 'drop';
        $this->includeMangle = false;
        $this->mangleRules = [];
    }

    /**
     * Add a named set definition.
     *
     * @param string $name Set name
     * @param string $type Set type (ipv4_addr, ipv6_addr, inet_service, ifname, etc.)
     * @param array $flags Set flags (interval, timeout, etc.)
     * @param array $elements Initial elements
     * @return self
     */
    public function addSet(string $name, string $type, array $flags = [], array $elements = []): self
    {
        $this->sets[$name] = [
            'type' => $type,
            'flags' => $flags,
            'elements' => $elements,
        ];

        return $this;
    }

    /**
     * Add rules to a chain.
     *
     * @param string $table Table identifier (e.g., "inet filter", "inet nat")
     * @param string $chain Chain name
     * @param array $rules Array of rule strings
     * @return self
     */
    public function addChainRules(string $table, string $chain, array $rules): self
    {
        $key = "{$table}/{$chain}";

        if (!isset($this->chainRules[$key])) {
            $this->chainRules[$key] = [];
        }

        $this->chainRules[$key] = array_merge($this->chainRules[$key], $rules);

        return $this;
    }

    /**
     * Set the INPUT chain final rules.
     *
     * @param array $rules Final rules (typically log + drop)
     * @return self
     */
    public function setInputFinal(array $rules): self
    {
        $this->inputFinal = $rules;
        return $this;
    }

    /**
     * Set the FORWARD chain final rules.
     *
     * @param array $rules Final rules (typically log + drop)
     * @return self
     */
    public function setForwardFinal(array $rules): self
    {
        $this->forwardFinal = $rules;
        return $this;
    }

    /**
     * Set MSS clamping rule.
     *
     * @param string $value "pmtu" or specific MSS value
     * @return self
     */
    public function setMssClamp(string $value): self
    {
        if ($value === 'pmtu') {
            $this->mssClamp = 'tcp flags syn / syn,rst tcp option maxseg size set rt mtu';
        } else {
            $this->mssClamp = "tcp flags syn / syn,rst tcp option maxseg size set {$value}";
        }

        return $this;
    }

    /**
     * Set default policies.
     *
     * @param string $input INPUT policy
     * @param string $forward FORWARD policy
     * @param string $output OUTPUT policy
     * @return self
     */
    public function setPolicies(string $input, string $forward, string $output = 'accept'): self
    {
        $this->inputPolicy = strtolower($input);
        $this->forwardPolicy = strtolower($forward);
        $this->outputPolicy = strtolower($output);

        return $this;
    }

    /**
     * Enable mangle table for QoS marking.
     *
     * @param array $rules Mangle rules
     * @return self
     */
    public function enableMangle(array $rules): self
    {
        $this->includeMangle = true;
        $this->mangleRules = $rules;

        return $this;
    }

    /**
     * Generate the complete .nft file content.
     *
     * @return string Complete ruleset
     */
    public function generate(): string
    {
        $lines = [];

        // Header
        $lines[] = '#!/usr/sbin/nft -f';
        $lines[] = '# Coyote Linux 4 Firewall Ruleset';
        $lines[] = '# Generated: ' . date('Y-m-d H:i:s');
        $lines[] = '# DO NOT EDIT - This file is auto-generated';
        $lines[] = '';
        $lines[] = 'flush ruleset';
        $lines[] = '';

        // Filter table
        $lines[] = $this->generateFilterTable();

        // NAT table
        $lines[] = $this->generateNatTable();

        // Mangle table (optional)
        if ($this->includeMangle) {
            $lines[] = $this->generateMangleTable();
        }

        return implode("\n", $lines);
    }

    /**
     * Generate the inet filter table.
     *
     * @return string Filter table definition
     */
    private function generateFilterTable(): string
    {
        $lines = [];
        $lines[] = 'table inet filter {';

        // Sets
        $lines[] = $this->generateSets();

        // Base chains
        $lines[] = $this->generateInputChain();
        $lines[] = $this->generateForwardChain();
        $lines[] = $this->generateOutputChain();

        // Service chains
        $lines[] = $this->generateServiceChains();

        // Custom chains from chainRules
        $lines[] = $this->generateCustomChains('inet filter');

        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Generate set definitions.
     *
     * @return string Set definitions
     */
    private function generateSets(): string
    {
        if (empty($this->sets)) {
            return '    # No sets defined';
        }

        $lines = [];
        $lines[] = '    # === SETS ===';

        foreach ($this->sets as $name => $set) {
            $lines[] = "    set {$name} {";
            $lines[] = "        type {$set['type']}";

            if (!empty($set['flags'])) {
                $lines[] = '        flags ' . implode(', ', $set['flags']);
            }

            if (!empty($set['elements'])) {
                $elements = array_map(function ($el) {
                    // Quote strings that need it, leave IPs/CIDRs as-is
                    if (preg_match('/^[a-zA-Z]/', $el) && !preg_match('/[\/\.]/', $el)) {
                        return "\"{$el}\"";
                    }
                    return $el;
                }, $set['elements']);

                $lines[] = '        elements = { ' . implode(', ', $elements) . ' }';
            }

            $lines[] = '    }';
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Generate the INPUT chain.
     *
     * @return string INPUT chain definition
     */
    private function generateInputChain(): string
    {
        $lines = [];
        $lines[] = '    # === INPUT CHAIN ===';
        $lines[] = '    chain input {';
        $lines[] = "        type filter hook input priority 0; policy {$this->inputPolicy};";
        $lines[] = '';
        $lines[] = '        # Connection tracking';
        $lines[] = '        ct state established,related accept';
        $lines[] = "        ct state invalid {$this->invalidAction}";
        $lines[] = '';
        $lines[] = '        # Loopback';
        $lines[] = '        iif lo accept';
        $lines[] = '';
        $lines[] = '        # Service ACLs';
        $lines[] = '        jump coyote-local-acls';
        $lines[] = '';

        // Custom INPUT rules
        $inputRules = $this->chainRules['inet filter/input'] ?? [];
        if (!empty($inputRules)) {
            $lines[] = '        # Custom rules';
            foreach ($inputRules as $rule) {
                $lines[] = "        {$rule}";
            }
            $lines[] = '';
        }

        // Final rules
        $lines[] = '        # Final action';
        foreach ($this->inputFinal as $rule) {
            $lines[] = "        {$rule}";
        }

        $lines[] = '    }';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Generate the FORWARD chain.
     *
     * @return string FORWARD chain definition
     */
    private function generateForwardChain(): string
    {
        $lines = [];
        $lines[] = '    # === FORWARD CHAIN ===';
        $lines[] = '    chain forward {';
        $lines[] = "        type filter hook forward priority 0; policy {$this->forwardPolicy};";
        $lines[] = '';
        $lines[] = '        # Connection tracking';
        $lines[] = '        ct state established,related accept';
        $lines[] = "        ct state invalid {$this->invalidAction}";
        $lines[] = '';

        // MSS clamping
        if ($this->mssClamp) {
            $lines[] = '        # MSS clamping';
            $lines[] = "        {$this->mssClamp}";
            $lines[] = '';
        }

        $lines[] = '        # UPnP dynamic rules';
        $lines[] = '        jump igd-forward';
        $lines[] = '';
        $lines[] = '        # Port forward ACLs';
        $lines[] = '        jump auto-forward-acl';
        $lines[] = '';
        $lines[] = '        # User ACLs';
        $lines[] = '        jump coyote-user-acls';
        $lines[] = '';

        // Custom FORWARD rules
        $forwardRules = $this->chainRules['inet filter/forward'] ?? [];
        if (!empty($forwardRules)) {
            $lines[] = '        # Custom rules';
            foreach ($forwardRules as $rule) {
                $lines[] = "        {$rule}";
            }
            $lines[] = '';
        }

        // Final rules
        $lines[] = '        # Final action';
        foreach ($this->forwardFinal as $rule) {
            $lines[] = "        {$rule}";
        }

        $lines[] = '    }';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Generate the OUTPUT chain.
     *
     * @return string OUTPUT chain definition
     */
    private function generateOutputChain(): string
    {
        $lines = [];
        $lines[] = '    # === OUTPUT CHAIN ===';
        $lines[] = '    chain output {';
        $lines[] = "        type filter hook output priority 0; policy {$this->outputPolicy};";
        $lines[] = '    }';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Generate service chains (SSH, SNMP, ICMP, DHCP, UPnP).
     *
     * @return string Service chain definitions
     */
    private function generateServiceChains(): string
    {
        $lines = [];
        $lines[] = '    # === SERVICE CHAINS ===';

        // coyote-local-acls - routes to service-specific chains
        $lines[] = '    chain coyote-local-acls {';
        $localAclRules = $this->chainRules['inet filter/coyote-local-acls'] ?? [];
        foreach ($localAclRules as $rule) {
            $lines[] = "        {$rule}";
        }
        $lines[] = '    }';
        $lines[] = '';

        // coyote-user-acls - user-defined forwarding rules
        $lines[] = '    chain coyote-user-acls {';
        $userAclRules = $this->chainRules['inet filter/coyote-user-acls'] ?? [];
        foreach ($userAclRules as $rule) {
            $lines[] = "        {$rule}";
        }
        $lines[] = '    }';
        $lines[] = '';

        // SSH hosts
        $lines[] = '    chain ssh-hosts {';
        $sshRules = $this->chainRules['inet filter/ssh-hosts'] ?? [];
        foreach ($sshRules as $rule) {
            $lines[] = "        {$rule}";
        }
        $lines[] = '    }';
        $lines[] = '';

        // Web admin hosts
        $lines[] = '    chain webadmin-hosts {';
        $webadminRules = $this->chainRules['inet filter/webadmin-hosts'] ?? [];
        foreach ($webadminRules as $rule) {
            $lines[] = "        {$rule}";
        }
        $lines[] = '    }';
        $lines[] = '';

        // SNMP hosts
        $lines[] = '    chain snmp-hosts {';
        $snmpRules = $this->chainRules['inet filter/snmp-hosts'] ?? [];
        foreach ($snmpRules as $rule) {
            $lines[] = "        {$rule}";
        }
        $lines[] = '    }';
        $lines[] = '';

        // ICMP rules
        $lines[] = '    chain icmp-rules {';
        $icmpRules = $this->chainRules['inet filter/icmp-rules'] ?? [];
        if (empty($icmpRules)) {
            // Default ICMP rules
            $lines[] = '        # Default ICMP rules';
            $lines[] = '        ip protocol icmp icmp type echo-request accept';
            $lines[] = '        ip protocol icmp icmp type destination-unreachable accept';
            $lines[] = '        ip protocol icmp icmp type time-exceeded accept';
            $lines[] = '        # ICMPv6 (required for IPv6 operation)';
            $lines[] = '        ip6 nexthdr icmpv6 icmpv6 type { echo-request, echo-reply, nd-neighbor-solicit, nd-neighbor-advert, nd-router-solicit, nd-router-advert, destination-unreachable, packet-too-big, time-exceeded, parameter-problem } accept';
        } else {
            foreach ($icmpRules as $rule) {
                $lines[] = "        {$rule}";
            }
        }
        $lines[] = '    }';
        $lines[] = '';

        // DHCP server
        $lines[] = '    chain dhcp-server {';
        $dhcpRules = $this->chainRules['inet filter/dhcp-server'] ?? [];
        foreach ($dhcpRules as $rule) {
            $lines[] = "        {$rule}";
        }
        $lines[] = '    }';
        $lines[] = '';

        // UPnP chains (populated dynamically by miniupnpd)
        $lines[] = '    chain igd-forward {';
        $lines[] = '        # Populated dynamically by miniupnpd';
        $igdFwdRules = $this->chainRules['inet filter/igd-forward'] ?? [];
        foreach ($igdFwdRules as $rule) {
            $lines[] = "        {$rule}";
        }
        $lines[] = '    }';
        $lines[] = '';

        $lines[] = '    chain igd-input {';
        $lines[] = '        # Populated dynamically by miniupnpd';
        $igdInputRules = $this->chainRules['inet filter/igd-input'] ?? [];
        foreach ($igdInputRules as $rule) {
            $lines[] = "        {$rule}";
        }
        $lines[] = '    }';
        $lines[] = '';

        // Auto-forward ACL
        $lines[] = '    chain auto-forward-acl {';
        $autoFwdRules = $this->chainRules['inet filter/auto-forward-acl'] ?? [];
        foreach ($autoFwdRules as $rule) {
            $lines[] = "        {$rule}";
        }
        $lines[] = '    }';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Generate custom chains for a specific table.
     *
     * @param string $table Table name (e.g., "inet filter")
     * @return string Custom chain definitions
     */
    private function generateCustomChains(string $table): string
    {
        $lines = [];
        $prefix = "{$table}/";

        // Find chains that aren't built-in
        $builtinChains = [
            'input', 'forward', 'output',
            'coyote-local-acls', 'coyote-user-acls',
            'ssh-hosts', 'webadmin-hosts', 'snmp-hosts', 'icmp-rules', 'dhcp-server',
            'igd-forward', 'igd-input', 'auto-forward-acl',
        ];

        $customChains = [];
        foreach ($this->chainRules as $key => $rules) {
            if (strpos($key, $prefix) !== 0) {
                continue;
            }

            $chainName = substr($key, strlen($prefix));
            if (in_array($chainName, $builtinChains)) {
                continue;
            }

            $customChains[$chainName] = $rules;
        }

        if (empty($customChains)) {
            return '    # No custom chains';
        }

        $lines[] = '    # === CUSTOM CHAINS ===';

        foreach ($customChains as $name => $rules) {
            $lines[] = "    chain {$name} {";
            foreach ($rules as $rule) {
                $lines[] = "        {$rule}";
            }
            $lines[] = '    }';
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Generate the inet nat table.
     *
     * @return string NAT table definition
     */
    private function generateNatTable(): string
    {
        $lines = [];
        $lines[] = 'table inet nat {';

        // Prerouting chain
        $lines[] = '    chain prerouting {';
        $lines[] = '        type nat hook prerouting priority -100;';
        $lines[] = '';
        $lines[] = '        # UPnP dynamic rules';
        $lines[] = '        jump igd-preroute';
        $lines[] = '';
        $lines[] = '        # Port forwards';
        $lines[] = '        jump port-forward';
        $lines[] = '';
        $lines[] = '        # Auto forwards';
        $lines[] = '        jump auto-forward';
        $lines[] = '    }';
        $lines[] = '';

        // Postrouting chain
        $lines[] = '    chain postrouting {';
        $lines[] = '        type nat hook postrouting priority 100;';
        $lines[] = '';

        // NAT bypass rules
        $bypassRules = $this->chainRules['inet nat/postrouting-bypass'] ?? [];
        if (!empty($bypassRules)) {
            $lines[] = '        # NAT bypass rules';
            foreach ($bypassRules as $rule) {
                $lines[] = "        {$rule}";
            }
            $lines[] = '';
        }

        // Masquerade rules
        $masqRules = $this->chainRules['inet nat/postrouting-masq'] ?? [];
        if (!empty($masqRules)) {
            $lines[] = '        # Masquerade rules';
            foreach ($masqRules as $rule) {
                $lines[] = "        {$rule}";
            }
        }

        $lines[] = '    }';
        $lines[] = '';

        // Port forward chain
        $lines[] = '    chain port-forward {';
        $pfRules = $this->chainRules['inet nat/port-forward'] ?? [];
        foreach ($pfRules as $rule) {
            $lines[] = "        {$rule}";
        }
        $lines[] = '    }';
        $lines[] = '';

        // Auto forward chain (for UPnP and dynamic forwards)
        $lines[] = '    chain auto-forward {';
        $lines[] = '        # Populated dynamically by miniupnpd';
        $autoFwdRules = $this->chainRules['inet nat/auto-forward'] ?? [];
        foreach ($autoFwdRules as $rule) {
            $lines[] = "        {$rule}";
        }
        $lines[] = '    }';
        $lines[] = '';

        // UPnP prerouting chain
        $lines[] = '    chain igd-preroute {';
        $lines[] = '        # Populated dynamically by miniupnpd';
        $igdRules = $this->chainRules['inet nat/igd-preroute'] ?? [];
        foreach ($igdRules as $rule) {
            $lines[] = "        {$rule}";
        }
        $lines[] = '    }';

        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Generate the inet mangle table for QoS.
     *
     * @return string Mangle table definition
     */
    private function generateMangleTable(): string
    {
        $lines = [];
        $lines[] = 'table inet mangle {';

        $lines[] = '    chain forward {';
        $lines[] = '        type filter hook forward priority -150;';
        foreach ($this->mangleRules as $rule) {
            $lines[] = "        {$rule}";
        }
        $lines[] = '    }';

        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines);
    }
}
