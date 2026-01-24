<?php

namespace Coyote\WebAdmin\Api;

use Coyote\System\Hardware;
use Coyote\System\Network;
use Coyote\System\Services;

/**
 * REST API for system status.
 */
class StatusApi extends BaseApi
{
    /**
     * Get overall system status.
     *
     * @param array $params Route parameters
     * @return void
     */
    public function index(array $params = []): void
    {
        $this->json([
            'status' => 'ok',
            'version' => COYOTE_VERSION,
            'hostname' => gethostname(),
            'uptime' => $this->getUptime(),
        ]);
    }

    /**
     * Get detailed system information.
     *
     * @param array $params Route parameters
     * @return void
     */
    public function system(array $params = []): void
    {
        $hardware = new Hardware();

        $this->json([
            'hostname' => gethostname(),
            'uptime' => $this->getUptime(),
            'cpu' => $hardware->getCpuInfo(),
            'memory' => $hardware->getMemoryInfo(),
            'load' => $hardware->getLoadAverage(),
            'disk' => $this->getDiskUsage(),
        ]);
    }

    /**
     * Get network status.
     *
     * @param array $params Route parameters
     * @return void
     */
    public function network(array $params = []): void
    {
        $network = new Network();

        $this->json([
            'interfaces' => $network->getInterfaces(),
            'routes' => $network->getRoutes(),
            'dns' => $network->getDnsServers(),
        ]);
    }

    /**
     * Get system uptime in seconds.
     *
     * @return int Uptime in seconds
     */
    private function getUptime(): int
    {
        $uptime = @file_get_contents('/proc/uptime');
        if ($uptime === false) {
            return 0;
        }
        return (int)explode(' ', $uptime)[0];
    }

    /**
     * Get disk usage information.
     *
     * @return array Disk usage
     */
    private function getDiskUsage(): array
    {
        $total = disk_total_space('/');
        $free = disk_free_space('/');

        return [
            'total' => $total,
            'free' => $free,
            'used' => $total - $free,
            'percent' => round((($total - $free) / $total) * 100, 1),
        ];
    }
}
