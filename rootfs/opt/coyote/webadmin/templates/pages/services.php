<?php $pageTitle = 'Services'; $page = 'services'; ?>

<div class="card">
    <h3>System Services</h3>
    <p>Manage runtime service state from this page. Startup persistence is controlled by the Coyote configuration system.</p>
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

                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

