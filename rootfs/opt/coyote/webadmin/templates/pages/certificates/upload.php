<?php
$pageTitle = 'Upload Certificate';
$page = 'certificates';
$types = $types ?? [];
$labels = [
    'ca' => 'CA Certificate',
    'server' => 'Server Certificate',
    'client' => 'Client Certificate',
    'private' => 'Private Key',
];
?>

<div class="page-header">
    <a href="/certificates" class="btn btn-small">&larr; Back to Certificates</a>
</div>

<div class="dashboard-grid">
    <div class="card full-width">
        <h3>Upload Certificate or Key</h3>
        <form method="post" action="/certificates/upload" enctype="multipart/form-data">
            <div class="form-group">
                <label for="name">Name</label>
                <input type="text" id="name" name="name" required placeholder="Example: api.example.com certificate">
            </div>

            <div class="form-group">
                <label for="type">Type</label>
                <select id="type" name="type" required>
                    <?php foreach ($types as $type): ?>
                    <option value="<?= htmlspecialchars((string)$type) ?>"><?= htmlspecialchars($labels[(string)$type] ?? (string)$type) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="certificate">Certificate or Key File</label>
                <input type="file" id="certificate" name="certificate" accept=".pem,.crt,.cer,.key,.p12,.pfx">
                <small class="text-muted">If both a file and PEM content are provided, the uploaded file is used.</small>
            </div>

            <div class="form-group">
                <label for="pem_content">Paste PEM Content</label>
                <textarea id="pem_content" name="pem_content" rows="10" placeholder="-----BEGIN CERTIFICATE-----"></textarea>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Upload</button>
                <a href="/certificates" class="btn">Cancel</a>
            </div>
        </form>
    </div>
</div>
