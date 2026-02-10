<?php

namespace Coyote\System\Subsystem;

use Coyote\Util\Logger;

/**
 * Manages configuration subsystems.
 *
 * Coordinates applying configuration changes across multiple subsystems,
 * determining which subsystems have changes and whether the 60-second
 * countdown is required.
 */
class SubsystemManager
{
    /** @var SubsystemInterface[] Registered subsystems */
    private array $subsystems = [];

    /** @var Logger */
    private Logger $logger;

    /** @var string Log file for debugging apply operations */
    private const LOG_FILE = '/var/log/coyote-apply.log';

    /**
     * Create a new SubsystemManager with default subsystems.
     */
    public function __construct()
    {
        $this->logger = new Logger('subsystem', LOG_LOCAL0, self::LOG_FILE);

        // Register default subsystems in apply order
        $this->register(new HostnameSubsystem());
        $this->register(new TimezoneSubsystem());
        $this->register(new NtpSubsystem());
        $this->register(new SyslogSubsystem());
        $this->register(new DnsSubsystem());
        $this->register(new DhcpSubsystem());
        $this->register(new NetworkSubsystem());
        $this->register(new FirewallSubsystem());
        $this->register(new VpnSubsystem());
    }

    /**
     * Register a subsystem.
     *
     * @param SubsystemInterface $subsystem
     * @return self
     */
    public function register(SubsystemInterface $subsystem): self
    {
        $this->subsystems[$subsystem->getName()] = $subsystem;
        return $this;
    }

    /**
     * Get a subsystem by name.
     *
     * @param string $name Subsystem name
     * @return SubsystemInterface|null
     */
    public function get(string $name): ?SubsystemInterface
    {
        return $this->subsystems[$name] ?? null;
    }

    /**
     * Get all registered subsystems.
     *
     * @return SubsystemInterface[]
     */
    public function all(): array
    {
        return $this->subsystems;
    }

    /**
     * Determine which subsystems have changes.
     *
     * @param array $working Working configuration
     * @param array $running Running configuration
     * @return SubsystemInterface[] Subsystems with changes
     */
    public function getChangedSubsystems(array $working, array $running): array
    {
        $changed = [];

        foreach ($this->subsystems as $subsystem) {
            if ($subsystem->hasChanges($working, $running)) {
                $changed[] = $subsystem;
            }
        }

        return $changed;
    }

    /**
     * Check if any changed subsystems require the 60-second countdown.
     *
     * @param array $working Working configuration
     * @param array $running Running configuration
     * @return bool True if countdown is required
     */
    public function requiresCountdown(array $working, array $running): bool
    {
        $changed = $this->getChangedSubsystems($working, $running);

        foreach ($changed as $subsystem) {
            if ($subsystem->requiresCountdown()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Apply only the subsystems that have changes.
     *
     * @param array $working Working configuration to apply
     * @param array $running Current running configuration
     * @return array{success: bool, message: string, results: array, requiresCountdown: bool}
     */
    public function applyChanges(array $working, array $running): array
    {
        $changed = $this->getChangedSubsystems($working, $running);

        if (empty($changed)) {
            $this->logger->debug('No subsystems have changes to apply');
            return [
                'success' => true,
                'message' => 'No changes to apply',
                'results' => [],
                'requiresCountdown' => false,
            ];
        }

        $changedNames = array_map(fn($s) => $s->getName(), $changed);
        $this->logger->info('Applying changes to subsystems: ' . implode(', ', $changedNames));

        $results = [];
        $allSuccess = true;
        $requiresCountdown = false;
        $failedSubsystems = [];
        $errorDetails = [];

        foreach ($changed as $subsystem) {
            $name = $subsystem->getName();
            $this->logger->debug("Applying subsystem: {$name}");

            $result = $subsystem->apply($working);
            $results[$name] = $result;

            if (!$result['success']) {
                $allSuccess = false;
                $failedSubsystems[] = $name;

                // Log the failure
                $this->logger->error("Subsystem {$name} failed: " . ($result['message'] ?? 'Unknown error'));

                // Log individual errors if present
                if (!empty($result['errors'])) {
                    foreach ($result['errors'] as $error) {
                        $this->logger->error("  - {$error}");
                        $errorDetails[] = "[{$name}] {$error}";
                    }
                } else {
                    $errorDetails[] = "[{$name}] " . ($result['message'] ?? 'Unknown error');
                }
            } else {
                $this->logger->info("Subsystem {$name} applied successfully");
            }

            if ($subsystem->requiresCountdown()) {
                $requiresCountdown = true;
            }
        }

        // Build descriptive message
        if ($allSuccess) {
            $message = 'Applied: ' . implode(', ', $changedNames);
        } else {
            $message = 'Failed subsystems: ' . implode(', ', $failedSubsystems);
            if (!empty($errorDetails)) {
                $message .= '. Errors: ' . implode('; ', array_slice($errorDetails, 0, 3));
                if (count($errorDetails) > 3) {
                    $message .= ' (and ' . (count($errorDetails) - 3) . ' more)';
                }
            }
        }

        return [
            'success' => $allSuccess,
            'message' => $message,
            'results' => $results,
            'requiresCountdown' => $requiresCountdown,
        ];
    }

    /**
     * Apply all subsystems (for boot-time full apply).
     *
     * @param array $config Configuration to apply
     * @return array{success: bool, message: string, results: array}
     */
    public function applyAll(array $config): array
    {
        $this->logger->info('Applying all subsystems');

        $results = [];
        $allSuccess = true;
        $failedSubsystems = [];
        $errorDetails = [];

        foreach ($this->subsystems as $subsystem) {
            $name = $subsystem->getName();
            $this->logger->debug("Applying subsystem: {$name}");

            $result = $subsystem->apply($config);
            $results[$name] = $result;

            if (!$result['success']) {
                $allSuccess = false;
                $failedSubsystems[] = $name;

                $this->logger->error("Subsystem {$name} failed: " . ($result['message'] ?? 'Unknown error'));

                if (!empty($result['errors'])) {
                    foreach ($result['errors'] as $error) {
                        $this->logger->error("  - {$error}");
                        $errorDetails[] = "[{$name}] {$error}";
                    }
                } else {
                    $errorDetails[] = "[{$name}] " . ($result['message'] ?? 'Unknown error');
                }
            } else {
                $this->logger->info("Subsystem {$name} applied successfully");
            }
        }

        // Build descriptive message
        if ($allSuccess) {
            $message = 'All subsystems applied successfully';
        } else {
            $message = 'Failed subsystems: ' . implode(', ', $failedSubsystems);
            if (!empty($errorDetails)) {
                $message .= '. Errors: ' . implode('; ', array_slice($errorDetails, 0, 3));
                if (count($errorDetails) > 3) {
                    $message .= ' (and ' . (count($errorDetails) - 3) . ' more)';
                }
            }
        }

        return [
            'success' => $allSuccess,
            'message' => $message,
            'results' => $results,
        ];
    }
}
