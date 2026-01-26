<?php

namespace Coyote\WebAdmin\Service;

use Coyote\System\Subsystem\SubsystemManager;
use Coyote\Util\Logger;

/**
 * Service for applying configuration changes with rollback protection.
 *
 * Uses subsystem-based configuration to determine:
 * - Which subsystems have changes to apply
 * - Whether the 60-second countdown is required
 *
 * Flow for changes requiring countdown (network, firewall):
 * 1. apply() - Apply working-config to system, start 60-second countdown
 * 2. confirm() - User confirms, save working-config → running-config → persistent
 * 3. rollback() - Timeout or manual rollback, reapply running-config to system
 *
 * Flow for safe changes (hostname, timezone):
 * 1. apply() - Apply changes immediately, save to persistent, no countdown
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

    /** @var SubsystemManager */
    private SubsystemManager $subsystemManager;

    /** @var Logger */
    private Logger $logger;

    /**
     * Create a new ApplyService instance.
     */
    public function __construct()
    {
        $this->configService = new ConfigService();
        $this->subsystemManager = new SubsystemManager();
        $this->logger = new Logger('coyote-apply');
    }

    /**
     * Apply working configuration to the system.
     *
     * Determines which subsystems have changes and whether countdown is required.
     * If no countdown needed, applies immediately and saves to persistent.
     *
     * @return array{success: bool, message: string, timeout: int, requiresCountdown: bool}
     */
    public function apply(): array
    {
        // Check if there's already a pending apply
        if ($this->hasPendingApply()) {
            return [
                'success' => false,
                'message' => 'Another apply operation is pending confirmation',
                'timeout' => $this->getRemainingTime(),
                'requiresCountdown' => true,
            ];
        }

        // Check if there are changes to apply
        if (!file_exists(self::WORKING_CONFIG)) {
            return [
                'success' => false,
                'message' => 'No configuration changes to apply',
                'timeout' => 0,
                'requiresCountdown' => false,
            ];
        }

        // Load configs
        $working = $this->configService->getWorkingConfig()->toArray();
        $running = $this->configService->getRunningConfig()->toArray();

        // Check what changed
        $changedSubsystems = $this->subsystemManager->getChangedSubsystems($working, $running);

        if (empty($changedSubsystems)) {
            return [
                'success' => false,
                'message' => 'No changes detected',
                'timeout' => 0,
                'requiresCountdown' => false,
            ];
        }

        // Determine if countdown is required
        $requiresCountdown = $this->subsystemManager->requiresCountdown($working, $running);

        // Apply the changes
        $result = $this->subsystemManager->applyChanges($working, $running);

        if (!$result['success']) {
            return [
                'success' => false,
                'message' => $result['message'],
                'timeout' => 0,
                'requiresCountdown' => false,
            ];
        }

        if ($requiresCountdown) {
            // Save state for rollback
            $state = [
                'started_at' => time(),
                'expires_at' => time() + self::CONFIRM_TIMEOUT,
                'working_config_hash' => md5_file(self::WORKING_CONFIG),
            ];

            // Save running config for rollback
            if (file_exists(self::RUNNING_CONFIG)) {
                $state['rollback_config'] = file_get_contents(self::RUNNING_CONFIG);
            }

            file_put_contents(self::STATE_FILE, json_encode($state));

            $this->logger->info('Configuration applied (countdown started): ' . $result['message']);

            return [
                'success' => true,
                'message' => $result['message'] . '. Please confirm within ' . self::CONFIRM_TIMEOUT . ' seconds.',
                'timeout' => self::CONFIRM_TIMEOUT,
                'requiresCountdown' => true,
            ];
        } else {
            // No countdown needed - save immediately
            if (!$this->configService->promoteWorkingToRunning()) {
                return [
                    'success' => false,
                    'message' => 'Failed to update running configuration',
                    'timeout' => 0,
                    'requiresCountdown' => false,
                ];
            }

            if (!$this->configService->saveRunningToPersistent()) {
                return [
                    'success' => false,
                    'message' => 'Failed to save to persistent storage',
                    'timeout' => 0,
                    'requiresCountdown' => false,
                ];
            }

            $this->logger->info('Configuration applied and saved: ' . $result['message']);

            return [
                'success' => true,
                'message' => $result['message'] . '. Changes saved.',
                'timeout' => 0,
                'requiresCountdown' => false,
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
            @mkdir(dirname(self::RUNNING_CONFIG), 0755, true);
            file_put_contents(self::RUNNING_CONFIG, $state['rollback_config']);
        }

        // Reapply the running configuration
        if (file_exists(self::RUNNING_CONFIG)) {
            $running = $this->configService->getRunningConfig()->toArray();
            $result = $this->subsystemManager->applyAll($running);

            if (!$result['success']) {
                $this->logger->error('Rollback had errors: ' . $result['message']);
            }
        }

        // Clean up state
        @unlink(self::STATE_FILE);

        // Discard the working config changes
        $this->configService->discardWorkingConfig();

        $this->logger->info('Configuration rolled back');

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
     * Get status of the apply service.
     *
     * @return array{pending: bool, remaining: int, hasChanges: bool, requiresCountdown: bool}
     */
    public function getStatus(): array
    {
        $hasChanges = $this->configService->hasUncommittedChanges();
        $requiresCountdown = false;

        if ($hasChanges) {
            $working = $this->configService->getWorkingConfig()->toArray();
            $running = $this->configService->getRunningConfig()->toArray();
            $requiresCountdown = $this->subsystemManager->requiresCountdown($working, $running);
        }

        return [
            'pending' => $this->hasPendingApply(),
            'remaining' => $this->getRemainingTime(),
            'hasChanges' => $hasChanges,
            'requiresCountdown' => $requiresCountdown,
        ];
    }
}
