<?php
$pageTitle = 'Apply ACLs to Interfaces';
$page = 'firewall';
$acls = $acls ?? [];
$applied = $applied ?? [];
$interfaces = $interfaces ?? [];
?>

<div class="page-header">
    <a href="/firewall" class="btn btn-small">&larr; Back to Firewall</a>
</div>

<div class="dashboard-grid">
    <!-- Add Application Form -->
    <div class="card">
        <h3>Apply ACL to Interface Pair</h3>
        <?php if (empty($acls)): ?>
        <div class="alert alert-warning">
            <p>No ACLs defined. <a href="/firewall/acl/new">Create an ACL</a> first.</p>
        </div>
        <?php else: ?>
        <form method="post" action="/firewall/apply">
            <div class="form-group">
                <label for="acl">Access Control List</label>
                <select id="acl" name="acl" required>
                    <option value="">Select ACL...</option>
                    <?php foreach ($acls as $acl): ?>
                    <option value="<?= htmlspecialchars($acl['name'] ?? '') ?>">
                        <?= htmlspecialchars($acl['name'] ?? '') ?>
                        <?php if (!empty($acl['description'])): ?>
                            - <?= htmlspecialchars($acl['description']) ?>
                        <?php endif; ?>
                        (<?= count($acl['rules'] ?? []) ?> rules)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="in_interface">Input Interface</label>
                    <select id="in_interface" name="in_interface" required>
                        <?php foreach ($interfaces as $value => $label): ?>
                        <option value="<?= htmlspecialchars($value) ?>"><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Traffic entering from this interface</small>
                </div>

                <div class="form-group interface-arrow">
                    <span>&rarr;</span>
                </div>

                <div class="form-group">
                    <label for="out_interface">Output Interface</label>
                    <select id="out_interface" name="out_interface" required>
                        <?php foreach ($interfaces as $value => $label): ?>
                        <option value="<?= htmlspecialchars($value) ?>"><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Traffic exiting through this interface</small>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Apply ACL</button>
        </form>
        <?php endif; ?>
    </div>

    <!-- Help -->
    <div class="card">
        <h3>How It Works</h3>
        <div class="help-text">
            <p>ACLs are applied to <strong>forwarded traffic</strong> between two interfaces.</p>
            <p>For example, to filter traffic from your LAN (eth1) going to the Internet (eth0):</p>
            <ul>
                <li>Input Interface: eth1 (LAN)</li>
                <li>Output Interface: eth0 (WAN)</li>
            </ul>
            <p>The ACL rules will be evaluated for all packets matching this flow.</p>
        </div>
    </div>

    <!-- Current Applications -->
    <div class="card full-width">
        <h3>Current ACL Applications</h3>
        <?php if (empty($applied)): ?>
        <div class="placeholder-box">
            <p>No ACLs are currently applied to any interface pairs.</p>
            <p class="text-muted">Traffic will use the default firewall policy.</p>
        </div>
        <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>ACL</th>
                    <th>Input Interface</th>
                    <th></th>
                    <th>Output Interface</th>
                    <th>Rules</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($applied as $index => $app): ?>
                <?php
                    // Find rule count for this ACL
                    $ruleCount = 0;
                    foreach ($acls as $acl) {
                        if (($acl['name'] ?? '') === ($app['acl'] ?? '')) {
                            $ruleCount = count($acl['rules'] ?? []);
                            break;
                        }
                    }
                ?>
                <tr>
                    <td>
                        <a href="/firewall/acl/<?= urlencode($app['acl'] ?? '') ?>">
                            <strong><?= htmlspecialchars($app['acl'] ?? '') ?></strong>
                        </a>
                    </td>
                    <td><?= htmlspecialchars($app['in_interface'] ?? 'any') ?></td>
                    <td class="text-center">&rarr;</td>
                    <td><?= htmlspecialchars($app['out_interface'] ?? 'any') ?></td>
                    <td>
                        <span class="badge"><?= $ruleCount ?> rules</span>
                    </td>
                    <td>
                        <form method="post" action="/firewall/apply/<?= $index ?>/delete" style="display:inline;">
                            <button type="submit" class="btn btn-small btn-danger"
                                    data-confirm="Remove this ACL application?">
                                Remove
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<style>
.page-header {
    margin-bottom: 1.5rem;
}

.form-row {
    display: flex;
    gap: 1rem;
    align-items: flex-end;
}

.form-row .form-group {
    flex: 1;
}

.interface-arrow {
    flex: 0 0 50px !important;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    color: var(--accent);
    padding-bottom: 1.5rem;
}

.help-text {
    color: var(--text-muted);
    font-size: 0.9rem;
}

.help-text ul {
    margin: 0.5rem 0;
    padding-left: 1.5rem;
}

.help-text p {
    margin: 0.5rem 0;
}

.help-text strong {
    color: var(--text);
}

.badge {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    font-size: 0.85rem;
    border-radius: 3px;
    background: var(--bg-dark);
}

.text-center {
    text-align: center;
    color: var(--accent);
    font-size: 1.25rem;
}

.alert {
    padding: 1rem;
    border-radius: 4px;
    margin-bottom: 1rem;
}

.alert-warning {
    background: rgba(255, 193, 7, 0.1);
    border: 1px solid var(--warning);
    color: var(--warning);
}

.alert a {
    color: inherit;
    text-decoration: underline;
}
</style>
