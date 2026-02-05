<?php $pageTitle = 'Address Lists'; $page = 'firewall'; ?>

<div class="page-header">
    <h2>Address Lists</h2>
    <a href="/firewall/address-list/new" class="btn btn-primary">Create Address List</a>
</div>

<div class="dashboard-grid">
    <div class="card full-width">
        <?php if (empty($lists ?? [])): ?>
            <p class="text-muted">No address lists defined yet.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Description</th>
                        <th>IPv4 Entries</th>
                        <th>IPv6 Entries</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach (($lists ?? []) as $list): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($list['name'] ?? '') ?></strong></td>
                        <td><?= htmlspecialchars($list['description'] ?? '') ?></td>
                        <td><?= count($list['ipv4'] ?? []) ?></td>
                        <td><?= count($list['ipv6'] ?? []) ?></td>
                        <td>
                            <a href="/firewall/address-list/<?= urlencode($list['name'] ?? '') ?>" class="btn btn-small">Edit</a>
                            <form method="post" action="/firewall/address-list/<?= urlencode($list['name'] ?? '') ?>/delete" style="display:inline;">
                                <button type="submit" class="btn btn-small btn-danger" data-confirm="Delete address list '<?= htmlspecialchars($list['name'] ?? '') ?>'?">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
