<?php
/**
 * Unit tests for ConfigLoader
 */

namespace Coyote\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Coyote\Config\ConfigLoader;

class ConfigLoaderTest extends TestCase
{
    private ConfigLoader $loader;
    private string $testDir;

    protected function setUp(): void
    {
        $this->loader = new ConfigLoader();
        $this->testDir = sys_get_temp_dir() . '/coyote-test-' . getmypid();
        mkdir($this->testDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->testDir);
    }

    public function testLoadValidJson(): void
    {
        $testFile = $this->testDir . '/config.json';
        $data = ['system' => ['hostname' => 'test']];
        file_put_contents($testFile, json_encode($data));

        $result = $this->loader->load($testFile);

        $this->assertIsArray($result);
        $this->assertEquals('test', $result['system']['hostname']);
    }

    public function testLoadInvalidJsonThrowsException(): void
    {
        $testFile = $this->testDir . '/invalid.json';
        file_put_contents($testFile, 'not valid json');

        $this->expectException(\RuntimeException::class);
        $this->loader->load($testFile);
    }

    public function testLoadNonExistentFileThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->loader->load('/nonexistent/path/config.json');
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
