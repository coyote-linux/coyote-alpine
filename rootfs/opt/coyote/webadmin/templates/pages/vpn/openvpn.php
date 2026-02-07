<?php
$pageTitle = 'OpenVPN Instances';
$page = 'vpn';
$instances = is_array($instances ?? null) ? $instances : [];
$pkiInitialized = (bool)($pkiInitialized ?? false);
?>

<div class="page-header">
    <div class="button-group">
        <a href="/vpn" class="btn btn-small">&larr; Back to VPN</a>
        <a href="/vpn/openvpn/pki" class="btn btn-small"><?= $pkiInitialized ? 'Manage PKI' : 'Initialize PKI' ?></a>
        <a href="/vpn/openvpn/new" class="btn btn-primary">Add Instance</a>
    </div>
</div>

<div class="card">
    <h3>OpenVPN Instances</h3>
    <?php if (empty($instances)): ?>
    <div class="placeholder-box">
        <p>No OpenVPN instances configured.</p>
        <p class="text-muted">Create a server or client instance to begin.</p>
        <a href="/vpn/openvpn/new" class="btn btn-primary">Create First Instance</a>
    </div>
    <?php else: ?>
    <table class="data-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Mode</th>
                <th>Port</th>
                <th>Network</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($instances as $name => $instance): ?>
            <?php
            $mode = strtolower((string)($instance['mode'] ?? 'server'));
            $enabled = (bool)($instance['enabled'] ?? true);
            $port = (int)($instance['port'] ?? 1194);
            $network = $mode === 'server'
                ? (string)($instance['network'] ?? '')
                : ((string)($instance['remote_host'] ?? '') . ':' . (string)($instance['remote_port'] ?? ''));
            $status = is_array($instance['status'] ?? null) ? $instance['status'] : [];
            $running = (bool)($status['running'] ?? false);
            $clients = (int)($status['connected_clients'] ?? 0);
            ?>
            <tr class="<?= $enabled ? '' : 'row-disabled' ?>">
                <td><strong><?= htmlspecialchars((string)$name) ?></strong></td>
                <td><?= $mode === 'client' ? 'Client' : 'Server' ?></td>
                <td><?= $port ?></td>
                <td><code><?= htmlspecialchars($network) ?></code></td>
                <td>
                    <?php if ($running): ?>
                    <span class="badge badge-success">Running<?= $mode === 'server' ? ' (' . $clients . ' clients)' : '' ?></span>
                    <?php else: ?>
                    <span class="badge" style="background: #6c757d; color: #fff;">Stopped</span>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="/vpn/openvpn/<?= urlencode((string)$name) ?>" class="btn btn-small">Edit</a>
                    <form method="post" action="/vpn/openvpn/<?= urlencode((string)$name) ?>/delete" style="display: inline;">
                        <button type="submit" class="btn btn-small btn-danger" data-confirm="Delete OpenVPN instance '<?= htmlspecialchars((string)$name) ?>'?">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
