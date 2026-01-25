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
                    <td><?= htmlspecialchars(implode(', ', $iface['ipv4'] ?? []) ?: '-') ?></td>
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

<?php
// Check if MRTG graphs are available
$mrtgDir = '/var/www/mrtg';
$mrtgAvailable = is_dir($mrtgDir) && file_exists("$mrtgDir/cpu-day.png");
?>

<div class="card full-width" style="margin-top: 1rem;">
    <h3>Traffic Graphs</h3>
    <?php if (!$mrtgAvailable): ?>
    <p class="text-muted">Network traffic statistics (MRTG graphs will appear after first data collection)</p>
    <?php endif; ?>
    <div class="graph-container">
        <?php
        $interfaces = array_keys($network['interfaces'] ?? []);
        $displayInterfaces = array_filter($interfaces, fn($i) => $i !== 'lo');
        if (empty($displayInterfaces)) $displayInterfaces = ['eth0', 'eth1'];
        foreach (array_slice($displayInterfaces, 0, 4) as $iface):
            $graphFile = "$mrtgDir/$iface-day.png";
            $hasGraph = file_exists($graphFile);
        ?>
        <div class="graph-card">
            <h4><?= htmlspecialchars($iface) ?> - Daily Traffic</h4>
            <?php if ($hasGraph): ?>
            <a href="/mrtg/<?= htmlspecialchars($iface) ?>.html">
                <img src="/mrtg/<?= htmlspecialchars($iface) ?>-day.png" alt="<?= htmlspecialchars($iface) ?> traffic">
            </a>
            <?php else: ?>
            <div class="graph-placeholder">
                <span>Waiting for data...</span>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="card full-width">
    <h3>System Load</h3>
    <?php if (!$mrtgAvailable): ?>
    <p class="text-muted">CPU and memory utilization (MRTG graphs will appear after first data collection)</p>
    <?php endif; ?>
    <div class="graph-container">
        <div class="graph-card">
            <h4>CPU Usage - Daily</h4>
            <?php if (file_exists("$mrtgDir/cpu-day.png")): ?>
            <a href="/mrtg/cpu.html">
                <img src="/mrtg/cpu-day.png" alt="CPU usage">
            </a>
            <?php else: ?>
            <div class="graph-placeholder">
                <span>Waiting for data...</span>
            </div>
            <?php endif; ?>
        </div>
        <div class="graph-card">
            <h4>Memory Usage - Daily</h4>
            <?php if (file_exists("$mrtgDir/memory-day.png")): ?>
            <a href="/mrtg/memory.html">
                <img src="/mrtg/memory-day.png" alt="Memory usage">
            </a>
            <?php else: ?>
            <div class="graph-placeholder">
                <span>Waiting for data...</span>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
