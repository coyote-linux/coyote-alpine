<?php
$pageTitle = 'Access Controls';
$page = 'firewall';
$webadminHosts = $webadminHosts ?? [];
$sshHosts = $sshHosts ?? [];
$sshEnabled = $sshEnabled ?? false;
$sshPort = $sshPort ?? 22;
?>

<div class="page-header">
    <a href="/firewall" class="btn btn-small">&larr; Back to Firewall</a>
</div>

<div class="dashboard-grid">
    <!-- Web Admin Hosts -->
    <div class="card">
        <h3>Web Admin Access</h3>
        <p class="text-muted">Control which hosts can access this web administration interface.</p>

        <?php if (empty($webadminHosts)): ?>
        <div class="alert alert-error">
            <strong>All access blocked.</strong> No hosts are allowed to access the web admin.
            Add at least one host to allow access.
        </div>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Host/Network</th>
                    <th style="width: 100px;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($webadminHosts as $index => $host): ?>
                <tr>
                    <td><code><?= htmlspecialchars($host) ?></code></td>
                    <td>
                        <form method="post" action="/firewall/access/webadmin/<?= $index ?>/delete" style="display: inline;">
                            <button type="submit" class="btn btn-small btn-danger" onclick="return confirm('Remove this host?');">Remove</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <form method="post" action="/firewall/access/webadmin/add" class="form-inline" style="margin-top: 1rem;">
            <div class="form-group" style="flex: 1;">
                <input type="text" name="host" placeholder="IP address or CIDR (e.g., 192.168.1.0/24)" required>
            </div>
            <button type="submit" class="btn btn-primary">Add Host</button>
        </form>
    </div>

    <!-- SSH Hosts -->
    <div class="card">
        <h3>SSH Access</h3>
        <p class="text-muted">Control which hosts can access SSH on this firewall.</p>

        <?php if (empty($sshHosts)): ?>
        <div class="alert alert-error">
            <strong>All access blocked.</strong> No hosts are allowed to access SSH.
            Add at least one host to allow access.
        </div>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Host/Network</th>
                    <th style="width: 100px;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sshHosts as $index => $host): ?>
                <tr>
                    <td><code><?= htmlspecialchars($host) ?></code></td>
                    <td>
                        <form method="post" action="/firewall/access/ssh/<?= $index ?>/delete" style="display: inline;">
                            <button type="submit" class="btn btn-small btn-danger" onclick="return confirm('Remove this host?');">Remove</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <form method="post" action="/firewall/access/ssh/add" class="form-inline" style="margin-top: 1rem;">
            <div class="form-group" style="flex: 1;">
                <input type="text" name="host" placeholder="IP address or CIDR (e.g., 192.168.1.0/24)" required>
            </div>
            <button type="submit" class="btn btn-primary">Add Host</button>
        </form>
    </div>
</div>

<!-- Help Section -->
<div class="card" style="margin-top: 1rem;">
    <h3>About Access Controls</h3>
    <div class="help-text">
        <p>These settings control which hosts can access management services on the firewall itself.</p>

        <h4>Important Notes:</h4>
        <ul>
            <li><strong>Empty lists block all access.</strong> If no hosts are specified, all access to that service is blocked by the firewall's default DROP policy.</li>
            <li>Enter IP addresses or networks in CIDR notation (e.g., <code>192.168.1.100</code> or <code>192.168.1.0/24</code>).</li>
            <li>Single IP addresses will automatically have <code>/32</code> appended.</li>
            <li>Changes must be applied to take effect. Use the "Apply Configuration" button in the header.</li>
        </ul>

        <h4>Examples:</h4>
        <ul>
            <li><code>192.168.1.100</code> - Allow a single host</li>
            <li><code>192.168.1.0/24</code> - Allow an entire /24 network (192.168.1.0 - 192.168.1.255)</li>
            <li><code>10.0.0.0/8</code> - Allow all 10.x.x.x addresses</li>
        </ul>
    </div>
</div>

<style>
.form-inline {
    display: flex;
    gap: 0.5rem;
    align-items: flex-end;
}
.form-inline .form-group {
    margin-bottom: 0;
}
.form-inline input {
    margin-bottom: 0;
}
code {
    background: var(--bg-dark);
    padding: 0.2rem 0.5rem;
    border-radius: 3px;
    font-family: monospace;
}
.help-text h4 {
    margin-top: 1rem;
    margin-bottom: 0.5rem;
    color: var(--text-secondary);
}
.help-text ul {
    margin-left: 1.5rem;
    margin-bottom: 0.5rem;
}
.help-text li {
    margin-bottom: 0.25rem;
}
</style>
