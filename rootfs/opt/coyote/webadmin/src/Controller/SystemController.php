<?php

namespace Coyote\WebAdmin\Controller;

use Coyote\Config\ConfigManager;

/**
 * System configuration controller.
 */
class SystemController extends BaseController
{
    /**
     * Display system settings.
     */
    public function index(array $params = []): void
    {
        $configManager = new ConfigManager();

        try {
            $config = $configManager->load()->toArray();
        } catch (\Exception $e) {
            $config = [];
        }

        header('Content-Type: text/html; charset=utf-8');
        echo "<!DOCTYPE html>\n<html><head><title>System - Coyote Linux</title>";
        echo "<style>body{font-family:sans-serif;margin:20px;} .info{background:#f0f0f0;padding:15px;margin:10px 0;} label{display:block;margin:10px 0 5px;font-weight:bold;} input,select{padding:8px;width:300px;}</style>";
        echo "</head><body>";
        echo "<h1>System Configuration</h1>";
        echo "<p><a href=\"/\">&larr; Dashboard</a></p>";

        $hostname = $config['system']['hostname'] ?? 'coyote';
        $domain = $config['system']['domain'] ?? '';
        $timezone = $config['system']['timezone'] ?? 'UTC';

        echo "<div class=\"info\">";
        echo "<h2>Basic Settings</h2>";
        echo "<form method=\"post\" action=\"/system\">";
        echo "<label>Hostname</label><input type=\"text\" name=\"hostname\" value=\"{$hostname}\">";
        echo "<label>Domain</label><input type=\"text\" name=\"domain\" value=\"{$domain}\">";
        echo "<label>Timezone</label><input type=\"text\" name=\"timezone\" value=\"{$timezone}\">";
        echo "<br><br><button type=\"submit\">Save Changes</button>";
        echo "</form>";
        echo "</div>";

        echo "<div class=\"info\">";
        echo "<h2>Configuration Management</h2>";
        echo "<p><button onclick=\"alert('Apply not yet implemented')\">Apply Configuration</button></p>";
        echo "<p><button onclick=\"alert('Backup not yet implemented')\">Create Backup</button></p>";
        echo "<p><button onclick=\"alert('Restore not yet implemented')\">Restore Backup</button></p>";
        echo "</div>";

        echo "<div class=\"info\">";
        echo "<h2>System Actions</h2>";
        echo "<p><button onclick=\"if(confirm('Reboot system?')) alert('Reboot not yet implemented')\">Reboot</button></p>";
        echo "<p><button onclick=\"if(confirm('Shutdown system?')) alert('Shutdown not yet implemented')\">Shutdown</button></p>";
        echo "</div>";

        echo "</body></html>";
    }

    /**
     * Apply configuration changes.
     */
    public function apply(array $params = []): void
    {
        $this->flash('warning', 'Apply configuration not yet implemented');
        $this->redirect('/system');
    }

    /**
     * Confirm configuration changes.
     */
    public function confirm(array $params = []): void
    {
        $this->flash('warning', 'Confirm configuration not yet implemented');
        $this->redirect('/system');
    }
}
