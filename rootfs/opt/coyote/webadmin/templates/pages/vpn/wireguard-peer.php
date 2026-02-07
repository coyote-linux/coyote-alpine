<?php
$interfaceName = (string)($interfaceName ?? '');
$peer = is_array($peer ?? null) ? $peer : [];

$name = (string)($peer['name'] ?? '');
$publicKey = (string)($peer['public_key'] ?? '');
$privateKey = (string)($peer['private_key'] ?? '');
$presharedKey = (string)($peer['preshared_key'] ?? '');
$allowedIps = (string)($peer['allowed_ips'] ?? '10.0.0.2/32');
$endpoint = (string)($peer['endpoint'] ?? '');
$persistentKeepalive = (int)($peer['persistent_keepalive'] ?? 25);

$pageTitle = 'New WireGuard Peer';
$page = 'vpn';
?>

<div class="page-header">
    <a href="/vpn/wireguard/<?= urlencode($interfaceName) ?>" class="btn btn-small">&larr; Back to Interface</a>
</div>

<div class="card">
    <h3>Add Peer to <?= htmlspecialchars($interfaceName) ?></h3>
    <form method="post" action="/vpn/wireguard/<?= urlencode($interfaceName) ?>/peer" id="wireguard_peer_form">
        <input type="hidden" name="private_key" id="private_key" value="<?= htmlspecialchars($privateKey) ?>">

        <div class="form-group">
            <label for="name">Name/Description</label>
            <input type="text" id="name" name="name" required value="<?= htmlspecialchars($name) ?>">
        </div>

        <div class="form-group">
            <label for="public_key">Public Key</label>
            <div class="button-group">
                <input type="text" id="public_key" name="public_key" required value="<?= htmlspecialchars($publicKey) ?>">
                <button type="button" class="btn btn-small" id="generate_keys">Generate</button>
            </div>
        </div>

        <div class="form-group">
            <label for="preshared_key">Preshared Key</label>
            <div class="button-group">
                <input type="text" id="preshared_key" name="preshared_key" value="<?= htmlspecialchars($presharedKey) ?>">
                <button type="button" class="btn btn-small" id="generate_psk">Generate</button>
            </div>
        </div>

        <div class="form-group">
            <label for="allowed_ips">Allowed IPs</label>
            <input type="text" id="allowed_ips" name="allowed_ips" required value="<?= htmlspecialchars($allowedIps) ?>">
        </div>

        <div class="form-group">
            <label for="endpoint">Endpoint</label>
            <input type="text" id="endpoint" name="endpoint" value="<?= htmlspecialchars($endpoint) ?>">
        </div>

        <div class="form-group">
            <label for="persistent_keepalive">Persistent Keepalive</label>
            <input type="number" id="persistent_keepalive" name="persistent_keepalive" min="0" max="65535" value="<?= $persistentKeepalive ?>">
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save Peer</button>
            <a href="/vpn/wireguard/<?= urlencode($interfaceName) ?>" class="btn">Cancel</a>
        </div>
    </form>
</div>

<div class="card">
    <h3>Next Step</h3>
    <p>After saving, use the Config action in the peers table to download or view this peer configuration.</p>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('wireguard_peer_form');
    var generateKeysButton = document.getElementById('generate_keys');
    var generatePskButton = document.getElementById('generate_psk');
    var nameInput = document.getElementById('name');
    var publicKeyInput = document.getElementById('public_key');
    var privateKeyInput = document.getElementById('private_key');
    var presharedKeyInput = document.getElementById('preshared_key');
    var allowedIpsInput = document.getElementById('allowed_ips');
    var endpointInput = document.getElementById('endpoint');
    var keepaliveInput = document.getElementById('persistent_keepalive');

    var toGenerateUrl = function (flag) {
        var params = new URLSearchParams();
        params.set('peer_name', nameInput.value || '');
        params.set('public_key', publicKeyInput.value || '');
        params.set('private_key', privateKeyInput.value || '');
        params.set('preshared_key', presharedKeyInput.value || '');
        params.set('allowed_ips', allowedIpsInput.value || '');
        params.set('endpoint', endpointInput.value || '');
        params.set('persistent_keepalive', keepaliveInput.value || '25');
        params.set(flag, '1');
        return '/vpn/wireguard/<?= urlencode($interfaceName) ?>/peer/new?' + params.toString();
    };

    generateKeysButton.addEventListener('click', function () {
        window.location.href = toGenerateUrl('generate_keys');
    });

    generatePskButton.addEventListener('click', function () {
        window.location.href = toGenerateUrl('generate_psk');
    });

    form.addEventListener('submit', function () {
        if (privateKeyInput.value === '' && publicKeyInput.value !== '') {
            privateKeyInput.value = '';
        }
    });
});
</script>
