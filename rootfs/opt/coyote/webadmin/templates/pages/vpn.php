<?php $pageTitle = 'VPN'; $page = 'vpn'; ?>

<div class="dashboard-grid">
    <div class="card">
        <h3>VPN Status</h3>
        <dl>
            <dt>StrongSwan</dt>
            <dd>
                <span class="status-badge status-<?= ($strongswan_running ?? false) ? 'up' : 'down' ?>">
                    <?= ($strongswan_running ?? false) ? 'Running' : 'Stopped' ?>
                </span>
            </dd>
            <dt>Active Tunnels</dt>
            <dd><?= $tunnel_count ?? 0 ?></dd>
        </dl>
    </div>

    <div class="card">
        <h3>Quick Actions</h3>
        <p>
            <button class="btn btn-primary" onclick="alert('Not yet implemented')">Add Tunnel</button>
            <button class="btn btn-primary" onclick="alert('Not yet implemented')">View Connections</button>
        </p>
    </div>

    <div class="card full-width">
        <h3>IPSec Tunnels</h3>
        <div class="placeholder-box">
            <p>VPN tunnel configuration coming soon.</p>
            <p class="text-muted">Configure site-to-site and remote access VPN tunnels.</p>
        </div>
    </div>
</div>
