<?php

namespace Coyote\Firewall;

use Coyote\Util\Logger;

/**
 * NAT service for nftables.
 *
 * Handles masquerade, SNAT, DNAT, and NAT bypass rules
 * with interface resolution support.
 */
class NftNatService
{
    /** @var InterfaceResolver */
    private InterfaceResolver $resolver;

    /** @var Logger */
    private Logger $logger;

    /** @var array Masquerade rules */
    private array $masqueradeRules = [];

    /** @var array SNAT rules */
    private array $snatRules = [];

    /** @var array DNAT/port forward rules */
    private array $dnatRules = [];

    /** @var array NAT bypass rules */
    private array $bypassRules = [];

    /** @var array Filter rules for forwarded traffic */
    private array $forwardAclRules = [];

    /**
     * Create a new NftNatService instance.
     *
     * @param InterfaceResolver|null $resolver Optional resolver instance
     */
    public function __construct(?InterfaceResolver $resolver = null)
    {
        $this->resolver = $resolver ?? new InterfaceResolver();
        $this->logger = new Logger('nft-nat');
    }

    /**
     * Load and process NAT configuration.
     *
     * @param array $firewallConfig Firewall configuration section
     * @param array $networkConfig Network configuration section
     * @return self
     */
    public function loadConfig(array $firewallConfig, array $networkConfig = []): self
    {
        $this->reset();

        if (!empty($networkConfig)) {
            $this->resolver->loadConfig($networkConfig);
        }

        $natConfig = $firewallConfig['nat'] ?? [];

        // Process bypass rules first (they take priority)
        $this->processBypassRules($natConfig['bypass'] ?? []);

        // Process masquerade rules
        $this->processMasqueradeRules($natConfig['masquerade'] ?? [], $natConfig);

        // Process SNAT rules
        $this->processSnatRules($natConfig['snat'] ?? []);

        // Process port forwards (DNAT)
        $this->processPortForwards($firewallConfig['port_forwards'] ?? []);

        return $this;
    }

    /**
     * Build NAT chain rules for RulesetBuilder.
     *
     * @return array Chain rules keyed by chain identifier
     */
    public function buildNatRules(): array
    {
        $chains = [];

        // Postrouting bypass rules
        if (!empty($this->bypassRules)) {
            $chains['inet nat/postrouting-bypass'] = $this->bypassRules;
        }

        // Postrouting masquerade rules
        $postroutingMasq = array_merge($this->masqueradeRules, $this->snatRules);
        if (!empty($postroutingMasq)) {
            $chains['inet nat/postrouting-masq'] = $postroutingMasq;
        }

        // Port forward (DNAT) rules
        if (!empty($this->dnatRules)) {
            $chains['inet nat/port-forward'] = $this->dnatRules;
        }

        // Filter rules for forwarded traffic
        if (!empty($this->forwardAclRules)) {
            $chains['inet filter/auto-forward-acl'] = $this->forwardAclRules;
        }

        return $chains;
    }

    /**
     * Process NAT bypass rules.
     *
     * Bypass rules prevent NAT from being applied to specific traffic,
     * typically used for site-to-site VPN or internal routing.
     *
     * @param array $bypassConfig Bypass rule configurations
     */
    private function processBypassRules(array $bypassConfig): void
    {
        foreach ($bypassConfig as $bypass) {
            $rule = $this->buildBypassRule($bypass);
            if ($rule) {
                $this->bypassRules[] = $rule;
            }
        }
    }

    /**
     * Build a single bypass rule.
     *
     * @param array $config Bypass rule configuration
     * @return string|null nftables rule or null if invalid
     */
    private function buildBypassRule(array $config): ?string
    {
        $source = $config['source'] ?? $config['src'] ?? null;
        $destination = $config['destination'] ?? $config['dst'] ?? null;
        $comment = $config['comment'] ?? null;

        if (!$source || !$destination) {
            $this->logger->warning('NAT bypass rule missing source or destination');
            return null;
        }

        $parts = [];

        // Source network
        $parts[] = "ip saddr {$source}";

        // Destination network
        $parts[] = "ip daddr {$destination}";

        // Comment
        if ($comment) {
            $parts[] = "comment \"{$comment}\"";
        }

        // Return (skip NAT)
        $parts[] = 'return';

        return implode(' ', $parts);
    }

    /**
     * Process masquerade rules.
     *
     * @param array $masqConfig Masquerade configurations
     * @param array $fullNatConfig Full NAT config for legacy format support
     */
    private function processMasqueradeRules(array $masqConfig, array $fullNatConfig = []): void
    {
        // Handle new format
        foreach ($masqConfig as $masq) {
            $rule = $this->buildMasqueradeRule($masq);
            if ($rule) {
                $this->masqueradeRules[] = $rule;
            }
        }

        // Handle legacy format (array without 'masquerade' key)
        if (empty($masqConfig) && !isset($fullNatConfig['masquerade'])) {
            foreach ($fullNatConfig as $key => $nat) {
                if (is_numeric($key) && is_array($nat) && isset($nat['interface'])) {
                    $rule = $this->buildMasqueradeRule($nat);
                    if ($rule) {
                        $this->masqueradeRules[] = $rule;
                    }
                }
            }
        }
    }

    /**
     * Build a single masquerade rule.
     *
     * @param array $config Masquerade configuration
     * @return string|null nftables rule or null if invalid
     */
    private function buildMasqueradeRule(array $config): ?string
    {
        $interface = $config['interface'] ?? $config['out_interface'] ?? null;
        $source = $config['source'] ?? $config['src'] ?? null;
        $enabled = $config['enabled'] ?? true;
        $comment = $config['comment'] ?? null;

        if (!$enabled) {
            return null;
        }

        if (!$interface) {
            $this->logger->warning('Masquerade rule missing interface');
            return null;
        }

        $parts = [];

        // Resolve interface (supports roles like 'wan')
        $interfaces = $this->resolver->resolve($interface);
        if (empty($interfaces)) {
            $interfaces = [$interface];
        }

        // Build rule for each resolved interface
        $rules = [];
        foreach ($interfaces as $iface) {
            $ruleParts = [];
            $ruleParts[] = "oifname \"{$iface}\"";

            // Source restriction
            if ($source) {
                $ruleParts[] = "ip saddr {$source}";
            }

            // Comment
            if ($comment) {
                $ruleParts[] = "comment \"{$comment}\"";
            }

            // Masquerade action
            $ruleParts[] = 'masquerade';

            $rules[] = implode(' ', $ruleParts);
        }

        // Return first rule (typically only one WAN interface)
        return $rules[0] ?? null;
    }

    /**
     * Process SNAT rules.
     *
     * @param array $snatConfig SNAT configurations
     */
    private function processSnatRules(array $snatConfig): void
    {
        foreach ($snatConfig as $snat) {
            $rule = $this->buildSnatRule($snat);
            if ($rule) {
                $this->snatRules[] = $rule;
            }
        }
    }

    /**
     * Build a single SNAT rule.
     *
     * @param array $config SNAT configuration
     * @return string|null nftables rule or null if invalid
     */
    private function buildSnatRule(array $config): ?string
    {
        $interface = $config['interface'] ?? $config['out_interface'] ?? null;
        $source = $config['source'] ?? $config['src'] ?? null;
        $toAddress = $config['to_address'] ?? $config['snat_to'] ?? null;
        $enabled = $config['enabled'] ?? true;
        $comment = $config['comment'] ?? null;

        if (!$enabled) {
            return null;
        }

        if (!$toAddress) {
            $this->logger->warning('SNAT rule missing to_address');
            return null;
        }

        $parts = [];

        // Output interface
        if ($interface) {
            $interfaces = $this->resolver->resolve($interface);
            $iface = $interfaces[0] ?? $interface;
            $parts[] = "oifname \"{$iface}\"";
        }

        // Source restriction
        if ($source) {
            $parts[] = "ip saddr {$source}";
        }

        // Comment
        if ($comment) {
            $parts[] = "comment \"{$comment}\"";
        }

        // SNAT action
        $parts[] = "snat to {$toAddress}";

        return implode(' ', $parts);
    }

    /**
     * Process port forward (DNAT) rules.
     *
     * @param array $portForwards Port forward configurations
     */
    private function processPortForwards(array $portForwards): void
    {
        foreach ($portForwards as $fwd) {
            $rules = $this->buildPortForwardRules($fwd);
            if (!empty($rules['dnat'])) {
                $this->dnatRules[] = $rules['dnat'];
            }
            if (!empty($rules['filter'])) {
                $this->forwardAclRules[] = $rules['filter'];
            }
        }
    }

    /**
     * Build DNAT and filter rules for a port forward.
     *
     * @param array $config Port forward configuration
     * @return array Array with 'dnat' and 'filter' rules
     */
    private function buildPortForwardRules(array $config): array
    {
        $result = ['dnat' => null, 'filter' => null];

        $protocol = strtolower($config['protocol'] ?? 'tcp');
        $externalPort = $config['external_port'] ?? $config['port'] ?? null;
        $internalIp = $config['internal_ip'] ?? $config['destination'] ?? null;
        $internalPort = $config['internal_port'] ?? $externalPort;
        $interface = $config['interface'] ?? $config['in_interface'] ?? null;
        $sourceRestrict = $config['source'] ?? $config['allowed_sources'] ?? null;
        $enabled = $config['enabled'] ?? true;
        $comment = $config['comment'] ?? $config['description'] ?? null;

        if (!$enabled) {
            return $result;
        }

        if (!$externalPort || !$internalIp) {
            $this->logger->warning('Port forward missing external_port or internal_ip');
            return $result;
        }

        // Build DNAT rule
        $dnatParts = [];

        // Input interface
        if ($interface) {
            $interfaces = $this->resolver->resolve($interface);
            $iface = $interfaces[0] ?? $interface;
            $dnatParts[] = "iifname \"{$iface}\"";
        }

        // Source restriction
        if ($sourceRestrict) {
            if (is_array($sourceRestrict)) {
                $sourceRestrict = implode(', ', $sourceRestrict);
                $dnatParts[] = "ip saddr { {$sourceRestrict} }";
            } else {
                $dnatParts[] = "ip saddr {$sourceRestrict}";
            }
        }

        // Protocol and port
        $dnatParts[] = "{$protocol} dport {$externalPort}";

        // Comment
        if ($comment) {
            $dnatParts[] = "comment \"{$comment}\"";
        }

        // DNAT action
        $dnatTarget = $internalIp;
        if ($internalPort != $externalPort) {
            $dnatTarget .= ":{$internalPort}";
        }
        $dnatParts[] = "dnat to {$dnatTarget}";

        $result['dnat'] = implode(' ', $dnatParts);

        // Build filter rule to allow forwarded traffic
        $filterParts = [];
        $filterParts[] = "{$protocol} dport {$internalPort}";
        $filterParts[] = "ip daddr {$internalIp}";

        // Source restriction in filter too
        if ($sourceRestrict) {
            if (is_array($sourceRestrict)) {
                $sourceRestrict = implode(', ', $sourceRestrict);
                $filterParts[] = "ip saddr { {$sourceRestrict} }";
            } else {
                $filterParts[] = "ip saddr {$sourceRestrict}";
            }
        }

        $filterParts[] = 'accept';

        $result['filter'] = implode(' ', $filterParts);

        return $result;
    }

    /**
     * Add a masquerade rule dynamically.
     *
     * @param string $interface Output interface
     * @param string|null $source Source network (CIDR)
     * @return self
     */
    public function addMasquerade(string $interface, ?string $source = null): self
    {
        $config = [
            'interface' => $interface,
            'source' => $source,
        ];

        $rule = $this->buildMasqueradeRule($config);
        if ($rule) {
            $this->masqueradeRules[] = $rule;
        }

        return $this;
    }

    /**
     * Add a port forward dynamically.
     *
     * @param string $protocol Protocol (tcp, udp)
     * @param int $externalPort External port
     * @param string $internalIp Internal IP address
     * @param int|null $internalPort Internal port (defaults to external)
     * @param string|null $interface Input interface
     * @return self
     */
    public function addPortForward(
        string $protocol,
        int $externalPort,
        string $internalIp,
        ?int $internalPort = null,
        ?string $interface = null
    ): self {
        $config = [
            'protocol' => $protocol,
            'external_port' => $externalPort,
            'internal_ip' => $internalIp,
            'internal_port' => $internalPort ?? $externalPort,
            'interface' => $interface,
        ];

        $rules = $this->buildPortForwardRules($config);
        if ($rules['dnat']) {
            $this->dnatRules[] = $rules['dnat'];
        }
        if ($rules['filter']) {
            $this->forwardAclRules[] = $rules['filter'];
        }

        return $this;
    }

    /**
     * Add a NAT bypass rule dynamically.
     *
     * @param string $source Source network (CIDR)
     * @param string $destination Destination network (CIDR)
     * @param string|null $comment Optional comment
     * @return self
     */
    public function addBypass(string $source, string $destination, ?string $comment = null): self
    {
        $config = [
            'source' => $source,
            'destination' => $destination,
            'comment' => $comment,
        ];

        $rule = $this->buildBypassRule($config);
        if ($rule) {
            $this->bypassRules[] = $rule;
        }

        return $this;
    }

    /**
     * Get all masquerade rules.
     *
     * @return array Masquerade rules
     */
    public function getMasqueradeRules(): array
    {
        return $this->masqueradeRules;
    }

    /**
     * Get all port forward rules.
     *
     * @return array DNAT rules
     */
    public function getPortForwardRules(): array
    {
        return $this->dnatRules;
    }

    /**
     * Get all bypass rules.
     *
     * @return array Bypass rules
     */
    public function getBypassRules(): array
    {
        return $this->bypassRules;
    }

    /**
     * Get filter rules for forwarded traffic.
     *
     * @return array Filter rules
     */
    public function getForwardAclRules(): array
    {
        return $this->forwardAclRules;
    }

    /**
     * Reset all rules.
     *
     * @return self
     */
    public function reset(): self
    {
        $this->masqueradeRules = [];
        $this->snatRules = [];
        $this->dnatRules = [];
        $this->bypassRules = [];
        $this->forwardAclRules = [];

        return $this;
    }

    /**
     * Get the interface resolver.
     *
     * @return InterfaceResolver
     */
    public function getResolver(): InterfaceResolver
    {
        return $this->resolver;
    }

    /**
     * Set the interface resolver.
     *
     * @param InterfaceResolver $resolver
     * @return self
     */
    public function setResolver(InterfaceResolver $resolver): self
    {
        $this->resolver = $resolver;
        return $this;
    }

    /**
     * Validate a port forward configuration.
     *
     * @param array $config Port forward configuration
     * @return array Validation errors (empty if valid)
     */
    public function validatePortForward(array $config): array
    {
        $errors = [];

        $protocol = strtolower($config['protocol'] ?? 'tcp');
        if (!in_array($protocol, ['tcp', 'udp', 'both'])) {
            $errors[] = "Invalid protocol: {$protocol}";
        }

        $externalPort = $config['external_port'] ?? $config['port'] ?? null;
        if (!$externalPort) {
            $errors[] = 'External port is required';
        } elseif (!is_numeric($externalPort) || $externalPort < 1 || $externalPort > 65535) {
            $errors[] = 'External port must be between 1 and 65535';
        }

        $internalIp = $config['internal_ip'] ?? $config['destination'] ?? null;
        if (!$internalIp) {
            $errors[] = 'Internal IP is required';
        } elseif (!filter_var($internalIp, FILTER_VALIDATE_IP)) {
            $errors[] = 'Invalid internal IP address';
        }

        $internalPort = $config['internal_port'] ?? $externalPort;
        if ($internalPort && (!is_numeric($internalPort) || $internalPort < 1 || $internalPort > 65535)) {
            $errors[] = 'Internal port must be between 1 and 65535';
        }

        return $errors;
    }
}
