<?php $pageTitle = 'Services'; $page = 'services'; ?>

<div class="card">
    <h3>System Services</h3>
    <table>
        <thead>
            <tr>
                <th>Service</th>
                <th>Description</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($services ?? [] as $name => $svc): ?>
            <tr>
                <td><?= htmlspecialchars($name) ?></td>
                <td><?= htmlspecialchars($svc['description'] ?? '') ?></td>
                <td>
                    <span class="status-badge status-<?= ($svc['running'] ?? false) ? 'up' : 'down' ?>">
                        <?= ($svc['running'] ?? false) ? 'Running' : 'Stopped' ?>
                    </span>
                </td>
                <td>
                    <?php if ($svc['running'] ?? false): ?>
                        <button class="btn btn-small" onclick="alert('Stop not yet implemented')">Stop</button>
                        <button class="btn btn-small" onclick="alert('Restart not yet implemented')">Restart</button>
                    <?php else: ?>
                        <button class="btn btn-small btn-success" onclick="alert('Start not yet implemented')">Start</button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
