<?php
/**
 * Unit tests for HaproxyService
 */

namespace Coyote\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Coyote\LoadBalancer\HaproxyService;

class HaproxyServiceTest extends TestCase
{
    private HaproxyService $service;

    protected function setUp(): void
    {
        $this->service = new HaproxyService();
    }

    public function testGenerateConfigWithMinimalSetup(): void
    {
        $config = [
            'frontends' => [],
            'backends' => [],
            'stats' => ['enabled' => false],
            'defaults' => [
                'mode' => 'http',
                'timeout_connect' => '5s',
                'timeout_client' => '50s',
                'timeout_server' => '50s',
            ],
        ];

        $output = $this->service->generateConfig($config);

        $this->assertStringContainsString('global', $output);
        $this->assertStringContainsString('defaults', $output);
    }

    public function testGenerateConfigWithFrontendAndBackend(): void
    {
        $config = [
            'frontends' => [
                'web' => [
                    'bind' => '*:80',
                    'mode' => 'http',
                    'backend' => 'webservers',
                ],
            ],
            'backends' => [
                'webservers' => [
                    'mode' => 'http',
                    'balance' => 'roundrobin',
                    'servers' => [
                        ['address' => '192.168.1.10', 'port' => 80, 'weight' => 1],
                    ],
                ],
            ],
            'stats' => ['enabled' => false],
            'defaults' => [
                'mode' => 'http',
                'timeout_connect' => '5s',
                'timeout_client' => '50s',
                'timeout_server' => '50s',
            ],
        ];

        $output = $this->service->generateConfig($config);

        $this->assertStringContainsString('frontend web', $output);
        $this->assertStringContainsString('backend webservers', $output);
        $this->assertStringContainsString('bind *:80', $output);
        $this->assertStringContainsString('balance roundrobin', $output);
    }

    public function testGenerateConfigWithStatsEnabled(): void
    {
        $config = [
            'frontends' => [],
            'backends' => [],
            'stats' => [
                'enabled' => true,
                'port' => 8404,
                'uri' => '/stats',
            ],
            'defaults' => [
                'mode' => 'http',
                'timeout_connect' => '5s',
                'timeout_client' => '50s',
                'timeout_server' => '50s',
            ],
        ];

        $output = $this->service->generateConfig($config);

        $this->assertStringContainsString('frontend stats', $output);
        $this->assertStringContainsString('bind *:8404', $output);
        $this->assertStringContainsString('stats uri /stats', $output);
    }
}
