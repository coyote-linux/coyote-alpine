<?php

namespace Coyote\System;

/**
 * System service management using OpenRC.
 */
class Services
{
    /**
     * Start a service.
     *
     * @param string $service Service name
     * @return bool True if successful
     */
    public function start(string $service): bool
    {
        $cmd = $this->getPrivilegedCommand('rc-service');
        exec("{$cmd} " . escapeshellarg($service) . " start 2>&1", $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * Stop a service.
     *
     * @param string $service Service name
     * @return bool True if successful
     */
    public function stop(string $service): bool
    {
        $cmd = $this->getPrivilegedCommand('rc-service');
        exec("{$cmd} " . escapeshellarg($service) . " stop 2>&1", $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * Restart a service.
     *
     * @param string $service Service name
     * @return bool True if successful
     */
    public function restart(string $service): bool
    {
        $cmd = $this->getPrivilegedCommand('rc-service');
        exec("{$cmd} " . escapeshellarg($service) . " restart 2>&1", $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * Reload a service configuration.
     *
     * @param string $service Service name
     * @return bool True if successful
     */
    public function reload(string $service): bool
    {
        $cmd = $this->getPrivilegedCommand('rc-service');
        exec("{$cmd} " . escapeshellarg($service) . " reload 2>&1", $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * Get service status.
     *
     * @param string $service Service name
     * @return array Status information
     */
    public function status(string $service): array
    {
        exec("rc-service " . escapeshellarg($service) . " status 2>&1", $output, $returnCode);

        return [
            'service' => $service,
            'running' => $returnCode === 0,
            'output' => implode("\n", $output),
        ];
    }

    /**
     * Check if a service is running.
     *
     * @param string $service Service name
     * @return bool True if running
     */
    public function isRunning(string $service): bool
    {
        exec("rc-service " . escapeshellarg($service) . " status 2>&1", $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * Enable a service to start at boot.
     *
     * @param string $service Service name
     * @param string $runlevel Runlevel (default: default)
     * @return bool True if successful
     */
    public function enable(string $service, string $runlevel = 'default'): bool
    {
        $cmd = $this->getPrivilegedCommand('rc-update');
        exec("{$cmd} add " . escapeshellarg($service) . " " . escapeshellarg($runlevel) . " 2>&1", $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * Disable a service from starting at boot.
     *
     * @param string $service Service name
     * @param string $runlevel Runlevel (default: default)
     * @return bool True if successful
     */
    public function disable(string $service, string $runlevel = 'default'): bool
    {
        $cmd = $this->getPrivilegedCommand('rc-update');
        exec("{$cmd} del " . escapeshellarg($service) . " " . escapeshellarg($runlevel) . " 2>&1", $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * Check if a service is enabled at boot.
     *
     * @param string $service Service name
     * @return bool True if enabled
     */
    public function isEnabled(string $service): bool
    {
        exec("rc-update show 2>&1", $output, $returnCode);

        $escaped = preg_quote($service, '/');
        foreach ($output as $line) {
            if (preg_match("/^\s*{$escaped}\s*\|/", $line)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get list of all services and their status.
     *
     * @return array List of services with status
     */
    public function listAll(): array
    {
        $services = [];

        // Get all init scripts
        $initDir = '/etc/init.d';
        if (is_dir($initDir)) {
            foreach (scandir($initDir) as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }

                $path = $initDir . '/' . $file;
                if (is_executable($path)) {
                    $services[$file] = [
                        'name' => $file,
                        'running' => $this->isRunning($file),
                        'enabled' => $this->isEnabled($file),
                    ];
                }
            }
        }

        return $services;
    }

    /**
     * Write a service configuration file.
     *
     * @param string $service Service name
     * @param string $content Configuration content
     * @param string $configDir Configuration directory path
     * @return bool True if successful
     */
    public function writeConfig(string $service, string $content, string $configDir = '/etc'): bool
    {
        $configPath = $configDir . '/' . $service . '.conf';
        return file_put_contents($configPath, $content) !== false;
    }

    /**
     * Get a command with privilege escalation if needed.
     *
     * @param string $command The command to run
     * @return string The command, prefixed with doas if not running as root
     */
    private function getPrivilegedCommand(string $command): string
    {
        // If running as root, no need for doas
        if (posix_getuid() === 0) {
            return $command;
        }
        return "doas {$command}";
    }
}
