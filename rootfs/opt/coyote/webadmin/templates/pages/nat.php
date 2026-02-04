<?php
$pageTitle = 'NAT';
$page = 'nat';
$forwards = $forwards ?? [];
$masqueradeRules = $masqueradeRules ?? [];
$interfaces = $interfaces ?? [];
?>

<div class="dashboard-grid">
    <!-- NAT Status -->
    <div class="card">
        <h3>NAT Status</h3>
        <dl>
            <dt>Port Forwards</dt>
            <dd>
                <?php
                $activeForwards = count(array_filter($forwards, fn($f) => $f['enabled'] ?? true));
                ?>
                <strong><?= $activeForwards ?></strong> active
                <?php if (count($forwards) > $activeForwards): ?>
                    <span class="text-muted">(<?= count($forwards) - $activeForwards ?> disabled)</span>
                <?php endif; ?>
            </dd>
            <dt>Masquerade Rules</dt>
            <dd>
                <?php
                $activeMasq = count(array_filter($masqueradeRules, fn($m) => $m['enabled'] ?? true));
                ?>
                <strong><?= $activeMasq ?></strong> active
                <?php if (count($masqueradeRules) > $activeMasq): ?>
                    <span class="text-muted">(<?= count($masqueradeRules) - $activeMasq ?> disabled)</span>
                <?php endif; ?>
            </dd>
        </dl>
    </div>

    <!-- Quick Actions -->
    <div class="card">
        <h3>Quick Actions</h3>
        <div class="button-group">
            <a href="/nat/forward/new" class="btn btn-primary">Add Port Forward</a>
            <a href="/nat/masquerade/new" class="btn btn-primary">Add Masquerade Rule</a>
        </div>
    </div>

    <!-- Port Forwards -->
    <div class="card full-width">
        <h3>Port Forwards</h3>
        <?php if (empty($forwards)): ?>
        <div class="placeholder-box">
            <p>No port forwards configured.</p>
            <p class="text-muted">Forward incoming connections from the WAN to internal hosts.</p>
            <a href="/nat/forward/new" class="btn btn-primary">Add Port Forward</a>
        </div>
        <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Status</th>
                    <th>Protocol</th>
                    <th>External Port</th>
                    <th>Target</th>
                    <th>Interface</th>
                    <th>Comment</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($forwards as $index => $forward): ?>
                <?php
                    $isEnabled = $forward['enabled'] ?? true;
                    $protocol = strtoupper($forward['protocol'] ?? 'TCP');
                    $extPort = $forward['external_port'] ?? '';
                    $intIp = $forward['internal_ip'] ?? '';
                    $intPort = $forward['internal_port'] ?? $extPort;
                    $iface = $forward['interface'] ?? '';
                    $comment = $forward['comment'] ?? '';
                ?>
                <tr class="<?= $isEnabled ? '' : 'row-disabled' ?>">
                    <td>
                        <span class="status-badge status-<?= $isEnabled ? 'up' : 'down' ?>">
                            <?= $isEnabled ? 'Enabled' : 'Disabled' ?>
                        </span>
                    </td>
                    <td><?= htmlspecialchars($protocol) ?></td>
                    <td><code><?= htmlspecialchars($extPort) ?></code></td>
                    <td>
                        <code><?= htmlspecialchars($intIp) ?></code><?php if ($intPort != $extPort): ?>:<code><?= htmlspecialchars($intPort) ?></code><?php endif; ?>
                    </td>
                    <td>
                        <?php if ($iface): ?>
                            <span class="badge"><?= htmlspecialchars($iface) ?></span>
                        <?php else: ?>
                            <span class="text-muted">any</span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($comment) ?></td>
                    <td>
                        <a href="/nat/forward/<?= $index ?>" class="btn btn-small">Edit</a>
                        <form method="post" action="/nat/forward/<?= $index ?>/delete" style="display:inline;">
                            <button type="submit" class="btn btn-small btn-danger"
                                    data-confirm="Delete this port forward?">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="text-muted" style="margin-top: 1rem;">
            <a href="/nat/forward/new" class="btn btn-small">Add Port Forward</a>
        </p>
        <?php endif; ?>
    </div>

    <!-- Masquerade Rules -->
    <div class="card full-width">
        <h3>Masquerade Rules (SNAT)</h3>
        <?php if (empty($masqueradeRules)): ?>
        <div class="placeholder-box">
            <p>No masquerade rules configured.</p>
            <p class="text-muted">Masquerade rules translate source addresses for outbound traffic, enabling NAT for internal networks.</p>
            <a href="/nat/masquerade/new" class="btn btn-primary">Add Masquerade Rule</a>
        </div>
        <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Status</th>
                    <th>Output Interface</th>
                    <th>Source Network</th>
                    <th>Comment</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($masqueradeRules as $index => $masq): ?>
                <?php
                    $isEnabled = $masq['enabled'] ?? true;
                    $iface = $masq['interface'] ?? '';
                    $source = $masq['source'] ?? '';
                    $comment = $masq['comment'] ?? '';
                ?>
                <tr class="<?= $isEnabled ? '' : 'row-disabled' ?>">
                    <td>
                        <span class="status-badge status-<?= $isEnabled ? 'up' : 'down' ?>">
                            <?= $isEnabled ? 'Enabled' : 'Disabled' ?>
                        </span>
                    </td>
                    <td>
                        <span class="badge"><?= htmlspecialchars($iface) ?></span>
                    </td>
                    <td>
                        <?php if ($source): ?>
                            <code><?= htmlspecialchars($source) ?></code>
                        <?php else: ?>
                            <span class="text-muted">any</span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($comment) ?></td>
                    <td>
                        <a href="/nat/masquerade/<?= $index ?>" class="btn btn-small">Edit</a>
                        <form method="post" action="/nat/masquerade/<?= $index ?>/delete" style="display:inline;">
                            <button type="submit" class="btn btn-small btn-danger"
                                    data-confirm="Delete this masquerade rule?">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="text-muted" style="margin-top: 1rem;">
            <a href="/nat/masquerade/new" class="btn btn-small">Add Masquerade Rule</a>
        </p>
        <?php endif; ?>
    </div>

    <!-- Help -->
    <div class="card full-width">
        <h3>How NAT Works</h3>
        <div class="help-content">
            <h4>Port Forwarding (DNAT)</h4>
            <p>Port forwards redirect incoming connections from the external interface to internal hosts. This allows services running on internal machines to be accessible from the Internet.</p>
            <ul>
                <li><strong>External Port</strong> - The port that external clients connect to</li>
                <li><strong>Internal IP</strong> - The internal server that will receive the connection</li>
                <li><strong>Internal Port</strong> - The port on the internal server (can differ from external port)</li>
            </ul>

            <h4>Masquerade (SNAT)</h4>
            <p>Masquerade rules perform source NAT on outgoing traffic, replacing the internal source IP with the firewall's external IP. This is required for internal hosts to access the Internet through the firewall.</p>
            <ul>
                <li><strong>Output Interface</strong> - The WAN interface where traffic exits</li>
                <li><strong>Source Network</strong> - Restrict masquerading to specific internal networks (optional)</li>
            </ul>
        </div>
    </div>
</div>


