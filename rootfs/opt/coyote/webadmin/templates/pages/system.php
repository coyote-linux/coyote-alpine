<?php $pageTitle = 'System'; $page = 'system'; ?>

<div class="dashboard-grid">
    <div class="card">
        <h3>Basic Settings</h3>
        <form method="post" action="/system">
            <div class="form-group">
                <label for="hostname">Hostname</label>
                <input type="text" id="hostname" name="hostname" value="<?= htmlspecialchars($hostname ?? 'coyote') ?>">
            </div>
            <div class="form-group">
                <label for="domain">Domain</label>
                <input type="text" id="domain" name="domain" value="<?= htmlspecialchars($domain ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="timezone">Timezone</label>
                <input type="text" id="timezone" name="timezone" value="<?= htmlspecialchars($timezone ?? 'UTC') ?>">
            </div>
            <button type="submit" class="btn btn-primary">Save Changes</button>
        </form>
    </div>

    <div class="card">
        <h3>Configuration Management</h3>
        <p>
            <button class="btn btn-primary" onclick="alert('Apply not yet implemented')">Apply Configuration</button>
        </p>
        <p>
            <button class="btn btn-primary" onclick="alert('Backup not yet implemented')">Create Backup</button>
        </p>
        <p>
            <button class="btn btn-primary" onclick="alert('Restore not yet implemented')">Restore Backup</button>
        </p>
    </div>

    <div class="card">
        <h3>System Actions</h3>
        <p>
            <button class="btn btn-danger" onclick="if(confirm('Reboot the system?')) alert('Reboot not yet implemented')">Reboot System</button>
        </p>
        <p>
            <button class="btn btn-danger" onclick="if(confirm('Shutdown the system?')) alert('Shutdown not yet implemented')">Shutdown System</button>
        </p>
    </div>
</div>
