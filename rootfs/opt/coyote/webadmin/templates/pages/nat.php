<?php $pageTitle = 'NAT'; $page = 'nat'; ?>

<div class="dashboard-grid">
    <div class="card">
        <h3>NAT Status</h3>
        <dl>
            <dt>Masquerading</dt>
            <dd>
                <span class="status-badge status-up">Enabled</span>
            </dd>
            <dt>Port Forwards</dt>
            <dd><?= $forward_count ?? 0 ?> rules</dd>
        </dl>
    </div>

    <div class="card">
        <h3>Quick Actions</h3>
        <div class="button-group">
            <button class="btn btn-primary" onclick="alert('Not yet implemented')">Add Port Forward</button>
            <button class="btn btn-primary" onclick="alert('Not yet implemented')">Add 1:1 NAT</button>
        </div>
    </div>

    <div class="card full-width">
        <h3>Port Forwards</h3>
        <div class="placeholder-box">
            <p>Port forwarding configuration coming soon.</p>
            <p class="text-muted">Forward incoming connections to internal hosts.</p>
        </div>
    </div>
</div>
