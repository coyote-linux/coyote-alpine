<?php

namespace Coyote\Addon;

/**
 * Interface that all Coyote add-ons must implement.
 *
 * Add-ons extend the base Coyote system with additional functionality
 * such as firewall rules, VPN, load balancing, etc.
 */
interface AddonInterface
{
    /**
     * Get the unique identifier for this add-on.
     *
     * @return string Add-on identifier (e.g., 'firewall', 'loadbalancer')
     */
    public function getId(): string;

    /**
     * Get the display name of the add-on.
     *
     * @return string Human-readable name
     */
    public function getName(): string;

    /**
     * Get the add-on version.
     *
     * @return string Semantic version string
     */
    public function getVersion(): string;

    /**
     * Initialize the add-on.
     *
     * Called when the add-on is loaded during system startup.
     *
     * @return void
     */
    public function initialize(): void;

    /**
     * Apply configuration changes for this add-on.
     *
     * Called when the system configuration is applied.
     *
     * @param array $config The add-on's configuration section
     * @return bool True if configuration was applied successfully
     */
    public function applyConfig(array $config): bool;

    /**
     * Validate the add-on's configuration.
     *
     * @param array $config The add-on's configuration section
     * @return array Array of validation errors, empty if valid
     */
    public function validateConfig(array $config): array;

    /**
     * Get the default configuration for this add-on.
     *
     * @return array Default configuration values
     */
    public function getDefaultConfig(): array;

    /**
     * Get the services managed by this add-on.
     *
     * @return array List of service names
     */
    public function getServices(): array;

    /**
     * Get TUI menu items provided by this add-on.
     *
     * @return array Menu item definitions
     */
    public function getTuiMenus(): array;

    /**
     * Get web admin routes provided by this add-on.
     *
     * @return array Route definitions
     */
    public function getWebRoutes(): array;
}
