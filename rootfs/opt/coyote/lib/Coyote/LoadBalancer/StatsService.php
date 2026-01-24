<?php

namespace Coyote\LoadBalancer;

/**
 * Service for retrieving HAProxy statistics.
 *
 * Reads statistics from HAProxy's stats socket or CSV endpoint.
 */
class StatsService
{
    /** @var string Path to HAProxy socket */
    private string $socketPath = '/var/run/haproxy.sock';

    /** @var array Stats configuration */
    private array $config = [];

    /** @var array Cached stats data */
    private array $cache = [];

    /** @var int Cache TTL in seconds */
    private int $cacheTtl = 5;

    /** @var int Last cache refresh time */
    private int $cacheTime = 0;

    /**
     * Configure the stats service.
     *
     * @param array $config Stats configuration
     * @return void
     */
    public function configure(array $config): void
    {
        $this->config = $config;
    }

    /**
     * Get all statistics.
     *
     * @return array All stats
     */
    public function getAll(): array
    {
        $this->refreshCache();
        return $this->cache;
    }

    /**
     * Get summary statistics.
     *
     * @return array Summary stats
     */
    public function getSummary(): array
    {
        $this->refreshCache();

        $totals = [
            'frontends' => 0,
            'backends' => 0,
            'servers' => 0,
            'servers_up' => 0,
            'servers_down' => 0,
            'total_sessions' => 0,
            'current_sessions' => 0,
            'bytes_in' => 0,
            'bytes_out' => 0,
        ];

        foreach ($this->cache as $entry) {
            switch ($entry['svname'] ?? '') {
                case 'FRONTEND':
                    $totals['frontends']++;
                    $totals['current_sessions'] += (int)($entry['scur'] ?? 0);
                    $totals['total_sessions'] += (int)($entry['stot'] ?? 0);
                    $totals['bytes_in'] += (int)($entry['bin'] ?? 0);
                    $totals['bytes_out'] += (int)($entry['bout'] ?? 0);
                    break;

                case 'BACKEND':
                    $totals['backends']++;
                    break;

                default:
                    if (($entry['type'] ?? '') === '2') { // Server type
                        $totals['servers']++;
                        $status = $entry['status'] ?? '';
                        if ($status === 'UP') {
                            $totals['servers_up']++;
                        } else {
                            $totals['servers_down']++;
                        }
                    }
                    break;
            }
        }

        return $totals;
    }

    /**
     * Get frontend statistics.
     *
     * @param string $name Frontend name
     * @return array Frontend stats
     */
    public function getFrontendStats(string $name): array
    {
        $this->refreshCache();

        foreach ($this->cache as $entry) {
            if (($entry['pxname'] ?? '') === $name && ($entry['svname'] ?? '') === 'FRONTEND') {
                return $this->normalizeStats($entry);
            }
        }

        return [];
    }

    /**
     * Get backend statistics.
     *
     * @param string $name Backend name
     * @return array Backend stats
     */
    public function getBackendStats(string $name): array
    {
        $this->refreshCache();

        foreach ($this->cache as $entry) {
            if (($entry['pxname'] ?? '') === $name && ($entry['svname'] ?? '') === 'BACKEND') {
                return $this->normalizeStats($entry);
            }
        }

        return [];
    }

    /**
     * Get server statistics.
     *
     * @param string $backend Backend name
     * @param string $server Server name
     * @return array Server stats
     */
    public function getServerStats(string $backend, string $server): array
    {
        $this->refreshCache();

        foreach ($this->cache as $entry) {
            if (($entry['pxname'] ?? '') === $backend && ($entry['svname'] ?? '') === $server) {
                return $this->normalizeStats($entry);
            }
        }

        return [];
    }

    /**
     * Get all servers in a backend.
     *
     * @param string $backend Backend name
     * @return array Server stats
     */
    public function getBackendServers(string $backend): array
    {
        $this->refreshCache();

        $servers = [];
        foreach ($this->cache as $entry) {
            if (($entry['pxname'] ?? '') === $backend) {
                $svname = $entry['svname'] ?? '';
                if ($svname !== 'FRONTEND' && $svname !== 'BACKEND') {
                    $servers[$svname] = $this->normalizeStats($entry);
                }
            }
        }

        return $servers;
    }

    /**
     * Normalize stats entry.
     *
     * @param array $entry Raw stats entry
     * @return array Normalized stats
     */
    private function normalizeStats(array $entry): array
    {
        return [
            'name' => $entry['svname'] ?? '',
            'status' => $entry['status'] ?? 'unknown',
            'weight' => (int)($entry['weight'] ?? 0),
            'scur' => (int)($entry['scur'] ?? 0),
            'smax' => (int)($entry['smax'] ?? 0),
            'stot' => (int)($entry['stot'] ?? 0),
            'bin' => (int)($entry['bin'] ?? 0),
            'bout' => (int)($entry['bout'] ?? 0),
            'rate' => (int)($entry['rate'] ?? 0),
            'hrsp_1xx' => (int)($entry['hrsp_1xx'] ?? 0),
            'hrsp_2xx' => (int)($entry['hrsp_2xx'] ?? 0),
            'hrsp_3xx' => (int)($entry['hrsp_3xx'] ?? 0),
            'hrsp_4xx' => (int)($entry['hrsp_4xx'] ?? 0),
            'hrsp_5xx' => (int)($entry['hrsp_5xx'] ?? 0),
            'check_status' => $entry['check_status'] ?? '',
            'lastchg' => (int)($entry['lastchg'] ?? 0),
        ];
    }

    /**
     * Refresh the stats cache if needed.
     *
     * @return void
     */
    private function refreshCache(): void
    {
        if (time() - $this->cacheTime < $this->cacheTtl) {
            return;
        }

        $stats = $this->fetchStats();
        if ($stats !== null) {
            $this->cache = $stats;
            $this->cacheTime = time();
        }
    }

    /**
     * Fetch stats from HAProxy.
     *
     * @return array|null Stats data or null on failure
     */
    private function fetchStats(): ?array
    {
        if (!file_exists($this->socketPath)) {
            return null;
        }

        $socket = @fsockopen("unix://{$this->socketPath}", 0, $errno, $errstr, 5);
        if (!$socket) {
            return null;
        }

        fwrite($socket, "show stat\n");

        $response = '';
        while (!feof($socket)) {
            $response .= fgets($socket, 8192);
        }

        fclose($socket);

        return $this->parseStats($response);
    }

    /**
     * Parse CSV stats output.
     *
     * @param string $csv CSV data
     * @return array Parsed stats
     */
    private function parseStats(string $csv): array
    {
        $lines = explode("\n", trim($csv));
        if (count($lines) < 2) {
            return [];
        }

        // First line is headers (prefixed with #)
        $headerLine = ltrim($lines[0], '# ');
        $headers = str_getcsv($headerLine);

        $stats = [];
        for ($i = 1; $i < count($lines); $i++) {
            if (empty(trim($lines[$i]))) {
                continue;
            }

            $values = str_getcsv($lines[$i]);
            if (count($values) !== count($headers)) {
                continue;
            }

            $entry = array_combine($headers, $values);
            $stats[] = $entry;
        }

        return $stats;
    }

    /**
     * Clear the stats cache.
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->cache = [];
        $this->cacheTime = 0;
    }
}
