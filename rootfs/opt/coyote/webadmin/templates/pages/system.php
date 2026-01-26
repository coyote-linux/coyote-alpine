<?php $pageTitle = 'System'; $page = 'system'; ?>

<div class="dashboard-grid">
    <div class="card">
        <h3>Basic Settings</h3>
        <form method="post" action="/system">
            <div class="form-group">
                <label for="hostname">Hostname</label>
                <input type="text" id="hostname" name="hostname" value="<?= htmlspecialchars($hostname ?? 'coyote') ?>" required pattern="[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?" title="Valid hostname (letters, numbers, hyphens)">
            </div>
            <div class="form-group">
                <label for="domain">Domain</label>
                <input type="text" id="domain" name="domain" value="<?= htmlspecialchars($domain ?? '') ?>" placeholder="local.lan">
            </div>
            <div class="form-group">
                <label for="timezone">Timezone</label>
                <select id="timezone" name="timezone">
                    <?php foreach ($timezones ?? [] as $tz): ?>
                    <option value="<?= htmlspecialchars($tz) ?>" <?= ($tz === ($timezone ?? 'UTC')) ? 'selected' : '' ?>><?= htmlspecialchars($tz) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="nameservers">DNS Servers</label>
                <input type="text" id="nameservers" name="nameservers" value="<?= htmlspecialchars(implode(', ', $nameservers ?? ['1.1.1.1'])) ?>" placeholder="1.1.1.1, 8.8.8.8">
                <small>Comma-separated list of DNS server IP addresses</small>
            </div>
            <button type="submit" class="btn btn-primary">Save Changes</button>
        </form>
    </div>

    <div class="card">
        <h3>Backup Configuration</h3>
        <p>Create a backup of the current configuration or download it as a file.</p>
        <form method="post" action="/system/backup" style="display: inline;">
            <button type="submit" class="btn btn-primary">Create Backup</button>
        </form>
        <a href="/system/backup/download" class="btn btn-primary">Download Config</a>

        <h4 style="margin-top: 1.5rem;">Restore Configuration</h4>
        <form method="post" action="/system/restore/upload" enctype="multipart/form-data">
            <div class="form-group">
                <label for="config_file">Upload Configuration File</label>
                <input type="file" id="config_file" name="config_file" accept=".json">
            </div>
            <button type="submit" class="btn btn-primary" data-confirm="This will replace your current configuration. Continue?">Upload &amp; Restore</button>
        </form>

        <?php if (!empty($backups)): ?>
        <h4 style="margin-top: 1.5rem;">Available Backups</h4>
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Date</th>
                    <th>Size</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($backups as $backup): ?>
                <tr>
                    <td><?= htmlspecialchars($backup['name']) ?></td>
                    <td><?= htmlspecialchars($backup['date']) ?></td>
                    <td><?= number_format($backup['size']) ?> bytes</td>
                    <td>
                        <form method="post" action="/system/restore" style="display: inline;">
                            <input type="hidden" name="backup_name" value="<?= htmlspecialchars($backup['name']) ?>">
                            <button type="submit" class="btn btn-small" data-confirm="Restore configuration from <?= htmlspecialchars($backup['name']) ?>?">Restore</button>
                        </form>
                        <form method="post" action="/system/backup/delete" style="display: inline;">
                            <input type="hidden" name="backup_name" value="<?= htmlspecialchars($backup['name']) ?>">
                            <button type="submit" class="btn btn-small btn-danger" data-confirm="Delete backup <?= htmlspecialchars($backup['name']) ?>?">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p><em>No backups available.</em></p>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>System Actions</h3>
        <p>These actions affect the running system.</p>
        <form method="post" action="/system/reboot" style="display: inline;">
            <button type="submit" class="btn btn-danger" data-confirm="Are you sure you want to reboot the system?">Reboot System</button>
        </form>
        <form method="post" action="/system/shutdown" style="display: inline;">
            <button type="submit" class="btn btn-danger" data-confirm="Are you sure you want to shutdown the system? You will need physical access to turn it back on.">Shutdown System</button>
        </form>
    </div>
</div>

<style>
.form-group small {
    display: block;
    color: #888;
    font-size: 0.85em;
    margin-top: 0.25rem;
}
</style>
