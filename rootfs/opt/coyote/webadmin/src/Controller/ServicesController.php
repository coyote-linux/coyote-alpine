<?php

namespace Coyote\WebAdmin\Controller;

use Coyote\System\Services;

/**
 * Services management controller.
 */
class ServicesController extends BaseController
{
    /**
     * Display services overview.
     */
    public function index(array $params = []): void
    {
        $svc = new Services();

        $serviceList = [
            'lighttpd' => 'Web Server',
            'dropbear' => 'SSH Server',
            'dnsmasq' => 'DNS/DHCP Server',
            'haproxy' => 'Load Balancer',
            'strongswan' => 'VPN Server',
        ];

        $services = [];
        foreach ($serviceList as $name => $desc) {
            $services[$name] = [
                'description' => $desc,
                'running' => $svc->isRunning($name),
                'enabled' => $svc->isEnabled($name),
            ];
        }

        $data = [
            'services' => $services,
        ];

        $this->render('pages/services', $data);
    }

    /**
     * Start a service.
     */
    public function start(array $params = []): void
    {
        $service = $params['service'] ?? '';
        $this->flash('warning', "Starting service '{$service}' not yet implemented");
        $this->redirect('/services');
    }

    /**
     * Stop a service.
     */
    public function stop(array $params = []): void
    {
        $service = $params['service'] ?? '';
        $this->flash('warning', "Stopping service '{$service}' not yet implemented");
        $this->redirect('/services');
    }
}
