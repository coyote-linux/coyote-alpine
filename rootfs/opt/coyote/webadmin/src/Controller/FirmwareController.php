<?php

namespace Coyote\WebAdmin\Controller;

/**
 * Firmware management controller.
 */
class FirmwareController extends BaseController
{
    /**
     * Display firmware status.
     */
    public function index(array $params = []): void
    {
        // Get current firmware info
        $firmwarePath = '/mnt/boot/firmware/current.squashfs';
        $firmwareExists = file_exists($firmwarePath);
        $firmwareSize = $firmwareExists ? filesize($firmwarePath) : 0;
        $firmwareDate = $firmwareExists ? date('Y-m-d H:i:s', filemtime($firmwarePath)) : 'N/A';

        header('Content-Type: text/html; charset=utf-8');
        echo "<!DOCTYPE html>\n<html><head><title>Firmware - Coyote Linux</title>";
        echo "<style>body{font-family:sans-serif;margin:20px;} .info{background:#f0f0f0;padding:15px;margin:10px 0;} .warning{background:#fff3cd;border:1px solid #ffc107;padding:15px;margin:10px 0;}</style>";
        echo "</head><body>";
        echo "<h1>Firmware Management</h1>";
        echo "<p><a href=\"/\">&larr; Dashboard</a></p>";

        echo "<div class=\"info\">";
        echo "<h2>Current Firmware</h2>";
        echo "<table>";
        echo "<tr><td><strong>Version:</strong></td><td>4.0.0</td></tr>";
        echo "<tr><td><strong>Path:</strong></td><td>{$firmwarePath}</td></tr>";
        echo "<tr><td><strong>Size:</strong></td><td>" . $this->formatBytes($firmwareSize) . "</td></tr>";
        echo "<tr><td><strong>Date:</strong></td><td>{$firmwareDate}</td></tr>";
        echo "</table>";
        echo "</div>";

        echo "<div class=\"info\">";
        echo "<h2>Upload New Firmware</h2>";
        echo "<form method=\"post\" action=\"/firmware/upload\" enctype=\"multipart/form-data\">";
        echo "<p><input type=\"file\" name=\"firmware\" accept=\".squashfs\"></p>";
        echo "<p><button type=\"submit\">Upload Firmware</button></p>";
        echo "</form>";
        echo "</div>";

        echo "<div class=\"warning\">";
        echo "<h2>Firmware Update Notes</h2>";
        echo "<ul>";
        echo "<li>Firmware updates require a reboot to take effect</li>";
        echo "<li>The previous firmware is kept for rollback</li>";
        echo "<li>Ensure you have a backup of your configuration before updating</li>";
        echo "</ul>";
        echo "</div>";

        echo "</body></html>";
    }

    /**
     * Handle firmware upload.
     */
    public function upload(array $params = []): void
    {
        $this->flash('warning', 'Firmware upload not yet implemented');
        $this->redirect('/firmware');
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
        return $bytes . ' B';
    }
}
