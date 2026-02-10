<?php
$pageTitle = 'Firmware';
$page = 'firmware';
?>

<div class="dashboard-grid">
    <div class="card">
        <h3>Current Firmware</h3>
        <dl>
            <dt>Version</dt>
            <dd><?= htmlspecialchars($current_version ?? '4.0.0') ?></dd>
            <dt>Build Date</dt>
            <dd><?= htmlspecialchars($firmware_date ?? 'Unknown') ?></dd>
            <dt>Size</dt>
            <dd><?= htmlspecialchars($firmware_size ?? 'Unknown') ?></dd>
        </dl>
    </div>

    <div class="card">
        <h3>Check for Updates</h3>
        <p>Check the official Coyote Linux update server for available firmware updates.</p>
        <form method="post" action="/firmware/check">
            <button type="submit" class="btn btn-primary">Check for Updates</button>
        </form>
        <?php if (isset($_SESSION['firmware_update_info']) && $_SESSION['firmware_update_info']['available']): ?>
        <?php $updateInfo = $_SESSION['firmware_update_info']; ?>
        <div class="alert alert-info" style="margin-top: 1rem;">
            <p><strong>Update Available:</strong> Version <?= htmlspecialchars($updateInfo['latest_version']) ?></p>
            <?php if (!empty($updateInfo['size'])): ?>
            <p><strong>Size:</strong> <?= number_format($updateInfo['size'] / 1048576, 2) ?> MB</p>
            <?php endif; ?>
            <form method="post" action="/firmware/download" style="margin-top: 0.5rem;">
                <button type="submit" class="btn btn-primary">Download Update</button>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>Upload Firmware</h3>
        <p>Upload a firmware update archive (.tar.gz) manually.</p>
        <form method="post" action="/firmware/upload" enctype="multipart/form-data">
            <div class="form-group">
                <label for="firmware_file">Firmware Archive (.tar.gz)</label>
                <input type="file" id="firmware_file" name="firmware_file" accept=".tar.gz" required>
            </div>
            <button type="submit" class="btn btn-primary">Upload Firmware</button>
        </form>
    </div>

    <?php if ($staged_update ?? null): ?>
    <div class="card full-width">
        <h3>Staged Firmware Update</h3>
        <div class="alert alert-warning">
            <p><strong>A firmware update is ready to be applied.</strong></p>
            <dl>
                <dt>Filename</dt>
                <dd><?= htmlspecialchars($staged_update['filename']) ?></dd>
                <dt>Size</dt>
                <dd><?= number_format($staged_update['size'] / 1048576, 2) ?> MB</dd>
                <dt>Staged Date</dt>
                <dd><?= htmlspecialchars($staged_update['date']) ?></dd>
            </dl>
        </div>
        <div style="margin-top: 1rem;">
            <form method="post" action="/firmware/apply" style="display: inline;">
                <button type="submit" class="btn btn-danger btn-large" data-confirm="Apply firmware update and reboot? This will restart the system immediately.">Apply Update &amp; Reboot</button>
            </form>
            <form method="post" action="/firmware/clear" style="display: inline; margin-left: 0.5rem;">
                <button type="submit" class="btn btn-danger" data-confirm="Discard staged firmware update?">Discard Staged Update</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="card full-width">
        <h3>Firmware Update Notes</h3>
        <div class="alert alert-info">
            <ul>
                <li>Firmware updates require a system reboot to take effect</li>
                <li>The update process is automatic during reboot</li>
                <li>Previous firmware is kept for rollback in case of failure</li>
                <li>Ensure you have a backup of your configuration before updating</li>
                <li>Do not power off the system during the update process</li>
                <li>Firmware archives must contain: vmlinuz, initramfs.img, and firmware.squashfs</li>
            </ul>
        </div>
    </div>
</div>
