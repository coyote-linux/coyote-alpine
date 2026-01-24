<?php
/**
 * Integration tests for configuration apply process
 */

namespace Coyote\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Coyote\Config\ConfigManager;

class ConfigApplyTest extends TestCase
{
    private string $testConfigDir;
    private string $testRunningDir;

    protected function setUp(): void
    {
        $this->testConfigDir = sys_get_temp_dir() . '/coyote-config-test-' . getmypid();
        $this->testRunningDir = sys_get_temp_dir() . '/coyote-running-test-' . getmypid();

        mkdir($this->testConfigDir, 0755, true);
        mkdir($this->testRunningDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->testConfigDir);
        $this->removeDirectory($this->testRunningDir);
    }

    public function testConfigManagerCanLoadDefaults(): void
    {
        $manager = new ConfigManager($this->testConfigDir, $this->testRunningDir);

        $config = $manager->load();

        $this->assertNotNull($config);
        $this->assertEquals('coyote', $config->get('system.hostname'));
    }

    public function testConfigManagerCanSaveAndLoad(): void
    {
        $manager = new ConfigManager($this->testConfigDir, $this->testRunningDir);

        $config = $manager->load();
        $config->set('system.hostname', 'test-host');

        $manager->save();

        // Create new manager to load fresh
        $manager2 = new ConfigManager($this->testConfigDir, $this->testRunningDir);
        $config2 = $manager2->load();

        $this->assertEquals('test-host', $config2->get('system.hostname'));
    }

    public function testConfigBackupAndRestore(): void
    {
        $manager = new ConfigManager($this->testConfigDir, $this->testRunningDir);

        // Create initial config
        $config = $manager->load();
        $config->set('system.hostname', 'original');
        $manager->save();

        // Backup
        $this->assertTrue($manager->backup('test-backup'));

        // Modify
        $config->set('system.hostname', 'modified');
        $manager->save();

        // Restore
        $this->assertTrue($manager->restore('test-backup'));

        // Verify
        $manager->load();
        $restoredConfig = $manager->getRunningConfig();
        $this->assertEquals('original', $restoredConfig->get('system.hostname'));
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
