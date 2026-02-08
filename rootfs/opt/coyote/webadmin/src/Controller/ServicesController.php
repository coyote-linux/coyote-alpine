<?php

namespace Coyote\WebAdmin\Controller;

use Coyote\System\Services;
use Coyote\WebAdmin\FeatureFlags;

/**
 * Services management controller.
 */
class ServicesController extends BaseController
{
    /** @var array Allowed services that can be managed via web UI */
    private array $allowedServices = [
        'lighttpd' => 'Web Server',
        'dropbear' => 'SSH Server',
        'dnsmasq' => 'DNS/DHCP Server',
        'haproxy' => 'Load Balancer',
        'strongswan' => 'VPN Server',
        'crond' => 'Cron Daemon',
        'ntpd' => 'NTP Client',
        'syslogd' => 'System Logger',
    ];

    /**
     * Core services that are always running and managed by Coyote.
     * Access is controlled via firewall ACLs, not by stopping the service.
     */
    private array $coreServices = ['lighttpd', 'dropbear'];

    public function __construct()
    {
        parent::__construct();

        $features = new FeatureFlags();

        if (!$features->isLoadBalancerAvailable()) {
            unset($this->allowedServices['haproxy']);
        }

        if (!$features->isIpsecAvailable()) {
            unset($this->allowedServices['strongswan']);
        }
    }

    /**
     * Display services overview.
     */
    public function index(array $params = []): void
    {
        $svc = new Services();

        $services = [];
        foreach ($this->allowedServices as $name => $desc) {
            $isCoreService = in_array($name, $this->coreServices);
            $services[$name] = [
                'description' => $desc,
                'running' => $svc->isRunning($name),
                'enabled' => $isCoreService ? true : $svc->isEnabled($name),
                'core' => $isCoreService,
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

        if (!$this->isAllowedService($service)) {
            $this->flash('error', "Service '{$service}' is not allowed to be managed");
            $this->redirect('/services');
            return;
        }

        // Core services cannot be started/stopped - they're always running
        if (in_array($service, $this->coreServices)) {
            $this->flash('error', "Core services cannot be managed - use firewall ACLs to control access");
            $this->redirect('/services');
            return;
        }

        $svc = new Services();

        if ($svc->isRunning($service)) {
            $this->flash('warning', "Service '{$service}' is already running");
        } elseif ($svc->start($service)) {
            $this->flash('success', "Service '{$service}' started successfully");
        } else {
            $this->flash('error', "Failed to start service '{$service}'");
        }

        $this->redirect('/services');
    }

    /**
     * Stop a service.
     */
    public function stop(array $params = []): void
    {
        $service = $params['service'] ?? '';

        if (!$this->isAllowedService($service)) {
            $this->flash('error', "Service '{$service}' is not allowed to be managed");
            $this->redirect('/services');
            return;
        }

        // Core services cannot be started/stopped - they're always running
        if (in_array($service, $this->coreServices)) {
            $this->flash('error', "Core services cannot be managed - use firewall ACLs to control access");
            $this->redirect('/services');
            return;
        }

        $svc = new Services();

        if (!$svc->isRunning($service)) {
            $this->flash('warning', "Service '{$service}' is not running");
        } elseif ($svc->stop($service)) {
            $this->flash('success', "Service '{$service}' stopped successfully");
        } else {
            $this->flash('error', "Failed to stop service '{$service}'");
        }

        $this->redirect('/services');
    }

    /**
     * Restart a service.
     */
    public function restart(array $params = []): void
    {
        $service = $params['service'] ?? '';

        if (!$this->isAllowedService($service)) {
            $this->flash('error', "Service '{$service}' is not allowed to be managed");
            $this->redirect('/services');
            return;
        }

        $svc = new Services();

        if ($svc->restart($service)) {
            $this->flash('success', "Service '{$service}' restarted successfully");
        } else {
            $this->flash('error', "Failed to restart service '{$service}'");
        }

        $this->redirect('/services');
    }

    /**
     * Enable a service to start at boot.
     */
    public function enable(array $params = []): void
    {
        $service = $params['service'] ?? '';

        if (!$this->isAllowedService($service)) {
            $this->flash('error', "Service '{$service}' is not allowed to be managed");
            $this->redirect('/services');
            return;
        }

        // Core services are always enabled
        if (in_array($service, $this->coreServices)) {
            $this->flash('error', "Core services are always enabled - use firewall ACLs to control access");
            $this->redirect('/services');
            return;
        }

        $svc = new Services();

        if ($svc->isEnabled($service)) {
            $this->flash('warning', "Service '{$service}' is already enabled");
        } elseif ($svc->enable($service)) {
            $this->flash('success', "Service '{$service}' enabled at boot");
        } else {
            $this->flash('error', "Failed to enable service '{$service}'");
        }

        $this->redirect('/services');
    }

    /**
     * Disable a service from starting at boot.
     */
    public function disable(array $params = []): void
    {
        $service = $params['service'] ?? '';

        if (!$this->isAllowedService($service)) {
            $this->flash('error', "Service '{$service}' is not allowed to be managed");
            $this->redirect('/services');
            return;
        }

        // Core services are always enabled
        if (in_array($service, $this->coreServices)) {
            $this->flash('error', "Core services are always enabled - use firewall ACLs to control access");
            $this->redirect('/services');
            return;
        }

        // Don't allow disabling critical services
        if ($service === 'syslogd') {
            $this->flash('error', "Cannot disable critical service '{$service}'");
            $this->redirect('/services');
            return;
        }

        $svc = new Services();

        if (!$svc->isEnabled($service)) {
            $this->flash('warning', "Service '{$service}' is already disabled");
        } elseif ($svc->disable($service)) {
            $this->flash('success', "Service '{$service}' disabled at boot");
        } else {
            $this->flash('error', "Failed to disable service '{$service}'");
        }

        $this->redirect('/services');
    }

    /**
     * Check if a service is allowed to be managed.
     */
    private function isAllowedService(string $service): bool
    {
        return array_key_exists($service, $this->allowedServices);
    }
}
