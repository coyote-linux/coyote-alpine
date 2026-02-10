<?php
$pageTitle = 'Certificates';
$page = 'certificates';
$certificates = $certificates ?? [];
$caCount = (int)($caCount ?? 0);
$serverCount = (int)($serverCount ?? 0);
$clientCount = (int)($clientCount ?? 0);
$privateCount = (int)($privateCount ?? 0);
$totalCount = (int)($totalCount ?? count($certificates));
?>

<div class="page-header">
    <a href="/certificates/upload" class="btn btn-primary">Upload Certificate</a>
    <a href="/certificates/acme" class="btn btn-primary">ACME / Let's Encrypt</a>
</div>

<div class="dashboard-grid">
    <div class="card">
        <h3>CA Certificates</h3>
        <p><strong><?= $caCount ?></strong></p>
    </div>

    <div class="card">
        <h3>Server Certificates</h3>
        <p><strong><?= $serverCount ?></strong></p>
    </div>

    <div class="card">
        <h3>Client Certificates</h3>
        <p><strong><?= $clientCount ?></strong></p>
    </div>

    <div class="card">
        <h3>Private Keys</h3>
        <p><strong><?= $privateCount ?></strong></p>
    </div>

    <div class="card full-width" id="ssl-certificate">
        <h3>Web Admin SSL Certificate</h3>

        <?php if (!empty($currentSslCertInfo)): ?>
        <div class="ssl-current-info">
            <h4>Current Certificate</h4>
            <table>
                <tr>
                    <td><strong>Subject</strong></td>
                    <td><?= htmlspecialchars($currentSslCertInfo['subject'] ?? '-') ?></td>
                </tr>
                <tr>
                    <td><strong>Issuer</strong></td>
                    <td><?= htmlspecialchars($currentSslCertInfo['issuer'] ?? '-') ?></td>
                </tr>
                <tr>
                    <td><strong>Valid From</strong></td>
                    <td><?= htmlspecialchars($currentSslCertInfo['valid_from'] ?? '-') ?></td>
                </tr>
                <tr>
                    <td><strong>Valid Until</strong></td>
                    <td><?= htmlspecialchars($currentSslCertInfo['valid_to'] ?? '-') ?></td>
                </tr>
            </table>
        </div>
        <?php else: ?>
        <p><em>No SSL certificate is currently configured. The web admin is using its default self-signed certificate.</em></p>
        <?php endif; ?>

        <h4 style="margin-top: 1.5rem;">Select Server Certificate</h4>
        <?php if (empty($serverCerts)): ?>
        <p>No server certificates are available. <a href="/certificates/upload">Upload</a> or <a href="/certificates/acme">request</a> a certificate first.</p>
        <?php else: ?>
        <form method="post" action="/certificates/ssl">
            <div class="form-group">
                <label for="ssl_cert_id">Server Certificate</label>
                <select id="ssl_cert_id" name="ssl_cert_id">
                    <option value="">-- Select a certificate --</option>
                    <?php foreach ($serverCerts as $cert): ?>
                    <option value="<?= htmlspecialchars($cert['id'] ?? '') ?>" <?= ($cert['id'] ?? '') === ($currentSslCertId ?? '') ? 'selected' : '' ?>>
                        <?= htmlspecialchars($cert['name'] ?? $cert['id'] ?? 'Unknown') ?>
                        <?php if (!empty($cert['info']['subject'])): ?>
                            (<?= htmlspecialchars($cert['info']['subject']) ?>)
                        <?php endif; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary" data-confirm="Apply this certificate to the web admin? The web server will be reloaded.">Apply Certificate</button>
        </form>
        <?php endif; ?>
    </div>

    <div class="card full-width">
        <h3>All Certificates and Keys</h3>
        <?php if (empty($certificates)): ?>
        <div class="placeholder-box">
            <p>No certificates or keys uploaded.</p>
            <p class="text-muted">Upload your first certificate to start managing TLS assets.</p>
            <a href="/certificates/upload" class="btn btn-primary">Upload Certificate</a>
        </div>
        <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Type</th>
                    <th>Subject</th>
                    <th>Expires</th>
                    <th>Fingerprint</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($certificates as $entry): ?>
                <?php
                $id = (string)($entry['id'] ?? '');
                $name = (string)($entry['name'] ?? '');
                $type = (string)($entry['type'] ?? '');
                $metadata = (array)($entry['metadata'] ?? []);
                $info = isset($entry['info']) && is_array($entry['info']) ? $entry['info'] : null;
                $subject = 'Private Key';
                if ($type !== 'private') {
                    $subject = (string)($info['subject'] ?? $metadata['subject'] ?? 'Unknown');
                }
                $daysUntilExpiry = null;
                if ($type !== 'private') {
                    if (isset($info['days_until_expiry'])) {
                        $daysUntilExpiry = (int)$info['days_until_expiry'];
                    } elseif (isset($metadata['days_until_expiry'])) {
                        $daysUntilExpiry = (int)$metadata['days_until_expiry'];
                    }
                }
                $validToHuman = '';
                if ($type !== 'private') {
                    $validToHuman = (string)($info['valid_to_human'] ?? $metadata['valid_to_human'] ?? '');
                }
                $fingerprint = (string)($info['fingerprint_sha256'] ?? $metadata['fingerprint_sha256'] ?? '');
                ?>
                <tr>
                    <td>
                        <a href="/certificates/<?= urlencode($id) ?>"><strong><?= htmlspecialchars($name) ?></strong></a>
                    </td>
                    <td>
                        <span class="status-badge <?= $type === 'private' ? 'status-down' : 'status-up' ?>">
                            <?= htmlspecialchars(ucfirst($type)) ?>
                        </span>
                    </td>
                    <td><?= htmlspecialchars($subject) ?></td>
                    <td>
                        <?php if ($type === 'private'): ?>
                        <span class="text-muted">N/A</span>
                        <?php elseif ($daysUntilExpiry === null): ?>
                        <span class="text-muted">Unknown</span>
                        <?php elseif ($daysUntilExpiry < 0): ?>
                        <span class="badge badge-danger">Expired</span>
                        <?php if ($validToHuman !== ''): ?>
                        <div class="text-muted"><?= htmlspecialchars($validToHuman) ?></div>
                        <?php endif; ?>
                        <?php elseif ($daysUntilExpiry < 30): ?>
                        <span class="badge badge-warning"><?= htmlspecialchars((string)$daysUntilExpiry) ?> days</span>
                        <?php if ($validToHuman !== ''): ?>
                        <div class="text-muted"><?= htmlspecialchars($validToHuman) ?></div>
                        <?php endif; ?>
                        <?php else: ?>
                        <span class="badge badge-success"><?= htmlspecialchars((string)$daysUntilExpiry) ?> days</span>
                        <?php if ($validToHuman !== ''): ?>
                        <div class="text-muted"><?= htmlspecialchars($validToHuman) ?></div>
                        <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($fingerprint !== ''): ?>
                        <code><?= htmlspecialchars(substr($fingerprint, 0, 24)) ?>...</code>
                        <?php else: ?>
                        <span class="text-muted">N/A</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="/certificates/<?= urlencode($id) ?>" class="btn btn-small">View</a>
                        <form method="post" action="/certificates/<?= urlencode($id) ?>/delete" style="display: inline;">
                            <button type="submit" class="btn btn-small btn-danger" data-confirm="Delete certificate '<?= htmlspecialchars($name) ?>'? This cannot be undone.">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="text-muted">Total items: <?= $totalCount ?></p>
        <?php endif; ?>
    </div>
</div>
