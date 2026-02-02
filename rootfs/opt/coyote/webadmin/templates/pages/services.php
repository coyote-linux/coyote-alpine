<?php $pageTitle = 'Services'; $page = 'services'; ?>

<div class="card">
    <h3>System Services</h3>
    <p>Manage system services. Services marked as "Enabled" will start automatically at boot.</p>
    <p class="text-muted"><em>Core services (Web Server, SSH) are always running. Use <a href="/firewall/access">Firewall Access Controls</a> to manage access.</em></p>
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
            <?php $isCore = $svc['core'] ?? false; ?>
            <tr>
                <td><strong><?= htmlspecialchars($name) ?></strong></td>
                <td><?= htmlspecialchars($svc['description'] ?? '') ?><?php if ($isCore): ?> <span class="core-badge">Core</span><?php endif; ?></td>
                <td>
                    <span class="status-badge status-<?= ($svc['running'] ?? false) ? 'up' : 'down' ?>">
                        <?= ($svc['running'] ?? false) ? 'Running' : 'Stopped' ?>
                    </span>
                </td>
                <td>
                    <?php if ($isCore): ?>
                    <span class="status-badge status-up">Always</span>
                    <?php else: ?>
                    <span class="status-badge status-<?= ($svc['enabled'] ?? false) ? 'up' : 'down' ?>">
                        <?= ($svc['enabled'] ?? false) ? 'Enabled' : 'Disabled' ?>
                    </span>
                    <?php endif; ?>
                </td>
                <td class="service-actions">
                    <?php if ($isCore): ?>
                        <span class="text-muted">ACL controlled</span>
                    <?php elseif ($svc['running'] ?? false): ?>
                        <form method="post" action="/services/<?= htmlspecialchars($name) ?>/stop" style="display: inline;">
                            <button type="submit" class="btn btn-small" data-confirm="Stop <?= htmlspecialchars($name) ?>?">Stop</button>
                        </form>
                        <form method="post" action="/services/<?= htmlspecialchars($name) ?>/restart" style="display: inline;">
                            <button type="submit" class="btn btn-small" data-confirm="Restart <?= htmlspecialchars($name) ?>?">Restart</button>
                        </form>
                    <?php else: ?>
                        <form method="post" action="/services/<?= htmlspecialchars($name) ?>/start" style="display: inline;">
                            <button type="submit" class="btn btn-small btn-success">Start</button>
                        </form>
                    <?php endif; ?>

                    <?php if (!$isCore): ?>
                        <?php if ($svc['enabled'] ?? false): ?>
                            <?php if ($name !== 'syslogd'): ?>
                            <form method="post" action="/services/<?= htmlspecialchars($name) ?>/disable" style="display: inline;">
                                <button type="submit" class="btn btn-small" data-confirm="Disable <?= htmlspecialchars($name) ?> at boot?">Disable</button>
                            </form>
                            <?php endif; ?>
                        <?php else: ?>
                            <form method="post" action="/services/<?= htmlspecialchars($name) ?>/enable" style="display: inline;">
                                <button type="submit" class="btn btn-small">Enable</button>
                            </form>
                        <?php endif; ?>
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
.core-badge {
    display: inline-block;
    padding: 0.1rem 0.4rem;
    font-size: 0.7rem;
    font-weight: 600;
    color: var(--color-primary);
    background: rgba(59, 130, 246, 0.15);
    border-radius: 3px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    vertical-align: middle;
    margin-left: 0.5rem;
}
</style>
