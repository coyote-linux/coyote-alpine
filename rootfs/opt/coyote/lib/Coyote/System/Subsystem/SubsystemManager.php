<?php

namespace Coyote\System\Subsystem;

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

    /**
     * Create a new SubsystemManager with default subsystems.
     */
    public function __construct()
    {
        // Register default subsystems in apply order
        $this->register(new HostnameSubsystem());
        $this->register(new TimezoneSubsystem());
        $this->register(new DnsSubsystem());
        $this->register(new NetworkSubsystem());
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
            return [
                'success' => true,
                'message' => 'No changes to apply',
                'results' => [],
                'requiresCountdown' => false,
            ];
        }

        $results = [];
        $allSuccess = true;
        $requiresCountdown = false;

        foreach ($changed as $subsystem) {
            $result = $subsystem->apply($working);
            $results[$subsystem->getName()] = $result;

            if (!$result['success']) {
                $allSuccess = false;
            }

            if ($subsystem->requiresCountdown()) {
                $requiresCountdown = true;
            }
        }

        $changedNames = array_map(fn($s) => $s->getName(), $changed);

        return [
            'success' => $allSuccess,
            'message' => $allSuccess
                ? 'Applied: ' . implode(', ', $changedNames)
                : 'Some subsystems failed to apply',
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
        $results = [];
        $allSuccess = true;

        foreach ($this->subsystems as $subsystem) {
            $result = $subsystem->apply($config);
            $results[$subsystem->getName()] = $result;

            if (!$result['success']) {
                $allSuccess = false;
            }
        }

        return [
            'success' => $allSuccess,
            'message' => $allSuccess
                ? 'All subsystems applied successfully'
                : 'Some subsystems failed to apply',
            'results' => $results,
        ];
    }
}
