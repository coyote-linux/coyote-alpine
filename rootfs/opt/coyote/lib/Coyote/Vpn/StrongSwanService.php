<?php

namespace Coyote\Vpn;

use Coyote\Util\Filesystem;

/**
 * Service for managing StrongSwan IPSec VPN.
 *
 * Handles configuration generation, service management, and status monitoring
 * for StrongSwan-based VPN tunnels.
 */
class StrongSwanService
{
    /** @var string Path to StrongSwan config directory */
    private string $configDir = '/etc/swanctl';

    /** @var string Path to swanctl binary */
    private string $swanctl = '/usr/sbin/swanctl';

    /** @var SwanctlConfig Configuration generator */
    private SwanctlConfig $configGenerator;

    /**
     * Create a new StrongSwanService instance.
     */
    public function __construct()
    {
        $this->configGenerator = new SwanctlConfig();
    }

    /**
     * Apply VPN configuration.
     *
     * @param array $config VPN configuration
     * @return bool True if successful
     */
    public function applyConfig(array $config): bool
    {
        if (!($config['enabled'] ?? false) && empty($config['tunnels'])) {
            return $this->stop();
        }

        // Ensure config directory exists
        Filesystem::ensureDir($this->configDir);
        Filesystem::ensureDir($this->configDir . '/conf.d');

        // Generate configuration
        $swanctlConf = $this->configGenerator->generate($config);

        // Write configuration file
        $confFile = $this->configDir . '/swanctl.conf';
        if (!Filesystem::writeAtomic($confFile, $swanctlConf, 0600)) {
            return false;
        }

        // Start service if not running
        if (!$this->isRunning()) {
            if (!$this->start()) {
                return false;
            }
        }

        // Reload configuration
        return $this->reload();
    }

    /**
     * Reload StrongSwan configuration.
     *
     * @return bool True if successful
     */
    public function reload(): bool
    {
        exec("{$this->swanctl} --load-all 2>&1", $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * Start StrongSwan service.
     *
     * @return bool True if successful
     */
    public function start(): bool
    {
        exec('rc-service strongswan start 2>&1', $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * Stop StrongSwan service.
     *
     * @return bool True if successful
     */
    public function stop(): bool
    {
        exec('rc-service strongswan stop 2>&1', $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * Restart StrongSwan service.
     *
     * @return bool True if successful
     */
    public function restart(): bool
    {
        exec('rc-service strongswan restart 2>&1', $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * Check if StrongSwan is running.
     *
     * @return bool True if running
     */
    public function isRunning(): bool
    {
        exec('rc-service strongswan status 2>&1', $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * Get VPN connection status.
     *
     * @return array Connection status information
     */
    public function getStatus(): array
    {
        $output = [];
        exec("{$this->swanctl} --list-sas 2>&1", $output, $returnCode);

        return [
            'running' => $this->isRunning(),
            'connections' => $this->parseSaOutput($output),
        ];
    }

    /**
     * Get status of a specific tunnel.
     *
     * @param string $name Tunnel name
     * @return array Tunnel status
     */
    public function getTunnelStatus(string $name): array
    {
        $output = [];
        exec("{$this->swanctl} --list-sas --ike {$name} 2>&1", $output, $returnCode);

        if ($returnCode !== 0 || empty($output)) {
            return [
                'state' => 'disconnected',
                'established' => false,
            ];
        }

        return $this->parseTunnelStatus($output, $name);
    }

    /**
     * Parse swanctl SA output.
     *
     * @param array $output Command output lines
     * @return array Parsed connections
     */
    private function parseSaOutput(array $output): array
    {
        $connections = [];
        $current = null;

        foreach ($output as $line) {
            if (preg_match('/^(\S+):\s+#\d+/', $line, $matches)) {
                if ($current !== null) {
                    $connections[] = $current;
                }
                $current = [
                    'name' => $matches[1],
                    'state' => 'established',
                    'established' => true,
                ];
            } elseif ($current !== null) {
                if (preg_match('/local\s+\'([^\']+)\'/', $line, $matches)) {
                    $current['local_id'] = $matches[1];
                }
                if (preg_match('/remote\s+\'([^\']+)\'/', $line, $matches)) {
                    $current['remote_id'] = $matches[1];
                }
                if (preg_match('/(\d+)\s+bytes_i/', $line, $matches)) {
                    $current['bytes_in'] = (int)$matches[1];
                }
                if (preg_match('/(\d+)\s+bytes_o/', $line, $matches)) {
                    $current['bytes_out'] = (int)$matches[1];
                }
            }
        }

        if ($current !== null) {
            $connections[] = $current;
        }

        return $connections;
    }

    /**
     * Parse tunnel status from output.
     *
     * @param array $output Command output
     * @param string $name Tunnel name
     * @return array Tunnel status
     */
    private function parseTunnelStatus(array $output, string $name): array
    {
        $status = [
            'state' => 'disconnected',
            'established' => false,
            'bytes_in' => 0,
            'bytes_out' => 0,
        ];

        foreach ($output as $line) {
            if (strpos($line, $name) !== false && strpos($line, '#') !== false) {
                $status['state'] = 'established';
                $status['established'] = true;
            }
            if (preg_match('/(\d+)\s+bytes_i/', $line, $matches)) {
                $status['bytes_in'] = (int)$matches[1];
            }
            if (preg_match('/(\d+)\s+bytes_o/', $line, $matches)) {
                $status['bytes_out'] = (int)$matches[1];
            }
        }

        return $status;
    }

    /**
     * Initiate a connection.
     *
     * @param string $name Connection name
     * @return bool True if successful
     */
    public function initiateConnection(string $name): bool
    {
        exec("{$this->swanctl} --initiate --child {$name} 2>&1", $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * Terminate a connection.
     *
     * @param string $name Connection name
     * @return bool True if successful
     */
    public function terminateConnection(string $name): bool
    {
        exec("{$this->swanctl} --terminate --child {$name} 2>&1", $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * List all configured connections.
     *
     * @return array Connection names
     */
    public function listConnections(): array
    {
        $output = [];
        exec("{$this->swanctl} --list-conns 2>&1", $output);
        return $output;
    }

    /**
     * Get the configuration generator.
     *
     * @return SwanctlConfig
     */
    public function getConfigGenerator(): SwanctlConfig
    {
        return $this->configGenerator;
    }
}
