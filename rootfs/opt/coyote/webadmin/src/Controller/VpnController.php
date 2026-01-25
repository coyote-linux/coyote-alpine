<?php

namespace Coyote\WebAdmin\Controller;

/**
 * VPN configuration controller.
 */
class VpnController extends BaseController
{
    /**
     * Display VPN overview.
     */
    public function index(array $params = []): void
    {
        $this->renderPlaceholder('VPN Configuration', 'IPSec VPN tunnel configuration and status.');
    }

    /**
     * Display VPN tunnels.
     */
    public function tunnels(array $params = []): void
    {
        $this->renderPlaceholder('VPN Tunnels', 'View and configure VPN tunnels.');
    }

    /**
     * Save VPN tunnels.
     */
    public function saveTunnels(array $params = []): void
    {
        $this->flash('warning', 'VPN configuration not yet implemented');
        $this->redirect('/vpn/tunnels');
    }

    private function renderPlaceholder(string $title, string $description): void
    {
        header('Content-Type: text/html; charset=utf-8');
        echo "<!DOCTYPE html>\n<html><head><title>{$title} - Coyote Linux</title>";
        echo "<style>body{font-family:sans-serif;margin:20px;} .placeholder{background:#f9f9f9;border:2px dashed #ccc;padding:40px;text-align:center;color:#666;}</style>";
        echo "</head><body>";
        echo "<h1>{$title}</h1>";
        echo "<p><a href=\"/\">&larr; Dashboard</a></p>";
        echo "<div class=\"placeholder\"><h2>Coming Soon</h2><p>{$description}</p></div>";
        echo "</body></html>";
    }
}
