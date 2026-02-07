<?php
$isNew = $isNew ?? false;
$frontend = $frontend ?? null;
$backendNames = $backendNames ?? [];
$name = $frontend['name'] ?? '';
$bind = $frontend['bind'] ?? '*:80';
$mode = $frontend['mode'] ?? 'http';
$defaultBackend = $frontend['backend'] ?? '';
$sslCert = (string)($frontend['ssl_cert'] ?? '');
$forwardHeaders = $frontend['http_request_add_header'] ?? false;
$maxconn = $frontend['maxconn'] ?? '';
$pageTitle = $isNew ? 'New Frontend' : 'Edit Frontend: ' . $name;
$page = 'loadbalancer';
?>

<div class="page-header">
    <a href="/loadbalancer" class="btn btn-small">&larr; Back to Load Balancer</a>
</div>

<div class="dashboard-grid">
    <div class="card full-width">
        <h3><?= $isNew ? 'Create New Frontend' : 'Frontend: ' . htmlspecialchars($name) ?></h3>
        <form method="post" action="<?= $isNew ? '/loadbalancer/frontend/new' : '/loadbalancer/frontend/' . urlencode($name) ?>">
            <input type="hidden" name="is_new" value="<?= $isNew ? '1' : '0' ?>">

            <?php if ($isNew): ?>
            <div class="form-group">
                <label for="name">Frontend Name</label>
                <input type="text" id="name" name="name" required
                       pattern="[a-zA-Z][a-zA-Z0-9_-]*" maxlength="32"
                       placeholder="web-frontend" value="<?= htmlspecialchars($name) ?>">
                <small class="text-muted">Letters, numbers, underscores, hyphens. Must start with a letter.</small>
            </div>
            <?php else: ?>
            <input type="hidden" name="name" value="<?= htmlspecialchars($name) ?>">
            <?php endif; ?>

            <div class="form-group">
                <label for="bind">Bind Address</label>
                <input type="text" id="bind" name="bind" required
                       placeholder="*:80" value="<?= htmlspecialchars($bind) ?>">
                <small class="text-muted">Address and port to listen on (e.g. *:80, 0.0.0.0:443, 192.168.1.1:8080)</small>
            </div>

            <div class="form-group">
                <label for="mode">Mode</label>
                <select id="mode" name="mode">
                    <option value="http" <?= $mode === 'http' ? 'selected' : '' ?>>HTTP</option>
                    <option value="tcp" <?= $mode === 'tcp' ? 'selected' : '' ?>>TCP</option>
                </select>
            </div>

            <div class="form-group">
                <label for="default_backend">Default Backend</label>
                <select id="default_backend" name="default_backend">
                    <option value="">— None —</option>
                    <?php foreach ($backendNames as $beName): ?>
                    <option value="<?= htmlspecialchars($beName) ?>" <?= $beName === $defaultBackend ? 'selected' : '' ?>>
                        <?= htmlspecialchars($beName) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted">Backend that handles requests when no ACL rules match.</small>
            </div>

            <div class="form-group">
                <label for="ssl_cert">SSL Certificate (optional)</label>
                <select id="ssl_cert" name="ssl_cert">
                    <option value="">— None —</option>
                    <?php if (str_starts_with($sslCert, '/')): ?>
                    <option value="<?= htmlspecialchars($sslCert) ?>" selected>
                        <?= htmlspecialchars($sslCert) ?>
                    </option>
                    <?php endif; ?>
                    <?php foreach ($serverCerts ?? [] as $cert): ?>
                    <option value="<?= htmlspecialchars($cert['id']) ?>" <?= ($sslCert ?? '') === ($cert['id'] ?? '') ? 'selected' : '' ?>>
                        <?= htmlspecialchars($cert['name']) ?>
                        <?php if (isset($cert['info']['subject'])): ?> (<?= htmlspecialchars($cert['info']['subject']) ?>)<?php endif; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted">Select a server certificate for SSL termination. Upload certificates in the Certificates section.</small>
            </div>

            <div class="form-group-inline">
                <label>
                    <input type="checkbox" name="forward_headers" value="1" <?= $forwardHeaders ? 'checked' : '' ?>>
                    Add X-Forwarded-Proto header
                </label>
            </div>

            <div class="form-group">
                <label for="maxconn">Max Connections (optional)</label>
                <input type="number" id="maxconn" name="maxconn" min="0"
                       placeholder="Leave empty for unlimited" value="<?= htmlspecialchars((string)$maxconn) ?>">
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?= $isNew ? 'Create Frontend' : 'Save Frontend' ?></button>
                <a href="/loadbalancer" class="btn">Cancel</a>
            </div>
        </form>
    </div>

    <?php if (!$isNew): ?>
    <div class="card full-width">
        <h3>Delete Frontend</h3>
        <form method="post" action="/loadbalancer/frontend/<?= urlencode($name) ?>/delete">
            <p class="text-muted">Permanently remove this frontend configuration.</p>
            <button type="submit" class="btn btn-danger"
                    data-confirm="Delete frontend '<?= htmlspecialchars($name) ?>'? This cannot be undone.">
                Delete Frontend
            </button>
        </form>
    </div>
    <?php endif; ?>
</div>
