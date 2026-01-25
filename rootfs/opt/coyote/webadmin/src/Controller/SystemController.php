<?php

namespace Coyote\WebAdmin\Controller;

use Coyote\Config\ConfigManager;

/**
 * System configuration controller.
 */
class SystemController extends BaseController
{
    /**
     * Display system settings.
     */
    public function index(array $params = []): void
    {
        $configManager = new ConfigManager();

        try {
            $config = $configManager->load()->toArray();
        } catch (\Exception $e) {
            $config = [];
        }

        $data = [
            'hostname' => $config['system']['hostname'] ?? 'coyote',
            'domain' => $config['system']['domain'] ?? '',
            'timezone' => $config['system']['timezone'] ?? 'UTC',
        ];

        $this->render('pages/system', $data);
    }

    /**
     * Apply configuration changes.
     */
    public function apply(array $params = []): void
    {
        $this->flash('warning', 'Apply configuration not yet implemented');
        $this->redirect('/system');
    }

    /**
     * Confirm configuration changes.
     */
    public function confirm(array $params = []): void
    {
        $this->flash('warning', 'Confirm configuration not yet implemented');
        $this->redirect('/system');
    }
}
