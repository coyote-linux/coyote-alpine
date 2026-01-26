<?php

namespace Coyote\System\Subsystem;

use Coyote\System\PrivilegedExecutor;

/**
 * Abstract base class for subsystems with common functionality.
 */
abstract class AbstractSubsystem implements SubsystemInterface
{
    /** @var PrivilegedExecutor Shared privileged executor instance */
    private static ?PrivilegedExecutor $privilegedExecutor = null;

    /**
     * Get the privileged executor instance.
     *
     * @return PrivilegedExecutor
     */
    protected function getPrivilegedExecutor(): PrivilegedExecutor
    {
        if (self::$privilegedExecutor === null) {
            self::$privilegedExecutor = new PrivilegedExecutor();
        }
        return self::$privilegedExecutor;
    }

    /**
     * Get a nested value from an array using dot notation.
     *
     * @param array $array The array to search
     * @param string $key Dot-notation key (e.g., 'system.hostname')
     * @param mixed $default Default value if not found
     * @return mixed
     */
    protected function getNestedValue(array $array, string $key, $default = null)
    {
        $keys = explode('.', $key);
        $value = $array;

        foreach ($keys as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    /**
     * Compare values from two configs for specific keys.
     *
     * @param array $working Working configuration
     * @param array $running Running configuration
     * @param array $keys Keys to compare
     * @return bool True if any values differ
     */
    protected function valuesChanged(array $working, array $running, array $keys): bool
    {
        foreach ($keys as $key) {
            $workingValue = $this->getNestedValue($working, $key);
            $runningValue = $this->getNestedValue($running, $key);

            if ($workingValue !== $runningValue) {
                return true;
            }
        }

        return false;
    }

    /**
     * Execute a shell command.
     *
     * @param string $command Command to execute
     * @param bool $privileged Whether to use doas for privilege escalation
     * @return array{success: bool, output: string, code: int}
     */
    protected function exec(string $command, bool $privileged = false): array
    {
        if ($privileged && posix_getuid() !== 0) {
            $command = "doas {$command}";
        }

        $output = [];
        $returnCode = 0;
        exec($command . ' 2>&1', $output, $returnCode);

        return [
            'success' => $returnCode === 0,
            'output' => implode("\n", $output),
            'code' => $returnCode,
        ];
    }

    /**
     * Create a success result.
     *
     * @param string $message Success message
     * @return array
     */
    protected function success(string $message = ''): array
    {
        return [
            'success' => true,
            'message' => $message ?: $this->getName() . ' applied successfully',
            'errors' => [],
        ];
    }

    /**
     * Create a failure result.
     *
     * @param string $message Error message
     * @param array $errors List of specific errors
     * @return array
     */
    protected function failure(string $message, array $errors = []): array
    {
        return [
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ];
    }
}
