<?php
$pageTitle = 'OpenVPN Client Download';
$page = 'vpn';

$serverName = (string)($serverName ?? '');
$clientName = (string)($clientName ?? '');
$serverConfig = is_array($serverConfig ?? null) ? $serverConfig : [];

$protocol = strtoupper((string)($serverConfig['protocol'] ?? 'udp'));
$port = (int)($serverConfig['port'] ?? 1194);
$host = trim((string)($serverConfig['public_host'] ?? $serverConfig['remote_host'] ?? $serverName));
?>

<div class="page-header">
    <div class="button-group">
        <a href="/vpn/openvpn/pki" class="btn btn-small">&larr; Back to PKI</a>
    </div>
</div>

<div class="card">
    <h3>Client Configuration Download</h3>
    <dl>
        <dt>Client Name</dt>
        <dd><?= htmlspecialchars($clientName) ?></dd>
        <dt>Server</dt>
        <dd><?= htmlspecialchars($serverName) ?></dd>
        <dt>Connection</dt>
        <dd><code><?= htmlspecialchars($host) ?>:<?= $port ?> (<?= htmlspecialchars($protocol) ?>)</code></dd>
    </dl>

    <p style="margin-top: 1rem;">
        <a href="/vpn/openvpn/client/<?= urlencode($serverName) ?>/<?= urlencode($clientName) ?>/download" class="btn btn-primary">Download .ovpn</a>
    </p>

    <div class="placeholder-box" style="margin-top: 1rem;">
        <p>Scan with OpenVPN Connect app</p>
    </div>
</div>
