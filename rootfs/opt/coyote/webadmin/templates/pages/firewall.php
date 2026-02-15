<?php
$pageTitle = 'Firewall';
$page = 'firewall';
$acls = $acls ?? [];
$applied = $applied ?? [];
$status = $status ?? ['enabled' => true, 'defaultPolicy' => 'drop'];
?>

<div class="dashboard-grid">
    <!-- Firewall Status -->
    <div class="card">
        <h3>Firewall Status</h3>
        <dl>
            <dt>Status</dt>
            <dd>
                <span class="status-badge status-<?= ($status['enabled'] ?? false) ? 'up' : 'down' ?>">
                    <?= ($status['enabled'] ?? false) ? 'Enabled' : 'Disabled' ?>
                </span>
            </dd>
            <dt>Default Policy</dt>
            <dd>
                <span class="badge badge-<?= ($status['defaultPolicy'] ?? 'drop') === 'drop' ? 'warning' : 'info' ?>">
                    <?= strtoupper($status['defaultPolicy'] ?? 'drop') ?>
                </span>
            </dd>
            <dt>Access Control Lists</dt>
            <dd><?= count($acls) ?></dd>
            <dt>Applied Rules</dt>
            <dd><?= count($applied) ?></dd>
        </dl>
    </div>

    <!-- Quick Actions -->
    <div class="card">
        <h3>Quick Actions</h3>
        <div class="button-group">
            <a href="/firewall/acl/new" class="btn btn-primary">Create ACL</a>
            <a href="/firewall/apply" class="btn btn-primary">Apply ACLs to Interfaces</a>
            <a href="/firewall/access" class="btn btn-primary">Access Controls</a>
            <a href="/firewall/address-lists" class="btn btn-primary">Address Lists</a>
        </div>
    </div>

    <!-- Access Control Lists -->
    <div class="card full-width">
        <h3>Access Control Lists</h3>
        <?php if (empty($acls)): ?>
        <div class="placeholder-box">
            <p>No access control lists defined.</p>
            <p class="text-muted">ACLs group firewall rules that can be applied to interface pairs.</p>
            <a href="/firewall/acl/new" class="btn btn-primary">Create Your First ACL</a>
        </div>
        <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Description</th>
                    <th>Rules</th>
                    <th>Applied To</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($acls as $acl): ?>
                <?php
                    $aclName = $acl['name'] ?? '';
                    $ruleCount = count($acl['rules'] ?? []);

                    // Count where this ACL is applied
                    $appliedTo = [];
                    foreach ($applied as $app) {
                        if (($app['acl'] ?? '') === $aclName) {
                            $appliedTo[] = ($app['in_interface'] ?? 'any') . ' -> ' . ($app['out_interface'] ?? 'any');
                        }
                    }
                ?>
                <tr>
                    <td>
                        <a href="/firewall/acl/<?= urlencode($aclName) ?>">
                            <strong><?= htmlspecialchars($aclName) ?></strong>
                        </a>
                    </td>
                    <td><?= htmlspecialchars($acl['description'] ?? '') ?></td>
                    <td>
                        <span class="badge"><?= $ruleCount ?> rule<?= $ruleCount !== 1 ? 's' : '' ?></span>
                    </td>
                    <td>
                        <?php if (empty($appliedTo)): ?>
                            <span class="text-muted">Not applied</span>
                        <?php else: ?>
                            <?php foreach ($appliedTo as $app): ?>
                                <span class="badge badge-info"><?= htmlspecialchars($app) ?></span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="/firewall/acl/<?= urlencode($aclName) ?>" class="btn btn-primary btn-small">Edit</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <!-- Applied ACLs Summary -->
    <?php if (!empty($applied)): ?>
    <div class="card full-width">
        <h3>Applied ACLs</h3>
        <table class="data-table">
            <thead>
                <tr>
                    <th>ACL</th>
                    <th>Input Interface</th>
                    <th>Output Interface</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($applied as $index => $app): ?>
                <tr>
                    <td>
                        <a href="/firewall/acl/<?= urlencode($app['acl'] ?? '') ?>">
                            <?= htmlspecialchars($app['acl'] ?? '') ?>
                        </a>
                    </td>
                    <td><?= htmlspecialchars($app['in_interface'] ?? 'any') ?></td>
                    <td><?= htmlspecialchars($app['out_interface'] ?? 'any') ?></td>
                    <td>
                        <form method="post" action="/firewall/apply/<?= $index ?>/delete" style="display:inline;">
                            <button type="submit" class="btn btn-small btn-danger" data-confirm="Remove this ACL application?">Remove</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="text-muted" style="margin-top: 1rem;">
            <a href="/firewall/apply" class="btn btn-small">Manage ACL Applications</a>
        </p>
    </div>
    <?php endif; ?>

    <!-- Help -->
    <div class="card full-width">
        <h3>How Firewall ACLs Work</h3>
        <div class="help-content">
            <ol>
                <li><strong>Create ACLs</strong> - Define named access control lists containing permit/deny rules.</li>
                <li><strong>Add Rules</strong> - Each ACL contains ordered rules that match traffic by protocol, source, destination, and ports.</li>
                <li><strong>Apply to Interfaces</strong> - ACLs are applied to interface pairs (input -> output) to filter forwarded traffic.</li>
                <li><strong>First Match Wins</strong> - Rules are evaluated in order; the first matching rule determines the action.</li>
                <li><strong>Default Policy</strong> - Traffic that doesn't match any rule uses the default policy (<?= strtoupper($status['defaultPolicy'] ?? 'drop') ?>).</li>
            </ol>
        </div>
    </div>
</div>

