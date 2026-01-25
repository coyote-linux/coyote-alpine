<?php

namespace Coyote\WebAdmin\Controller;

/**
 * Load balancer configuration controller.
 */
class LoadBalancerController extends BaseController
{
    /**
     * Display load balancer overview.
     */
    public function index(array $params = []): void
    {
        $this->renderPlaceholder('Load Balancer', 'HAProxy load balancer configuration and status.');
    }

    /**
     * Display load balancer statistics.
     */
    public function stats(array $params = []): void
    {
        $this->renderPlaceholder('Load Balancer Stats', 'View load balancer statistics and health checks.');
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
