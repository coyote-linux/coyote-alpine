<?php $pageTitle = 'Network'; $page = 'network'; ?>

<?php
// Get apply status from controller
$applyStatus = $applyStatus ?? ['pending' => false, 'remaining' => 0, 'hasChanges' => false, 'requiresCountdown' => false];
?>

<?php if ($applyStatus['pending']): ?>
<!-- Confirmation countdown modal -->
<div class="apply-countdown-overlay" id="countdown-overlay">
    <div class="apply-countdown-modal">
        <h3>Configuration Applied</h3>
        <p>Please verify your network settings are working correctly.</p>
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
            window.location.reload();
        }
    }, 1000);
})();
</script>
<?php endif; ?>

<div class="dashboard-grid">
    <?php if ($applyStatus['hasChanges'] && !$applyStatus['pending']): ?>
    <div class="card full-width apply-config-card <?= $applyStatus['requiresCountdown'] ? 'countdown-required' : 'no-countdown' ?>">
        <h3>Pending Configuration Changes</h3>
        <?php if ($applyStatus['requiresCountdown']): ?>
        <p>You have uncommitted changes that include <strong>network settings</strong>.</p>
        <p class="countdown-warning">These changes could affect remote access. After applying, you will have <strong>60 seconds</strong> to confirm before automatic rollback.</p>
        <?php else: ?>
        <p>You have uncommitted changes that have not been applied to the system.</p>
        <p class="safe-apply-note">These changes are safe and will be applied immediately.</p>
        <?php endif; ?>
        <div class="apply-actions">
            <form method="post" action="/system/config/apply" style="display: inline;">
                <?php if ($applyStatus['requiresCountdown']): ?>
                <button type="submit" class="btn btn-primary btn-large" data-confirm="Apply configuration changes? You will have 60 seconds to confirm before automatic rollback.">Apply Configuration</button>
                <?php else: ?>
                <button type="submit" class="btn btn-primary btn-large" data-confirm="Apply configuration changes?">Apply Configuration</button>
                <?php endif; ?>
            </form>
            <form method="post" action="/system/config/discard" style="display: inline;">
                <button type="submit" class="btn btn-danger" data-confirm="Discard all uncommitted changes?">Discard Changes</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="card full-width">
        <h3>Network Interfaces</h3>
        <table>
            <thead>
                <tr>
                    <th>Interface</th>
                    <th>MAC Address</th>
                    <th>Configuration</th>
                    <th>State</th>
                    <th>IPv4 Address</th>
                    <th>RX / TX</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($interfaces ?? [] as $name => $iface): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($name) ?></strong></td>
                    <td><code><?= htmlspecialchars($iface['mac'] ?? '-') ?></code></td>
                    <td>
                        <?php if ($iface['configured'] && isset($iface['config']['type'])): ?>
                            <?php $type = $iface['config']['type']; ?>
                            <?php if ($type === 'static'): ?>
                                <span class="config-badge config-static">Static</span>
                            <?php elseif ($type === 'dhcp'): ?>
                                <span class="config-badge config-dhcp">DHCP</span>
                            <?php else: ?>
                                <span class="config-badge config-disabled">Disabled</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="config-badge config-unconfigured">Unconfigured</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="status-badge status-<?= ($iface['state'] ?? 'down') === 'up' ? 'up' : 'down' ?>">
                            <?= htmlspecialchars($iface['state'] ?? 'unknown') ?>
                        </span>
                    </td>
                    <td>
                        <?php $addresses = $iface['ipv4'] ?? []; ?>
                        <?php if (!empty($addresses)): ?>
                            <?= htmlspecialchars(implode(', ', $addresses)) ?>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= formatBytes($iface['stats']['rx_bytes'] ?? 0) ?> /
                        <?= formatBytes($iface['stats']['tx_bytes'] ?? 0) ?>
                    </td>
                    <td>
                        <a href="/network/interface/<?= urlencode($name) ?>" class="btn btn-small btn-primary">Edit</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($interfaces)): ?>
                <tr>
                    <td colspan="7" class="text-center text-muted">No network interfaces found</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card full-width">
        <h3>Routing Table</h3>
        <table>
            <thead>
                <tr>
                    <th>Destination</th>
                    <th>Gateway</th>
                    <th>Interface</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($routes ?? [] as $route): ?>
                <tr>
                    <td><?= htmlspecialchars($route['destination'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($route['gateway'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($route['interface'] ?? '-') ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($routes)): ?>
                <tr>
                    <td colspan="3" class="text-center text-muted">No routes configured</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
function formatBytes(int $bytes): string {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}
?>

<style>
/* Configuration badges */
.config-badge {
    display: inline-block;
    padding: 0.2rem 0.5rem;
    border-radius: 3px;
    font-size: 0.85em;
    font-weight: 500;
}

.config-static {
    background: #2a5a8a;
    color: #fff;
}

.config-dhcp {
    background: #5a8a2a;
    color: #fff;
}

.config-disabled {
    background: #555;
    color: #aaa;
}

.config-unconfigured {
    background: #3a3a3a;
    color: #888;
    font-style: italic;
}

/* Text utilities */
.text-muted {
    color: #666;
}

.text-center {
    text-align: center;
}

/* Apply Configuration Card */
.apply-config-card {
    background: #2a3a4a;
    border: 2px solid #f0ad4e;
}

.apply-config-card.no-countdown {
    border-color: #5cb85c;
}

.apply-config-card h3 {
    color: #f0ad4e;
}

.apply-config-card.no-countdown h3 {
    color: #5cb85c;
}

.countdown-warning {
    color: #f0ad4e;
    font-style: italic;
}

.safe-apply-note {
    color: #5cb85c;
    font-style: italic;
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
