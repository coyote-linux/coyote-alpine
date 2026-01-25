<?php

namespace Coyote\WebAdmin\Controller;

use Coyote\System\Services;

/**
 * Load balancer configuration controller.
 */
class LoadBalancerController extends BaseController
{
    /**
     * Display load balancer overview.
     */
    public function index(array $params = []): void
    {
        $services = new Services();

        $data = [
            'haproxy_running' => $services->isRunning('haproxy'),
            'frontend_count' => 0,
            'backend_count' => 0,
        ];

        $this->render('pages/loadbalancer', $data);
    }

    /**
     * Display load balancer statistics.
     */
    public function stats(array $params = []): void
    {
        $services = new Services();

        $data = [
            'haproxy_running' => $services->isRunning('haproxy'),
            'frontend_count' => 0,
            'backend_count' => 0,
        ];

        $this->render('pages/loadbalancer', $data);
    }
}
