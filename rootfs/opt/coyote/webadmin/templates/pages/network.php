<?php $pageTitle = 'Network'; $page = 'network'; ?>

<div class="dashboard-grid">
    <div class="card full-width">
        <h3>Network Interfaces</h3>
        <table>
            <thead>
                <tr>
                    <th>Interface</th>
                    <th>MAC Address</th>
                    <th>State</th>
                    <th>IPv4 Address</th>
                    <th>MTU</th>
                    <th>RX / TX</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($interfaces ?? [] as $name => $iface): ?>
                <tr>
                    <td><?= htmlspecialchars($name) ?></td>
                    <td><?= htmlspecialchars($iface['mac'] ?? '-') ?></td>
                    <td>
                        <span class="status-badge status-<?= ($iface['state'] ?? 'down') === 'up' ? 'up' : 'down' ?>">
                            <?= htmlspecialchars($iface['state'] ?? 'unknown') ?>
                        </span>
                    </td>
                    <td><?= htmlspecialchars(implode(', ', $iface['ipv4'] ?? []) ?: '-') ?></td>
                    <td><?= htmlspecialchars($iface['mtu'] ?? '-') ?></td>
                    <td>
                        <?= formatBytes($iface['stats']['rx_bytes'] ?? 0) ?> /
                        <?= formatBytes($iface['stats']['tx_bytes'] ?? 0) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card full-width">
        <h3>Routing Table</h3>
        <table>
            <thead>
                <tr>
                    <th>Destination</th>
                    <th>Gateway</th>
                    <th>Interface</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($routes ?? [] as $route): ?>
                <tr>
                    <td><?= htmlspecialchars($route['destination'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($route['gateway'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($route['interface'] ?? '-') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
function formatBytes(int $bytes): string {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}
?>
