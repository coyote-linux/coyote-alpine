<?php
$pageTitle = 'IPSec Tunnels';
$page = 'vpn';
$tunnels = is_array($tunnels ?? null) ? $tunnels : [];
?>

<div class="page-header">
    <div class="button-group">
        <a href="/vpn" class="btn btn-small">&larr; Back to VPN</a>
        <a href="/vpn/ipsec/new" class="btn btn-primary">Add Tunnel</a>
    </div>
</div>

<div class="card">
    <h3>IPSec Tunnels</h3>
    <?php if (empty($tunnels)): ?>
    <div class="placeholder-box">
        <p>No IPSec tunnels configured.</p>
        <p class="text-muted">Create a tunnel to connect remote sites or peers.</p>
        <a href="/vpn/ipsec/new" class="btn btn-primary">Create First Tunnel</a>
    </div>
    <?php else: ?>
    <table class="data-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Remote Address</th>
                <th>Local Network &rarr; Remote Network</th>
                <th>Auth Type</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($tunnels as $name => $tunnel): ?>
            <?php
            $remoteAddress = (string)($tunnel['remote_address'] ?? '');
            $localTs = (string)($tunnel['local_ts'] ?? '');
            $remoteTs = (string)($tunnel['remote_ts'] ?? '');
            $status = is_array($tunnel['status'] ?? null) ? $tunnel['status'] : [];
            $established = (bool)($status['established'] ?? false);
            $authMethod = (string)($tunnel['auth_method'] ?? '');
            if ($authMethod === '') {
                $authMethod = (($tunnel['local_auth'] ?? 'psk') === 'psk') ? 'psk' : 'cert';
            }
            $enabled = (bool)($tunnel['enabled'] ?? true);
            ?>
            <tr class="<?= $enabled ? '' : 'row-disabled' ?>">
                <td><strong><?= htmlspecialchars((string)$name) ?></strong></td>
                <td><?= htmlspecialchars($remoteAddress) ?></td>
                <td>
                    <code><?= htmlspecialchars($localTs) ?></code>
                    &rarr;
                    <code><?= htmlspecialchars($remoteTs) ?></code>
                </td>
                <td>
                    <span class="badge"><?= $authMethod === 'cert' ? 'X.509' : 'PSK' ?></span>
                </td>
                <td>
                    <?php if ($established): ?>
                    <span class="badge badge-success">Established</span>
                    <?php else: ?>
                    <span class="badge" style="background: #6c757d; color: #fff;">Disconnected</span>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="/vpn/ipsec/<?= urlencode((string)$name) ?>" class="btn btn-small">Edit</a>
                    <button
                        type="button"
                        class="btn btn-small <?= $established ? 'btn-danger' : 'btn-success' ?> js-tunnel-action"
                        data-url="/vpn/ipsec/<?= urlencode((string)$name) ?>/<?= $established ? 'disconnect' : 'connect' ?>"
                    >
                        <?= $established ? 'Disconnect' : 'Connect' ?>
                    </button>
                    <form method="post" action="/vpn/ipsec/<?= urlencode((string)$name) ?>/delete" style="display: inline;">
                        <button type="submit" class="btn btn-small btn-danger" data-confirm="Delete tunnel '<?= htmlspecialchars((string)$name) ?>'?">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var buttons = document.querySelectorAll('.js-tunnel-action');

    buttons.forEach(function (button) {
        button.addEventListener('click', function () {
            var url = button.getAttribute('data-url');

            fetch(url, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
                .then(function (response) {
                    return response.json();
                })
                .then(function (payload) {
                    if (payload.success) {
                        window.location.reload();
                        return;
                    }

                    alert('VPN tunnel operation failed.');
                })
                .catch(function () {
                    alert('VPN tunnel operation failed.');
                });
        });
    });
});
</script>
