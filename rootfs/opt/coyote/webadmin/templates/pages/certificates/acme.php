<?php
$pageTitle = 'ACME / Let\'s Encrypt';
$page = 'certificates';
$registered = (bool)($registered ?? false);
$accountInfo = is_array($accountInfo ?? null) ? $accountInfo : [];
$managedCertificates = is_array($managedCertificates ?? null) ? $managedCertificates : [];
?>

<div class="page-header">
    <a href="/certificates" class="btn btn-small">&larr; Back to Certificates</a>
</div>

<div class="dashboard-grid">
    <div class="card">
        <h3>Account Status</h3>
        <?php if (!$registered): ?>
        <p><span class="badge badge-warning">Not Registered</span></p>
        <form method="post" action="/certificates/acme/register">
            <div class="form-group">
                <label for="acme_email">Email</label>
                <input type="email" id="acme_email" name="email" required placeholder="admin@example.com">
            </div>
            <button type="submit" class="btn btn-primary">Register Account</button>
        </form>
        <?php else: ?>
        <p><span class="badge badge-success">Registered</span></p>
        <?php if (!empty($accountInfo['email'])): ?>
        <p><strong>Email:</strong> <?= htmlspecialchars((string)$accountInfo['email']) ?></p>
        <?php endif; ?>
        <?php if (!empty($accountInfo['registered_at'])): ?>
        <p><strong>Registered:</strong> <?= htmlspecialchars((string)$accountInfo['registered_at']) ?></p>
        <?php endif; ?>
        <?php if (!empty($accountInfo['key_path'])): ?>
        <p><strong>Account Key:</strong> <code><?= htmlspecialchars((string)$accountInfo['key_path']) ?></code></p>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>Request Certificate</h3>
        <form method="post" action="/certificates/acme/request">
            <div class="form-group">
                <label for="acme_domain">Domain</label>
                <input type="text" id="acme_domain" name="domain" required placeholder="vpn.example.com">
            </div>
            <button type="submit" class="btn btn-primary">Request Certificate</button>
        </form>
        <p class="text-muted">Your domain must resolve to this server and port 80 must be accessible from the internet.</p>
    </div>

    <div class="card full-width">
        <h3>Managed Certificates</h3>
        <div class="form-actions">
            <form method="post" action="/certificates/acme/renew-all" style="display: inline;">
                <button type="submit" class="btn btn-primary">Renew All Expiring</button>
            </form>
        </div>
        <?php include __DIR__ . '/acme-table.php'; ?>
    </div>

    <div class="card full-width">
        <h3>Auto-Renewal</h3>
        <p><strong>Status:</strong> Enabled (runs daily)</p>
        <p class="text-muted">Daily renewal checks run via cron. Certificates nearing expiry are renewed automatically.</p>
    </div>
</div>
