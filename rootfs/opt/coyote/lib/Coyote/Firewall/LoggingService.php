<?php

namespace Coyote\Firewall;

use Coyote\Util\Logger;

/**
 * Firewall logging service for nftables.
 *
 * Manages log rules, rate limiting, log prefixes, and
 * integration with system logging facilities.
 */
class LoggingService
{
    /** @var Logger */
    private Logger $logger;

    /** @var bool Whether logging is enabled */
    private bool $enabled = true;

    /** @var string Default log prefix */
    private string $prefix = 'COYOTE';

    /** @var string Log level (emerg, alert, crit, err, warn, notice, info, debug) */
    private string $level = 'info';

    /** @var bool Log denied local (INPUT) traffic */
    private bool $logLocalDeny = true;

    /** @var bool Log denied forwarded traffic */
    private bool $logForwardDeny = true;

    /** @var bool Log accepted connections */
    private bool $logAccept = false;

    /** @var bool Log new connections */
    private bool $logNew = false;

    /** @var bool Log invalid packets */
    private bool $logInvalid = false;

    /** @var string|null Rate limit for logging (e.g., "10/minute") */
    private ?string $rateLimit = null;

    /** @var int Rate limit burst */
    private int $rateBurst = 5;

    /** @var array Custom log rules */
    private array $customRules = [];

    /** @var array Log group configurations */
    private array $logGroups = [];

    /** @var array Valid log levels */
    private const VALID_LEVELS = [
        'emerg', 'alert', 'crit', 'err', 'warn', 'notice', 'info', 'debug'
    ];

    /** @var array Log level descriptions */
    private const LEVEL_DESCRIPTIONS = [
        'emerg' => 'System is unusable',
        'alert' => 'Action must be taken immediately',
        'crit' => 'Critical conditions',
        'err' => 'Error conditions',
        'warn' => 'Warning conditions',
        'notice' => 'Normal but significant condition',
        'info' => 'Informational messages',
        'debug' => 'Debug-level messages',
    ];

    /**
     * Create a new LoggingService instance.
     */
    public function __construct()
    {
        $this->logger = new Logger('fw-logging');
    }

    /**
     * Load logging configuration.
     *
     * @param array $config Logging configuration section
     * @return self
     */
    public function loadConfig(array $config): self
    {
        $this->enabled = $config['enabled'] ?? true;
        $this->prefix = $config['prefix'] ?? 'COYOTE';
        $this->level = $this->validateLevel($config['level'] ?? 'info');
        $this->logLocalDeny = $config['local_deny'] ?? true;
        $this->logForwardDeny = $config['forward_deny'] ?? true;
        $this->logAccept = $config['accept'] ?? false;
        $this->logNew = $config['new_connections'] ?? false;
        $this->logInvalid = $config['invalid'] ?? false;
        $this->rateLimit = $config['rate_limit'] ?? null;
        $this->rateBurst = $config['rate_burst'] ?? 5;
        $this->logGroups = $config['groups'] ?? [];

        return $this;
    }

    /**
     * Build logging rules for chain endings.
     *
     * @return array Chain rules keyed by purpose
     */
    public function buildLoggingRules(): array
    {
        $rules = [
            'input_final' => [],
            'forward_final' => [],
            'invalid' => null,
            'new_connection' => null,
        ];

        if (!$this->enabled) {
            // Just drop without logging
            $rules['input_final'] = ['drop'];
            $rules['forward_final'] = ['drop'];
            return $rules;
        }

        // Input chain final rules
        if ($this->logLocalDeny) {
            $rules['input_final'] = $this->buildLogAndDrop('DROP-LOCAL');
        } else {
            $rules['input_final'] = ['drop'];
        }

        // Forward chain final rules
        if ($this->logForwardDeny) {
            $rules['forward_final'] = $this->buildLogAndDrop('DROP-FWD');
        } else {
            $rules['forward_final'] = ['drop'];
        }

        // Invalid packet logging
        if ($this->logInvalid) {
            $rules['invalid'] = $this->buildLogRule('INVALID', 'drop');
        }

        // New connection logging
        if ($this->logNew) {
            $rules['new_connection'] = $this->buildLogRule('NEW', 'accept');
        }

        return $rules;
    }

    /**
     * Build a log-and-drop rule pair.
     *
     * @param string $suffix Log prefix suffix
     * @return array Array of rules (log, then drop)
     */
    private function buildLogAndDrop(string $suffix): array
    {
        $rules = [];

        $logRule = $this->buildLogStatement($suffix);
        if ($logRule) {
            $rules[] = $logRule;
        }
        $rules[] = 'drop';

        return $rules;
    }

    /**
     * Build a log rule with optional action.
     *
     * @param string $suffix Log prefix suffix
     * @param string|null $action Action after logging (accept, drop, null)
     * @return string Log rule
     */
    private function buildLogRule(string $suffix, ?string $action = null): string
    {
        $parts = [];

        // Rate limiting
        if ($this->rateLimit) {
            $parts[] = "limit rate {$this->rateLimit} burst {$this->rateBurst} packets";
        }

        // Log statement
        $parts[] = $this->buildLogStatement($suffix);

        // Action
        if ($action) {
            $parts[] = $action;
        }

        return implode(' ', array_filter($parts));
    }

    /**
     * Build a log statement.
     *
     * @param string $suffix Log prefix suffix
     * @return string Log statement
     */
    private function buildLogStatement(string $suffix): string
    {
        $fullPrefix = "{$this->prefix}-{$suffix}";
        return "log prefix \"{$fullPrefix}: \" level {$this->level}";
    }

    /**
     * Build a custom log rule.
     *
     * @param string $name Rule name/identifier
     * @param array $match Match criteria
     * @param string $action Action (accept, drop, continue)
     * @param string|null $customPrefix Custom prefix (uses default if null)
     * @return string nftables rule
     */
    public function buildCustomLogRule(
        string $name,
        array $match,
        string $action = 'continue',
        ?string $customPrefix = null
    ): string {
        $parts = [];

        // Match criteria
        if (!empty($match['protocol'])) {
            $proto = strtolower($match['protocol']);
            if ($proto !== 'any') {
                $parts[] = $proto;
            }
        }

        if (!empty($match['source'])) {
            $parts[] = "ip saddr {$match['source']}";
        }

        if (!empty($match['destination'])) {
            $parts[] = "ip daddr {$match['destination']}";
        }

        if (!empty($match['port'])) {
            $parts[] = "dport {$match['port']}";
        }

        if (!empty($match['interface'])) {
            $parts[] = "iifname \"{$match['interface']}\"";
        }

        // Rate limiting
        if ($this->rateLimit) {
            $parts[] = "limit rate {$this->rateLimit} burst {$this->rateBurst} packets";
        }

        // Log statement
        $prefix = $customPrefix ?? "{$this->prefix}-{$name}";
        $parts[] = "log prefix \"{$prefix}: \" level {$this->level}";

        // Action
        if ($action !== 'continue') {
            $parts[] = $action;
        }

        return implode(' ', $parts);
    }

    /**
     * Build log rules for a specific chain.
     *
     * @param string $chainType Chain type (input, forward, output)
     * @param string $position Position (start, end)
     * @return array Log rules
     */
    public function buildChainLogRules(string $chainType, string $position = 'end'): array
    {
        $rules = [];

        if (!$this->enabled) {
            return $rules;
        }

        // Check log groups for this chain
        foreach ($this->logGroups as $group) {
            if (($group['chain'] ?? '') !== $chainType) {
                continue;
            }
            if (($group['position'] ?? 'end') !== $position) {
                continue;
            }
            if (!($group['enabled'] ?? true)) {
                continue;
            }

            $rule = $this->buildCustomLogRule(
                $group['name'] ?? 'LOG',
                $group['match'] ?? [],
                $group['action'] ?? 'continue',
                $group['prefix'] ?? null
            );

            $rules[] = $rule;
        }

        return $rules;
    }

    /**
     * Add a log group configuration.
     *
     * @param string $name Group name
     * @param string $chain Chain (input, forward, output)
     * @param array $match Match criteria
     * @param string $action Action after logging
     * @param string $position Position in chain (start, end)
     * @return self
     */
    public function addLogGroup(
        string $name,
        string $chain,
        array $match = [],
        string $action = 'continue',
        string $position = 'end'
    ): self {
        $this->logGroups[] = [
            'name' => $name,
            'chain' => $chain,
            'match' => $match,
            'action' => $action,
            'position' => $position,
            'enabled' => true,
        ];

        return $this;
    }

    /**
     * Get the final rules for INPUT chain.
     *
     * @return array Final rules (log + drop or just drop)
     */
    public function getInputFinalRules(): array
    {
        if (!$this->enabled || !$this->logLocalDeny) {
            return ['drop'];
        }

        return $this->buildLogAndDrop('DROP-LOCAL');
    }

    /**
     * Get the final rules for FORWARD chain.
     *
     * @return array Final rules (log + drop or just drop)
     */
    public function getForwardFinalRules(): array
    {
        if (!$this->enabled || !$this->logForwardDeny) {
            return ['drop'];
        }

        return $this->buildLogAndDrop('DROP-FWD');
    }

    /**
     * Get invalid packet handling rule.
     *
     * @return string Rule for invalid packets
     */
    public function getInvalidRule(): string
    {
        if ($this->enabled && $this->logInvalid) {
            return $this->buildLogRule('INVALID', 'drop');
        }

        return 'drop';
    }

    /**
     * Validate a log level.
     *
     * @param string $level Log level
     * @return string Validated level (defaults to 'info')
     */
    private function validateLevel(string $level): string
    {
        $level = strtolower($level);
        return in_array($level, self::VALID_LEVELS) ? $level : 'info';
    }

    /**
     * Get available log levels.
     *
     * @return array Log levels with descriptions
     */
    public function getAvailableLevels(): array
    {
        return self::LEVEL_DESCRIPTIONS;
    }

    /**
     * Check if logging is enabled.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Enable or disable logging.
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
     * Get the log prefix.
     *
     * @return string
     */
    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * Set the log prefix.
     *
     * @param string $prefix
     * @return self
     */
    public function setPrefix(string $prefix): self
    {
        $this->prefix = $prefix;
        return $this;
    }

    /**
     * Get the log level.
     *
     * @return string
     */
    public function getLevel(): string
    {
        return $this->level;
    }

    /**
     * Set the log level.
     *
     * @param string $level
     * @return self
     */
    public function setLevel(string $level): self
    {
        $this->level = $this->validateLevel($level);
        return $this;
    }

    /**
     * Set rate limiting.
     *
     * @param string|null $limit Rate limit (e.g., "10/minute", "5/second")
     * @param int $burst Burst limit
     * @return self
     */
    public function setRateLimit(?string $limit, int $burst = 5): self
    {
        $this->rateLimit = $limit;
        $this->rateBurst = $burst;
        return $this;
    }

    /**
     * Enable logging for denied local traffic.
     *
     * @param bool $enabled
     * @return self
     */
    public function setLogLocalDeny(bool $enabled): self
    {
        $this->logLocalDeny = $enabled;
        return $this;
    }

    /**
     * Enable logging for denied forwarded traffic.
     *
     * @param bool $enabled
     * @return self
     */
    public function setLogForwardDeny(bool $enabled): self
    {
        $this->logForwardDeny = $enabled;
        return $this;
    }

    /**
     * Enable logging for invalid packets.
     *
     * @param bool $enabled
     * @return self
     */
    public function setLogInvalid(bool $enabled): self
    {
        $this->logInvalid = $enabled;
        return $this;
    }

    /**
     * Enable logging for new connections.
     *
     * @param bool $enabled
     * @return self
     */
    public function setLogNew(bool $enabled): self
    {
        $this->logNew = $enabled;
        return $this;
    }

    /**
     * Get current configuration as array.
     *
     * @return array Configuration array
     */
    public function getConfig(): array
    {
        return [
            'enabled' => $this->enabled,
            'prefix' => $this->prefix,
            'level' => $this->level,
            'local_deny' => $this->logLocalDeny,
            'forward_deny' => $this->logForwardDeny,
            'accept' => $this->logAccept,
            'new_connections' => $this->logNew,
            'invalid' => $this->logInvalid,
            'rate_limit' => $this->rateLimit,
            'rate_burst' => $this->rateBurst,
            'groups' => $this->logGroups,
        ];
    }

    /**
     * Create a preset logging configuration.
     *
     * @param string $preset Preset name (minimal, standard, verbose, debug)
     * @return array Configuration array
     */
    public static function getPreset(string $preset): array
    {
        switch ($preset) {
            case 'minimal':
                return [
                    'enabled' => true,
                    'prefix' => 'FW',
                    'level' => 'warn',
                    'local_deny' => false,
                    'forward_deny' => true,
                    'invalid' => false,
                    'rate_limit' => '5/minute',
                    'rate_burst' => 3,
                ];

            case 'verbose':
                return [
                    'enabled' => true,
                    'prefix' => 'COYOTE',
                    'level' => 'info',
                    'local_deny' => true,
                    'forward_deny' => true,
                    'invalid' => true,
                    'new_connections' => true,
                    'rate_limit' => '30/minute',
                    'rate_burst' => 10,
                ];

            case 'debug':
                return [
                    'enabled' => true,
                    'prefix' => 'COYOTE-DBG',
                    'level' => 'debug',
                    'local_deny' => true,
                    'forward_deny' => true,
                    'invalid' => true,
                    'new_connections' => true,
                    'accept' => true,
                    'rate_limit' => null,  // No rate limit for debug
                    'rate_burst' => 0,
                ];

            case 'standard':
            default:
                return [
                    'enabled' => true,
                    'prefix' => 'COYOTE',
                    'level' => 'info',
                    'local_deny' => true,
                    'forward_deny' => true,
                    'invalid' => false,
                    'rate_limit' => '10/minute',
                    'rate_burst' => 5,
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
            'minimal' => 'Minimal logging - only forward denies with rate limiting',
            'standard' => 'Standard logging - local and forward denies',
            'verbose' => 'Verbose logging - includes invalid and new connections',
            'debug' => 'Debug logging - everything, no rate limiting',
        ];
    }
}
