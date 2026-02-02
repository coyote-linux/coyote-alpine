<?php
$pageTitle = $isNew ? 'Add Port Forward' : 'Edit Port Forward';
$page = 'nat';
$forward = $forward ?? [];
$interfaces = $interfaces ?? [];
$id = $id ?? 'new';
?>

<div class="page-header">
    <a href="/nat" class="btn btn-small">&larr; Back to NAT</a>
</div>

<div class="dashboard-grid">
    <div class="card full-width">
        <h3><?= $isNew ? 'Add Port Forward' : 'Edit Port Forward' ?></h3>
        <form method="post" action="/nat/forward/<?= $isNew ? 'new' : htmlspecialchars($id) ?>">
            <!-- Basic Settings -->
            <div class="form-row">
                <div class="form-group form-group-inline">
                    <label>
                        <input type="checkbox" name="enabled" value="1" <?= ($forward['enabled'] ?? true) ? 'checked' : '' ?>>
                        Enabled
                    </label>
                </div>
            </div>

            <div class="form-group">
                <label for="protocol">Protocol</label>
                <select id="protocol" name="protocol" style="width: 200px;">
                    <option value="tcp" <?= ($forward['protocol'] ?? 'tcp') === 'tcp' ? 'selected' : '' ?>>TCP</option>
                    <option value="udp" <?= ($forward['protocol'] ?? '') === 'udp' ? 'selected' : '' ?>>UDP</option>
                    <option value="both" <?= ($forward['protocol'] ?? '') === 'both' ? 'selected' : '' ?>>TCP + UDP</option>
                </select>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="external_port">External Port</label>
                    <input type="number" id="external_port" name="external_port"
                           value="<?= htmlspecialchars($forward['external_port'] ?? '') ?>"
                           min="1" max="65535" required style="width: 150px;">
                    <small class="text-muted">Port that external clients connect to (1-65535)</small>
                </div>

                <div class="form-group">
                    <label for="internal_port">Internal Port</label>
                    <input type="number" id="internal_port" name="internal_port"
                           value="<?= htmlspecialchars($forward['internal_port'] ?? '') ?>"
                           min="1" max="65535" placeholder="Same as external" style="width: 150px;">
                    <small class="text-muted">Port on the internal server (leave blank if same)</small>
                </div>
            </div>

            <div class="form-group">
                <label for="internal_ip">Internal IP Address</label>
                <input type="text" id="internal_ip" name="internal_ip"
                       value="<?= htmlspecialchars($forward['internal_ip'] ?? '') ?>"
                       placeholder="192.168.1.100" required style="width: 200px;">
                <small class="text-muted">IP address of the internal server that will receive connections</small>
            </div>

            <!-- Optional Settings -->
            <div class="config-section">
                <h4>Optional Settings</h4>

                <div class="form-group">
                    <label for="interface">Input Interface</label>
                    <select id="interface" name="interface" style="width: 200px;">
                        <option value="">Any Interface</option>
                        <?php foreach ($interfaces as $iface): ?>
                            <?php if ($iface === 'lo') continue; ?>
                            <option value="<?= htmlspecialchars($iface) ?>"
                                <?= ($forward['interface'] ?? '') === $iface ? 'selected' : '' ?>>
                                <?= htmlspecialchars($iface) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Restrict to traffic arriving on a specific interface (typically WAN)</small>
                </div>

                <div class="form-group">
                    <label for="source">Source Restriction</label>
                    <input type="text" id="source" name="source"
                           value="<?= htmlspecialchars($forward['source'] ?? '') ?>"
                           placeholder="0.0.0.0/0" style="width: 200px;">
                    <small class="text-muted">Only allow connections from this IP/network (CIDR notation). Leave blank for any source.</small>
                </div>

                <div class="form-group">
                    <label for="comment">Comment</label>
                    <input type="text" id="comment" name="comment"
                           value="<?= htmlspecialchars($forward['comment'] ?? '') ?>"
                           placeholder="Web server" style="width: 300px;">
                    <small class="text-muted">Optional description of this port forward</small>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?= $isNew ? 'Add Port Forward' : 'Save Changes' ?></button>
                <a href="/nat" class="btn">Cancel</a>
            </div>

            <p class="text-muted" style="margin-top: 1rem;">
                Changes are saved but not applied until you click "Apply Configuration" on the System page.
            </p>
        </form>
    </div>

    <?php if (!$isNew): ?>
    <div class="card">
        <h3>Delete Port Forward</h3>
        <p>Permanently remove this port forward rule.</p>
        <form method="post" action="/nat/forward/<?= htmlspecialchars($id) ?>/delete">
            <button type="submit" class="btn btn-danger" data-confirm="Delete this port forward?">Delete Port Forward</button>
        </form>
    </div>
    <?php endif; ?>

    <!-- Help -->
    <div class="card">
        <h3>Port Forward Help</h3>
        <div class="help-content">
            <p><strong>Port forwards</strong> (DNAT) redirect incoming connections from the firewall's external interface to an internal server.</p>
            <p>Common uses:</p>
            <ul>
                <li>Web server (port 80/443)</li>
                <li>SSH access (port 22)</li>
                <li>Mail server (port 25/587)</li>
                <li>Game servers</li>
                <li>Remote desktop (port 3389)</li>
            </ul>
            <p class="text-muted">The firewall will also add filter rules to allow the forwarded traffic through.</p>
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
