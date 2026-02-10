<?php
$managedCertificates = is_array($managedCertificates ?? null) ? $managedCertificates : [];
?>

<?php if (empty($managedCertificates)): ?>
<div class="placeholder-box">
    <p>No ACME-managed certificates found.</p>
    <p class="text-muted">Request a certificate above to start managing Let's Encrypt certificates.</p>
</div>
<?php else: ?>
<table class="data-table">
    <thead>
        <tr>
            <th>Domain</th>
            <th>Status</th>
            <th>Issued</th>
            <th>Expires</th>
            <th>Days Remaining</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($managedCertificates as $entry): ?>
        <?php
        $metadata = (array)($entry['metadata'] ?? []);
        $info = isset($entry['info']) && is_array($entry['info']) ? $entry['info'] : [];
        $domain = (string)($metadata['domain'] ?? $entry['name'] ?? '');
        $createdAt = (int)($entry['created_at'] ?? 0);
        $issuedAt = $createdAt > 0 ? date('Y-m-d H:i:s', $createdAt) : 'Unknown';
        $expiresAt = (string)($info['valid_to_human'] ?? $metadata['valid_to_human'] ?? 'Unknown');
        $daysRemaining = null;
        if (isset($info['days_until_expiry'])) {
            $daysRemaining = (int)$info['days_until_expiry'];
        } elseif (isset($metadata['days_until_expiry'])) {
            $daysRemaining = (int)$metadata['days_until_expiry'];
        }
        $statusLabel = 'Unknown';
        $statusClass = 'badge-warning';
        if ($daysRemaining !== null) {
            if ($daysRemaining < 0) {
                $statusLabel = 'Expired';
                $statusClass = 'badge-danger';
            } elseif ($daysRemaining < 30) {
                $statusLabel = 'Expiring Soon';
                $statusClass = 'badge-warning';
            } else {
                $statusLabel = 'Active';
                $statusClass = 'badge-success';
            }
        }
        ?>
        <tr>
            <td><strong><?= htmlspecialchars($domain) ?></strong></td>
            <td><span class="badge <?= htmlspecialchars($statusClass) ?>"><?= htmlspecialchars($statusLabel) ?></span></td>
            <td><?= htmlspecialchars($issuedAt) ?></td>
            <td><?= htmlspecialchars($expiresAt) ?></td>
            <td>
                <?php if ($daysRemaining === null): ?>
                <span class="text-muted">Unknown</span>
                <?php else: ?>
                <?= htmlspecialchars((string)$daysRemaining) ?>
                <?php endif; ?>
            </td>
            <td>
                <form method="post" action="/certificates/acme/renew" style="display: inline;">
                    <input type="hidden" name="domain" value="<?= htmlspecialchars($domain) ?>">
                    <button type="submit" class="btn btn-small">Renew</button>
                </form>
                <a href="/certificates#ssl-certificate" class="btn btn-small btn-primary">Assign to service</a>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
