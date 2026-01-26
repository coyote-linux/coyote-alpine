<?php

namespace Coyote\System;

/**
 * Privileged command executor for system configuration.
 *
 * This class provides a safe interface for executing privileged operations
 * via the coyote-apply-helper script and doas. It handles:
 * - Writing to protected system files (/etc/*)
 * - Running privileged network commands (ip, sysctl)
 * - Managing network daemons (udhcpc, pppd)
 *
 * All operations are logged via syslog for audit purposes.
 */
class PrivilegedExecutor
{
    /** @var string Path to the apply helper script */
    private const HELPER = '/opt/coyote/bin/coyote-apply-helper';

    /** @var string Temporary directory for staging files */
    private const TEMP_DIR = '/tmp/coyote-apply';

    /**
     * Execute a privileged command via the apply helper.
     *
     * @param string $command The helper command to run
     * @param array $args Arguments for the command
     * @return array{success: bool, output: string, code: int}
     */
    public function execute(string $command, array $args = []): array
    {
        // Build the command line
        $cmdLine = 'doas ' . escapeshellarg(self::HELPER) . ' ' . escapeshellarg($command);

        foreach ($args as $arg) {
            $cmdLine .= ' ' . escapeshellarg($arg);
        }

        // Execute
        $output = [];
        $returnCode = 0;
        exec($cmdLine . ' 2>&1', $output, $returnCode);

        return [
            'success' => $returnCode === 0,
            'output' => implode("\n", $output),
            'code' => $returnCode,
        ];
    }

    /**
     * Write content to a protected system file.
     *
     * The content is first written to a temp file, then the helper
     * copies it to the destination with proper permissions.
     *
     * @param string $destination The target file path (must be in allowlist)
     * @param string $content The content to write
     * @return array{success: bool, output: string}
     */
    public function writeFile(string $destination, string $content): array
    {
        // Ensure temp directory exists
        if (!is_dir(self::TEMP_DIR)) {
            mkdir(self::TEMP_DIR, 0755, true);
        }

        // Write to temp file
        $tempFile = self::TEMP_DIR . '/file-' . uniqid() . '.tmp';

        if (file_put_contents($tempFile, $content) === false) {
            return [
                'success' => false,
                'output' => 'Failed to write temp file',
            ];
        }

        // Make readable by root
        chmod($tempFile, 0644);

        // Call helper to copy to destination
        $result = $this->execute('write-file', [$destination, $tempFile]);

        // Clean up temp file on failure (helper removes it on success)
        if (!$result['success'] && file_exists($tempFile)) {
            unlink($tempFile);
        }

        return [
            'success' => $result['success'],
            'output' => $result['output'],
        ];
    }

    /**
     * Create a symlink at a protected location.
     *
     * @param string $destination The symlink path (must be in allowlist)
     * @param string $target The target the symlink points to
     * @return array{success: bool, output: string}
     */
    public function writeSymlink(string $destination, string $target): array
    {
        $result = $this->execute('write-symlink', [$destination, $target]);

        return [
            'success' => $result['success'],
            'output' => $result['output'],
        ];
    }

    /**
     * Set the system hostname.
     *
     * @param string $hostname The hostname to set
     * @return array{success: bool, output: string}
     */
    public function setHostname(string $hostname): array
    {
        $result = $this->execute('set-hostname', [$hostname]);

        return [
            'success' => $result['success'],
            'output' => $result['output'],
        ];
    }

    /**
     * Run an ip command.
     *
     * @param string ...$args Arguments for the ip command
     * @return array{success: bool, output: string, code: int}
     */
    public function ip(string ...$args): array
    {
        return $this->execute('ip', $args);
    }

    /**
     * Run a sysctl command.
     *
     * @param string ...$args Arguments for the sysctl command
     * @return array{success: bool, output: string, code: int}
     */
    public function sysctl(string ...$args): array
    {
        return $this->execute('sysctl', $args);
    }

    /**
     * Load a kernel module.
     *
     * @param string $module Module name (must be in allowlist)
     * @return array{success: bool, output: string}
     */
    public function modprobe(string $module): array
    {
        $result = $this->execute('modprobe', [$module]);

        return [
            'success' => $result['success'],
            'output' => $result['output'],
        ];
    }

    /**
     * Start a DHCP client.
     *
     * @param string ...$args Arguments for udhcpc
     * @return array{success: bool, output: string, code: int}
     */
    public function udhcpc(string ...$args): array
    {
        return $this->execute('udhcpc', $args);
    }

    /**
     * Start a PPPoE client.
     *
     * @param string ...$args Arguments for pppd
     * @return array{success: bool, output: string, code: int}
     */
    public function pppd(string ...$args): array
    {
        return $this->execute('pppd', $args);
    }

    /**
     * Kill a process by PID.
     *
     * @param int $pid Process ID
     * @return array{success: bool, output: string}
     */
    public function killPid(int $pid): array
    {
        $result = $this->execute('kill-pid', [(string)$pid]);

        return [
            'success' => $result['success'],
            'output' => $result['output'],
        ];
    }

    /**
     * Kill processes matching a pattern.
     *
     * @param string $pattern Pattern to match
     * @return array{success: bool, output: string}
     */
    public function pkillPattern(string $pattern): array
    {
        $result = $this->execute('pkill-pattern', [$pattern]);

        return [
            'success' => $result['success'],
            'output' => $result['output'],
        ];
    }
}
