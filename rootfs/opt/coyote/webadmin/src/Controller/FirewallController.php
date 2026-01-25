<?php

namespace Coyote\WebAdmin\Controller;

/**
 * Firewall configuration controller.
 */
class FirewallController extends BaseController
{
    /**
     * Display firewall overview.
     */
    public function index(array $params = []): void
    {
        $data = [
            'status' => [
                'enabled' => true,
                'connections' => 0,
            ],
        ];

        $this->render('pages/firewall', $data);
    }

    /**
     * Display firewall rules.
     */
    public function rules(array $params = []): void
    {
        $data = [
            'status' => [
                'enabled' => true,
                'connections' => 0,
            ],
        ];

        $this->render('pages/firewall', $data);
    }

    /**
     * Save firewall rules.
     */
    public function saveRules(array $params = []): void
    {
        $this->flash('warning', 'Firewall rules saving not yet implemented');
        $this->redirect('/firewall/rules');
    }
}
