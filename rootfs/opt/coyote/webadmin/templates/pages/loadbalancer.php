<?php
$pageTitle = 'Load Balancer';
$page = 'loadbalancer';
$enabled = $enabled ?? false;
$haproxy_running = $haproxy_running ?? false;
$frontends = $frontends ?? [];
$backends = $backends ?? [];
$statsEnabled = $stats_enabled ?? false;
$defaults = $defaults ?? [];
?>

<div class="dashboard-grid">
    <div class="card">
        <h3>HAProxy Status</h3>
        <dl>
            <dt>Load Balancer</dt>
            <dd>
                <span class="status-badge status-<?= $enabled ? 'up' : 'down' ?>">
                    <?= $enabled ? 'Enabled' : 'Disabled' ?>
                </span>
            </dd>
            <dt>Service</dt>
            <dd>
                <span class="status-badge status-<?= $haproxy_running ? 'up' : 'down' ?>">
                    <?= $haproxy_running ? 'Running' : 'Stopped' ?>
                </span>
            </dd>
            <dt>Frontends</dt>
            <dd><?= count($frontends) ?></dd>
            <dt>Backends</dt>
            <dd><?= count($backends) ?></dd>
            <dt>Default Mode</dt>
            <dd><?= htmlspecialchars(strtoupper($defaults['mode'] ?? 'HTTP')) ?></dd>
        </dl>
    </div>

    <div class="card">
        <h3>Quick Actions</h3>
        <div class="button-group">
            <a href="/loadbalancer/frontend/new" class="btn btn-primary">Add Frontend</a>
            <a href="/loadbalancer/backend/new" class="btn btn-primary">Add Backend</a>
            <a href="/loadbalancer/settings" class="btn btn-primary">Settings</a>
            <?php if ($statsEnabled): ?>
            <a href="/loadbalancer/stats" class="btn">View Stats</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="card full-width">
        <h3>Frontends</h3>
        <?php if (empty($frontends)): ?>
        <div class="placeholder-box">
            <p>No frontends configured.</p>
            <p class="text-muted">Frontends define how HAProxy accepts incoming connections.</p>
            <a href="/loadbalancer/frontend/new" class="btn btn-primary">Create First Frontend</a>
        </div>
        <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Bind</th>
                    <th>Mode</th>
                    <th>Default Backend</th>
                    <th>SSL</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($frontends as $name => $fe): ?>
                <tr>
                    <td>
                        <a href="/loadbalancer/frontend/<?= urlencode($name) ?>">
                            <strong><?= htmlspecialchars($name) ?></strong>
                        </a>
                    </td>
                    <td><?= htmlspecialchars($fe['bind'] ?? '') ?></td>
                    <td>
                        <span class="badge"><?= strtoupper($fe['mode'] ?? 'http') ?></span>
                    </td>
                    <td>
                        <?php if (!empty($fe['backend'])): ?>
                            <a href="/loadbalancer/backend/<?= urlencode($fe['backend']) ?>">
                                <?= htmlspecialchars($fe['backend']) ?>
                            </a>
                        <?php else: ?>
                            <span class="text-muted">None</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($fe['ssl_cert'])): ?>
                            <span class="badge badge-success">Yes</span>
                        <?php else: ?>
                            <span class="text-muted">No</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="/loadbalancer/frontend/<?= urlencode($name) ?>" class="btn btn-small">Edit</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <div class="card full-width">
        <h3>Backends</h3>
        <?php if (empty($backends)): ?>
        <div class="placeholder-box">
            <p>No backends configured.</p>
            <p class="text-muted">Backends define groups of servers that handle requests.</p>
            <a href="/loadbalancer/backend/new" class="btn btn-primary">Create First Backend</a>
        </div>
        <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Mode</th>
                    <th>Balance</th>
                    <th>Servers</th>
                    <th>Health Check</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($backends as $name => $be): ?>
                <?php $serverCount = count($be['servers'] ?? []); ?>
                <tr>
                    <td>
                        <a href="/loadbalancer/backend/<?= urlencode($name) ?>">
                            <strong><?= htmlspecialchars($name) ?></strong>
                        </a>
                    </td>
                    <td>
                        <span class="badge"><?= strtoupper($be['mode'] ?? 'http') ?></span>
                    </td>
                    <td><?= htmlspecialchars($be['balance'] ?? 'roundrobin') ?></td>
                    <td>
                        <span class="badge"><?= $serverCount ?> server<?= $serverCount !== 1 ? 's' : '' ?></span>
                    </td>
                    <td>
                        <?php if ($be['health_check'] ?? true): ?>
                            <span class="badge badge-success">Enabled</span>
                        <?php else: ?>
                            <span class="text-muted">Disabled</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="/loadbalancer/backend/<?= urlencode($name) ?>" class="btn btn-small">Edit</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <div class="card full-width">
        <h3>How Load Balancing Works</h3>
        <div class="help-content">
            <ol>
                <li><strong>Create Backends</strong> &mdash; Define server groups with balance algorithms and health checks.</li>
                <li><strong>Create Frontends</strong> &mdash; Define listener addresses and map them to backends.</li>
                <li><strong>Enable</strong> &mdash; Turn on the load balancer in <a href="/loadbalancer/settings">Settings</a>.</li>
                <li><strong>Apply Config</strong> &mdash; Apply changes via <a href="/system">System</a> to activate.</li>
            </ol>
        </div>
    </div>
</div>
