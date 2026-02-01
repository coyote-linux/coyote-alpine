<?php

namespace Coyote\Firewall;

use Coyote\Util\Logger;

/**
 * Manages ICMP firewall rules with granular control.
 *
 * Provides fine-grained ICMP type configuration for both IPv4 (ICMP)
 * and IPv6 (ICMPv6), including rate limiting and per-type allow/deny.
 */
class IcmpService
{
    /** @var Logger */
    private Logger $logger;

    /** @var array ICMPv4 type definitions */
    private const ICMP_TYPES = [
        'echo-reply' => ['code' => 0, 'description' => 'Echo Reply (pong)'],
        'destination-unreachable' => ['code' => 3, 'description' => 'Destination Unreachable'],
        'source-quench' => ['code' => 4, 'description' => 'Source Quench (deprecated)'],
        'redirect' => ['code' => 5, 'description' => 'Redirect Message'],
        'echo-request' => ['code' => 8, 'description' => 'Echo Request (ping)'],
        'router-advertisement' => ['code' => 9, 'description' => 'Router Advertisement'],
        'router-solicitation' => ['code' => 10, 'description' => 'Router Solicitation'],
        'time-exceeded' => ['code' => 11, 'description' => 'Time Exceeded'],
        'parameter-problem' => ['code' => 12, 'description' => 'Parameter Problem'],
        'timestamp-request' => ['code' => 13, 'description' => 'Timestamp Request'],
        'timestamp-reply' => ['code' => 14, 'description' => 'Timestamp Reply'],
        'info-request' => ['code' => 15, 'description' => 'Information Request (deprecated)'],
        'info-reply' => ['code' => 16, 'description' => 'Information Reply (deprecated)'],
        'address-mask-request' => ['code' => 17, 'description' => 'Address Mask Request'],
        'address-mask-reply' => ['code' => 18, 'description' => 'Address Mask Reply'],
    ];

    /** @var array ICMPv6 type definitions */
    private const ICMPV6_TYPES = [
        'destination-unreachable' => ['code' => 1, 'description' => 'Destination Unreachable'],
        'packet-too-big' => ['code' => 2, 'description' => 'Packet Too Big'],
        'time-exceeded' => ['code' => 3, 'description' => 'Time Exceeded'],
        'parameter-problem' => ['code' => 4, 'description' => 'Parameter Problem'],
        'echo-request' => ['code' => 128, 'description' => 'Echo Request (ping)'],
        'echo-reply' => ['code' => 129, 'description' => 'Echo Reply (pong)'],
        'mld-listener-query' => ['code' => 130, 'description' => 'MLD Listener Query'],
        'mld-listener-report' => ['code' => 131, 'description' => 'MLD Listener Report'],
        'mld-listener-done' => ['code' => 132, 'description' => 'MLD Listener Done'],
        'nd-router-solicit' => ['code' => 133, 'description' => 'Router Solicitation'],
        'nd-router-advert' => ['code' => 134, 'description' => 'Router Advertisement'],
        'nd-neighbor-solicit' => ['code' => 135, 'description' => 'Neighbor Solicitation'],
        'nd-neighbor-advert' => ['code' => 136, 'description' => 'Neighbor Advertisement'],
        'nd-redirect' => ['code' => 137, 'description' => 'Redirect Message'],
        'router-renumbering' => ['code' => 138, 'description' => 'Router Renumbering'],
    ];

    /** @var array Essential ICMPv4 types that should generally be allowed */
    private const ESSENTIAL_ICMP = [
        'destination-unreachable',
        'time-exceeded',
        'parameter-problem',
    ];

    /** @var array Essential ICMPv6 types required for IPv6 operation */
    private const ESSENTIAL_ICMPV6 = [
        'destination-unreachable',
        'packet-too-big',
        'time-exceeded',
        'parameter-problem',
        'nd-router-solicit',
        'nd-router-advert',
        'nd-neighbor-solicit',
        'nd-neighbor-advert',
    ];

    /** @var array Default ICMP configuration */
    private array $defaultConfig = [
        'allow_ping' => true,
        'allow_ping_reply' => true,
        'rate_limit' => null,  // e.g., '10/second'
        'rate_burst' => 5,
        'log_dropped' => false,
        'ipv4' => [
            'enabled' => true,
            'types' => [],  // Empty = use defaults
        ],
        'ipv6' => [
            'enabled' => true,
            'types' => [],  // Empty = use defaults
        ],
    ];

    /**
     * Create a new IcmpService instance.
     */
    public function __construct()
    {
        $this->logger = new Logger('icmp-service');
    }

    /**
     * Build ICMP rules from configuration.
     *
     * @param array $config ICMP configuration section
     * @return array nftables rule strings for icmp-rules chain
     */
    public function buildIcmpRules(array $config): array
    {
        $config = array_merge($this->defaultConfig, $config);
        $rules = [];

        // IPv4 ICMP rules
        if ($config['ipv4']['enabled'] ?? true) {
            $rules = array_merge($rules, $this->buildIcmpv4Rules($config));
        }

        // IPv6 ICMP rules
        if ($config['ipv6']['enabled'] ?? true) {
            $rules = array_merge($rules, $this->buildIcmpv6Rules($config));
        }

        return $rules;
    }

    /**
     * Build ICMPv4 rules.
     *
     * @param array $config ICMP configuration
     * @return array nftables rule strings
     */
    private function buildIcmpv4Rules(array $config): array
    {
        $rules = [];
        $allowedTypes = [];
        $rateLimit = $config['rate_limit'] ?? null;
        $rateBurst = $config['rate_burst'] ?? 5;

        // Determine which types to allow
        $customTypes = $config['ipv4']['types'] ?? [];

        if (!empty($customTypes)) {
            // Use custom type list
            $allowedTypes = $customTypes;
        } else {
            // Build default list
            // Always allow essential types
            $allowedTypes = self::ESSENTIAL_ICMP;

            // Add ping based on config
            if ($config['allow_ping'] ?? true) {
                $allowedTypes[] = 'echo-request';
            }
            if ($config['allow_ping_reply'] ?? true) {
                $allowedTypes[] = 'echo-reply';
            }
        }

        // Remove duplicates
        $allowedTypes = array_unique($allowedTypes);

        // Generate rules
        if ($rateLimit !== null) {
            // Rate-limited ping
            if (in_array('echo-request', $allowedTypes)) {
                $rules[] = "ip protocol icmp icmp type echo-request limit rate {$rateLimit} burst {$rateBurst} packets accept";
                // Remove from list so it's not added again
                $allowedTypes = array_diff($allowedTypes, ['echo-request']);
            }
        }

        // Single rule for all other allowed types
        if (!empty($allowedTypes)) {
            $typeList = implode(', ', $allowedTypes);
            $rules[] = "ip protocol icmp icmp type { {$typeList} } accept";
        }

        // Log dropped ICMP if configured
        if ($config['log_dropped'] ?? false) {
            $rules[] = 'ip protocol icmp log prefix "ICMP-DROP: " level info';
        }

        return $rules;
    }

    /**
     * Build ICMPv6 rules.
     *
     * @param array $config ICMP configuration
     * @return array nftables rule strings
     */
    private function buildIcmpv6Rules(array $config): array
    {
        $rules = [];
        $allowedTypes = [];
        $rateLimit = $config['rate_limit'] ?? null;
        $rateBurst = $config['rate_burst'] ?? 5;

        // Determine which types to allow
        $customTypes = $config['ipv6']['types'] ?? [];

        if (!empty($customTypes)) {
            // Use custom type list, but always include essential NDP types
            $allowedTypes = array_unique(array_merge($customTypes, self::ESSENTIAL_ICMPV6));
        } else {
            // Build default list - essential types required for IPv6
            $allowedTypes = self::ESSENTIAL_ICMPV6;

            // Add ping based on config
            if ($config['allow_ping'] ?? true) {
                $allowedTypes[] = 'echo-request';
            }
            if ($config['allow_ping_reply'] ?? true) {
                $allowedTypes[] = 'echo-reply';
            }
        }

        // Remove duplicates
        $allowedTypes = array_unique($allowedTypes);

        // Generate rules
        if ($rateLimit !== null) {
            // Rate-limited ping
            if (in_array('echo-request', $allowedTypes)) {
                $rules[] = "ip6 nexthdr icmpv6 icmpv6 type echo-request limit rate {$rateLimit} burst {$rateBurst} packets accept";
                $allowedTypes = array_diff($allowedTypes, ['echo-request']);
            }
        }

        // Single rule for all allowed types
        if (!empty($allowedTypes)) {
            $typeList = implode(', ', $allowedTypes);
            $rules[] = "ip6 nexthdr icmpv6 icmpv6 type { {$typeList} } accept";
        }

        // Log dropped ICMPv6 if configured
        if ($config['log_dropped'] ?? false) {
            $rules[] = 'ip6 nexthdr icmpv6 log prefix "ICMPV6-DROP: " level info';
        }

        return $rules;
    }

    /**
     * Get all available ICMPv4 types.
     *
     * @return array Type definitions
     */
    public function getIcmpTypes(): array
    {
        return self::ICMP_TYPES;
    }

    /**
     * Get all available ICMPv6 types.
     *
     * @return array Type definitions
     */
    public function getIcmpv6Types(): array
    {
        return self::ICMPV6_TYPES;
    }

    /**
     * Get essential ICMPv4 types.
     *
     * @return array Essential type names
     */
    public function getEssentialIcmpTypes(): array
    {
        return self::ESSENTIAL_ICMP;
    }

    /**
     * Get essential ICMPv6 types.
     *
     * @return array Essential type names
     */
    public function getEssentialIcmpv6Types(): array
    {
        return self::ESSENTIAL_ICMPV6;
    }

    /**
     * Check if an ICMP type is essential.
     *
     * @param string $type Type name
     * @param string $family 'ipv4' or 'ipv6'
     * @return bool True if essential
     */
    public function isEssentialType(string $type, string $family = 'ipv4'): bool
    {
        if ($family === 'ipv6' || $family === 'icmpv6') {
            return in_array($type, self::ESSENTIAL_ICMPV6);
        }

        return in_array($type, self::ESSENTIAL_ICMP);
    }

    /**
     * Get the default ICMP configuration.
     *
     * @return array Default configuration
     */
    public function getDefaultConfig(): array
    {
        return $this->defaultConfig;
    }

    /**
     * Validate an ICMP type name.
     *
     * @param string $type Type name
     * @param string $family 'ipv4' or 'ipv6'
     * @return bool True if valid
     */
    public function isValidType(string $type, string $family = 'ipv4'): bool
    {
        if ($family === 'ipv6' || $family === 'icmpv6') {
            return isset(self::ICMPV6_TYPES[$type]);
        }

        return isset(self::ICMP_TYPES[$type]);
    }

    /**
     * Get type description.
     *
     * @param string $type Type name
     * @param string $family 'ipv4' or 'ipv6'
     * @return string|null Description or null if not found
     */
    public function getTypeDescription(string $type, string $family = 'ipv4'): ?string
    {
        if ($family === 'ipv6' || $family === 'icmpv6') {
            return self::ICMPV6_TYPES[$type]['description'] ?? null;
        }

        return self::ICMP_TYPES[$type]['description'] ?? null;
    }

    /**
     * Build a preset configuration for common scenarios.
     *
     * @param string $preset Preset name (strict, permissive, server, gateway)
     * @return array Configuration array
     */
    public function getPreset(string $preset): array
    {
        switch ($preset) {
            case 'strict':
                // Minimal ICMP - only essential types, no ping
                return [
                    'allow_ping' => false,
                    'allow_ping_reply' => false,
                    'log_dropped' => true,
                    'ipv4' => [
                        'enabled' => true,
                        'types' => self::ESSENTIAL_ICMP,
                    ],
                    'ipv6' => [
                        'enabled' => true,
                        'types' => self::ESSENTIAL_ICMPV6,
                    ],
                ];

            case 'permissive':
                // Allow most ICMP types
                return [
                    'allow_ping' => true,
                    'allow_ping_reply' => true,
                    'log_dropped' => false,
                    'ipv4' => [
                        'enabled' => true,
                        'types' => array_keys(self::ICMP_TYPES),
                    ],
                    'ipv6' => [
                        'enabled' => true,
                        'types' => array_keys(self::ICMPV6_TYPES),
                    ],
                ];

            case 'server':
                // Server configuration - allow ping with rate limiting
                return [
                    'allow_ping' => true,
                    'allow_ping_reply' => true,
                    'rate_limit' => '10/second',
                    'rate_burst' => 5,
                    'log_dropped' => true,
                    'ipv4' => [
                        'enabled' => true,
                        'types' => [],
                    ],
                    'ipv6' => [
                        'enabled' => true,
                        'types' => [],
                    ],
                ];

            case 'gateway':
            default:
                // Gateway/router configuration - standard defaults
                return [
                    'allow_ping' => true,
                    'allow_ping_reply' => true,
                    'rate_limit' => null,
                    'log_dropped' => false,
                    'ipv4' => [
                        'enabled' => true,
                        'types' => [],
                    ],
                    'ipv6' => [
                        'enabled' => true,
                        'types' => [],
                    ],
                ];
        }
    }

    /**
     * Get available preset names.
     *
     * @return array Preset names with descriptions
     */
    public function getAvailablePresets(): array
    {
        return [
            'strict' => 'Minimal ICMP - essential types only, no ping',
            'permissive' => 'Allow most ICMP types',
            'server' => 'Server mode - ping with rate limiting',
            'gateway' => 'Gateway/router - standard defaults',
        ];
    }
}
