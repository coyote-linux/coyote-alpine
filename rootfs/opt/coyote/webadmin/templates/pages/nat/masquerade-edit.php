<?php
$pageTitle = $isNew ? 'Add Masquerade Rule' : 'Edit Masquerade Rule';
$page = 'nat';
$masquerade = $masquerade ?? [];
$interfaces = $interfaces ?? [];
$id = $id ?? 'new';
?>

<div class="page-header">
    <a href="/nat" class="btn btn-small">&larr; Back to NAT</a>
</div>

<div class="dashboard-grid">
    <div class="card full-width">
        <h3><?= $isNew ? 'Add Masquerade Rule' : 'Edit Masquerade Rule' ?></h3>
        <form method="post" action="/nat/masquerade/<?= $isNew ? 'new' : htmlspecialchars($id) ?>">
            <!-- Basic Settings -->
            <div class="form-row">
                <div class="form-group form-group-inline">
                    <label>
                        <input type="checkbox" name="enabled" value="1" <?= ($masquerade['enabled'] ?? true) ? 'checked' : '' ?>>
                        Enabled
                    </label>
                </div>
            </div>

            <div class="form-group">
                <label for="interface">Output Interface</label>
                <select id="interface" name="interface" required style="width: 200px;">
                    <option value="">-- Select Interface --</option>
                    <?php foreach ($interfaces as $iface): ?>
                        <?php if ($iface === 'lo') continue; ?>
                        <option value="<?= htmlspecialchars($iface) ?>"
                            <?= ($masquerade['interface'] ?? '') === $iface ? 'selected' : '' ?>>
                            <?= htmlspecialchars($iface) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted">The WAN/external interface where outbound traffic exits. Traffic leaving through this interface will have its source address translated.</small>
            </div>

            <!-- Optional Settings -->
            <div class="config-section">
                <h4>Optional Settings</h4>

                <div class="form-group">
                    <label for="source">Source Network</label>
                    <input type="text" id="source" name="source"
                           value="<?= htmlspecialchars($masquerade['source'] ?? '') ?>"
                           placeholder="192.168.1.0/24" style="width: 200px;">
                    <small class="text-muted">Only masquerade traffic from this network (CIDR notation). Leave blank to masquerade all traffic.</small>
                </div>

                <div class="form-group">
                    <label for="comment">Comment</label>
                    <input type="text" id="comment" name="comment"
                           value="<?= htmlspecialchars($masquerade['comment'] ?? '') ?>"
                           placeholder="LAN to Internet" style="width: 300px;">
                    <small class="text-muted">Optional description of this masquerade rule</small>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?= $isNew ? 'Add Masquerade Rule' : 'Save Changes' ?></button>
                <a href="/nat" class="btn">Cancel</a>
            </div>

            <p class="text-muted" style="margin-top: 1rem;">
                Changes are saved but not applied until you click "Apply Configuration" on the System page.
            </p>
        </form>
    </div>

    <?php if (!$isNew): ?>
    <div class="card">
        <h3>Delete Masquerade Rule</h3>
        <p>Permanently remove this masquerade rule.</p>
        <form method="post" action="/nat/masquerade/<?= htmlspecialchars($id) ?>/delete">
            <button type="submit" class="btn btn-danger" data-confirm="Delete this masquerade rule?">Delete Masquerade Rule</button>
        </form>
    </div>
    <?php endif; ?>

    <!-- Help -->
    <div class="card">
        <h3>Masquerade Help</h3>
        <div class="help-content">
            <p><strong>Masquerade</strong> (SNAT) translates the source IP address of outgoing packets to the firewall's external interface address.</p>
            <p>This is required for:</p>
            <ul>
                <li>Allowing internal hosts to access the Internet</li>
                <li>Hiding internal network structure from external networks</li>
                <li>Sharing a single public IP among multiple internal hosts</li>
            </ul>
            <p><strong>Typical configuration:</strong></p>
            <ul>
                <li>Output Interface: Your WAN interface (e.g., eth0)</li>
                <li>Source Network: Your LAN network (e.g., 192.168.1.0/24)</li>
            </ul>
            <p class="text-muted">If you don't specify a source network, all traffic leaving through the output interface will be masqueraded.</p>
        </div>
    </div>
</div>

<style>
.page-header {
    margin-bottom: 1.5rem;
}

.config-section {
    margin: 1.5rem 0;
    padding: 1rem;
    background: var(--bg-dark);
    border-radius: 4px;
    border-left: 3px solid var(--accent);
}

.config-section h4 {
    margin: 0 0 1rem 0;
    color: var(--accent);
    font-size: 1rem;
}

.form-actions {
    margin-top: 1.5rem;
    display: flex;
    gap: 0.75rem;
}

.form-group small {
    display: block;
    margin-top: 0.25rem;
}

.form-row {
    display: flex;
    gap: 1.5rem;
    flex-wrap: wrap;
}

.form-group-inline {
    display: flex;
    align-items: center;
}

.form-group-inline label {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0;
}

.form-group-inline input[type="checkbox"] {
    width: auto;
}

.help-content {
    color: var(--text-muted);
    font-size: 0.9rem;
}

.help-content ul {
    margin: 0.5rem 0;
    padding-left: 1.5rem;
}

.help-content li {
    margin-bottom: 0.25rem;
}

.help-content strong {
    color: var(--text);
}
</style>
