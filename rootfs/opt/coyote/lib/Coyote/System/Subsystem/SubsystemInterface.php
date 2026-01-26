<?php

namespace Coyote\System\Subsystem;

/**
 * Interface for configuration subsystems.
 *
 * Each subsystem handles a specific area of system configuration
 * (hostname, network, firewall, etc.) and can determine whether
 * changes require the 60-second confirmation countdown.
 */
interface SubsystemInterface
{
    /**
     * Get the subsystem name.
     *
     * @return string Subsystem identifier (e.g., 'hostname', 'network')
     */
    public function getName(): string;

    /**
     * Check if this subsystem requires the 60-second countdown.
     *
     * Subsystems that could cause loss of remote access (network, firewall)
     * should return true. Safe changes (hostname, timezone) return false.
     *
     * @return bool True if countdown required
     */
    public function requiresCountdown(): bool;

    /**
     * Get the configuration keys this subsystem handles.
     *
     * Used to determine which subsystems need to be applied when
     * specific configuration values change.
     *
     * @return array List of dot-notation config keys (e.g., ['system.hostname', 'system.domain'])
     */
    public function getConfigKeys(): array;

    /**
     * Check if there are changes between working and running config.
     *
     * @param array $working The working (new) configuration
     * @param array $running The running (current) configuration
     * @return bool True if this subsystem has changes to apply
     */
    public function hasChanges(array $working, array $running): bool;

    /**
     * Apply the configuration for this subsystem.
     *
     * @param array $config The configuration to apply
     * @return array{success: bool, message: string, errors: array}
     */
    public function apply(array $config): array;
}
