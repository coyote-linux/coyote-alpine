<?php
/**
 * Unit tests for Network system class
 */

namespace Coyote\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Coyote\System\Network;

class NetworkTest extends TestCase
{
    private Network $network;

    protected function setUp(): void
    {
        $this->network = new Network();
    }

    public function testGetInterfacesReturnsArray(): void
    {
        $interfaces = $this->network->getInterfaces();
        $this->assertIsArray($interfaces);
    }

    public function testGetRoutesReturnsArray(): void
    {
        $routes = $this->network->getRoutes();
        $this->assertIsArray($routes);
    }

    public function testGetDnsServersReturnsArray(): void
    {
        $dns = $this->network->getDnsServers();
        $this->assertIsArray($dns);
    }
}
