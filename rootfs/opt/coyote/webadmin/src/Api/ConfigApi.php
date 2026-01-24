<?php

namespace Coyote\WebAdmin\Api;

use Coyote\Config\ConfigManager;
use Coyote\WebAdmin\Service\ApplyService;

/**
 * REST API for configuration management.
 */
class ConfigApi extends BaseApi
{
    /** @var ConfigManager */
    private ConfigManager $configManager;

    /** @var ApplyService */
    private ApplyService $applyService;

    /**
     * Create a new ConfigApi instance.
     */
    public function __construct()
    {
        $this->configManager = new ConfigManager();
        $this->applyService = new ApplyService();
    }

    /**
     * Get current configuration.
     *
     * @param array $params Route parameters
     * @return void
     */
    public function get(array $params = []): void
    {
        try {
            $config = $this->configManager->load();
            $this->json($config->toArray());
        } catch (\Exception $e) {
            $this->error('Failed to load configuration: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update configuration.
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
            // Load current config
            $config = $this->configManager->load();

            // Merge updates
            foreach ($data as $key => $value) {
                $config->set($key, $value);
            }

            // Validate
            $errors = $this->configManager->validate();
            if (!empty($errors)) {
                $this->json(['success' => false, 'errors' => $errors], 400);
                return;
            }

            $this->success('Configuration updated (not yet applied)');
        } catch (\Exception $e) {
            $this->error('Failed to update configuration: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Apply configuration changes.
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
     * @param array $params Route parameters
     * @return void
     */
    public function rollback(array $params = []): void
    {
        $result = $this->applyService->rollback();
        $this->json($result, $result['success'] ? 200 : 400);
    }
}
