<?php

namespace Coyote\WebAdmin\Api;

use Coyote\LoadBalancer\LoadBalancerManager;
use Coyote\WebAdmin\Service\ConfigService;

/**
 * REST API for load balancer status and statistics.
 */
class LoadBalancerApi extends BaseApi
{
    /**
     * Get load balancer status.
     *
     * @param array $params Route parameters
     * @return void
     */
    public function status(array $params = []): void
    {
        $configService = new ConfigService();
        $config = $configService->getWorkingConfig();
        $lbConfig = $config->get('loadbalancer', []);

        $manager = new LoadBalancerManager();
        $manager->applyConfig($lbConfig);

        $this->json($manager->getStatus());
    }

    /**
     * Get load balancer statistics.
     *
     * @param array $params Route parameters
     * @return void
     */
    public function stats(array $params = []): void
    {
        $configService = new ConfigService();
        $config = $configService->getWorkingConfig();
        $lbConfig = $config->get('loadbalancer', []);

        $manager = new LoadBalancerManager();
        $manager->applyConfig($lbConfig);

        $this->json([
            'summary' => $manager->getStatsService()->getSummary(),
            'frontends' => $manager->getFrontendStatus(),
            'backends' => $manager->getBackendStatus(),
        ]);
    }
}
