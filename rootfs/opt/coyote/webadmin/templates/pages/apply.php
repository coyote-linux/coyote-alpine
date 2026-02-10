<?php $pageTitle = 'Apply Configuration'; $page = 'apply'; ?>

<?php
$status = $status ?? ['pending' => false, 'remaining' => 0, 'hasChanges' => false];
$hasChanges = $hasChanges ?? false;
?>

<?php if ($status['pending']): ?>
<div class="apply-countdown-overlay" id="countdown-overlay">
    <div class="apply-countdown-modal">
        <h3>Configuration Applied</h3>
        <p>Please verify your settings are working correctly.</p>
        <p class="countdown-timer">
            Time remaining: <span id="countdown"><?= $status['remaining'] ?></span> seconds
        </p>
        <p>If you don't confirm, the previous configuration will be restored automatically.</p>
        <div class="countdown-actions">
            <form method="post" action="/apply/confirm" style="display: inline;">
                <button type="submit" class="btn btn-primary btn-large">Confirm &amp; Save</button>
            </form>
            <form method="post" action="/apply/cancel" style="display: inline;">
                <button type="submit" class="btn btn-danger">Cancel &amp; Rollback</button>
            </form>
        </div>
    </div>
</div>

<script>
(function() {
    var remaining = <?= $status['remaining'] ?>;
    var countdownEl = document.getElementById('countdown');

    var timer = setInterval(function() {
        remaining--;
        if (countdownEl) {
            countdownEl.textContent = remaining;
        }
        if (remaining <= 0) {
            clearInterval(timer);
            window.location.reload();
        }
    }, 1000);
})();
</script>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h2>Configuration Status</h2>
    </div>
    <div class="card-body">
        <?php if (!$hasChanges && !$status['pending']): ?>
            <div class="alert alert-info">
                <strong>No configuration changes pending.</strong>
                <p>All changes have been applied and saved.</p>
            </div>
        <?php elseif ($hasChanges && !$status['pending']): ?>
            <div class="alert alert-warning">
                <strong>Configuration changes pending.</strong>
                <p>You have uncommitted changes in your working configuration. Apply them to activate the new settings.</p>
            </div>

            <div class="form-actions">
                <form method="post" action="/apply" style="display: inline;">
                    <button type="submit" class="btn btn-primary btn-large">Apply Configuration</button>
                </form>
                <form method="post" action="/apply/discard" style="display: inline;" onsubmit="return confirm('Are you sure you want to discard all uncommitted changes?');">
                    <button type="submit" class="btn btn-secondary">Discard Changes</button>
                </form>
            </div>
        <?php elseif ($status['pending']): ?>
            <div class="alert alert-success">
                <strong>Configuration applied successfully.</strong>
                <p>Please confirm the changes are working correctly, or cancel to rollback.</p>
            </div>
        <?php endif; ?>
    </div>
</div>
