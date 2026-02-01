<?php

namespace Coyote\Firewall;

use Coyote\Util\Logger;

/**
 * Manages service-specific firewall ACL rules.
 *
 * Generates nftables rules for system services (SSH, SNMP, DHCP, DNS, UPnP, etc.)
 * and handles the coyote-local-acls chain that routes traffic to service chains.
 */
class ServiceAclService
{
    /** @var Logger */
    private Logger $logger;

    /** @var array Service chain definitions */
    private array $serviceChains = [];

    /** @var array Local ACL routing rules */
    private array $localAclRules = [];

    /**
     * Create a new ServiceAclService instance.
     */
    public function __construct()
    {
        $this->logger = new Logger('service-acl');
    }

    /**
     * Build service ACL rules from configuration.
     *
     * @param array $servicesConfig Services configuration section
     * @param array $firewallConfig Firewall configuration section
     * @return array Chain rules keyed by chain name
     */
    public function buildServiceAcls(array $servicesConfig, array $firewallConfig = []): array
    {
        $this->reset();

        // SSH service
        $this->buildSshAcl($servicesConfig['ssh'] ?? []);

        // SNMP service
        $this->buildSnmpAcl($servicesConfig['snmp'] ?? []);

        // DHCP server
        $this->buildDhcpAcl($servicesConfig['dhcpd'] ?? []);

        // DNS server (dnsmasq)
        $this->buildDnsAcl($servicesConfig['dns'] ?? []);

        // UPnP (miniupnpd)
        $this->buildUpnpAcl($servicesConfig['upnp'] ?? []);

        // Web admin interface
        $this->buildWebAdminAcl($servicesConfig['webadmin'] ?? []);

        // ICMP - always included
        $this->localAclRules[] = 'ip protocol icmp jump icmp-rules';
        $this->localAclRules[] = 'ip6 nexthdr icmpv6 jump icmp-rules';

        // Build the final chain rules array
        $chains = [
            'coyote-local-acls' => $this->localAclRules,
        ];

        foreach ($this->serviceChains as $chainName => $rules) {
            $chains[$chainName] = $rules;
        }

        return $chains;
    }

    /**
     * Build SSH service ACL.
     *
     * @param array $config SSH service configuration
     */
    private function buildSshAcl(array $config): void
    {
        if (!($config['enabled'] ?? false)) {
            // SSH disabled - empty chain
            $this->serviceChains['ssh-hosts'] = [];
            return;
        }

        $port = $config['port'] ?? 22;
        $allowedHosts = $config['allowed_hosts'] ?? [];

        // Route SSH traffic to ssh-hosts chain
        $this->localAclRules[] = "tcp dport {$port} jump ssh-hosts";

        // Build ssh-hosts chain
        $rules = [];

        if (!empty($allowedHosts)) {
            // Restrict to allowed hosts via set
            $rules[] = 'ip saddr @ssh_allowed accept';
            // No fallback - if not in set, continue to default drop
        } else {
            // No restrictions - accept all SSH
            $rules[] = 'accept';
        }

        $this->serviceChains['ssh-hosts'] = $rules;
    }

    /**
     * Build SNMP service ACL.
     *
     * @param array $config SNMP service configuration
     */
    private function buildSnmpAcl(array $config): void
    {
        if (!($config['enabled'] ?? false)) {
            // SNMP disabled - empty chain
            $this->serviceChains['snmp-hosts'] = [];
            return;
        }

        $allowedHosts = $config['allowed_hosts'] ?? [];

        // Route SNMP traffic to snmp-hosts chain
        $this->localAclRules[] = 'udp dport 161 jump snmp-hosts';

        // Build snmp-hosts chain
        $rules = [];

        if (!empty($allowedHosts)) {
            // Restrict to allowed hosts via set
            $rules[] = 'ip saddr @snmp_allowed accept';
        }
        // SNMP always requires explicit host list - no fallback accept

        $this->serviceChains['snmp-hosts'] = $rules;
    }

    /**
     * Build DHCP server ACL.
     *
     * @param array $config DHCP server configuration
     */
    private function buildDhcpAcl(array $config): void
    {
        if (!($config['enabled'] ?? false)) {
            // DHCP disabled - empty chain
            $this->serviceChains['dhcp-server'] = [];
            return;
        }

        // Route DHCP traffic to dhcp-server chain
        $this->localAclRules[] = 'udp dport { 67, 68 } jump dhcp-server';

        // Build dhcp-server chain
        $rules = [];

        // Get interface(s) DHCP should listen on
        $interface = $config['interface'] ?? null;
        $interfaces = $config['interfaces'] ?? [];

        if ($interface && !in_array($interface, $interfaces)) {
            $interfaces[] = $interface;
        }

        if (!empty($interfaces)) {
            foreach ($interfaces as $iface) {
                $rules[] = "iifname \"{$iface}\" accept";
            }
        } else {
            // No interface specified - accept from all (not recommended)
            $this->logger->warning('DHCP server enabled without interface restriction');
            $rules[] = 'accept';
        }

        $this->serviceChains['dhcp-server'] = $rules;
    }

    /**
     * Build DNS server ACL.
     *
     * @param array $config DNS service configuration
     */
    private function buildDnsAcl(array $config): void
    {
        if (!($config['enabled'] ?? false)) {
            return;
        }

        // DNS service rules - allow from LAN interfaces
        $interfaces = $config['interfaces'] ?? [];
        $allowAll = $config['allow_all'] ?? false;

        if ($allowAll) {
            // Accept DNS from anywhere (not recommended for public-facing)
            $this->localAclRules[] = 'udp dport 53 accept';
            $this->localAclRules[] = 'tcp dport 53 accept';
        } elseif (!empty($interfaces)) {
            foreach ($interfaces as $iface) {
                $this->localAclRules[] = "iifname \"{$iface}\" udp dport 53 accept";
                $this->localAclRules[] = "iifname \"{$iface}\" tcp dport 53 accept";
            }
        } else {
            // Default: allow from non-WAN interfaces
            // This would need interface role detection - for now, log warning
            $this->logger->warning('DNS server enabled without interface configuration');
        }
    }

    /**
     * Build UPnP service ACL.
     *
     * @param array $config UPnP service configuration
     */
    private function buildUpnpAcl(array $config): void
    {
        if (!($config['enabled'] ?? false)) {
            // UPnP disabled - empty chains
            $this->serviceChains['igd-input'] = [];
            return;
        }

        // Route UPnP traffic to igd-input chain
        $this->localAclRules[] = 'jump igd-input';

        // Build igd-input chain
        $rules = [];

        $interface = $config['interface'] ?? 'lan';

        // SSDP discovery (UDP 1900)
        $rules[] = "iifname \"{$interface}\" udp dport 1900 accept";

        // UPnP SOAP (TCP, dynamic port - miniupnpd typically uses 5000)
        $port = $config['port'] ?? 5000;
        $rules[] = "iifname \"{$interface}\" tcp dport {$port} accept";

        // NAT-PMP (UDP 5351) if enabled
        if ($config['nat_pmp'] ?? false) {
            $rules[] = "iifname \"{$interface}\" udp dport 5351 accept";
        }

        // PCP (UDP 5351) if enabled - same port as NAT-PMP
        if ($config['pcp'] ?? false) {
            $rules[] = "iifname \"{$interface}\" udp dport 5351 accept";
        }

        $this->serviceChains['igd-input'] = $rules;
    }

    /**
     * Build web admin interface ACL.
     *
     * @param array $config Web admin configuration
     */
    private function buildWebAdminAcl(array $config): void
    {
        $enabled = $config['enabled'] ?? true; // Enabled by default

        if (!$enabled) {
            return;
        }

        $httpPort = $config['http_port'] ?? 80;
        $httpsPort = $config['https_port'] ?? 443;
        $httpsOnly = $config['https_only'] ?? false;
        $interfaces = $config['interfaces'] ?? [];
        $allowedHosts = $config['allowed_hosts'] ?? [];

        // Build access rules
        $ports = [];
        if (!$httpsOnly) {
            $ports[] = $httpPort;
        }
        $ports[] = $httpsPort;
        $portList = implode(', ', $ports);

        if (!empty($allowedHosts)) {
            // Use admin_hosts set if defined
            $this->localAclRules[] = "tcp dport { {$portList} } ip saddr @admin_hosts accept";
        } elseif (!empty($interfaces)) {
            // Restrict to specific interfaces
            foreach ($interfaces as $iface) {
                $this->localAclRules[] = "iifname \"{$iface}\" tcp dport { {$portList} } accept";
            }
        } else {
            // Accept from anywhere (default for initial setup)
            $this->localAclRules[] = "tcp dport { {$portList} } accept";
        }
    }

    /**
     * Get rules for a specific service chain.
     *
     * @param string $chainName Chain name
     * @return array Chain rules
     */
    public function getServiceChainRules(string $chainName): array
    {
        return $this->serviceChains[$chainName] ?? [];
    }

    /**
     * Get the local ACL routing rules.
     *
     * @return array Local ACL rules
     */
    public function getLocalAclRules(): array
    {
        return $this->localAclRules;
    }

    /**
     * Add a custom service rule.
     *
     * @param string $rule nftables rule string
     * @param string|null $chainName Target chain (null for coyote-local-acls)
     */
    public function addServiceRule(string $rule, ?string $chainName = null): void
    {
        if ($chainName === null) {
            $this->localAclRules[] = $rule;
        } else {
            if (!isset($this->serviceChains[$chainName])) {
                $this->serviceChains[$chainName] = [];
            }
            $this->serviceChains[$chainName][] = $rule;
        }
    }

    /**
     * Create a custom service chain.
     *
     * @param string $chainName Chain name
     * @param array $rules Chain rules
     * @param string|null $routingRule Rule to add to coyote-local-acls
     */
    public function createServiceChain(string $chainName, array $rules, ?string $routingRule = null): void
    {
        $this->serviceChains[$chainName] = $rules;

        if ($routingRule !== null) {
            $this->localAclRules[] = $routingRule;
        }
    }

    /**
     * Reset the service ACL state.
     */
    public function reset(): void
    {
        $this->serviceChains = [];
        $this->localAclRules = [];
    }

    /**
     * Get all configured service chains.
     *
     * @return array Service chain names
     */
    public function getServiceChainNames(): array
    {
        return array_keys($this->serviceChains);
    }

    /**
     * Check if a service is configured to allow traffic.
     *
     * @param string $serviceName Service name (ssh, snmp, dhcpd, dns, upnp, webadmin)
     * @param array $config Service configuration
     * @return bool True if service allows traffic
     */
    public function isServiceAccessible(string $serviceName, array $config): bool
    {
        if (!($config['enabled'] ?? false)) {
            return false;
        }

        switch ($serviceName) {
            case 'ssh':
                // SSH is accessible if enabled
                return true;

            case 'snmp':
                // SNMP requires allowed_hosts to be set
                return !empty($config['allowed_hosts'] ?? []);

            case 'dhcpd':
                // DHCP requires interface to be specified
                return !empty($config['interface'] ?? '') || !empty($config['interfaces'] ?? []);

            case 'dns':
                // DNS is accessible if enabled (may need interface config)
                return true;

            case 'upnp':
                // UPnP requires interface to be specified
                return !empty($config['interface'] ?? '');

            case 'webadmin':
                // Web admin is accessible if enabled (default true)
                return $config['enabled'] ?? true;

            default:
                return false;
        }
    }
}
