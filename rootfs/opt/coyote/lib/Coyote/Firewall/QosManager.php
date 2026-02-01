<?php

namespace Coyote\Firewall;

use Coyote\Util\Logger;

/**
 * QoS (Quality of Service) manager for nftables.
 *
 * Handles traffic classification, packet marking, and integration
 * with Linux traffic control (tc) for bandwidth management.
 */
class QosManager
{
    /** @var InterfaceResolver */
    private InterfaceResolver $resolver;

    /** @var Logger */
    private Logger $logger;

    /** @var bool Whether QoS is enabled */
    private bool $enabled = false;

    /** @var array Traffic classes */
    private array $classes = [];

    /** @var array Classification rules */
    private array $rules = [];

    /** @var array Interface QoS configurations */
    private array $interfaceConfigs = [];

    /** @var array Mangle table rules for packet marking */
    private array $mangleRules = [];

    /** @var array Default traffic class marks */
    private const DEFAULT_MARKS = [
        'realtime' => 0x10,      // VoIP, video conferencing
        'interactive' => 0x20,   // SSH, gaming, DNS
        'bulk' => 0x30,          // Large downloads, backups
        'background' => 0x40,    // P2P, updates
        'default' => 0x00,       // Unmarked traffic
    ];

    /** @var array DSCP to mark mappings */
    private const DSCP_MARKS = [
        'ef' => 0x10,            // Expedited Forwarding (VoIP)
        'af41' => 0x10,          // Video
        'af21' => 0x20,          // Low-latency data
        'cs1' => 0x40,           // Background
        'default' => 0x00,
    ];

    /**
     * Create a new QosManager instance.
     *
     * @param InterfaceResolver|null $resolver Optional resolver instance
     */
    public function __construct(?InterfaceResolver $resolver = null)
    {
        $this->resolver = $resolver ?? new InterfaceResolver();
        $this->logger = new Logger('qos-manager');
    }

    /**
     * Load QoS configuration.
     *
     * @param array $qosConfig QoS configuration section
     * @param array $networkConfig Network configuration for interface resolution
     * @return self
     */
    public function loadConfig(array $qosConfig, array $networkConfig = []): self
    {
        $this->reset();

        if (!empty($networkConfig)) {
            $this->resolver->loadConfig($networkConfig);
        }

        $this->enabled = $qosConfig['enabled'] ?? false;

        if (!$this->enabled) {
            return $this;
        }

        // Load traffic classes
        $this->classes = $qosConfig['classes'] ?? $this->getDefaultClasses();

        // Load classification rules
        $this->processRules($qosConfig['rules'] ?? []);

        // Load interface-specific configurations
        $this->interfaceConfigs = $qosConfig['interfaces'] ?? [];

        return $this;
    }

    /**
     * Build nftables mangle rules for packet marking.
     *
     * @return array Chain rules for mangle table
     */
    public function buildMangleRules(): array
    {
        if (!$this->enabled) {
            return [];
        }

        $rules = [];

        // Process each classification rule
        foreach ($this->rules as $rule) {
            $nftRule = $this->buildClassificationRule($rule);
            if ($nftRule) {
                $rules[] = $nftRule;
            }
        }

        // Add DSCP-based classification if enabled
        if (!empty($this->classes)) {
            $dscpRules = $this->buildDscpRules();
            $rules = array_merge($rules, $dscpRules);
        }

        return $rules;
    }

    /**
     * Check if QoS requires the mangle table.
     *
     * @return bool True if mangle table should be included
     */
    public function requiresMangleTable(): bool
    {
        return $this->enabled && (!empty($this->rules) || !empty($this->classes));
    }

    /**
     * Get mangle table configuration for RulesetBuilder.
     *
     * @return array Mangle table rules
     */
    public function getMangleConfig(): array
    {
        if (!$this->requiresMangleTable()) {
            return [];
        }

        return [
            'enabled' => true,
            'rules' => $this->buildMangleRules(),
        ];
    }

    /**
     * Process classification rules.
     *
     * @param array $rules Rule configurations
     */
    private function processRules(array $rules): void
    {
        foreach ($rules as $rule) {
            if (!($rule['enabled'] ?? true)) {
                continue;
            }

            $this->rules[] = [
                'name' => $rule['name'] ?? 'unnamed',
                'class' => $rule['class'] ?? 'default',
                'protocol' => $rule['protocol'] ?? null,
                'source' => $rule['source'] ?? null,
                'destination' => $rule['destination'] ?? null,
                'port' => $rule['port'] ?? null,
                'sport' => $rule['source_port'] ?? null,
                'dport' => $rule['destination_port'] ?? $rule['port'] ?? null,
                'dscp' => $rule['dscp'] ?? null,
                'interface' => $rule['interface'] ?? null,
                'direction' => $rule['direction'] ?? 'both',
                'priority' => $rule['priority'] ?? 100,
            ];
        }

        // Sort by priority
        usort($this->rules, fn($a, $b) => $a['priority'] <=> $b['priority']);
    }

    /**
     * Build a single classification rule.
     *
     * @param array $rule Rule configuration
     * @return string|null nftables rule or null if invalid
     */
    private function buildClassificationRule(array $rule): ?string
    {
        $parts = [];
        $class = $rule['class'] ?? 'default';
        $mark = $this->getMarkForClass($class);

        // Interface
        if (!empty($rule['interface'])) {
            $interfaces = $this->resolver->resolve($rule['interface']);
            if (!empty($interfaces)) {
                $direction = $rule['direction'] ?? 'both';
                if ($direction === 'in' || $direction === 'both') {
                    $parts[] = "iifname \"" . $interfaces[0] . "\"";
                } elseif ($direction === 'out') {
                    $parts[] = "oifname \"" . $interfaces[0] . "\"";
                }
            }
        }

        // Protocol
        if (!empty($rule['protocol'])) {
            $proto = strtolower($rule['protocol']);
            if ($proto !== 'any' && $proto !== 'all') {
                $parts[] = $proto;
            }
        }

        // Source
        if (!empty($rule['source'])) {
            $parts[] = "ip saddr {$rule['source']}";
        }

        // Destination
        if (!empty($rule['destination'])) {
            $parts[] = "ip daddr {$rule['destination']}";
        }

        // Source port
        if (!empty($rule['sport'])) {
            $parts[] = "sport {$rule['sport']}";
        }

        // Destination port
        if (!empty($rule['dport'])) {
            $parts[] = "dport {$rule['dport']}";
        }

        // DSCP matching
        if (!empty($rule['dscp'])) {
            $dscp = strtolower($rule['dscp']);
            $parts[] = "ip dscp {$dscp}";
        }

        if (empty($parts)) {
            return null;
        }

        // Set mark
        $parts[] = "meta mark set {$mark}";

        // Add comment
        if (!empty($rule['name'])) {
            $comment = "QoS: {$rule['name']}";
            // nftables comments would go here if supported in rule
        }

        return implode(' ', $parts);
    }

    /**
     * Build DSCP-based classification rules.
     *
     * @return array DSCP classification rules
     */
    private function buildDscpRules(): array
    {
        $rules = [];

        // Only add if DSCP classification is enabled
        $dscpEnabled = false;
        foreach ($this->classes as $class) {
            if (!empty($class['dscp'])) {
                $dscpEnabled = true;
                break;
            }
        }

        if (!$dscpEnabled) {
            return $rules;
        }

        // Map DSCP values to marks
        foreach (self::DSCP_MARKS as $dscp => $mark) {
            if ($dscp === 'default') {
                continue;
            }
            $rules[] = "ip dscp {$dscp} meta mark set {$mark}";
        }

        return $rules;
    }

    /**
     * Get the mark value for a traffic class.
     *
     * @param string $className Class name
     * @return int Mark value
     */
    private function getMarkForClass(string $className): int
    {
        // Check custom class definitions
        foreach ($this->classes as $class) {
            if (($class['name'] ?? '') === $className) {
                return $class['mark'] ?? self::DEFAULT_MARKS['default'];
            }
        }

        // Check default marks
        return self::DEFAULT_MARKS[$className] ?? self::DEFAULT_MARKS['default'];
    }

    /**
     * Get default traffic classes.
     *
     * @return array Default class definitions
     */
    public function getDefaultClasses(): array
    {
        return [
            [
                'name' => 'realtime',
                'mark' => self::DEFAULT_MARKS['realtime'],
                'description' => 'Real-time traffic (VoIP, video)',
                'priority' => 1,
            ],
            [
                'name' => 'interactive',
                'mark' => self::DEFAULT_MARKS['interactive'],
                'description' => 'Interactive traffic (SSH, DNS, gaming)',
                'priority' => 2,
            ],
            [
                'name' => 'default',
                'mark' => self::DEFAULT_MARKS['default'],
                'description' => 'Default traffic class',
                'priority' => 3,
            ],
            [
                'name' => 'bulk',
                'mark' => self::DEFAULT_MARKS['bulk'],
                'description' => 'Bulk transfers (large downloads)',
                'priority' => 4,
            ],
            [
                'name' => 'background',
                'mark' => self::DEFAULT_MARKS['background'],
                'description' => 'Background traffic (P2P, updates)',
                'priority' => 5,
            ],
        ];
    }

    /**
     * Add a classification rule.
     *
     * @param string $name Rule name
     * @param string $class Traffic class
     * @param array $match Match criteria
     * @param int $priority Rule priority (lower = higher priority)
     * @return self
     */
    public function addRule(string $name, string $class, array $match, int $priority = 100): self
    {
        $this->rules[] = array_merge($match, [
            'name' => $name,
            'class' => $class,
            'priority' => $priority,
            'enabled' => true,
        ]);

        // Re-sort by priority
        usort($this->rules, fn($a, $b) => $a['priority'] <=> $b['priority']);

        return $this;
    }

    /**
     * Add a traffic class.
     *
     * @param string $name Class name
     * @param int $mark Packet mark value
     * @param string $description Class description
     * @param int $priority Class priority
     * @return self
     */
    public function addClass(string $name, int $mark, string $description = '', int $priority = 3): self
    {
        $this->classes[] = [
            'name' => $name,
            'mark' => $mark,
            'description' => $description,
            'priority' => $priority,
        ];

        return $this;
    }

    /**
     * Generate tc (traffic control) commands for an interface.
     *
     * Note: This generates the commands but doesn't execute them.
     * Actual tc configuration should be done by a separate script.
     *
     * @param string $interface Interface name
     * @param int $bandwidth Bandwidth in kbps
     * @return array tc commands
     */
    public function generateTcCommands(string $interface, int $bandwidth): array
    {
        $commands = [];

        // Clear existing qdisc
        $commands[] = "tc qdisc del dev {$interface} root 2>/dev/null || true";

        // Add HTB root qdisc
        $commands[] = "tc qdisc add dev {$interface} root handle 1: htb default 30";

        // Root class with total bandwidth
        $commands[] = "tc class add dev {$interface} parent 1: classid 1:1 htb rate {$bandwidth}kbit ceil {$bandwidth}kbit";

        // Realtime class (10% guaranteed, can burst to 30%)
        $rtRate = (int)($bandwidth * 0.1);
        $rtCeil = (int)($bandwidth * 0.3);
        $commands[] = "tc class add dev {$interface} parent 1:1 classid 1:10 htb rate {$rtRate}kbit ceil {$rtCeil}kbit prio 1";

        // Interactive class (20% guaranteed, can burst to 50%)
        $intRate = (int)($bandwidth * 0.2);
        $intCeil = (int)($bandwidth * 0.5);
        $commands[] = "tc class add dev {$interface} parent 1:1 classid 1:20 htb rate {$intRate}kbit ceil {$intCeil}kbit prio 2";

        // Default class (40% guaranteed, can burst to 80%)
        $defRate = (int)($bandwidth * 0.4);
        $defCeil = (int)($bandwidth * 0.8);
        $commands[] = "tc class add dev {$interface} parent 1:1 classid 1:30 htb rate {$defRate}kbit ceil {$defCeil}kbit prio 3";

        // Bulk class (20% guaranteed, can burst to 90%)
        $bulkRate = (int)($bandwidth * 0.2);
        $bulkCeil = (int)($bandwidth * 0.9);
        $commands[] = "tc class add dev {$interface} parent 1:1 classid 1:40 htb rate {$bulkRate}kbit ceil {$bulkCeil}kbit prio 4";

        // Background class (10% guaranteed, can use remaining)
        $bgRate = (int)($bandwidth * 0.1);
        $commands[] = "tc class add dev {$interface} parent 1:1 classid 1:50 htb rate {$bgRate}kbit ceil {$bandwidth}kbit prio 5";

        // Add SFQ (Stochastic Fair Queuing) to leaf classes
        $commands[] = "tc qdisc add dev {$interface} parent 1:10 handle 10: sfq perturb 10";
        $commands[] = "tc qdisc add dev {$interface} parent 1:20 handle 20: sfq perturb 10";
        $commands[] = "tc qdisc add dev {$interface} parent 1:30 handle 30: sfq perturb 10";
        $commands[] = "tc qdisc add dev {$interface} parent 1:40 handle 40: sfq perturb 10";
        $commands[] = "tc qdisc add dev {$interface} parent 1:50 handle 50: sfq perturb 10";

        // Filters to match marks to classes
        $commands[] = "tc filter add dev {$interface} parent 1: protocol ip prio 1 handle 0x10 fw classid 1:10";
        $commands[] = "tc filter add dev {$interface} parent 1: protocol ip prio 2 handle 0x20 fw classid 1:20";
        $commands[] = "tc filter add dev {$interface} parent 1: protocol ip prio 4 handle 0x30 fw classid 1:40";
        $commands[] = "tc filter add dev {$interface} parent 1: protocol ip prio 5 handle 0x40 fw classid 1:50";

        return $commands;
    }

    /**
     * Check if QoS is enabled.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Enable or disable QoS.
     *
     * @param bool $enabled
     * @return self
     */
    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        return $this;
    }

    /**
     * Get all traffic classes.
     *
     * @return array Traffic classes
     */
    public function getClasses(): array
    {
        return $this->classes;
    }

    /**
     * Get all classification rules.
     *
     * @return array Classification rules
     */
    public function getRules(): array
    {
        return $this->rules;
    }

    /**
     * Get interface configurations.
     *
     * @return array Interface QoS configs
     */
    public function getInterfaceConfigs(): array
    {
        return $this->interfaceConfigs;
    }

    /**
     * Reset QoS configuration.
     *
     * @return self
     */
    public function reset(): self
    {
        $this->enabled = false;
        $this->classes = [];
        $this->rules = [];
        $this->interfaceConfigs = [];
        $this->mangleRules = [];

        return $this;
    }

    /**
     * Get a preset QoS configuration.
     *
     * @param string $preset Preset name (voip, gaming, streaming, general)
     * @return array QoS configuration
     */
    public static function getPreset(string $preset): array
    {
        switch ($preset) {
            case 'voip':
                return [
                    'enabled' => true,
                    'classes' => null, // Use defaults
                    'rules' => [
                        ['name' => 'SIP', 'class' => 'realtime', 'protocol' => 'udp', 'dport' => '5060-5061', 'priority' => 10],
                        ['name' => 'RTP', 'class' => 'realtime', 'protocol' => 'udp', 'dport' => '10000-20000', 'priority' => 10],
                        ['name' => 'DNS', 'class' => 'interactive', 'protocol' => 'udp', 'dport' => '53', 'priority' => 20],
                    ],
                ];

            case 'gaming':
                return [
                    'enabled' => true,
                    'classes' => null,
                    'rules' => [
                        ['name' => 'DNS', 'class' => 'interactive', 'protocol' => 'udp', 'dport' => '53', 'priority' => 10],
                        ['name' => 'GamePorts', 'class' => 'interactive', 'protocol' => 'udp', 'dport' => '3074,3478-3480', 'priority' => 15],
                        ['name' => 'Steam', 'class' => 'interactive', 'protocol' => 'udp', 'dport' => '27000-27050', 'priority' => 20],
                    ],
                ];

            case 'streaming':
                return [
                    'enabled' => true,
                    'classes' => null,
                    'rules' => [
                        ['name' => 'HTTPS', 'class' => 'default', 'protocol' => 'tcp', 'dport' => '443', 'priority' => 30],
                        ['name' => 'HTTP', 'class' => 'default', 'protocol' => 'tcp', 'dport' => '80', 'priority' => 30],
                        ['name' => 'DNS', 'class' => 'interactive', 'protocol' => 'udp', 'dport' => '53', 'priority' => 10],
                    ],
                ];

            case 'general':
            default:
                return [
                    'enabled' => true,
                    'classes' => null,
                    'rules' => [
                        ['name' => 'SSH', 'class' => 'interactive', 'protocol' => 'tcp', 'dport' => '22', 'priority' => 10],
                        ['name' => 'DNS', 'class' => 'interactive', 'protocol' => 'udp', 'dport' => '53', 'priority' => 10],
                        ['name' => 'HTTP', 'class' => 'default', 'protocol' => 'tcp', 'dport' => '80', 'priority' => 50],
                        ['name' => 'HTTPS', 'class' => 'default', 'protocol' => 'tcp', 'dport' => '443', 'priority' => 50],
                    ],
                ];
        }
    }

    /**
     * Get available preset names.
     *
     * @return array Preset names with descriptions
     */
    public static function getAvailablePresets(): array
    {
        return [
            'voip' => 'VoIP optimized - prioritizes SIP and RTP traffic',
            'gaming' => 'Gaming optimized - prioritizes game traffic and DNS',
            'streaming' => 'Streaming optimized - balanced for video streaming',
            'general' => 'General purpose - balanced QoS for mixed use',
        ];
    }
}
