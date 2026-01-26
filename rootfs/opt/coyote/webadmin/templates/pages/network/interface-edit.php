<?php
$ifaceName = $interface['name'] ?? 'unknown';
$pageTitle = "Edit Interface: {$ifaceName}";
$page = 'network';
?>

<div class="page-header">
    <a href="/network" class="btn btn-small">&larr; Back to Network</a>
</div>

<div class="dashboard-grid">
    <div class="card">
        <h3>Interface Information</h3>
        <dl>
            <dt>Interface Name</dt>
            <dd><strong><?= htmlspecialchars($ifaceName) ?></strong></dd>

            <dt>MAC Address</dt>
            <dd><code><?= htmlspecialchars($interface['mac'] ?? '-') ?></code></dd>

            <dt>Current State</dt>
            <dd>
                <span class="status-badge status-<?= ($interface['state'] ?? 'down') === 'up' ? 'up' : 'down' ?>">
                    <?= htmlspecialchars($interface['state'] ?? 'unknown') ?>
                </span>
            </dd>

            <dt>Current IPv4</dt>
            <dd>
                <?php if (!empty($interface['currentIpv4'])): ?>
                    <?= htmlspecialchars(implode(', ', $interface['currentIpv4'])) ?>
                <?php else: ?>
                    <span class="text-muted">None</span>
                <?php endif; ?>
            </dd>
        </dl>
    </div>

    <div class="card">
        <h3>Interface Configuration</h3>
        <form method="post" action="/network/interface/<?= urlencode($ifaceName) ?>">
            <div class="form-group">
                <label for="type">Configuration Type</label>
                <select id="type" name="type" onchange="toggleAddressFields()">
                    <option value="disabled" <?= ($config['type'] ?? 'disabled') === 'disabled' ? 'selected' : '' ?>>Disabled</option>
                    <option value="static" <?= ($config['type'] ?? '') === 'static' ? 'selected' : '' ?>>Static IP</option>
                    <option value="dhcp" <?= ($config['type'] ?? '') === 'dhcp' ? 'selected' : '' ?>>DHCP</option>
                </select>
            </div>

            <div id="static-fields" class="conditional-fields" style="display: <?= ($config['type'] ?? '') === 'static' ? 'block' : 'none' ?>;">
                <div class="form-group">
                    <label for="address">IP Address (CIDR)</label>
                    <input type="text" id="address" name="address"
                           value="<?= htmlspecialchars($config['address'] ?? '') ?>"
                           placeholder="192.168.1.1/24">
                    <small class="text-muted">Enter IP address with subnet mask in CIDR notation (e.g., 192.168.1.1/24)</small>
                </div>

                <div class="form-group">
                    <label for="gateway">Default Gateway (optional)</label>
                    <input type="text" id="gateway" name="gateway"
                           value="<?= htmlspecialchars($config['gateway'] ?? '') ?>"
                           placeholder="192.168.1.254">
                    <small class="text-muted">Only set this on the interface connected to your internet connection</small>
                </div>
            </div>

            <div id="dhcp-fields" class="conditional-fields" style="display: <?= ($config['type'] ?? '') === 'dhcp' ? 'block' : 'none' ?>;">
                <div class="alert alert-info">
                    This interface will obtain its IP address automatically from a DHCP server.
                </div>
            </div>

            <div id="disabled-fields" class="conditional-fields" style="display: <?= ($config['type'] ?? 'disabled') === 'disabled' ? 'block' : 'none' ?>;">
                <div class="alert alert-warning">
                    This interface will not be configured. It will remain down or unconfigured.
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Save Configuration</button>
                <a href="/network" class="btn">Cancel</a>
            </div>

            <p class="text-muted" style="margin-top: 1rem;">
                Changes are saved but not applied until you click "Apply Configuration" on the Network page.
            </p>
        </form>
    </div>

    <?php if (($config['type'] ?? 'disabled') !== 'disabled' && !empty($config['type'])): ?>
    <div class="card">
        <h3>Remove Configuration</h3>
        <p>Remove the configuration for this interface, returning it to an unconfigured state.</p>
        <form method="post" action="/network/interface/<?= urlencode($ifaceName) ?>/delete">
            <button type="submit" class="btn btn-danger" data-confirm="Remove configuration for <?= htmlspecialchars($ifaceName) ?>? The interface will become unconfigured.">Remove Configuration</button>
        </form>
    </div>
    <?php endif; ?>
</div>

<script>
function toggleAddressFields() {
    var type = document.getElementById('type').value;
    document.getElementById('static-fields').style.display = type === 'static' ? 'block' : 'none';
    document.getElementById('dhcp-fields').style.display = type === 'dhcp' ? 'block' : 'none';
    document.getElementById('disabled-fields').style.display = type === 'disabled' ? 'block' : 'none';
}

document.addEventListener('DOMContentLoaded', function() {
    toggleAddressFields();
});
</script>

<style>
.page-header {
    margin-bottom: 1.5rem;
}

.conditional-fields {
    margin: 1rem 0;
    padding: 1rem;
    background: var(--bg-dark);
    border-radius: 4px;
    border-left: 3px solid var(--accent);
}

.form-actions {
    margin-top: 1.5rem;
    display: flex;
    gap: 0.75rem;
}

.form-group small {
    display: block;
    margin-top: 0.25rem;
}

code {
    background: var(--bg-dark);
    padding: 0.2rem 0.4rem;
    border-radius: 3px;
    font-family: monospace;
}
</style>
