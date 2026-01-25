<?php $pageTitle = 'Firewall'; $page = 'firewall'; ?>

<div class="dashboard-grid">
    <div class="card">
        <h3>Firewall Status</h3>
        <dl>
            <dt>Status</dt>
            <dd>
                <span class="status-badge status-<?= ($status['enabled'] ?? false) ? 'up' : 'down' ?>">
                    <?= ($status['enabled'] ?? false) ? 'Enabled' : 'Disabled' ?>
                </span>
            </dd>
            <dt>Active Connections</dt>
            <dd><?= $status['connections'] ?? 0 ?></dd>
        </dl>
    </div>

    <div class="card">
        <h3>Quick Actions</h3>
        <p>
            <button class="btn btn-primary" onclick="alert('Not yet implemented')">View Rules</button>
            <button class="btn btn-primary" onclick="alert('Not yet implemented')">Add Rule</button>
        </p>
    </div>

    <div class="card full-width">
        <h3>Firewall Rules</h3>
        <div class="placeholder-box">
            <p>Firewall rule management coming soon.</p>
            <p class="text-muted">Configure INPUT, OUTPUT, and FORWARD chain rules.</p>
        </div>
    </div>
</div>
