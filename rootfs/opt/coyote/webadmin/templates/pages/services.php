<?php $pageTitle = 'Services'; $page = 'services'; ?>

<div class="card">
    <h3>System Services</h3>
    <p>Manage system services. Services marked as "Enabled" will start automatically at boot.</p>
    <table>
        <thead>
            <tr>
                <th>Service</th>
                <th>Description</th>
                <th>Status</th>
                <th>Boot</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($services ?? [] as $name => $svc): ?>
            <tr>
                <td><strong><?= htmlspecialchars($name) ?></strong></td>
                <td><?= htmlspecialchars($svc['description'] ?? '') ?></td>
                <td>
                    <span class="status-badge status-<?= ($svc['running'] ?? false) ? 'up' : 'down' ?>">
                        <?= ($svc['running'] ?? false) ? 'Running' : 'Stopped' ?>
                    </span>
                </td>
                <td>
                    <span class="status-badge status-<?= ($svc['enabled'] ?? false) ? 'up' : 'down' ?>">
                        <?= ($svc['enabled'] ?? false) ? 'Enabled' : 'Disabled' ?>
                    </span>
                </td>
                <td class="service-actions">
                    <?php if ($svc['running'] ?? false): ?>
                        <form method="post" action="/services/<?= htmlspecialchars($name) ?>/stop" style="display: inline;">
                            <button type="submit" class="btn btn-small" <?= $name === 'lighttpd' ? 'disabled title="Cannot stop web server"' : 'data-confirm="Stop ' . htmlspecialchars($name) . '?"' ?>>Stop</button>
                        </form>
                        <form method="post" action="/services/<?= htmlspecialchars($name) ?>/restart" style="display: inline;">
                            <button type="submit" class="btn btn-small" data-confirm="Restart <?= htmlspecialchars($name) ?>?">Restart</button>
                        </form>
                    <?php else: ?>
                        <form method="post" action="/services/<?= htmlspecialchars($name) ?>/start" style="display: inline;">
                            <button type="submit" class="btn btn-small btn-success">Start</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($svc['enabled'] ?? false): ?>
                        <?php if (!in_array($name, ['lighttpd', 'dropbear', 'syslogd'])): ?>
                        <form method="post" action="/services/<?= htmlspecialchars($name) ?>/disable" style="display: inline;">
                            <button type="submit" class="btn btn-small" data-confirm="Disable <?= htmlspecialchars($name) ?> at boot?">Disable</button>
                        </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <form method="post" action="/services/<?= htmlspecialchars($name) ?>/enable" style="display: inline;">
                            <button type="submit" class="btn btn-small">Enable</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<style>
.service-actions form {
    margin-right: 0.25rem;
}
.service-actions .btn-small {
    margin-bottom: 0.25rem;
}
</style>
