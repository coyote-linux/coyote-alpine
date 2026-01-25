<?php

namespace Coyote\WebAdmin\Controller;

use Coyote\System\Services;

/**
 * VPN configuration controller.
 */
class VpnController extends BaseController
{
    /**
     * Display VPN overview.
     */
    public function index(array $params = []): void
    {
        $services = new Services();

        $data = [
            'strongswan_running' => $services->isRunning('strongswan'),
            'tunnel_count' => 0,
        ];

        $this->render('pages/vpn', $data);
    }

    /**
     * Display VPN tunnels.
     */
    public function tunnels(array $params = []): void
    {
        $services = new Services();

        $data = [
            'strongswan_running' => $services->isRunning('strongswan'),
            'tunnel_count' => 0,
        ];

        $this->render('pages/vpn', $data);
    }

    /**
     * Save VPN tunnels.
     */
    public function saveTunnels(array $params = []): void
    {
        $this->flash('warning', 'VPN configuration not yet implemented');
        $this->redirect('/vpn/tunnels');
    }
}
