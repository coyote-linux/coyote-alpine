<?php

namespace Coyote\WebAdmin\Controller;

use Coyote\System\Hardware;
use Coyote\System\Network;
use Coyote\System\Services;
use Coyote\Firewall\FirewallManager;
use Coyote\LoadBalancer\LoadBalancerManager;
use Coyote\WebAdmin\FeatureFlags;

/**
 * Dashboard controller - system overview.
 */
class DashboardController extends BaseController
{
    /**
     * Display the dashboard.
     *
     * @param array $params Route parameters
     * @return void
     */
    public function index(array $params = []): void
    {
        $hardware = new Hardware();
        $network = new Network();
        $services = new Services();
        $features = new FeatureFlags();

        $data = [
            'system' => [
                'hostname' => gethostname(),
                'uptime' => $this->getUptime(),
                'cpu' => $hardware->getCpuInfo(),
                'memory' => $hardware->getMemoryInfo(),
                'load' => $hardware->getLoadAverage(),
            ],
            'network' => [
                'interfaces' => $network->getInterfaces(),
            ],
            'services' => $this->getServiceStatus($services, $features),
            'firewall' => $this->getFirewallStatus(),
            'loadbalancer' => $features->isLoadBalancerAvailable() ? $this->getLoadBalancerStatus() : ['running' => false],
            'features' => $features->toArray(),
        ];

        $this->render('pages/dashboard', $data);
    }

    /**
     * Get system uptime.
     *
     * @return string Uptime string
     */
    private function getUptime(): string
    {
        $uptime = @file_get_contents('/proc/uptime');
        if ($uptime === false) {
            return 'unknown';
        }

        $seconds = (int)explode(' ', $uptime)[0];
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        return "{$days}d {$hours}h {$minutes}m";
    }

    /**
     * Get status of key services.
     *
     * @param Services $services Services instance
     * @return array Service status
     */
    private function getServiceStatus(Services $services, FeatureFlags $features): array
    {
        // Service name => display name mapping
        $keyServices = [
            'dropbear' => 'SSH',
            'lighttpd' => 'Web Server',
            'dnsmasq' => 'DNS/DHCP',
            'haproxy' => 'Load Balancer',
            'strongswan' => 'VPN',
        ];

        if (!$features->isLoadBalancerAvailable()) {
            unset($keyServices['haproxy']);
        }

        if (!$features->isIpsecAvailable()) {
            unset($keyServices['strongswan']);
        }

        $status = [];

        foreach ($keyServices as $service => $displayName) {
            $isCoreService = $services->isCoreService($service);
            $status[$service] = [
                'name' => $displayName,
                'running' => $services->isRunning($service),
                'enabled' => $isCoreService ? true : $services->isEnabled($service),
                'core' => $isCoreService,
            ];
        }

        return $status;
    }

    /**
     * Get firewall status summary.
     *
     * @return array Firewall status
     */
    private function getFirewallStatus(): array
    {
        try {
            $manager = new FirewallManager();
            return $manager->getStatus();
        } catch (\Exception $e) {
            return ['enabled' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get load balancer status summary.
     *
     * @return array Load balancer status
     */
    private function getLoadBalancerStatus(): array
    {
        try {
            $manager = new LoadBalancerManager();
            return $manager->getStatus();
        } catch (\Exception $e) {
            return ['enabled' => false, 'error' => $e->getMessage()];
        }
    }
}
