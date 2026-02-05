<?php
$pageTitle = $isNew ? 'Create Address List' : 'Edit Address List';
$page = 'firewall';
$list = $list ?? [];
$name = $list['name'] ?? '';
$description = $list['description'] ?? '';
$ipv4Entries = implode("\n", $list['ipv4'] ?? []);
$ipv6Entries = implode("\n", $list['ipv6'] ?? []);
?>

<div class="page-header">
    <a href="/firewall/address-lists" class="btn btn-small">&larr; Back to Address Lists</a>
</div>

<div class="dashboard-grid">
    <div class="card full-width">
        <h3><?= $isNew ? 'Create Address List' : 'Edit Address List: ' . htmlspecialchars($name) ?></h3>
        <form method="post" action="<?= $isNew ? '/firewall/address-list/new' : '/firewall/address-list/' . urlencode($name) ?>">
            <div class="form-group">
                <label for="name">List Name</label>
                <?php if ($isNew): ?>
                    <input type="text" id="name" name="name" value="" placeholder="cloudflare_proxy">
                <?php else: ?>
                    <input type="text" id="name" name="name" value="<?= htmlspecialchars($name) ?>" disabled>
                <?php endif; ?>
                <small class="text-muted">Use letters, numbers, underscores, and hyphens.</small>
            </div>

            <div class="form-group">
                <label for="description">Description</label>
                <input type="text" id="description" name="description" value="<?= htmlspecialchars($description) ?>" placeholder="Cloudflare proxy ranges">
            </div>

            <div class="form-group">
                <label for="ipv4_entries">IPv4 Entries</label>
                <textarea id="ipv4_entries" name="ipv4_entries" rows="8" placeholder="203.0.113.0/24\n198.51.100.0/24"><?= htmlspecialchars($ipv4Entries) ?></textarea>
                <small class="text-muted">One entry per line. Supports single IPs or CIDR ranges.</small>
            </div>

            <div class="form-group">
                <label for="ipv6_entries">IPv6 Entries</label>
                <textarea id="ipv6_entries" name="ipv6_entries" rows="6" placeholder="2001:db8::/32"><?= htmlspecialchars($ipv6Entries) ?></textarea>
                <small class="text-muted">One entry per line. Supports single IPs or CIDR ranges.</small>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?= $isNew ? 'Create List' : 'Save Changes' ?></button>
                <a href="/firewall/address-lists" class="btn">Cancel</a>
            </div>
        </form>
    </div>

    <?php if (!$isNew): ?>
    <div class="card full-width">
        <h3>Import Entries</h3>
        <form method="post" action="/firewall/address-list/<?= urlencode($name) ?>/import">
            <?php if (!empty($presets ?? [])): ?>
            <div class="form-group">
                <label for="preset">Preset</label>
                <select id="preset" name="preset">
                    <option value="">Select preset...</option>
                    <?php foreach ($presets as $presetKey => $preset): ?>
                        <option value="<?= htmlspecialchars($presetKey) ?>"><?= htmlspecialchars($preset['label'] ?? $presetKey) ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted">Preset import will use the official provider lists.</small>
            </div>
            <?php endif; ?>

            <div class="form-group">
                <label for="ipv4_url">IPv4 URL</label>
                <input type="text" id="ipv4_url" name="ipv4_url" placeholder="https://example.com/ipv4.txt">
            </div>

            <div class="form-group">
                <label for="ipv6_url">IPv6 URL</label>
                <input type="text" id="ipv6_url" name="ipv6_url" placeholder="https://example.com/ipv6.txt">
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Import Entries</button>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>
