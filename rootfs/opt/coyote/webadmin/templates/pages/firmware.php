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
            <form method="post" action="/firmware/download" id="firmware-download-form" style="margin-top: 0.5rem;">
                <button type="submit" class="btn btn-primary" id="firmware-download-button">Download Update</button>
            </form>
            <div id="firmware-download-progress" style="display:none; margin-top: 0.75rem;">
                <div class="progress-bar">
                    <div class="progress-fill" id="firmware-download-progress-fill" style="width: 0%;"></div>
                </div>
                <p id="firmware-download-progress-text">Preparing firmware download...</p>
            </div>
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

<script>
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('firmware-download-form');
    if (!form) {
        return;
    }

    var button = document.getElementById('firmware-download-button');
    var progressContainer = document.getElementById('firmware-download-progress');
    var progressFill = document.getElementById('firmware-download-progress-fill');
    var progressText = document.getElementById('firmware-download-progress-text');
    var pollTimer = null;

    function formatBytes(bytes) {
        if (!bytes || bytes <= 0) {
            return '0 B';
        }

        if (bytes >= 1073741824) {
            return (bytes / 1073741824).toFixed(2) + ' GB';
        }
        if (bytes >= 1048576) {
            return (bytes / 1048576).toFixed(2) + ' MB';
        }
        if (bytes >= 1024) {
            return (bytes / 1024).toFixed(2) + ' KB';
        }

        return bytes + ' B';
    }

    function updateProgressUi(payload) {
        if (!payload || !progressFill || !progressText || !progressContainer) {
            return;
        }

        progressContainer.style.display = 'block';

        var percent = typeof payload.percent === 'number' ? payload.percent : 0;
        if (percent < 0) {
            percent = 0;
        }
        if (percent > 100) {
            percent = 100;
        }

        progressFill.style.width = percent.toFixed(1) + '%';

        var message = payload.message || 'Downloading firmware...';
        if (payload.phase === 'downloading') {
            var downloaded = Number(payload.downloaded_bytes || 0);
            var total = Number(payload.total_bytes || 0);
            if (total > 0) {
                message += ' (' + formatBytes(downloaded) + ' / ' + formatBytes(total) + ')';
            } else if (downloaded > 0) {
                message += ' (' + formatBytes(downloaded) + ')';
            }
        }

        progressText.textContent = message;
    }

    function pollProgress() {
        fetch('/firmware/download/progress', {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Failed to load progress');
                }

                return response.json();
            })
            .then(function (payload) {
                updateProgressUi(payload);
            })
            .catch(function () {
                // Ignore transient polling failures; final result comes from download request.
            });
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();

        if (button) {
            button.disabled = true;
        }

        if (progressContainer) {
            progressContainer.style.display = 'block';
        }
        if (progressFill) {
            progressFill.style.width = '0%';
        }
        if (progressText) {
            progressText.textContent = 'Preparing firmware download...';
        }

        pollProgress();
        pollTimer = window.setInterval(pollProgress, 1000);

        var formData = new FormData(form);

        fetch(form.action, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': String(formData.get('_csrf_token') || '')
            },
            body: formData
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (payload) {
                if (pollTimer !== null) {
                    window.clearInterval(pollTimer);
                    pollTimer = null;
                }

                pollProgress();

                if (payload && payload.success) {
                    if (progressFill) {
                        progressFill.style.width = '100%';
                    }
                    if (progressText) {
                        progressText.textContent = payload.message || 'Firmware downloaded and staged successfully.';
                    }

                    window.setTimeout(function () {
                        window.location.reload();
                    }, 800);
                    return;
                }

                if (button) {
                    button.disabled = false;
                }

                if (progressText) {
                    progressText.textContent = (payload && payload.error) ? payload.error : 'Firmware download failed.';
                }
            })
            .catch(function () {
                if (pollTimer !== null) {
                    window.clearInterval(pollTimer);
                    pollTimer = null;
                }

                if (button) {
                    button.disabled = false;
                }

                if (progressText) {
                    progressText.textContent = 'Firmware download failed.';
                }
            });
    });
});
</script>
