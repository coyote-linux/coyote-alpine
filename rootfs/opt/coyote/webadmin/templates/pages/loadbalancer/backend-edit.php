<?php
$isNew = $isNew ?? false;
$backend = $backend ?? null;
$name = $backend['name'] ?? '';
$mode = $backend['mode'] ?? 'http';
$balance = $backend['balance'] ?? 'roundrobin';
$healthCheck = $backend['health_check'] ?? true;
$healthCheckPath = $backend['health_check_path'] ?? 'GET /';
$cookie = $backend['cookie'] ?? '';
$servers = $backend['servers'] ?? [];
$pageTitle = $isNew ? 'New Backend' : 'Edit Backend: ' . $name;
$page = 'loadbalancer';

$balanceAlgorithms = [
    'roundrobin' => 'Round Robin',
    'leastconn' => 'Least Connections',
    'source' => 'Source IP Hash',
    'first' => 'First Available',
    'uri' => 'URI Hash',
    'random' => 'Random',
];
?>

<div class="page-header">
    <a href="/loadbalancer" class="btn btn-small">&larr; Back to Load Balancer</a>
</div>

<div class="dashboard-grid">
    <div class="card full-width">
        <h3><?= $isNew ? 'Create New Backend' : 'Backend: ' . htmlspecialchars($name) ?></h3>
        <form method="post" action="<?= $isNew ? '/loadbalancer/backend/new' : '/loadbalancer/backend/' . urlencode($name) ?>">
            <input type="hidden" name="is_new" value="<?= $isNew ? '1' : '0' ?>">

            <?php if ($isNew): ?>
            <div class="form-group">
                <label for="name">Backend Name</label>
                <input type="text" id="name" name="name" required
                       pattern="[a-zA-Z][a-zA-Z0-9_-]*" maxlength="32"
                       placeholder="web-servers" value="<?= htmlspecialchars($name) ?>">
                <small class="text-muted">Letters, numbers, underscores, hyphens. Must start with a letter.</small>
            </div>
            <?php else: ?>
            <input type="hidden" name="name" value="<?= htmlspecialchars($name) ?>">
            <?php endif; ?>

            <div class="form-group">
                <label for="mode">Mode</label>
                <select id="mode" name="mode">
                    <option value="http" <?= $mode === 'http' ? 'selected' : '' ?>>HTTP</option>
                    <option value="tcp" <?= $mode === 'tcp' ? 'selected' : '' ?>>TCP</option>
                </select>
            </div>

            <div class="form-group">
                <label for="balance">Balance Algorithm</label>
                <select id="balance" name="balance">
                    <?php foreach ($balanceAlgorithms as $value => $label): ?>
                    <option value="<?= $value ?>" <?= $balance === $value ? 'selected' : '' ?>>
                        <?= htmlspecialchars($label) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group-inline">
                <label>
                    <input type="checkbox" name="health_check" value="1" <?= $healthCheck ? 'checked' : '' ?>>
                    Enable health checks
                </label>
            </div>

            <div class="form-group">
                <label for="health_check_path">Health Check</label>
                <input type="text" id="health_check_path" name="health_check_path"
                       placeholder="GET /" value="<?= htmlspecialchars($healthCheckPath) ?>">
                <small class="text-muted">HTTP method and path for health checking (e.g. GET /health)</small>
            </div>

            <div class="form-group">
                <label for="cookie">Session Persistence Cookie (optional)</label>
                <input type="text" id="cookie" name="cookie"
                       placeholder="SERVERID" value="<?= htmlspecialchars($cookie) ?>">
                <small class="text-muted">Cookie name for sticky sessions. Leave empty to disable.</small>
            </div>

            <h3>Servers</h3>
            <table class="data-table" id="servers-table">
                <thead>
                    <tr>
                        <th>Name (optional)</th>
                        <th>Address</th>
                        <th>Port</th>
                        <th>Weight</th>
                        <th>Backup</th>
                        <th width="60"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($servers)): ?>
                    <tr class="server-row">
                        <td><input type="text" name="server_name[]" placeholder="srv1"></td>
                        <td><input type="text" name="server_address[]" placeholder="192.168.1.10" required></td>
                        <td><input type="number" name="server_port[]" value="80" min="1" max="65535"></td>
                        <td><input type="number" name="server_weight[]" value="1" min="0" max="256"></td>
                        <td><input type="checkbox" name="server_backup[]" value="1"></td>
                        <td><button type="button" class="btn btn-mini btn-danger" onclick="removeServerRow(this)">&times;</button></td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($servers as $i => $srv): ?>
                    <tr class="server-row">
                        <td><input type="text" name="server_name[]" value="<?= htmlspecialchars($srv['name'] ?? '') ?>" placeholder="srv<?= $i + 1 ?>"></td>
                        <td><input type="text" name="server_address[]" value="<?= htmlspecialchars($srv['address'] ?? '') ?>" placeholder="192.168.1.10" required></td>
                        <td><input type="number" name="server_port[]" value="<?= (int)($srv['port'] ?? 80) ?>" min="1" max="65535"></td>
                        <td><input type="number" name="server_weight[]" value="<?= (int)($srv['weight'] ?? 1) ?>" min="0" max="256"></td>
                        <td><input type="checkbox" name="server_backup[]" value="1" <?= !empty($srv['backup']) ? 'checked' : '' ?>></td>
                        <td><button type="button" class="btn btn-mini btn-danger" onclick="removeServerRow(this)">&times;</button></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <button type="button" class="btn btn-small" onclick="addServerRow()" style="margin-top: 0.5rem;">+ Add Server</button>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?= $isNew ? 'Create Backend' : 'Save Backend' ?></button>
                <a href="/loadbalancer" class="btn">Cancel</a>
            </div>
        </form>
    </div>

    <?php if (!$isNew): ?>
    <div class="card full-width">
        <h3>Delete Backend</h3>
        <form method="post" action="/loadbalancer/backend/<?= urlencode($name) ?>/delete">
            <p class="text-muted">Permanently remove this backend and all its servers.</p>
            <button type="submit" class="btn btn-danger"
                    data-confirm="Delete backend '<?= htmlspecialchars($name) ?>'? This cannot be undone.">
                Delete Backend
            </button>
        </form>
    </div>
    <?php endif; ?>
</div>

<script>
function addServerRow() {
    var tbody = document.querySelector('#servers-table tbody');
    var idx = tbody.querySelectorAll('.server-row').length + 1;
    var row = document.createElement('tr');
    row.className = 'server-row';
    row.innerHTML =
        '<td><input type="text" name="server_name[]" placeholder="srv' + idx + '"></td>' +
        '<td><input type="text" name="server_address[]" placeholder="192.168.1.10" required></td>' +
        '<td><input type="number" name="server_port[]" value="80" min="1" max="65535"></td>' +
        '<td><input type="number" name="server_weight[]" value="1" min="0" max="256"></td>' +
        '<td><input type="checkbox" name="server_backup[]" value="1"></td>' +
        '<td><button type="button" class="btn btn-mini btn-danger" onclick="removeServerRow(this)">&times;</button></td>';
    tbody.appendChild(row);
}

function removeServerRow(btn) {
    var tbody = document.querySelector('#servers-table tbody');
    if (tbody.querySelectorAll('.server-row').length > 1) {
        btn.closest('tr').remove();
    }
}
</script>
