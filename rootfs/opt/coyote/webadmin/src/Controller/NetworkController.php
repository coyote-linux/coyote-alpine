<?php

namespace Coyote\WebAdmin\Controller;

use Coyote\System\Network;
use Coyote\System\Hardware;

/**
 * Network configuration controller.
 */
class NetworkController extends BaseController
{
    /**
     * Display network overview.
     */
    public function index(array $params = []): void
    {
        $network = new Network();
        $hardware = new Hardware();

        $data = [
            'page_title' => 'Network Configuration',
            'interfaces' => $network->getInterfaces(),
            'routes' => $network->getRoutes(),
        ];

        $this->renderPage('Network Configuration', $data, 'network');
    }

    /**
     * Display interface configuration.
     */
    public function interfaces(array $params = []): void
    {
        $network = new Network();

        $data = [
            'page_title' => 'Network Interfaces',
            'interfaces' => $network->getInterfaces(),
        ];

        $this->renderPage('Network Interfaces', $data, 'interfaces');
    }

    /**
     * Save interface configuration.
     */
    public function saveInterfaces(array $params = []): void
    {
        // TODO: Implement interface saving
        $this->flash('warning', 'Interface configuration not yet implemented');
        $this->redirect('/network/interfaces');
    }

    /**
     * Render a page with standard layout.
     */
    private function renderPage(string $title, array $data, string $section): void
    {
        header('Content-Type: text/html; charset=utf-8');
        echo "<!DOCTYPE html>\n<html><head><title>{$title} - Coyote Linux</title>";
        echo "<style>body{font-family:sans-serif;margin:20px;} table{border-collapse:collapse;width:100%;} th,td{border:1px solid #ccc;padding:8px;text-align:left;} th{background:#f0f0f0;} .status-up{color:green;} .status-down{color:red;}</style>";
        echo "</head><body>";
        echo "<h1>{$title}</h1>";
        echo "<p><a href=\"/\">&larr; Dashboard</a></p>";

        if ($section === 'network' || $section === 'interfaces') {
            echo "<h2>Interfaces</h2>";
            echo "<table><tr><th>Name</th><th>MAC</th><th>State</th><th>IPv4</th><th>MTU</th><th>RX/TX</th></tr>";
            foreach ($data['interfaces'] as $iface) {
                $stateClass = $iface['state'] === 'up' ? 'status-up' : 'status-down';
                $ipv4 = implode(', ', $iface['ipv4'] ?? []) ?: '-';
                $rx = $this->formatBytes($iface['stats']['rx_bytes'] ?? 0);
                $tx = $this->formatBytes($iface['stats']['tx_bytes'] ?? 0);
                echo "<tr><td>{$iface['name']}</td><td>{$iface['mac']}</td><td class=\"{$stateClass}\">{$iface['state']}</td><td>{$ipv4}</td><td>{$iface['mtu']}</td><td>{$rx} / {$tx}</td></tr>";
            }
            echo "</table>";

            if ($section === 'network' && !empty($data['routes'])) {
                echo "<h2>Routes</h2>";
                echo "<table><tr><th>Destination</th><th>Gateway</th><th>Interface</th></tr>";
                foreach ($data['routes'] as $route) {
                    $gw = $route['gateway'] ?? '-';
                    echo "<tr><td>{$route['destination']}</td><td>{$gw}</td><td>{$route['interface']}</td></tr>";
                }
                echo "</table>";
            }
        }

        echo "</body></html>";
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
        return $bytes . ' B';
    }
}
