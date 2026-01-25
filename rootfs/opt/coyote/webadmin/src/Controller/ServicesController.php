<?php

namespace Coyote\WebAdmin\Controller;

use Coyote\System\Services;

/**
 * Services management controller.
 */
class ServicesController extends BaseController
{
    /**
     * Display services overview.
     */
    public function index(array $params = []): void
    {
        $services = new Services();

        $serviceList = [
            'lighttpd' => 'Web Server',
            'dropbear' => 'SSH Server',
            'dnsmasq' => 'DNS/DHCP Server',
            'haproxy' => 'Load Balancer',
            'strongswan' => 'VPN Server',
        ];

        header('Content-Type: text/html; charset=utf-8');
        echo "<!DOCTYPE html>\n<html><head><title>Services - Coyote Linux</title>";
        echo "<style>body{font-family:sans-serif;margin:20px;} table{border-collapse:collapse;width:100%;} th,td{border:1px solid #ccc;padding:8px;text-align:left;} th{background:#f0f0f0;} .running{color:green;font-weight:bold;} .stopped{color:red;} .btn{padding:5px 10px;margin:2px;cursor:pointer;}</style>";
        echo "</head><body>";
        echo "<h1>Services</h1>";
        echo "<p><a href=\"/\">&larr; Dashboard</a></p>";

        echo "<table><tr><th>Service</th><th>Description</th><th>Status</th><th>Actions</th></tr>";
        foreach ($serviceList as $name => $desc) {
            $running = $services->isRunning($name);
            $statusClass = $running ? 'running' : 'stopped';
            $statusText = $running ? 'Running' : 'Stopped';
            echo "<tr>";
            echo "<td>{$name}</td>";
            echo "<td>{$desc}</td>";
            echo "<td class=\"{$statusClass}\">{$statusText}</td>";
            echo "<td>";
            if ($running) {
                echo "<button class=\"btn\" onclick=\"alert('Stop not yet implemented')\">Stop</button>";
                echo "<button class=\"btn\" onclick=\"alert('Restart not yet implemented')\">Restart</button>";
            } else {
                echo "<button class=\"btn\" onclick=\"alert('Start not yet implemented')\">Start</button>";
            }
            echo "</td>";
            echo "</tr>";
        }
        echo "</table>";
        echo "</body></html>";
    }

    /**
     * Start a service.
     */
    public function start(array $params = []): void
    {
        $service = $params['service'] ?? '';
        $this->flash('warning', "Starting service '{$service}' not yet implemented");
        $this->redirect('/services');
    }

    /**
     * Stop a service.
     */
    public function stop(array $params = []): void
    {
        $service = $params['service'] ?? '';
        $this->flash('warning', "Stopping service '{$service}' not yet implemented");
        $this->redirect('/services');
    }
}
