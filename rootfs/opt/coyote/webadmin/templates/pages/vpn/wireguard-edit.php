<?php
$isNew = (bool)($isNew ?? false);
$interface = is_array($interface ?? null) ? $interface : [];
$interfaceStatus = is_array($interfaceStatus ?? null) ? $interfaceStatus : [];

$name = (string)($interface['name'] ?? '');
$enabled = (bool)($interface['enabled'] ?? true);
$listenPort = (int)($interface['listen_port'] ?? 51820);
$address = (string)($interface['address'] ?? '10.0.0.1/24');
$privateKey = (string)($interface['private_key'] ?? '');
$publicKey = (string)($interface['public_key'] ?? '');
$dns = (string)($interface['dns'] ?? '');
$mtu = (int)($interface['mtu'] ?? 0);
$peers = is_array($interface['peers'] ?? null) ? $interface['peers'] : [];

$pageTitle = $isNew ? 'New WireGuard Interface' : 'Edit WireGuard Interface: ' . $name;
$page = 'vpn';
?>

<div class="page-header">
    <a href="/vpn/wireguard" class="btn btn-small">&larr; Back to WireGuard</a>
</div>

<div class="card">
    <h3><?= $isNew ? 'Create WireGuard Interface' : 'Edit WireGuard Interface' ?></h3>
    <form method="post" action="<?= $isNew ? '/vpn/wireguard/new' : '/vpn/wireguard/' . urlencode($name) ?>">
        <?php if ($isNew): ?>
        <div class="form-group">
            <label for="name">Name</label>
            <input
                type="text"
                id="name"
                name="name"
                required
                pattern="[a-zA-Z][a-zA-Z0-9_-]{0,14}"
                placeholder="wg0"
                value="<?= htmlspecialchars($name) ?>"
            >
        </div>
        <?php else: ?>
        <div class="form-group">
            <label for="name_display">Name</label>
            <input type="text" id="name_display" value="<?= htmlspecialchars($name) ?>" disabled>
        </div>
        <?php endif; ?>

        <div class="form-group-inline">
            <label>
                <input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
                Enabled
            </label>
        </div>

        <div class="form-group">
            <label for="listen_port">Listen Port</label>
            <input type="number" id="listen_port" name="listen_port" min="1" max="65535" value="<?= $listenPort ?>">
        </div>

        <div class="form-group">
            <label for="address">Address</label>
            <input type="text" id="address" name="address" required value="<?= htmlspecialchars($address) ?>">
        </div>

        <div class="form-group">
            <label for="private_key">Private Key</label>
            <div class="button-group">
                <input type="password" id="private_key" name="private_key" readonly value="<?= htmlspecialchars($privateKey) ?>">
                <button type="button" class="btn btn-small" id="toggle_private_key">Reveal</button>
            </div>
        </div>

        <div class="form-group">
            <label for="public_key">Public Key</label>
            <div class="button-group">
                <input type="text" id="public_key" name="public_key" readonly value="<?= htmlspecialchars($publicKey) ?>">
                <button type="button" class="btn btn-small" id="copy_public_key">Copy</button>
            </div>
        </div>

        <div class="form-group">
            <label for="dns">DNS</label>
            <input type="text" id="dns" name="dns" value="<?= htmlspecialchars($dns) ?>">
        </div>

        <div class="form-group">
            <label for="mtu">MTU</label>
            <input type="number" id="mtu" name="mtu" min="0" value="<?= $mtu ?>">
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= $isNew ? 'Create Interface' : 'Save Interface' ?></button>
            <a href="/vpn/wireguard" class="btn">Cancel</a>
        </div>
    </form>
</div>

<?php if (!$isNew): ?>
<div class="card">
    <div class="page-header">
        <h3>Peers</h3>
        <a href="/vpn/wireguard/<?= urlencode($name) ?>/peer/new" class="btn btn-primary">Add Peer</a>
    </div>

    <?php if (empty($peers)): ?>
    <div class="placeholder-box">
        <p>No peers configured.</p>
        <p class="text-muted">Add a peer to generate client configuration.</p>
    </div>
    <?php else: ?>
    <table class="data-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Public Key</th>
                <th>Allowed IPs</th>
                <th>Endpoint</th>
                <th>Last Handshake</th>
                <th>Transfer</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($peers as $peer): ?>
            <?php
            $peerName = (string)($peer['name'] ?? 'peer');
            $peerPublicKey = (string)($peer['public_key'] ?? '');
            $peerAllowedIps = (string)($peer['allowed_ips'] ?? '');
            $peerEndpoint = (string)($peer['endpoint'] ?? '');
            $peerStatus = is_array($peer['status'] ?? null) ? $peer['status'] : [];
            $lastHandshake = (string)($peerStatus['latest_handshake'] ?? 'never');
            $transfer = (string)($peerStatus['transfer'] ?? '0 B received, 0 B sent');
            $shortKey = $peerPublicKey;
            if (strlen($shortKey) > 18) {
                $shortKey = substr($shortKey, 0, 8) . '...' . substr($shortKey, -8);
            }
            ?>
            <tr>
                <td><?= htmlspecialchars($peerName) ?></td>
                <td><code title="<?= htmlspecialchars($peerPublicKey) ?>"><?= htmlspecialchars($shortKey) ?></code></td>
                <td><code><?= htmlspecialchars($peerAllowedIps) ?></code></td>
                <td><?= htmlspecialchars($peerEndpoint) ?></td>
                <td><?= htmlspecialchars($lastHandshake) ?></td>
                <td><?= htmlspecialchars($transfer) ?></td>
                <td>
                    <a href="/vpn/wireguard/<?= urlencode($name) ?>/peer/<?= rawurlencode($peerPublicKey) ?>/config" class="btn btn-small">Config</a>
                    <form method="post" action="/vpn/wireguard/<?= urlencode($name) ?>/peer/<?= rawurlencode($peerPublicKey) ?>/delete" style="display: inline;">
                        <button type="submit" class="btn btn-small btn-danger" data-confirm="Delete peer '<?= htmlspecialchars($peerName) ?>'?">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var privateKeyInput = document.getElementById('private_key');
    var togglePrivateKeyButton = document.getElementById('toggle_private_key');
    var publicKeyInput = document.getElementById('public_key');
    var copyPublicKeyButton = document.getElementById('copy_public_key');

    if (togglePrivateKeyButton && privateKeyInput) {
        togglePrivateKeyButton.addEventListener('click', function () {
            var isHidden = privateKeyInput.type === 'password';
            privateKeyInput.type = isHidden ? 'text' : 'password';
            togglePrivateKeyButton.textContent = isHidden ? 'Hide' : 'Reveal';
        });
    }

    if (copyPublicKeyButton && publicKeyInput) {
        copyPublicKeyButton.addEventListener('click', function () {
            if (publicKeyInput.value === '') {
                return;
            }

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(publicKeyInput.value).then(function () {
                    copyPublicKeyButton.textContent = 'Copied';
                    setTimeout(function () {
                        copyPublicKeyButton.textContent = 'Copy';
                    }, 1000);
                });
                return;
            }

            publicKeyInput.select();
            document.execCommand('copy');
            copyPublicKeyButton.textContent = 'Copied';
            setTimeout(function () {
                copyPublicKeyButton.textContent = 'Copy';
            }, 1000);
        });
    }
});
</script>
