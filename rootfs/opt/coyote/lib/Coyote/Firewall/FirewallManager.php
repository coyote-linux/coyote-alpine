<?php

namespace Coyote\Firewall;

use Coyote\Util\Logger;

/**
 * Central firewall management for Coyote Linux.
 *
 * Orchestrates all firewall-related services including iptables,
 * NAT, ACLs, and QoS. Handles configuration application and status reporting.
 */
class FirewallManager
{
    /** @var IptablesService */
    private IptablesService $iptables;

    /** @var NatService */
    private NatService $nat;

    /** @var AclService */
    private AclService $acl;

    /** @var QosService */
    private QosService $qos;

    /** @var Logger */
    private Logger $logger;

    /** @var bool Whether the firewall is enabled */
    private bool $enabled = false;

    /**
     * Create a new FirewallManager instance.
     */
    public function __construct()
    {
        $this->iptables = new IptablesService();
        $this->nat = new NatService();
        $this->acl = new AclService();
        $this->qos = new QosService();
        $this->logger = new Logger('coyote-firewall');
    }

    /**
     * Apply firewall configuration.
     *
     * @param array $config Firewall configuration section
     * @return bool True if successful
     */
    public function applyConfig(array $config): bool
    {
        $this->enabled = $config['enabled'] ?? true;

        if (!$this->enabled) {
            $this->logger->info('Firewall disabled, flushing rules');
            $this->iptables->flush();
            return true;
        }

        $this->logger->info('Applying firewall configuration');

        // Set default policy
        $defaultPolicy = strtoupper($config['default_policy'] ?? 'DROP');
        $this->iptables->setDefaultPolicies($defaultPolicy, $defaultPolicy, 'ACCEPT');

        // Apply base rules (allow established, loopback)
        $this->applyBaseRules();

        // Apply NAT rules
        if (isset($config['nat'])) {
            $this->nat->applyConfig([
                'masquerade' => $config['nat'],
            ]);
        }

        // Apply port forwards
        if (isset($config['port_forwards'])) {
            foreach ($config['port_forwards'] as $forward) {
                $this->nat->addDnat($forward);
            }
        }

        // Apply firewall rules
        if (isset($config['rules'])) {
            foreach ($config['rules'] as $rule) {
                $this->iptables->addRule($rule);
            }
        }

        // Apply ACLs
        if (isset($config['acls'])) {
            $this->acl->applyConfig($config['acls']);
        }

        // Apply QoS
        if (isset($config['qos'])) {
            $this->qos->applyConfig($config['qos']);
        }

        $this->logger->info('Firewall configuration applied successfully');
        return true;
    }

    /**
     * Apply base firewall rules that should always be present.
     *
     * @return void
     */
    private function applyBaseRules(): void
    {
        // Allow established and related connections
        $this->iptables->addRule([
            'chain' => 'INPUT',
            'match' => 'state',
            'state' => 'ESTABLISHED,RELATED',
            'action' => 'ACCEPT',
        ]);

        $this->iptables->addRule([
            'chain' => 'FORWARD',
            'match' => 'state',
            'state' => 'ESTABLISHED,RELATED',
            'action' => 'ACCEPT',
        ]);

        // Allow loopback
        $this->iptables->addRule([
            'chain' => 'INPUT',
            'interface' => 'lo',
            'action' => 'ACCEPT',
        ]);

        $this->iptables->addRule([
            'chain' => 'OUTPUT',
            'interface' => 'lo',
            'action' => 'ACCEPT',
        ]);

        // Allow ICMP ping
        $this->iptables->addRule([
            'chain' => 'INPUT',
            'protocol' => 'icmp',
            'icmp_type' => 'echo-request',
            'action' => 'ACCEPT',
        ]);
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
            'rules' => $this->iptables->listRules(),
            'nat' => $this->nat->listRules(),
            'connections' => $this->getActiveConnections(),
        ];
    }

    /**
     * Get active connection count.
     *
     * @return array Connection tracking info
     */
    public function getActiveConnections(): array
    {
        $count = 0;
        $conntrackFile = '/proc/sys/net/netfilter/nf_conntrack_count';

        if (file_exists($conntrackFile)) {
            $count = (int)trim(file_get_contents($conntrackFile));
        }

        return [
            'count' => $count,
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
        $this->iptables->flush();
        $this->iptables->setDefaultPolicies('ACCEPT', 'ACCEPT', 'ACCEPT');
        $this->enabled = false;
        return true;
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
     * Get the iptables service instance.
     *
     * @return IptablesService
     */
    public function getIptablesService(): IptablesService
    {
        return $this->iptables;
    }

    /**
     * Get the NAT service instance.
     *
     * @return NatService
     */
    public function getNatService(): NatService
    {
        return $this->nat;
    }

    /**
     * Get the ACL service instance.
     *
     * @return AclService
     */
    public function getAclService(): AclService
    {
        return $this->acl;
    }

    /**
     * Get the QoS service instance.
     *
     * @return QosService
     */
    public function getQosService(): QosService
    {
        return $this->qos;
    }
}
