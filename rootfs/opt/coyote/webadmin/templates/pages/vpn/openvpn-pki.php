<?php
$pageTitle = 'OpenVPN PKI';
$page = 'vpn';

$initialized = (bool)($initialized ?? false);
$caInfo = is_array($caInfo ?? null) ? $caInfo : [];
$serverCerts = is_array($serverCerts ?? null) ? $serverCerts : [];
$clientCerts = is_array($clientCerts ?? null) ? $clientCerts : [];
$dhGenerated = (bool)($dhGenerated ?? false);
$serverInstances = is_array($serverInstances ?? null) ? $serverInstances : [];
$defaultServer = '';

if (!empty($serverInstances)) {
    $keys = array_keys($serverInstances);
    $defaultServer = (string)($keys[0] ?? '');
}
?>

<div class="page-header">
    <div class="button-group">
        <a href="/vpn/openvpn" class="btn btn-small">&larr; Back to OpenVPN</a>
    </div>
</div>

<div class="card">
    <h3>PKI Status</h3>
    <p>
        Status:
        <span class="status-badge status-<?= $initialized ? 'up' : 'down' ?>">
            <?= $initialized ? 'Initialized' : 'Not Initialized' ?>
        </span>
    </p>

    <?php if (!$initialized): ?>
    <form method="post" action="/vpn/openvpn/pki/init">
        <button type="submit" class="btn btn-primary" data-confirm="Initialize OpenVPN PKI and create a new CA?">Initialize PKI</button>
    </form>
    <?php else: ?>
    <dl>
        <dt>CA Subject</dt>
        <dd><?= htmlspecialchars((string)($caInfo['subject'] ?? 'Unknown')) ?></dd>
        <dt>CA Expiry</dt>
        <dd><?= htmlspecialchars((string)($caInfo['expires'] ?? 'Unknown')) ?></dd>
    </dl>
    <?php endif; ?>
</div>

<?php if ($initialized): ?>
<div class="card">
    <h3>Server Certificates</h3>
    <?php if (empty($serverCerts)): ?>
    <p class="text-muted">No server certificates generated.</p>
    <?php else: ?>
    <table class="data-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Generated</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($serverCerts as $cert): ?>
            <?php
            $certName = (string)($cert['name'] ?? '');
            $generatedAt = (int)($cert['generated_at'] ?? 0);
            ?>
            <tr>
                <td><strong><?= htmlspecialchars($certName) ?></strong></td>
                <td><?= $generatedAt > 0 ? date('Y-m-d H:i:s', $generatedAt) : 'Unknown' ?></td>
                <td>
                    <a href="/vpn/openvpn/pki?download=server-cert&amp;name=<?= urlencode($certName) ?>" class="btn btn-small">Download</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <h4 style="margin-top: 1.5rem;">Generate Server Certificate</h4>
    <form method="post" action="/vpn/openvpn/pki/server-cert" class="form-inline">
        <input type="text" name="name" required pattern="[a-zA-Z][a-zA-Z0-9_-]*" placeholder="server name">
        <button type="submit" class="btn btn-primary">Generate Server Cert</button>
    </form>
</div>

<div class="card">
    <h3>Client Certificates</h3>
    <?php if (empty($clientCerts)): ?>
    <p class="text-muted">No client certificates generated.</p>
    <?php else: ?>
    <table class="data-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Generated</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($clientCerts as $cert): ?>
            <?php
            $certName = (string)($cert['name'] ?? '');
            $generatedAt = (int)($cert['generated_at'] ?? 0);
            ?>
            <tr>
                <td><strong><?= htmlspecialchars($certName) ?></strong></td>
                <td><?= $generatedAt > 0 ? date('Y-m-d H:i:s', $generatedAt) : 'Unknown' ?></td>
                <td>
                    <?php if ($defaultServer !== ''): ?>
                    <a href="/vpn/openvpn/client/<?= urlencode($defaultServer) ?>/<?= urlencode($certName) ?>/download?view=1" class="btn btn-small">Download .ovpn</a>
                    <?php else: ?>
                    <span class="text-muted">No server instance</span>
                    <?php endif; ?>
                    <form method="post" action="/vpn/openvpn/pki/revoke/<?= urlencode($certName) ?>" style="display: inline;">
                        <button type="submit" class="btn btn-small btn-danger" data-confirm="Revoke client certificate '<?= htmlspecialchars($certName) ?>'?">Revoke</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <h4 style="margin-top: 1.5rem;">Generate Client Certificate</h4>
    <form method="post" action="/vpn/openvpn/pki/client-cert" class="form-inline">
        <input type="text" name="name" required pattern="[a-zA-Z][a-zA-Z0-9_-]*" placeholder="client name">
        <button type="submit" class="btn btn-primary">Generate Client Cert</button>
    </form>
</div>

<div class="card">
    <h3>DH Parameters</h3>
    <p>
        Status:
        <span class="status-badge status-<?= $dhGenerated ? 'up' : 'down' ?>">
            <?= $dhGenerated ? 'Generated' : 'Not Generated' ?>
        </span>
    </p>
    <form method="post" action="/vpn/openvpn/pki/init">
        <input type="hidden" name="action" value="dh">
        <button type="submit" class="btn btn-small" data-confirm="Generate DH parameters now? This can take several minutes.">Generate DH</button>
    </form>
</div>
<?php endif; ?>
