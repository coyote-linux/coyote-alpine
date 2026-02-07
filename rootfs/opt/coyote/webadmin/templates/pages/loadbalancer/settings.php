<?php
$pageTitle = 'Load Balancer Settings';
$page = 'loadbalancer';
$enabled = $enabled ?? false;
$defaults = $defaults ?? [];
$stats = $stats ?? [];
?>

<div class="page-header">
    <a href="/loadbalancer" class="btn btn-small">&larr; Back to Load Balancer</a>
</div>

<div class="dashboard-grid">
    <div class="card full-width">
        <h3>Global Settings</h3>
        <form method="post" action="/loadbalancer/settings">
            <div class="form-group-inline">
                <label>
                    <input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
                    Enable load balancer
                </label>
            </div>

            <h4>Default Timeouts</h4>

            <div class="form-group">
                <label for="default_mode">Default Mode</label>
                <select id="default_mode" name="default_mode">
                    <option value="http" <?= ($defaults['mode'] ?? 'http') === 'http' ? 'selected' : '' ?>>HTTP</option>
                    <option value="tcp" <?= ($defaults['mode'] ?? 'http') === 'tcp' ? 'selected' : '' ?>>TCP</option>
                </select>
            </div>

            <div class="form-group">
                <label for="timeout_connect">Connect Timeout</label>
                <input type="text" id="timeout_connect" name="timeout_connect"
                       value="<?= htmlspecialchars($defaults['timeout_connect'] ?? '5s') ?>"
                       placeholder="5s">
                <small class="text-muted">Time to wait for a connection to a server (e.g. 5s, 10s)</small>
            </div>

            <div class="form-group">
                <label for="timeout_client">Client Timeout</label>
                <input type="text" id="timeout_client" name="timeout_client"
                       value="<?= htmlspecialchars($defaults['timeout_client'] ?? '50s') ?>"
                       placeholder="50s">
                <small class="text-muted">Maximum inactivity time on the client side (e.g. 50s, 2m)</small>
            </div>

            <div class="form-group">
                <label for="timeout_server">Server Timeout</label>
                <input type="text" id="timeout_server" name="timeout_server"
                       value="<?= htmlspecialchars($defaults['timeout_server'] ?? '50s') ?>"
                       placeholder="50s">
                <small class="text-muted">Maximum inactivity time on the server side (e.g. 50s, 2m)</small>
            </div>

            <h4>Statistics</h4>

            <div class="form-group-inline">
                <label>
                    <input type="checkbox" name="stats_enabled" value="1" <?= ($stats['enabled'] ?? true) ? 'checked' : '' ?>>
                    Enable HAProxy stats page
                </label>
            </div>

            <div class="form-group">
                <label for="stats_port">Stats Port</label>
                <input type="number" id="stats_port" name="stats_port"
                       value="<?= (int)($stats['port'] ?? 8404) ?>"
                       min="1" max="65535">
            </div>

            <div class="form-group">
                <label for="stats_uri">Stats URI</label>
                <input type="text" id="stats_uri" name="stats_uri"
                       value="<?= htmlspecialchars($stats['uri'] ?? '/stats') ?>"
                       placeholder="/stats">
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Save Settings</button>
                <a href="/loadbalancer" class="btn">Cancel</a>
            </div>
        </form>
    </div>
</div>
