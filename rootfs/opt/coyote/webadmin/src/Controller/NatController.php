<?php

namespace Coyote\WebAdmin\Controller;

/**
 * NAT configuration controller.
 */
class NatController extends BaseController
{
    /**
     * Display NAT overview.
     */
    public function index(array $params = []): void
    {
        $this->renderPlaceholder('NAT Configuration', 'Network Address Translation and port forwarding.');
    }

    /**
     * Save port forwards.
     */
    public function saveForwards(array $params = []): void
    {
        $this->flash('warning', 'NAT configuration not yet implemented');
        $this->redirect('/nat');
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
