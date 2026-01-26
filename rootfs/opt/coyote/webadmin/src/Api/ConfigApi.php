<?php

namespace Coyote\WebAdmin\Api;

use Coyote\WebAdmin\Service\ConfigService;
use Coyote\WebAdmin\Service\ApplyService;

/**
 * REST API for configuration management.
 */
class ConfigApi extends BaseApi
{
    /** @var ConfigService */
    private ConfigService $configService;

    /** @var ApplyService */
    private ApplyService $applyService;

    /**
     * Create a new ConfigApi instance.
     */
    public function __construct()
    {
        $this->configService = new ConfigService();
        $this->applyService = new ApplyService();
    }

    /**
     * Get current configuration.
     *
     * Returns the working configuration if there are uncommitted changes,
     * otherwise returns the running configuration.
     *
     * @param array $params Route parameters
     * @return void
     */
    public function get(array $params = []): void
    {
        try {
            $config = $this->configService->getWorkingConfig();
            $status = $this->applyService->getStatus();

            $this->json([
                'config' => $config->toArray(),
                'status' => $status,
            ]);
        } catch (\Exception $e) {
            $this->error('Failed to load configuration: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update configuration.
     *
     * Updates are saved to the working configuration. They are not applied
     * to the system until the apply endpoint is called.
     *
     * @param array $params Route parameters
     * @return void
     */
    public function update(array $params = []): void
    {
        $data = $this->getJsonBody();

        if (empty($data)) {
            $this->error('No configuration data provided');
            return;
        }

        try {
            // Load working config
            $config = $this->configService->getWorkingConfig();

            // Merge updates
            foreach ($data as $key => $value) {
                $config->set($key, $value);
            }

            // Save to working config
            if ($this->configService->saveWorkingConfig($config)) {
                $this->json([
                    'success' => true,
                    'message' => 'Configuration updated. Call /api/config/apply to apply changes.',
                ]);
            } else {
                $this->error('Failed to save working configuration', 500);
            }
        } catch (\Exception $e) {
            $this->error('Failed to update configuration: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Apply configuration changes.
     *
     * Applies the working configuration to the system and starts the
     * 60-second confirmation countdown.
     *
     * @param array $params Route parameters
     * @return void
     */
    public function apply(array $params = []): void
    {
        $result = $this->applyService->apply();
        $this->json($result, $result['success'] ? 200 : 400);
    }

    /**
     * Confirm applied configuration.
     *
     * Confirms the applied configuration, promoting it to running config
     * and saving it to persistent storage.
     *
     * @param array $params Route parameters
     * @return void
     */
    public function confirm(array $params = []): void
    {
        $result = $this->applyService->confirm();
        $this->json($result, $result['success'] ? 200 : 400);
    }

    /**
     * Rollback to previous configuration.
     *
     * Cancels the pending apply operation and restores the previous
     * running configuration.
     *
     * @param array $params Route parameters
     * @return void
     */
    public function rollback(array $params = []): void
    {
        $result = $this->applyService->rollback();
        $this->json($result, $result['success'] ? 200 : 400);
    }

    /**
     * Get configuration status.
     *
     * @param array $params Route parameters
     * @return void
     */
    public function status(array $params = []): void
    {
        $this->json($this->applyService->getStatus());
    }
}
