<?php $pageTitle = 'System'; $page = 'system'; ?>

<?php
// Get apply status from controller
$applyStatus = $applyStatus ?? ['pending' => false, 'remaining' => 0, 'hasChanges' => false];
?>

<?php if ($applyStatus['pending']): ?>
<!-- Confirmation countdown modal -->
<div class="apply-countdown-overlay" id="countdown-overlay">
    <div class="apply-countdown-modal">
        <h3>Configuration Applied</h3>
        <p>Please verify your settings are working correctly.</p>
        <p class="countdown-timer">
            Time remaining: <span id="countdown"><?= $applyStatus['remaining'] ?></span> seconds
        </p>
        <p>If you don't confirm, the previous configuration will be restored automatically.</p>
        <div class="countdown-actions">
            <form method="post" action="/system/config/confirm" style="display: inline;">
                <button type="submit" class="btn btn-primary btn-large">Confirm &amp; Save</button>
            </form>
            <form method="post" action="/system/config/cancel" style="display: inline;">
                <button type="submit" class="btn btn-danger">Cancel &amp; Rollback</button>
            </form>
        </div>
    </div>
</div>

<script>
(function() {
    var remaining = <?= $applyStatus['remaining'] ?>;
    var countdownEl = document.getElementById('countdown');

    var timer = setInterval(function() {
        remaining--;
        if (countdownEl) {
            countdownEl.textContent = remaining;
        }
        if (remaining <= 0) {
            clearInterval(timer);
            // Auto-refresh to show rollback message
            window.location.reload();
        }
    }, 1000);
})();
</script>
<?php endif; ?>

<div class="dashboard-grid">
    <?php if ($applyStatus['hasChanges'] && !$applyStatus['pending']): ?>
    <div class="card apply-config-card">
        <h3>Pending Configuration Changes</h3>
        <p>You have uncommitted changes that have not been applied to the system.</p>
        <div class="apply-actions">
            <form method="post" action="/system/config/apply" style="display: inline;">
                <button type="submit" class="btn btn-primary btn-large" data-confirm="Apply configuration changes? You will have 60 seconds to confirm before automatic rollback.">Apply Configuration</button>
            </form>
            <form method="post" action="/system/config/discard" style="display: inline;">
                <button type="submit" class="btn btn-danger" data-confirm="Discard all uncommitted changes?">Discard Changes</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

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
            <small class="form-note">Changes are saved but not applied until you click "Apply Configuration"</small>
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

.form-note {
    display: block;
    color: #888;
    font-size: 0.85em;
    margin-top: 0.75rem;
}

/* Apply Configuration Card */
.apply-config-card {
    background: #2a3a4a;
    border: 2px solid #f0ad4e;
}

.apply-config-card h3 {
    color: #f0ad4e;
}

.apply-actions {
    margin-top: 1rem;
}

/* Countdown Overlay */
.apply-countdown-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.8);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000;
}

.apply-countdown-modal {
    background: #1e2a3a;
    padding: 2rem;
    border-radius: 8px;
    border: 2px solid #f0ad4e;
    text-align: center;
    max-width: 500px;
}

.apply-countdown-modal h3 {
    color: #f0ad4e;
    margin-bottom: 1rem;
}

.countdown-timer {
    font-size: 1.5rem;
    font-weight: bold;
    color: #f0ad4e;
    margin: 1.5rem 0;
}

.countdown-timer span {
    font-size: 2rem;
    color: #ff6b6b;
}

.countdown-actions {
    margin-top: 1.5rem;
}

.countdown-actions .btn {
    margin: 0 0.5rem;
}

.btn-large {
    padding: 0.75rem 1.5rem;
    font-size: 1.1rem;
}
</style>
