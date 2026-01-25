<?php $pageTitle = 'Load Balancer'; $page = 'loadbalancer'; ?>

<div class="dashboard-grid">
    <div class="card">
        <h3>HAProxy Status</h3>
        <dl>
            <dt>Service</dt>
            <dd>
                <span class="status-badge status-<?= ($haproxy_running ?? false) ? 'up' : 'down' ?>">
                    <?= ($haproxy_running ?? false) ? 'Running' : 'Stopped' ?>
                </span>
            </dd>
            <dt>Frontends</dt>
            <dd><?= $frontend_count ?? 0 ?></dd>
            <dt>Backends</dt>
            <dd><?= $backend_count ?? 0 ?></dd>
        </dl>
    </div>

    <div class="card">
        <h3>Quick Actions</h3>
        <p>
            <button class="btn btn-primary" onclick="alert('Not yet implemented')">Add Frontend</button>
            <button class="btn btn-primary" onclick="alert('Not yet implemented')">Add Backend</button>
            <button class="btn btn-primary" onclick="alert('Not yet implemented')">View Stats</button>
        </p>
    </div>

    <div class="card full-width">
        <h3>Load Balancer Configuration</h3>
        <div class="placeholder-box">
            <p>Load balancer configuration coming soon.</p>
            <p class="text-muted">Configure HAProxy frontends, backends, and health checks.</p>
        </div>
    </div>
</div>
