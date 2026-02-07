<?php
$entry = $entry ?? [];
$info = $info ?? null;
$content = (string)($content ?? '');
$name = (string)($entry['name'] ?? '');
$id = (string)($entry['id'] ?? '');
$type = (string)($entry['type'] ?? '');
$createdAt = isset($entry['created_at']) ? (int)$entry['created_at'] : 0;
$createdAtHuman = $createdAt > 0 ? date('Y-m-d H:i:s', $createdAt) : 'Unknown';
$path = (string)($entry['path'] ?? '');
$metadata = (array)($entry['metadata'] ?? []);
$pageTitle = 'Certificate: ' . $name;
$page = 'certificates';
$san = [];
if (is_array($info) && isset($info['san']) && is_array($info['san'])) {
    $san = $info['san'];
}
?>

<div class="page-header">
    <a href="/certificates" class="btn btn-small">&larr; Back to Certificates</a>
</div>

<div class="dashboard-grid">
    <?php if (is_array($info)): ?>
    <div class="card full-width">
        <h3>Certificate Details</h3>
        <dl>
            <dt>Subject</dt>
            <dd><?= htmlspecialchars((string)($info['subject'] ?? 'Unknown')) ?></dd>

            <dt>Issuer</dt>
            <dd><?= htmlspecialchars((string)($info['issuer'] ?? 'Unknown')) ?></dd>

            <dt>Serial</dt>
            <dd><?= htmlspecialchars((string)($info['serial'] ?? '')) ?></dd>

            <dt>Valid From</dt>
            <dd><?= htmlspecialchars((string)($info['valid_from_human'] ?? '')) ?></dd>

            <dt>Valid To</dt>
            <dd><?= htmlspecialchars((string)($info['valid_to_human'] ?? '')) ?></dd>

            <dt>Days Until Expiry</dt>
            <dd>
                <?php $days = (int)($info['days_until_expiry'] ?? 0); ?>
                <?php if (($info['is_expired'] ?? false) || $days < 0): ?>
                <span class="badge badge-danger">Expired</span>
                <?php elseif ($days < 30): ?>
                <span class="badge badge-warning"><?= htmlspecialchars((string)$days) ?> days</span>
                <?php else: ?>
                <span class="badge badge-success"><?= htmlspecialchars((string)$days) ?> days</span>
                <?php endif; ?>
            </dd>

            <dt>SANs</dt>
            <dd>
                <?php if (empty($san)): ?>
                <span class="text-muted">None</span>
                <?php else: ?>
                <?= htmlspecialchars(implode(', ', array_map('strval', $san))) ?>
                <?php endif; ?>
            </dd>

            <dt>Key Type</dt>
            <dd><?= htmlspecialchars((string)($info['key_type'] ?? 'Unknown')) ?></dd>

            <dt>Key Size</dt>
            <dd><?= htmlspecialchars((string)($info['key_bits'] ?? 0)) ?> bits</dd>

            <dt>Is CA</dt>
            <dd><?= !empty($info['is_ca']) ? 'Yes' : 'No' ?></dd>

            <dt>SHA256 Fingerprint</dt>
            <dd><code><?= htmlspecialchars((string)($info['fingerprint_sha256'] ?? '')) ?></code></dd>
        </dl>
    </div>
    <?php endif; ?>

    <div class="card">
        <h3>Certificate Metadata</h3>
        <dl>
            <dt>Name</dt>
            <dd><?= htmlspecialchars($name) ?></dd>

            <dt>Type</dt>
            <dd><?= htmlspecialchars(ucfirst($type)) ?></dd>

            <dt>ID</dt>
            <dd><code><?= htmlspecialchars($id) ?></code></dd>

            <dt>Created At</dt>
            <dd><?= htmlspecialchars($createdAtHuman) ?></dd>

            <dt>File Path</dt>
            <dd><code><?= htmlspecialchars($path) ?></code></dd>
        </dl>
    </div>

    <div class="card">
        <h3>Stored Metadata</h3>
        <?php if (empty($metadata)): ?>
        <p class="text-muted">No metadata available.</p>
        <?php else: ?>
        <dl>
            <?php foreach ($metadata as $metadataKey => $metadataValue): ?>
            <dt><?= htmlspecialchars((string)$metadataKey) ?></dt>
            <dd>
                <?php if (is_array($metadataValue)): ?>
                <?= htmlspecialchars(json_encode($metadataValue, JSON_UNESCAPED_SLASHES) ?: '') ?>
                <?php else: ?>
                <?= htmlspecialchars((string)$metadataValue) ?>
                <?php endif; ?>
            </dd>
            <?php endforeach; ?>
        </dl>
        <?php endif; ?>
    </div>

    <div class="card full-width">
        <h3>PEM Content</h3>
        <div class="form-actions">
            <button type="button" class="btn btn-small" onclick="navigator.clipboard.writeText(document.getElementById('pem-content').textContent)">Copy</button>
        </div>
        <pre><code id="pem-content"><?= htmlspecialchars($content) ?></code></pre>
    </div>

    <div class="card full-width">
        <h3>Delete</h3>
        <form method="post" action="/certificates/<?= urlencode($id) ?>/delete">
            <p class="text-muted">This permanently removes the stored certificate or key.</p>
            <button type="submit" class="btn btn-danger" data-confirm="Delete '<?= htmlspecialchars($name) ?>'? This cannot be undone.">Delete Certificate</button>
        </form>
    </div>
</div>
