<?php

namespace Coyote\LoadBalancer;

use Coyote\Util\Logger;

/**
 * Central load balancer management for Coyote Linux.
 *
 * Orchestrates HAProxy-based load balancing services.
 */
class LoadBalancerManager
{
    /** @var HaproxyService */
    private HaproxyService $haproxy;

    /** @var StatsService */
    private StatsService $stats;

    /** @var Logger */
    private Logger $logger;

    /** @var bool Whether the load balancer is enabled */
    private bool $enabled = false;

    /** @var array Current configuration */
    private array $config = [];

    /**
     * Create a new LoadBalancerManager instance.
     */
    public function __construct()
    {
        $this->haproxy = new HaproxyService();
        $this->stats = new StatsService();
        $this->logger = new Logger('coyote-lb');
    }

    /**
     * Apply load balancer configuration.
     *
     * @param array $config Load balancer configuration section
     * @return bool True if successful
     */
    public function applyConfig(array $config): bool
    {
        $this->config = $config;
        $this->enabled = $config['enabled'] ?? false;

        if (!$this->enabled) {
            $this->logger->info('Load balancer disabled');
            return $this->haproxy->stop();
        }

        $this->logger->info('Applying load balancer configuration');

        // Configure stats if enabled
        if ($config['stats']['enabled'] ?? false) {
            $this->stats->configure($config['stats']);
        }

        // Apply HAProxy configuration
        if (!$this->haproxy->applyConfig($config)) {
            $this->logger->error('Failed to apply HAProxy configuration');
            return false;
        }

        $this->logger->info('Load balancer configuration applied successfully');
        return true;
    }

    /**
     * Get load balancer status.
     *
     * @return array Status information
     */
    public function getStatus(): array
    {
        return [
            'enabled' => $this->enabled,
            'running' => $this->haproxy->isRunning(),
            'frontends' => $this->getFrontendStatus(),
            'backends' => $this->getBackendStatus(),
            'stats' => $this->stats->getSummary(),
        ];
    }

    /**
     * Get status of all frontends.
     *
     * @return array Frontend status
     */
    public function getFrontendStatus(): array
    {
        $frontends = [];

        foreach ($this->config['frontends'] ?? [] as $name => $config) {
            $stats = $this->stats->getFrontendStats($name);
            $frontends[$name] = [
                'name' => $name,
                'bind' => $config['bind'] ?? 'unknown',
                'status' => $stats['status'] ?? 'unknown',
                'sessions' => $stats['scur'] ?? 0,
                'bytes_in' => $stats['bin'] ?? 0,
                'bytes_out' => $stats['bout'] ?? 0,
            ];
        }

        return $frontends;
    }

    /**
     * Get status of all backends.
     *
     * @return array Backend status
     */
    public function getBackendStatus(): array
    {
        $backends = [];

        foreach ($this->config['backends'] ?? [] as $name => $config) {
            $stats = $this->stats->getBackendStats($name);
            $servers = [];

            foreach ($config['servers'] ?? [] as $server) {
                $serverName = $server['name'] ?? $server['address'];
                $serverStats = $this->stats->getServerStats($name, $serverName);
                $servers[] = [
                    'name' => $serverName,
                    'address' => $server['address'],
                    'status' => $serverStats['status'] ?? 'unknown',
                    'weight' => $server['weight'] ?? 1,
                ];
            }

            $backends[$name] = [
                'name' => $name,
                'mode' => $config['mode'] ?? 'http',
                'balance' => $config['balance'] ?? 'roundrobin',
                'status' => $stats['status'] ?? 'unknown',
                'servers' => $servers,
            ];
        }

        return $backends;
    }

    /**
     * Create a new frontend configuration.
     *
     * @param string $name Frontend name
     * @return FrontendConfig
     */
    public function createFrontend(string $name): FrontendConfig
    {
        return new FrontendConfig($name);
    }

    /**
     * Create a new backend configuration.
     *
     * @param string $name Backend name
     * @return BackendConfig
     */
    public function createBackend(string $name): BackendConfig
    {
        return new BackendConfig($name);
    }

    /**
     * Add a frontend to the configuration.
     *
     * @param FrontendConfig $frontend Frontend to add
     * @return bool True if successful
     */
    public function addFrontend(FrontendConfig $frontend): bool
    {
        $this->config['frontends'][$frontend->getName()] = $frontend->toArray();
        return $this->applyConfig($this->config);
    }

    /**
     * Add a backend to the configuration.
     *
     * @param BackendConfig $backend Backend to add
     * @return bool True if successful
     */
    public function addBackend(BackendConfig $backend): bool
    {
        $this->config['backends'][$backend->getName()] = $backend->toArray();
        return $this->applyConfig($this->config);
    }

    /**
     * Remove a frontend from the configuration.
     *
     * @param string $name Frontend name
     * @return bool True if successful
     */
    public function removeFrontend(string $name): bool
    {
        unset($this->config['frontends'][$name]);
        return $this->applyConfig($this->config);
    }

    /**
     * Remove a backend from the configuration.
     *
     * @param string $name Backend name
     * @return bool True if successful
     */
    public function removeBackend(string $name): bool
    {
        unset($this->config['backends'][$name]);
        return $this->applyConfig($this->config);
    }

    /**
     * Reload HAProxy configuration.
     *
     * @return bool True if successful
     */
    public function reload(): bool
    {
        return $this->haproxy->reload();
    }

    /**
     * Check if load balancer is enabled.
     *
     * @return bool True if enabled
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Check if load balancer is running.
     *
     * @return bool True if running
     */
    public function isRunning(): bool
    {
        return $this->haproxy->isRunning();
    }

    /**
     * Get the HAProxy service instance.
     *
     * @return HaproxyService
     */
    public function getHaproxyService(): HaproxyService
    {
        return $this->haproxy;
    }

    /**
     * Get the stats service instance.
     *
     * @return StatsService
     */
    public function getStatsService(): StatsService
    {
        return $this->stats;
    }
}
