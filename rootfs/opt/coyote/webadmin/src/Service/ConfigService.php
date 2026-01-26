<?php

namespace Coyote\WebAdmin\Service;

use Coyote\Config\ConfigLoader;
use Coyote\Config\ConfigWriter;
use Coyote\Config\RunningConfig;

/**
 * Service for managing configuration in the web admin.
 *
 * Manages three configuration states:
 * - working-config: Uncommitted changes made in the web admin
 * - running-config: Configuration currently applied to the system
 * - persistent: Configuration saved to /mnt/config (survives reboot)
 *
 * Flow:
 * 1. User makes changes → written to working-config
 * 2. User applies changes → working-config applied to system, 60-second countdown starts
 * 3. User confirms → working-config copied to running-config, then saved to persistent
 * 4. Timeout without confirm → running-config reapplied (rollback)
 */
class ConfigService
{
    /** @var string Path to working configuration (uncommitted changes) */
    private const WORKING_CONFIG_DIR = '/tmp/working-config';

    /** @var string Path to running configuration (currently applied) */
    private const RUNNING_CONFIG_DIR = '/tmp/running-config';

    /** @var string Path to persistent configuration */
    private const PERSISTENT_CONFIG_DIR = '/mnt/config';

    /** @var string Config filename */
    private const CONFIG_FILE = 'system.json';

    /** @var ConfigLoader */
    private ConfigLoader $loader;

    /** @var ConfigWriter */
    private ConfigWriter $writer;

    /**
     * Create a new ConfigService instance.
     */
    public function __construct()
    {
        $this->loader = new ConfigLoader();
        $this->writer = new ConfigWriter();
    }

    /**
     * Get the working configuration.
     *
     * Returns the working config if it exists, otherwise falls back to running config.
     *
     * @return RunningConfig
     */
    public function getWorkingConfig(): RunningConfig
    {
        $workingFile = self::WORKING_CONFIG_DIR . '/' . self::CONFIG_FILE;
        $runningFile = self::RUNNING_CONFIG_DIR . '/' . self::CONFIG_FILE;

        if (file_exists($workingFile)) {
            return new RunningConfig($this->loader->load($workingFile));
        }

        if (file_exists($runningFile)) {
            return new RunningConfig($this->loader->load($runningFile));
        }

        // Fall back to persistent or defaults
        return $this->getRunningConfig();
    }

    /**
     * Get the running configuration (currently applied to system).
     *
     * @return RunningConfig
     */
    public function getRunningConfig(): RunningConfig
    {
        $runningFile = self::RUNNING_CONFIG_DIR . '/' . self::CONFIG_FILE;

        if (file_exists($runningFile)) {
            return new RunningConfig($this->loader->load($runningFile));
        }

        // Fall back to persistent
        $persistentFile = self::PERSISTENT_CONFIG_DIR . '/' . self::CONFIG_FILE;
        if (file_exists($persistentFile)) {
            return new RunningConfig($this->loader->load($persistentFile));
        }

        // Return defaults
        return new RunningConfig($this->getDefaults());
    }

    /**
     * Save changes to the working configuration.
     *
     * This does NOT apply the changes to the system - use ApplyService for that.
     *
     * @param RunningConfig $config The configuration to save
     * @return bool True if saved successfully
     */
    public function saveWorkingConfig(RunningConfig $config): bool
    {
        $this->ensureDirectory(self::WORKING_CONFIG_DIR);
        return $this->writer->write(
            self::WORKING_CONFIG_DIR . '/' . self::CONFIG_FILE,
            $config->toArray()
        );
    }

    /**
     * Check if there are uncommitted changes in the working config.
     *
     * @return bool True if working config differs from running config
     */
    public function hasUncommittedChanges(): bool
    {
        $workingFile = self::WORKING_CONFIG_DIR . '/' . self::CONFIG_FILE;

        if (!file_exists($workingFile)) {
            return false;
        }

        $runningFile = self::RUNNING_CONFIG_DIR . '/' . self::CONFIG_FILE;

        if (!file_exists($runningFile)) {
            return true;
        }

        // Compare the two files
        $working = $this->loader->load($workingFile);
        $running = $this->loader->load($runningFile);

        return $working !== $running;
    }

    /**
     * Discard uncommitted changes in the working config.
     *
     * @return bool True if discarded successfully
     */
    public function discardWorkingConfig(): bool
    {
        $workingFile = self::WORKING_CONFIG_DIR . '/' . self::CONFIG_FILE;

        if (file_exists($workingFile)) {
            return unlink($workingFile);
        }

        return true;
    }

    /**
     * Copy working config to running config.
     *
     * Called after configuration is successfully applied.
     *
     * @return bool True if copied successfully
     */
    public function promoteWorkingToRunning(): bool
    {
        $workingFile = self::WORKING_CONFIG_DIR . '/' . self::CONFIG_FILE;
        $runningFile = self::RUNNING_CONFIG_DIR . '/' . self::CONFIG_FILE;

        if (!file_exists($workingFile)) {
            return false;
        }

        $this->ensureDirectory(self::RUNNING_CONFIG_DIR);
        return copy($workingFile, $runningFile);
    }

    /**
     * Save running config to persistent storage.
     *
     * Called after user confirms the applied configuration.
     *
     * @return bool True if saved successfully
     */
    public function saveRunningToPersistent(): bool
    {
        $runningFile = self::RUNNING_CONFIG_DIR . '/' . self::CONFIG_FILE;

        if (!file_exists($runningFile)) {
            return false;
        }

        // Remount config partition read-write
        if (!$this->remountConfig(true)) {
            return false;
        }

        try {
            $result = copy($runningFile, self::PERSISTENT_CONFIG_DIR . '/' . self::CONFIG_FILE);
        } finally {
            // Always remount read-only
            $this->remountConfig(false);
        }

        // Clear the working config after successful save
        if ($result) {
            $this->discardWorkingConfig();
        }

        return $result;
    }

    /**
     * Get default configuration values.
     *
     * @return array
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
     * Ensure a directory exists with proper permissions.
     *
     * @param string $dir Directory path
     * @return void
     */
    private function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
            // Set ownership for web server access
            @chown($dir, 'lighttpd');
            @chgrp($dir, 'lighttpd');
        }
    }

    /**
     * Remount the config partition.
     *
     * @param bool $writable True for read-write, false for read-only
     * @return bool True if successful
     */
    private function remountConfig(bool $writable): bool
    {
        $mode = $writable ? 'rw' : 'ro';
        $cmd = (posix_getuid() === 0) ? 'mount' : 'doas mount';
        exec("{$cmd} -o remount,{$mode} " . escapeshellarg(self::PERSISTENT_CONFIG_DIR) . " 2>&1", $output, $returnCode);
        return $returnCode === 0;
    }
}
