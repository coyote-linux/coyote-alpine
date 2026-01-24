<?php

namespace Coyote\WebAdmin\Service;

use Coyote\Config\ConfigManager;
use Coyote\Util\Logger;

/**
 * Service for applying configuration changes with rollback protection.
 *
 * Implements a 60-second confirmation countdown to prevent lockouts
 * from misconfigured network settings.
 */
class ApplyService
{
    /** @var int Confirmation timeout in seconds */
    private const CONFIRM_TIMEOUT = 60;

    /** @var string Path to pending apply state file */
    private const STATE_FILE = '/tmp/coyote-apply-state.json';

    /** @var ConfigManager */
    private ConfigManager $configManager;

    /** @var Logger */
    private Logger $logger;

    /**
     * Create a new ApplyService instance.
     */
    public function __construct()
    {
        $this->configManager = new ConfigManager();
        $this->logger = new Logger('coyote-apply');
    }

    /**
     * Apply configuration changes and start countdown.
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

        // Create backup of current config
        $backupName = 'pre-apply-' . date('Y-m-d-His');
        $this->configManager->backup($backupName);

        // Save state for rollback
        $state = [
            'backup_name' => $backupName,
            'started_at' => time(),
            'expires_at' => time() + self::CONFIRM_TIMEOUT,
        ];
        file_put_contents(self::STATE_FILE, json_encode($state));

        // Apply configuration
        try {
            $this->applyConfiguration();

            $this->logger->info('Configuration applied, awaiting confirmation');

            return [
                'success' => true,
                'message' => 'Configuration applied. Please confirm within ' . self::CONFIRM_TIMEOUT . ' seconds.',
                'timeout' => self::CONFIRM_TIMEOUT,
            ];
        } catch (\Exception $e) {
            // Immediate rollback on apply failure
            $this->rollback();
            unlink(self::STATE_FILE);

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

        // Confirmation successful - save to persistent storage
        if (!$this->configManager->save()) {
            return [
                'success' => false,
                'message' => 'Failed to save configuration to persistent storage',
            ];
        }

        // Clean up state
        unlink(self::STATE_FILE);

        $this->logger->info('Configuration confirmed and saved');

        return [
            'success' => true,
            'message' => 'Configuration confirmed and saved',
        ];
    }

    /**
     * Manually rollback to the previous configuration.
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

        if (!$state || !isset($state['backup_name'])) {
            unlink(self::STATE_FILE);
            return [
                'success' => false,
                'message' => 'Invalid state file',
            ];
        }

        // Restore from backup
        if (!$this->configManager->restore($state['backup_name'])) {
            return [
                'success' => false,
                'message' => 'Failed to restore backup',
            ];
        }

        // Reload and reapply the restored configuration
        $this->configManager->load();
        $this->applyConfiguration();

        // Clean up state
        unlink(self::STATE_FILE);

        $this->logger->info('Configuration rolled back to: ' . $state['backup_name']);

        return [
            'success' => true,
            'message' => 'Configuration rolled back successfully',
        ];
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
     * Apply the current running configuration.
     *
     * @return void
     * @throws \Exception If apply fails
     */
    private function applyConfiguration(): void
    {
        // Execute the apply-config script
        $output = [];
        $returnCode = 0;
        exec('/opt/coyote/bin/apply-config 2>&1', $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \Exception(implode("\n", $output));
        }
    }
}
