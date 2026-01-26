<?php

namespace Coyote\WebAdmin\Service;

use Coyote\Config\ConfigManager;
use Coyote\Util\Logger;

/**
 * Service for applying configuration changes with rollback protection.
 *
 * Implements a 60-second confirmation countdown to prevent lockouts
 * from misconfigured network settings.
 *
 * Flow:
 * 1. apply() - Apply working-config to system, start 60-second countdown
 * 2. confirm() - User confirms, save working-config → running-config → persistent
 * 3. rollback() - Timeout or manual rollback, reapply running-config to system
 */
class ApplyService
{
    /** @var int Confirmation timeout in seconds */
    private const CONFIRM_TIMEOUT = 60;

    /** @var string Path to pending apply state file */
    private const STATE_FILE = '/tmp/coyote-apply-state.json';

    /** @var string Path to working configuration */
    private const WORKING_CONFIG = '/tmp/working-config/system.json';

    /** @var string Path to running configuration */
    private const RUNNING_CONFIG = '/tmp/running-config/system.json';

    /** @var ConfigService */
    private ConfigService $configService;

    /** @var Logger */
    private Logger $logger;

    /**
     * Create a new ApplyService instance.
     */
    public function __construct()
    {
        $this->configService = new ConfigService();
        $this->logger = new Logger('coyote-apply');
    }

    /**
     * Apply working configuration to the system and start countdown.
     *
     * The working-config is applied to the system. If the user confirms
     * within 60 seconds, it becomes the new running-config and is saved
     * to persistent storage. If not confirmed, the running-config is
     * reapplied (rollback).
     *
     * @return array{success: bool, message: string, timeout: int}
     */
    public function apply(): array
    {
        // Check if there's already a pending apply
        if ($this->hasPendingApply()) {
            return [
                'success' => false,
                'message' => 'Another apply operation is pending confirmation',
                'timeout' => $this->getRemainingTime(),
            ];
        }

        // Check if there are changes to apply
        if (!file_exists(self::WORKING_CONFIG)) {
            return [
                'success' => false,
                'message' => 'No configuration changes to apply',
                'timeout' => 0,
            ];
        }

        // Save state for rollback (references current running-config)
        $state = [
            'started_at' => time(),
            'expires_at' => time() + self::CONFIRM_TIMEOUT,
            'working_config_hash' => md5_file(self::WORKING_CONFIG),
        ];

        // Also save a backup of running-config in case we need to rollback
        if (file_exists(self::RUNNING_CONFIG)) {
            $state['rollback_config'] = file_get_contents(self::RUNNING_CONFIG);
        }

        file_put_contents(self::STATE_FILE, json_encode($state));

        // Apply the working configuration
        try {
            $this->applyConfigFile(self::WORKING_CONFIG);

            $this->logger->info('Working configuration applied, awaiting confirmation');

            return [
                'success' => true,
                'message' => 'Configuration applied. Please confirm within ' . self::CONFIRM_TIMEOUT . ' seconds.',
                'timeout' => self::CONFIRM_TIMEOUT,
            ];
        } catch (\Exception $e) {
            // Immediate rollback on apply failure
            $this->rollback();

            return [
                'success' => false,
                'message' => 'Failed to apply configuration: ' . $e->getMessage(),
                'timeout' => 0,
            ];
        }
    }

    /**
     * Confirm the pending configuration changes.
     *
     * Promotes working-config to running-config and saves to persistent storage.
     *
     * @return array{success: bool, message: string}
     */
    public function confirm(): array
    {
        if (!$this->hasPendingApply()) {
            return [
                'success' => false,
                'message' => 'No pending configuration to confirm',
            ];
        }

        // Check if timeout has expired
        if ($this->getRemainingTime() <= 0) {
            $this->rollback();
            return [
                'success' => false,
                'message' => 'Confirmation timeout expired, configuration rolled back',
            ];
        }

        // Promote working-config to running-config
        if (!$this->configService->promoteWorkingToRunning()) {
            return [
                'success' => false,
                'message' => 'Failed to update running configuration',
            ];
        }

        // Save running-config to persistent storage
        if (!$this->configService->saveRunningToPersistent()) {
            return [
                'success' => false,
                'message' => 'Failed to save configuration to persistent storage',
            ];
        }

        // Clean up state
        @unlink(self::STATE_FILE);

        $this->logger->info('Configuration confirmed and saved');

        return [
            'success' => true,
            'message' => 'Configuration confirmed and saved',
        ];
    }

    /**
     * Cancel the pending apply and rollback to running configuration.
     *
     * @return array{success: bool, message: string}
     */
    public function cancel(): array
    {
        return $this->rollback();
    }

    /**
     * Rollback to the previous running configuration.
     *
     * @return array{success: bool, message: string}
     */
    public function rollback(): array
    {
        if (!file_exists(self::STATE_FILE)) {
            return [
                'success' => false,
                'message' => 'No pending configuration to rollback',
            ];
        }

        $state = json_decode(file_get_contents(self::STATE_FILE), true);

        // Restore from the saved rollback config if we have it
        if (isset($state['rollback_config'])) {
            // Restore the original running-config
            @mkdir(dirname(self::RUNNING_CONFIG), 0755, true);
            file_put_contents(self::RUNNING_CONFIG, $state['rollback_config']);
        }

        // Reapply the running configuration
        try {
            if (file_exists(self::RUNNING_CONFIG)) {
                $this->applyConfigFile(self::RUNNING_CONFIG);
            }

            $this->logger->info('Configuration rolled back');

            // Clean up state
            @unlink(self::STATE_FILE);

            // Discard the working config changes
            $this->configService->discardWorkingConfig();

            return [
                'success' => true,
                'message' => 'Configuration rolled back successfully',
            ];
        } catch (\Exception $e) {
            // Even if apply fails, clean up state
            @unlink(self::STATE_FILE);

            return [
                'success' => false,
                'message' => 'Rollback failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Check if there's a pending apply operation.
     *
     * @return bool True if pending
     */
    public function hasPendingApply(): bool
    {
        if (!file_exists(self::STATE_FILE)) {
            return false;
        }

        // Check if expired
        if ($this->getRemainingTime() <= 0) {
            // Auto-rollback on expiry
            $this->rollback();
            return false;
        }

        return true;
    }

    /**
     * Get remaining confirmation time.
     *
     * @return int Remaining seconds
     */
    public function getRemainingTime(): int
    {
        if (!file_exists(self::STATE_FILE)) {
            return 0;
        }

        $state = json_decode(file_get_contents(self::STATE_FILE), true);
        if (!$state || !isset($state['expires_at'])) {
            return 0;
        }

        return max(0, $state['expires_at'] - time());
    }

    /**
     * Get status of the apply service.
     *
     * @return array{pending: bool, remaining: int, hasChanges: bool}
     */
    public function getStatus(): array
    {
        return [
            'pending' => $this->hasPendingApply(),
            'remaining' => $this->getRemainingTime(),
            'hasChanges' => $this->configService->hasUncommittedChanges(),
        ];
    }

    /**
     * Apply configuration from a specific file.
     *
     * @param string $configFile Path to config file
     * @return void
     * @throws \Exception If apply fails
     */
    private function applyConfigFile(string $configFile): void
    {
        // Execute the apply-config script with the config file path
        $output = [];
        $returnCode = 0;

        // Set environment variable to tell apply-config which file to use
        $cmd = sprintf(
            'COYOTE_CONFIG_FILE=%s /opt/coyote/bin/apply-config 2>&1',
            escapeshellarg($configFile)
        );
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \Exception(implode("\n", $output));
        }
    }
}
