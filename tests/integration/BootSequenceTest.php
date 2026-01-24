<?php
/**
 * Integration tests for boot sequence
 */

namespace Coyote\Tests\Integration;

use PHPUnit\Framework\TestCase;

class BootSequenceTest extends TestCase
{
    /**
     * Test that init scripts exist and are properly ordered.
     */
    public function testInitScriptsExist(): void
    {
        $initDir = __DIR__ . '/../../initramfs/init.d';

        $expectedScripts = [
            '01-mount-basics.sh',
            '02-detect-boot-media.sh',
            '03-check-firmware.sh',
            '04-recovery-prompt.sh',
            '05-mount-firmware.sh',
            '06-setup-tmpfs.sh',
            '07-pivot-root.sh',
        ];

        foreach ($expectedScripts as $script) {
            $this->assertFileExists(
                $initDir . '/' . $script,
                "Init script {$script} should exist"
            );
        }
    }

    /**
     * Test that main init script exists.
     */
    public function testMainInitExists(): void
    {
        $initFile = __DIR__ . '/../../initramfs/init';
        $this->assertFileExists($initFile, 'Main init script should exist');
    }

    /**
     * Test that recovery scripts exist.
     */
    public function testRecoveryScriptsExist(): void
    {
        $recoveryDir = __DIR__ . '/../../initramfs/recovery';

        $expectedScripts = [
            'recovery-menu.sh',
            'rollback-firmware.sh',
            'edit-config.sh',
        ];

        foreach ($expectedScripts as $script) {
            $this->assertFileExists(
                $recoveryDir . '/' . $script,
                "Recovery script {$script} should exist"
            );
        }
    }
}
