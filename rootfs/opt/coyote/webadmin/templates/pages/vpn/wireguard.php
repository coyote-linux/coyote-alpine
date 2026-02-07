<?php
$pageTitle = 'WireGuard';
$page = 'vpn';
$interfaces = is_array($interfaces ?? null) ? $interfaces : [];
$wireguardEnabled = (bool)($wireguardEnabled ?? false);
?>

<div class="page-header">
    <div class="button-group">
        <a href="/vpn" class="btn btn-small">&larr; Back to VPN</a>
        <a href="/vpn/wireguard/new" class="btn btn-primary">Add Interface</a>
    </div>
</div>

<div class="card">
    <h3>WireGuard Interfaces</h3>
    <p>
        <span class="status-badge status-<?= $wireguardEnabled ? 'up' : 'down' ?>">
            <?= $wireguardEnabled ? 'Enabled' : 'Disabled' ?>
        </span>
    </p>

    <?php if (empty($interfaces)): ?>
    <div class="placeholder-box">
        <p>No WireGuard interfaces configured.</p>
        <p class="text-muted">Create an interface to start managing peers and client configs.</p>
        <a href="/vpn/wireguard/new" class="btn btn-primary">Create First Interface</a>
    </div>
    <?php else: ?>
    <table class="data-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Address</th>
                <th>Port</th>
                <th>Peers</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($interfaces as $name => $interface): ?>
            <?php
            $enabled = (bool)($interface['enabled'] ?? true);
            $status = is_array($interface['status'] ?? null) ? $interface['status'] : [];
            $up = (bool)($status['up'] ?? false);
            ?>
            <tr class="<?= $enabled ? '' : 'row-disabled' ?>">
                <td><strong><?= htmlspecialchars((string)$name) ?></strong></td>
                <td><code><?= htmlspecialchars((string)($interface['address'] ?? '')) ?></code></td>
                <td><?= (int)($interface['listen_port'] ?? 51820) ?></td>
                <td><?= (int)($interface['peer_count'] ?? 0) ?></td>
                <td>
                    <span class="badge <?= $up ? 'badge-success' : '' ?>"<?= $up ? '' : ' style="background: #6c757d; color: #fff;"' ?>>
                        <?= $up ? 'Up' : 'Down' ?>
                    </span>
                </td>
                <td>
                    <a href="/vpn/wireguard/<?= urlencode((string)$name) ?>" class="btn btn-small">Edit</a>
                    <form method="post" action="/vpn/wireguard/<?= urlencode((string)$name) ?>/delete" style="display: inline;">
                        <button type="submit" class="btn btn-small btn-danger" data-confirm="Delete WireGuard interface '<?= htmlspecialchars((string)$name) ?>'?">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
