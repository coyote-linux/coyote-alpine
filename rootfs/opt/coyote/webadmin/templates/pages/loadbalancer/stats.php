<?php
$pageTitle = 'Load Balancer Stats';
$page = 'loadbalancer';
$haproxy_running = $haproxy_running ?? false;
$frontends = $frontends ?? [];
$backends = $backends ?? [];
$statsEnabled = $stats_enabled ?? false;
$statsPort = $stats_port ?? 8404;
$statsUri = $stats_uri ?? '/stats';
?>

<div class="page-header">
    <a href="/loadbalancer" class="btn btn-small">&larr; Back to Load Balancer</a>
</div>

<div class="dashboard-grid">
    <div class="card">
        <h3>Stats Overview</h3>
        <dl>
            <dt>HAProxy</dt>
            <dd>
                <span class="status-badge status-<?= $haproxy_running ? 'up' : 'down' ?>">
                    <?= $haproxy_running ? 'Running' : 'Stopped' ?>
                </span>
            </dd>
            <dt>Stats Page</dt>
            <dd>
                <?php if ($statsEnabled && $haproxy_running): ?>
                    <span class="badge badge-success">Enabled</span>
                <?php else: ?>
                    <span class="text-muted">Disabled</span>
                <?php endif; ?>
            </dd>
            <dt>Frontends</dt>
            <dd><?= count($frontends) ?></dd>
            <dt>Backends</dt>
            <dd><?= count($backends) ?></dd>
        </dl>

        <?php if ($statsEnabled && $haproxy_running): ?>
        <p class="text-muted">
            HAProxy native stats available at port <?= (int)$statsPort ?><?= htmlspecialchars($statsUri) ?>
        </p>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>Configuration Summary</h3>
        <?php if (empty($frontends) && empty($backends)): ?>
        <div class="placeholder-box">
            <p>No frontends or backends configured.</p>
            <p class="text-muted">Configure load balancing first to see statistics.</p>
        </div>
        <?php else: ?>
        <dl>
            <?php foreach ($frontends as $feName => $fe): ?>
            <dt>Frontend: <?= htmlspecialchars($feName) ?></dt>
            <dd>
                <?= htmlspecialchars($fe['bind'] ?? '') ?>
                &rarr;
                <?= htmlspecialchars($fe['backend'] ?? 'no backend') ?>
                <span class="badge"><?= strtoupper($fe['mode'] ?? 'http') ?></span>
            </dd>
            <?php endforeach; ?>

            <?php foreach ($backends as $beName => $be): ?>
            <dt>Backend: <?= htmlspecialchars($beName) ?></dt>
            <dd>
                <?= count($be['servers'] ?? []) ?> server(s),
                <?= htmlspecialchars($be['balance'] ?? 'roundrobin') ?>
                <span class="badge"><?= strtoupper($be['mode'] ?? 'http') ?></span>
            </dd>
            <?php endforeach; ?>
        </dl>
        <?php endif; ?>
    </div>

    <?php if (!empty($backends)): ?>
    <div class="card full-width">
        <h3>Backend Servers</h3>
        <?php foreach ($backends as $beName => $be): ?>
        <h4><?= htmlspecialchars($beName) ?> (<?= htmlspecialchars($be['balance'] ?? 'roundrobin') ?>)</h4>
        <?php if (empty($be['servers'])): ?>
        <p class="text-muted">No servers in this backend.</p>
        <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Server</th>
                    <th>Address</th>
                    <th>Port</th>
                    <th>Weight</th>
                    <th>Backup</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($be['servers'] as $srv): ?>
                <tr>
                    <td><?= htmlspecialchars($srv['name'] ?? $srv['address']) ?></td>
                    <td><?= htmlspecialchars($srv['address']) ?></td>
                    <td><?= (int)($srv['port'] ?? 80) ?></td>
                    <td><?= (int)($srv['weight'] ?? 1) ?></td>
                    <td>
                        <?php if (!empty($srv['backup'])): ?>
                            <span class="badge badge-warning">Backup</span>
                        <?php else: ?>
                            <span class="text-muted">No</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
