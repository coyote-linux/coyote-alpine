<?php
$isNew = (bool)($isNew ?? false);
$instance = is_array($instance ?? null) ? $instance : [];

$name = (string)($instance['name'] ?? '');
$mode = strtolower((string)($instance['mode'] ?? 'server'));
$enabled = (bool)($instance['enabled'] ?? true);
$protocol = strtolower((string)($instance['protocol'] ?? 'udp'));
$port = (int)($instance['port'] ?? 1194);
$device = strtolower((string)($instance['device'] ?? 'tun'));
$network = (string)($instance['network'] ?? '10.8.0.0/24');
$pushRoutes = (string)($instance['push_routes'] ?? '');
$pushDns = (string)($instance['push_dns'] ?? '');
$clientToClient = (bool)($instance['client_to_client'] ?? false);
$remoteHost = (string)($instance['remote_host'] ?? '');
$remotePort = (int)($instance['remote_port'] ?? 1194);
$cipher = (string)($instance['cipher'] ?? 'AES-256-GCM');
$auth = (string)($instance['auth'] ?? 'SHA256');
$keepaliveInterval = (int)($instance['keepalive_interval'] ?? 10);
$keepaliveTimeout = (int)($instance['keepalive_timeout'] ?? 120);

$pageTitle = $isNew ? 'New OpenVPN Instance' : 'Edit OpenVPN Instance: ' . $name;
$page = 'vpn';
?>

<div class="page-header">
    <a href="/vpn/openvpn" class="btn btn-small">&larr; Back to OpenVPN Instances</a>
</div>

<div class="card">
    <h3><?= $isNew ? 'Create OpenVPN Instance' : 'Edit OpenVPN Instance' ?></h3>
    <form method="post" action="<?= $isNew ? '/vpn/openvpn/new' : '/vpn/openvpn/' . urlencode($name) ?>">
        <?php if ($isNew): ?>
        <div class="form-group">
            <label for="name">Name</label>
            <input
                type="text"
                id="name"
                name="name"
                required
                pattern="[a-zA-Z][a-zA-Z0-9_-]*"
                value="<?= htmlspecialchars($name) ?>"
            >
        </div>
        <?php else: ?>
        <div class="form-group">
            <label for="name_display">Name</label>
            <input type="text" id="name_display" value="<?= htmlspecialchars($name) ?>" disabled>
            <input type="hidden" name="name" value="<?= htmlspecialchars($name) ?>">
        </div>
        <?php endif; ?>

        <div class="form-group">
            <label for="mode">Mode</label>
            <select id="mode" name="mode">
                <option value="server" <?= $mode === 'server' ? 'selected' : '' ?>>Server</option>
                <option value="client" <?= $mode === 'client' ? 'selected' : '' ?>>Client</option>
            </select>
        </div>

        <div class="form-group-inline">
            <label>
                <input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
                Enabled
            </label>
        </div>

        <div class="form-group">
            <label for="protocol">Protocol</label>
            <select id="protocol" name="protocol">
                <option value="udp" <?= $protocol === 'udp' ? 'selected' : '' ?>>UDP</option>
                <option value="tcp" <?= $protocol === 'tcp' ? 'selected' : '' ?>>TCP</option>
            </select>
        </div>

        <div class="form-group">
            <label for="port">Port</label>
            <input type="number" id="port" name="port" min="1" max="65535" value="<?= $port ?>">
        </div>

        <div class="form-group">
            <label for="device">Device</label>
            <select id="device" name="device">
                <option value="tun" <?= $device === 'tun' ? 'selected' : '' ?>>TUN</option>
                <option value="tap" <?= $device === 'tap' ? 'selected' : '' ?>>TAP</option>
            </select>
        </div>

        <div id="server_fields">
            <div class="form-group">
                <label for="network">VPN Network</label>
                <input type="text" id="network" name="network" value="<?= htmlspecialchars($network) ?>">
            </div>

            <div class="form-group">
                <label for="push_routes">Push Routes</label>
                <textarea id="push_routes" name="push_routes" rows="4" placeholder="10.10.0.0/16&#10;192.168.100.0/24"><?= htmlspecialchars($pushRoutes) ?></textarea>
            </div>

            <div class="form-group">
                <label for="push_dns">Push DNS</label>
                <input type="text" id="push_dns" name="push_dns" value="<?= htmlspecialchars($pushDns) ?>" placeholder="1.1.1.1, 8.8.8.8">
            </div>

            <div class="form-group-inline">
                <label>
                    <input type="checkbox" name="client_to_client" value="1" <?= $clientToClient ? 'checked' : '' ?>>
                    Client to Client
                </label>
            </div>
        </div>

        <div id="client_fields">
            <div class="form-group">
                <label for="remote_host">Remote Host</label>
                <input type="text" id="remote_host" name="remote_host" value="<?= htmlspecialchars($remoteHost) ?>">
            </div>

            <div class="form-group">
                <label for="remote_port">Remote Port</label>
                <input type="number" id="remote_port" name="remote_port" min="1" max="65535" value="<?= $remotePort ?>">
            </div>
        </div>

        <div class="form-group">
            <label for="cipher">Cipher</label>
            <select id="cipher" name="cipher">
                <option value="AES-256-GCM" <?= $cipher === 'AES-256-GCM' ? 'selected' : '' ?>>AES-256-GCM</option>
                <option value="AES-128-GCM" <?= $cipher === 'AES-128-GCM' ? 'selected' : '' ?>>AES-128-GCM</option>
                <option value="AES-256-CBC" <?= $cipher === 'AES-256-CBC' ? 'selected' : '' ?>>AES-256-CBC</option>
            </select>
        </div>

        <div class="form-group">
            <label for="auth">HMAC Auth</label>
            <select id="auth" name="auth">
                <option value="SHA256" <?= $auth === 'SHA256' ? 'selected' : '' ?>>SHA256</option>
                <option value="SHA384" <?= $auth === 'SHA384' ? 'selected' : '' ?>>SHA384</option>
                <option value="SHA512" <?= $auth === 'SHA512' ? 'selected' : '' ?>>SHA512</option>
            </select>
        </div>

        <div class="form-group">
            <label for="keepalive_interval">Keepalive Interval</label>
            <input type="number" id="keepalive_interval" name="keepalive_interval" min="1" value="<?= $keepaliveInterval ?>">
        </div>

        <div class="form-group">
            <label for="keepalive_timeout">Keepalive Timeout</label>
            <input type="number" id="keepalive_timeout" name="keepalive_timeout" min="1" value="<?= $keepaliveTimeout ?>">
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= $isNew ? 'Create Instance' : 'Save Instance' ?></button>
            <a href="/vpn/openvpn" class="btn">Cancel</a>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var modeSelect = document.getElementById('mode');
    var serverFields = document.getElementById('server_fields');
    var clientFields = document.getElementById('client_fields');
    var networkInput = document.getElementById('network');
    var remoteHostInput = document.getElementById('remote_host');

    var updateModeFields = function () {
        var mode = modeSelect.value;

        if (mode === 'client') {
            serverFields.style.display = 'none';
            clientFields.style.display = 'block';
            networkInput.required = false;
            remoteHostInput.required = true;
            return;
        }

        serverFields.style.display = 'block';
        clientFields.style.display = 'none';
        networkInput.required = true;
        remoteHostInput.required = false;
    };

    modeSelect.addEventListener('change', updateModeFields);
    updateModeFields();
});
</script>
