<?php $pageTitle = 'Firmware'; $page = 'firmware'; ?>

<div class="dashboard-grid">
    <div class="card">
        <h3>Current Firmware</h3>
        <dl>
            <dt>Version</dt>
            <dd>4.0.0</dd>
            <dt>Build Date</dt>
            <dd><?= htmlspecialchars($firmware_date ?? 'Unknown') ?></dd>
            <dt>Size</dt>
            <dd><?= htmlspecialchars($firmware_size ?? 'Unknown') ?></dd>
        </dl>
    </div>

    <div class="card">
        <h3>Upload New Firmware</h3>
        <form method="post" action="/firmware/upload" enctype="multipart/form-data">
            <div class="form-group">
                <label for="firmware">Firmware File (.squashfs)</label>
                <input type="file" id="firmware" name="firmware" accept=".squashfs">
            </div>
            <button type="submit" class="btn btn-primary">Upload Firmware</button>
        </form>
    </div>

    <div class="card full-width">
        <h3>Firmware Notes</h3>
        <div class="alert alert-warning">
            <ul>
                <li>Firmware updates require a reboot to take effect</li>
                <li>The previous firmware is kept for rollback</li>
                <li>Ensure you have a backup of your configuration before updating</li>
            </ul>
        </div>
    </div>
</div>
