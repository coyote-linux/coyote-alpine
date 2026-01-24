<?php
/**
 * Unit tests for FirewallManager
 */

namespace Coyote\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Coyote\Firewall\FirewallManager;
use Coyote\Firewall\IptablesService;

class FirewallManagerTest extends TestCase
{
    public function testManagerInstantiation(): void
    {
        $manager = new FirewallManager();
        $this->assertInstanceOf(FirewallManager::class, $manager);
    }

    public function testGetIptablesService(): void
    {
        $manager = new FirewallManager();
        $iptables = $manager->getIptablesService();
        $this->assertInstanceOf(IptablesService::class, $iptables);
    }

    public function testIsEnabledDefaultsFalse(): void
    {
        $manager = new FirewallManager();
        $this->assertFalse($manager->isEnabled());
    }

    public function testApplyConfigWithEmptyArray(): void
    {
        // This test would need mocking in a real environment
        // as it tries to run iptables commands
        $this->markTestSkipped('Requires iptables to be available');
    }
}
