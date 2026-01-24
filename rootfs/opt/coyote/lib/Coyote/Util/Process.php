<?php

namespace Coyote\Util;

/**
 * Process execution utilities.
 *
 * Provides safe methods for executing system commands.
 */
class Process
{
    /**
     * Execute a command and return the result.
     *
     * @param string $command Command to execute
     * @param array $args Command arguments (will be escaped)
     * @param string|null $cwd Working directory
     * @param array $env Environment variables
     * @return array{success: bool, output: string[], exitCode: int}
     */
    public static function exec(
        string $command,
        array $args = [],
        ?string $cwd = null,
        array $env = []
    ): array {
        // Build command with escaped arguments
        $escapedArgs = array_map('escapeshellarg', $args);
        $fullCommand = $command . ' ' . implode(' ', $escapedArgs);

        // Add stderr redirect
        $fullCommand .= ' 2>&1';

        $output = [];
        $exitCode = 0;

        // Change directory if specified
        $originalDir = null;
        if ($cwd !== null && is_dir($cwd)) {
            $originalDir = getcwd();
            chdir($cwd);
        }

        // Set environment variables
        foreach ($env as $key => $value) {
            putenv("{$key}={$value}");
        }

        exec($fullCommand, $output, $exitCode);

        // Restore directory
        if ($originalDir !== null) {
            chdir($originalDir);
        }

        return [
            'success' => $exitCode === 0,
            'output' => $output,
            'exitCode' => $exitCode,
        ];
    }

    /**
     * Execute a command and return just the output as a string.
     *
     * @param string $command Command to execute
     * @param array $args Command arguments
     * @return string|null Command output or null on failure
     */
    public static function run(string $command, array $args = []): ?string
    {
        $result = self::exec($command, $args);

        if (!$result['success']) {
            return null;
        }

        return implode("\n", $result['output']);
    }

    /**
     * Check if a process is running by name.
     *
     * @param string $processName Process name to check
     * @return bool True if process is running
     */
    public static function isRunning(string $processName): bool
    {
        $result = self::exec('pgrep', ['-x', $processName]);
        return $result['success'];
    }

    /**
     * Get the PID of a running process by name.
     *
     * @param string $processName Process name
     * @return int|null PID or null if not running
     */
    public static function getPid(string $processName): ?int
    {
        $result = self::exec('pgrep', ['-x', $processName]);

        if (!$result['success'] || empty($result['output'])) {
            return null;
        }

        return (int)$result['output'][0];
    }

    /**
     * Kill a process by PID.
     *
     * @param int $pid Process ID
     * @param int $signal Signal to send (default: SIGTERM)
     * @return bool True if successful
     */
    public static function kill(int $pid, int $signal = SIGTERM): bool
    {
        $result = self::exec('kill', ["-{$signal}", (string)$pid]);
        return $result['success'];
    }

    /**
     * Kill all processes matching a name.
     *
     * @param string $processName Process name
     * @param int $signal Signal to send
     * @return bool True if successful
     */
    public static function killAll(string $processName, int $signal = SIGTERM): bool
    {
        $result = self::exec('pkill', ["-{$signal}", '-x', $processName]);
        return $result['success'];
    }

    /**
     * Run a command in the background.
     *
     * @param string $command Command to run
     * @param array $args Command arguments
     * @param string|null $outputFile File to redirect output to
     * @return int|null PID of background process or null on failure
     */
    public static function background(
        string $command,
        array $args = [],
        ?string $outputFile = null
    ): ?int {
        $escapedArgs = array_map('escapeshellarg', $args);
        $fullCommand = $command . ' ' . implode(' ', $escapedArgs);

        if ($outputFile !== null) {
            $fullCommand .= ' > ' . escapeshellarg($outputFile) . ' 2>&1';
        } else {
            $fullCommand .= ' > /dev/null 2>&1';
        }

        $fullCommand .= ' & echo $!';

        $output = [];
        exec($fullCommand, $output);

        if (empty($output)) {
            return null;
        }

        return (int)$output[0];
    }

    /**
     * Wait for a process to exit.
     *
     * @param int $pid Process ID
     * @param int $timeout Timeout in seconds (0 = no timeout)
     * @return bool True if process exited, false if timeout
     */
    public static function wait(int $pid, int $timeout = 0): bool
    {
        $start = time();

        while (self::pidExists($pid)) {
            if ($timeout > 0 && (time() - $start) >= $timeout) {
                return false;
            }
            usleep(100000); // 100ms
        }

        return true;
    }

    /**
     * Check if a PID exists.
     *
     * @param int $pid Process ID
     * @return bool True if PID exists
     */
    public static function pidExists(int $pid): bool
    {
        return file_exists("/proc/{$pid}");
    }
}
