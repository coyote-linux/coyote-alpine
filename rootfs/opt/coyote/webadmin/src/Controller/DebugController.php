<?php

namespace Coyote\WebAdmin\Controller;

/**
 * Debug controller for viewing logs and diagnostics.
 *
 * This controller provides access to system logs for debugging purposes.
 * Routes are public (no auth required) to facilitate debugging auth issues.
 */
class DebugController extends BaseController
{
    /**
     * Show debug index page with links to logs.
     *
     * @param array $params Route parameters
     * @return void
     */
    public function index(array $params = []): void
    {
        header('Content-Type: text/html; charset=utf-8');
        echo "<!DOCTYPE html>\n<html><head><title>Coyote Debug</title></head><body>\n";
        echo "<h1>Coyote Linux Debug</h1>\n";
        echo "<ul>\n";
        echo "<li><a href=\"/debug/logs/apply\">Apply/Subsystem Log</a> (configuration apply debugging)</li>\n";
        echo "<li><a href=\"/debug/logs/access\">Lighttpd Access Log</a></li>\n";
        echo "<li><a href=\"/debug/logs/error\">Lighttpd Error Log</a></li>\n";
        echo "<li><a href=\"/debug/logs/php\">PHP Error Log</a></li>\n";
        echo "<li><a href=\"/debug/logs/syslog\">System Log (logread)</a></li>\n";
        echo "<li><a href=\"/debug/phpinfo\">PHP Info</a></li>\n";
        echo "<li><a href=\"/debug/config\">Running Configuration</a></li>\n";
        echo "</ul>\n";
        echo "</body></html>\n";
    }

    /**
     * Display configuration apply log (subsystem debugging).
     *
     * @param array $params Route parameters
     * @return void
     */
    public function applyLog(array $params = []): void
    {
        $this->displayLogFile('/var/log/coyote-apply.log', 'Configuration Apply Log');
    }

    /**
     * Display lighttpd access log.
     *
     * @param array $params Route parameters
     * @return void
     */
    public function accessLog(array $params = []): void
    {
        $this->displayLogFile('/var/log/lighttpd/access.log', 'Lighttpd Access Log');
    }

    /**
     * Display lighttpd error log.
     *
     * @param array $params Route parameters
     * @return void
     */
    public function errorLog(array $params = []): void
    {
        $this->displayLogFile('/var/log/lighttpd/error.log', 'Lighttpd Error Log');
    }

    /**
     * Display PHP error log.
     *
     * @param array $params Route parameters
     * @return void
     */
    public function phpLog(array $params = []): void
    {
        // Try common PHP error log locations
        $logPaths = [
            '/var/log/php/error.log',
            '/var/log/php-fpm/error.log',
            '/var/log/lighttpd/php-error.log',
            ini_get('error_log'),
        ];

        foreach ($logPaths as $path) {
            if ($path && file_exists($path)) {
                $this->displayLogFile($path, 'PHP Error Log');
                return;
            }
        }

        $this->displayLogFile('/var/log/php/error.log', 'PHP Error Log');
    }

    /**
     * Display system log (via logread).
     *
     * @param array $params Route parameters
     * @return void
     */
    public function syslog(array $params = []): void
    {
        header('Content-Type: text/plain; charset=utf-8');

        echo "=== System Log (logread) ===\n";
        echo "Time: " . date('Y-m-d H:i:s') . "\n";
        echo str_repeat('=', 60) . "\n\n";

        $lines = $this->query('lines', 100);
        $lines = max(10, min(1000, (int)$lines));

        // Use logread to get syslog entries
        $output = [];
        exec("logread 2>&1 | tail -n {$lines}", $output, $ret);

        if ($ret !== 0) {
            echo "ERROR: Failed to read system log (logread returned {$ret})\n";
            return;
        }

        if (empty($output)) {
            echo "(No log entries)\n";
        } else {
            echo implode("\n", $output);
        }

        echo "\n\n" . str_repeat('=', 60) . "\n";
        echo "Showing last {$lines} lines. Use ?lines=N to change.\n";
    }

    /**
     * Display PHP info.
     *
     * @param array $params Route parameters
     * @return void
     */
    public function phpInfo(array $params = []): void
    {
        phpinfo();
    }

    /**
     * Display running configuration.
     *
     * @param array $params Route parameters
     * @return void
     */
    public function config(array $params = []): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $configFile = '/mnt/config/system.json';
        $runningConfig = '/tmp/running-config/system.json';

        $result = [
            'persistent_config' => [
                'path' => $configFile,
                'exists' => file_exists($configFile),
                'content' => null,
            ],
            'running_config' => [
                'path' => $runningConfig,
                'exists' => file_exists($runningConfig),
                'content' => null,
            ],
            'mounts' => [],
        ];

        if (file_exists($configFile)) {
            $result['persistent_config']['content'] = json_decode(file_get_contents($configFile), true);
        }

        if (file_exists($runningConfig)) {
            $result['running_config']['content'] = json_decode(file_get_contents($runningConfig), true);
        }

        // Get mount info
        if (file_exists('/proc/mounts')) {
            $mounts = file_get_contents('/proc/mounts');
            foreach (explode("\n", $mounts) as $line) {
                if (strpos($line, '/mnt/') !== false) {
                    $result['mounts'][] = $line;
                }
            }
        }

        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Display a log file with tail option.
     *
     * @param string $path Path to log file
     * @param string $title Log title
     * @return void
     */
    private function displayLogFile(string $path, string $title): void
    {
        header('Content-Type: text/plain; charset=utf-8');

        echo "=== {$title} ===\n";
        echo "File: {$path}\n";
        echo "Time: " . date('Y-m-d H:i:s') . "\n";
        echo str_repeat('=', 60) . "\n\n";

        if (!file_exists($path)) {
            echo "ERROR: Log file does not exist: {$path}\n";
            return;
        }

        if (!is_readable($path)) {
            echo "ERROR: Log file is not readable: {$path}\n";
            echo "Check file permissions.\n";
            return;
        }

        $lines = $this->query('lines', 100);
        $lines = max(10, min(1000, (int)$lines));

        // Read last N lines
        $content = $this->tailFile($path, $lines);

        if (empty($content)) {
            echo "(Log file is empty)\n";
        } else {
            echo $content;
        }

        echo "\n\n" . str_repeat('=', 60) . "\n";
        echo "Showing last {$lines} lines. Use ?lines=N to change.\n";
    }

    /**
     * Read the last N lines of a file.
     *
     * @param string $path File path
     * @param int $lines Number of lines
     * @return string File content
     */
    private function tailFile(string $path, int $lines): string
    {
        $file = new \SplFileObject($path, 'r');
        $file->seek(PHP_INT_MAX);
        $totalLines = $file->key();

        $startLine = max(0, $totalLines - $lines);
        $result = [];

        $file->seek($startLine);
        while (!$file->eof()) {
            $line = $file->fgets();
            if ($line !== false) {
                $result[] = $line;
            }
        }

        return implode('', $result);
    }
}
