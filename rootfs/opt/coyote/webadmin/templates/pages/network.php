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
        <h3>Routing Table <a href="/network/routes" class="btn btn-small btn-primary" style="margin-left: 1rem;">Edit Routes</a></h3>
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


