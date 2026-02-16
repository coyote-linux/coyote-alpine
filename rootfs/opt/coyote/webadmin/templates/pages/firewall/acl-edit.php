<?php
$isNew = $isNew ?? false;
$acl = $acl ?? null;
$aclName = $acl['name'] ?? '';
$aclDescription = $acl['description'] ?? '';
$rules = $acl['rules'] ?? [];
$pageTitle = $isNew ? 'Create ACL' : 'Edit ACL: ' . $aclName;
$page = 'firewall';
?>

<div class="page-header">
    <a href="/firewall" class="btn btn-small">&larr; Back to Firewall</a>
</div>

<div class="dashboard-grid">
    <?php if ($isNew): ?>
    <!-- Create New ACL Form -->
    <div class="card full-width">
        <h3>Create New Access Control List</h3>
        <form method="post" action="/firewall/acl/new">
            <div class="form-group">
                <label for="name">ACL Name</label>
                <input type="text" id="name" name="name" required
                       pattern="[a-zA-Z][a-zA-Z0-9_-]*" maxlength="32"
                       placeholder="allow_web_traffic">
                <small class="text-muted">Letters, numbers, underscores, hyphens. Must start with a letter.</small>
            </div>

            <div class="form-group">
                <label for="description">Description (optional)</label>
                <input type="text" id="description" name="description"
                       placeholder="Allow HTTP and HTTPS traffic from LAN to WAN">
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Create ACL</button>
                <a href="/firewall" class="btn">Cancel</a>
            </div>
        </form>
    </div>
    <?php else: ?>
    <!-- ACL Details -->
    <div class="card">
        <h3>ACL Details</h3>
        <form method="post" action="/firewall/acl/<?= urlencode($aclName) ?>">
            <dl>
                <dt>Name</dt>
                <dd><strong><?= htmlspecialchars($aclName) ?></strong></dd>
            </dl>

            <div class="form-group">
                <label for="description">Description</label>
                <input type="text" id="description" name="description"
                       value="<?= htmlspecialchars($aclDescription) ?>"
                       placeholder="Optional description">
            </div>

            <button type="submit" class="btn btn-small">Update Description</button>
        </form>
    </div>

    <!-- Quick Actions -->
    <div class="card">
        <h3>Actions</h3>
        <div class="button-group">
            <a href="/firewall/acl/<?= urlencode($aclName) ?>/rule/new" class="btn btn-primary">Add Rule</a>
        </div>

        <hr>

        <h4>Delete ACL</h4>
        <form method="post" action="/firewall/acl/<?= urlencode($aclName) ?>/delete">
            <button type="submit" class="btn btn-danger"
                    data-confirm="Delete ACL '<?= htmlspecialchars($aclName) ?>'? This cannot be undone.">
                Delete ACL
            </button>
        </form>
    </div>

    <!-- Rules List -->
    <div class="card full-width">
        <h3>Rules</h3>
        <?php if (empty($rules)): ?>
        <div class="placeholder-box">
            <p>No rules in this ACL.</p>
            <p class="text-muted">Add rules to define what traffic to permit or deny.</p>
            <a href="/firewall/acl/<?= urlencode($aclName) ?>/rule/new" class="btn btn-primary">Add First Rule</a>
        </div>
        <?php else: ?>
        <table class="data-table rules-table">
            <thead>
                <tr>
                    <th width="40">#</th>
                    <th width="80">Action</th>
                    <th width="80">Protocol</th>
                    <th>Source</th>
                    <th>Destination</th>
                    <th>Ports</th>
                    <th>Comment</th>
                    <th width="200">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rules as $index => $rule): ?>
                <?php
                    $action = $rule['action'] ?? 'permit';
                    $protocol = $rule['protocol'] ?? 'any';
                    $source = $rule['source'] ?? 'any';
                    $dest = $rule['destination'] ?? 'any';
                    $ports = $rule['ports'] ?? ($rule['destination_port'] ?? ($rule['port'] ?? ($rule['dport'] ?? '')));
                    $comment = $rule['comment'] ?? '';
                ?>
                <tr>
                    <td class="rule-number"><?= $index + 1 ?></td>
                    <td>
                        <span class="badge badge-<?= $action === 'permit' ? 'success' : 'danger' ?>">
                            <?= strtoupper($action) ?>
                        </span>
                    </td>
                    <td><?= htmlspecialchars(strtoupper($protocol)) ?></td>
                    <td><?= htmlspecialchars($source) ?></td>
                    <td><?= htmlspecialchars($dest) ?></td>
                    <td><?= htmlspecialchars($ports) ?: '<span class="text-muted">-</span>' ?></td>
                    <td class="text-muted"><?= htmlspecialchars($comment) ?></td>
                    <td class="rule-actions">
                        <!-- Move buttons -->
                        <?php if ($index > 0): ?>
                        <form method="post" action="/firewall/acl/<?= urlencode($aclName) ?>/rule/<?= $index ?>/move" style="display:inline;">
                            <input type="hidden" name="direction" value="up">
                            <button type="submit" class="btn btn-mini" title="Move Up">&uarr;</button>
                        </form>
                        <?php else: ?>
                        <button class="btn btn-mini" disabled>&uarr;</button>
                        <?php endif; ?>

                        <?php if ($index < count($rules) - 1): ?>
                        <form method="post" action="/firewall/acl/<?= urlencode($aclName) ?>/rule/<?= $index ?>/move" style="display:inline;">
                            <input type="hidden" name="direction" value="down">
                            <button type="submit" class="btn btn-mini" title="Move Down">&darr;</button>
                        </form>
                        <?php else: ?>
                        <button class="btn btn-mini" disabled>&darr;</button>
                        <?php endif; ?>

                        <!-- Edit/Delete -->
                        <a href="/firewall/acl/<?= urlencode($aclName) ?>/rule/<?= $index ?>" class="btn btn-primary btn-mini">Edit</a>
                        <form method="post" action="/firewall/acl/<?= urlencode($aclName) ?>/rule/<?= $index ?>/delete" style="display:inline;">
                            <button type="submit" class="btn btn-mini btn-danger" data-confirm="Delete this rule?">Del</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="text-muted" style="margin-top: 1rem;">
            Rules are evaluated in order from top to bottom. The first matching rule determines the action.
        </p>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

