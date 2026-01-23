<?php

namespace Coyote\Config;

/**
 * Central configuration management for Coyote Linux.
 *
 * Handles loading, saving, and applying system configuration.
 * Configuration is stored as JSON on persistent storage and
 * loaded into RAM as the "running configuration".
 */
class ConfigManager
{
    /** @var string Path to persistent configuration storage */
    private string $persistentPath;

    /** @var string Path to running configuration in RAM */
    private string $runningPath;

    /** @var ConfigLoader */
    private ConfigLoader $loader;

    /** @var ConfigWriter */
    private ConfigWriter $writer;

    /** @var ConfigValidator */
    private ConfigValidator $validator;

    /** @var RunningConfig|null Currently loaded configuration */
    private ?RunningConfig $runningConfig = null;

    /**
     * Create a new ConfigManager instance.
     *
     * @param string $persistentPath Path to persistent config (default: /mnt/config)
     * @param string $runningPath Path to running config in RAM (default: /tmp/running-config)
     */
    public function __construct(
        string $persistentPath = '/mnt/config',
        string $runningPath = '/tmp/running-config'
    ) {
        $this->persistentPath = $persistentPath;
        $this->runningPath = $runningPath;
        $this->loader = new ConfigLoader();
        $this->writer = new ConfigWriter();
        $this->validator = new ConfigValidator();
    }

    /**
     * Load configuration from persistent storage into running config.
     *
     * @return RunningConfig The loaded configuration
     * @throws \RuntimeException If configuration cannot be loaded
     */
    public function load(): RunningConfig
    {
        $configFile = $this->persistentPath . '/system.json';

        if (!file_exists($configFile)) {
            // Load defaults if no config exists
            $this->runningConfig = new RunningConfig($this->getDefaults());
        } else {
            $data = $this->loader->load($configFile);
            $this->runningConfig = new RunningConfig($data);
        }

        // Write to running config location
        $this->writer->write($this->runningPath . '/system.json', $this->runningConfig->toArray());

        return $this->runningConfig;
    }

    /**
     * Get the currently loaded running configuration.
     *
     * @return RunningConfig|null
     */
    public function getRunningConfig(): ?RunningConfig
    {
        return $this->runningConfig;
    }

    /**
     * Save running configuration to persistent storage.
     *
     * @return bool True if saved successfully
     */
    public function save(): bool
    {
        if (!$this->runningConfig) {
            return false;
        }

        // Validate before saving
        $errors = $this->validator->validate($this->runningConfig->toArray());
        if (!empty($errors)) {
            return false;
        }

        return $this->writer->write(
            $this->persistentPath . '/system.json',
            $this->runningConfig->toArray()
        );
    }

    /**
     * Validate the current running configuration.
     *
     * @return array Array of validation errors, empty if valid
     */
    public function validate(): array
    {
        if (!$this->runningConfig) {
            return ['Configuration not loaded'];
        }

        return $this->validator->validate($this->runningConfig->toArray());
    }

    /**
     * Get default configuration values.
     *
     * @return array Default configuration
     */
    public function getDefaults(): array
    {
        $defaultsFile = '/opt/coyote/defaults/system.json';

        if (file_exists($defaultsFile)) {
            return $this->loader->load($defaultsFile);
        }

        return [
            'system' => [
                'hostname' => 'coyote',
                'timezone' => 'UTC',
            ],
            'network' => [
                'interfaces' => [],
                'routes' => [],
            ],
            'services' => [],
            'addons' => [],
        ];
    }

    /**
     * Create a backup of the current configuration.
     *
     * @param string $backupName Name for the backup
     * @return bool True if backup created successfully
     */
    public function backup(string $backupName): bool
    {
        $backupDir = $this->persistentPath . '/backups';

        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $backupFile = $backupDir . '/' . $backupName . '.json';
        $sourceFile = $this->persistentPath . '/system.json';

        if (!file_exists($sourceFile)) {
            return false;
        }

        return copy($sourceFile, $backupFile);
    }

    /**
     * Restore configuration from a backup.
     *
     * @param string $backupName Name of the backup to restore
     * @return bool True if restored successfully
     */
    public function restore(string $backupName): bool
    {
        $backupFile = $this->persistentPath . '/backups/' . $backupName . '.json';

        if (!file_exists($backupFile)) {
            return false;
        }

        $destFile = $this->persistentPath . '/system.json';
        return copy($backupFile, $destFile);
    }
}
