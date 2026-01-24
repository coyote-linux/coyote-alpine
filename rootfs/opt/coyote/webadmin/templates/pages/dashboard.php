<?php $pageTitle = 'Dashboard'; $page = 'dashboard'; ?>

<div class="dashboard-grid">
    <div class="card">
        <h3>System</h3>
        <dl>
            <dt>Hostname</dt>
            <dd><?= htmlspecialchars($system['hostname'] ?? 'unknown') ?></dd>
            <dt>Uptime</dt>
            <dd><?= htmlspecialchars($system['uptime'] ?? 'unknown') ?></dd>
            <dt>Load Average</dt>
            <dd><?= htmlspecialchars(implode(', ', $system['load'] ?? [])) ?></dd>
        </dl>
    </div>

    <div class="card">
        <h3>Memory</h3>
        <?php
        $mem = $system['memory'] ?? [];
        $total = $mem['total'] ?? 0;
        $available = $mem['available'] ?? 0;
        $used = $total - $available;
        $percent = $total > 0 ? round(($used / $total) * 100, 1) : 0;
        ?>
        <div class="progress-bar">
            <div class="progress-fill" style="width: <?= $percent ?>%"></div>
        </div>
        <p><?= round($used / 1024 / 1024) ?> MB / <?= round($total / 1024 / 1024) ?> MB (<?= $percent ?>%)</p>
    </div>

    <div class="card">
        <h3>Network Interfaces</h3>
        <table>
            <thead>
                <tr>
                    <th>Interface</th>
                    <th>Status</th>
                    <th>IP Address</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($network['interfaces'] ?? [] as $name => $iface): ?>
                <tr>
                    <td><?= htmlspecialchars($name) ?></td>
                    <td>
                        <span class="status-badge status-<?= ($iface['state'] ?? 'down') === 'up' ? 'up' : 'down' ?>">
                            <?= htmlspecialchars($iface['state'] ?? 'unknown') ?>
                        </span>
                    </td>
                    <td><?= htmlspecialchars($iface['address'] ?? '-') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h3>Services</h3>
        <table>
            <thead>
                <tr>
                    <th>Service</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($services ?? [] as $name => $status): ?>
                <tr>
                    <td><?= htmlspecialchars($name) ?></td>
                    <td>
                        <span class="status-badge status-<?= $status['running'] ? 'up' : 'down' ?>">
                            <?= $status['running'] ? 'Running' : 'Stopped' ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h3>Firewall</h3>
        <p>
            Status:
            <span class="status-badge status-<?= ($firewall['enabled'] ?? false) ? 'up' : 'down' ?>">
                <?= ($firewall['enabled'] ?? false) ? 'Enabled' : 'Disabled' ?>
            </span>
        </p>
        <?php if (isset($firewall['connections'])): ?>
        <p>Active connections: <?= $firewall['connections']['count'] ?? 0 ?></p>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>Load Balancer</h3>
        <p>
            Status:
            <span class="status-badge status-<?= ($loadbalancer['running'] ?? false) ? 'up' : 'down' ?>">
                <?= ($loadbalancer['running'] ?? false) ? 'Running' : 'Stopped' ?>
            </span>
        </p>
    </div>
</div>
