<?php $pageTitle = 'Static Routes'; $page = 'network'; ?>

<div class="dashboard-grid">
    <div class="card full-width">
        <h3>Static Routes</h3>
        <p>Configure static IP routes for IPv4 and IPv6 traffic. These routes are applied to the system routing table when configuration is activated.</p>
        <p><strong>Note:</strong> This page shows only configured static routes, not kernel-added automatic routes.</p>

        <form method="post" action="/network/routes">
            <table>
                <thead>
                    <tr>
                        <th>Destination (CIDR)</th>
                        <th>Gateway</th>
                        <th>Metric (Optional)</th>
                        <th>Device (Optional)</th>
                        <th>Family</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($routes ?? [] as $idx => $route): ?>
                    <tr>
                        <td>
                            <input type="text" name="destination[]" value="<?= htmlspecialchars($route['destination'] ?? '') ?>" placeholder="192.168.1.0/24 or ::/0" required style="width: 100%;">
                        </td>
                        <td>
                            <input type="text" name="gateway[]" value="<?= htmlspecialchars($route['gateway'] ?? '') ?>" placeholder="192.168.1.1" required style="width: 100%;">
                        </td>
                        <td>
                            <input type="number" name="metric[]" value="<?= htmlspecialchars($route['metric'] ?? '') ?>" placeholder="1-1000" min="1" max="1000" style="width: 100%;">
                        </td>
                        <td>
                            <select name="device[]" style="width: 100%;">
                                <option value="">Default</option>
                                <?php foreach ($interfaceNames ?? [] as $iface): ?>
                                <option value="<?= htmlspecialchars($iface) ?>" <?= ($route['interface'] ?? '') === $iface ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($iface) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <?php
                            $family = $route['family'] ?? 'ipv4';
                            $badgeClass = $family === 'ipv6' ? 'config-badge config-dhcp' : 'config-badge config-static';
                            ?>
                            <span class="<?= $badgeClass ?>"><?= strtoupper(htmlspecialchars($family)) ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <!-- Empty rows for adding new routes -->
                    <tr>
                        <td>
                            <input type="text" name="destination[]" value="" placeholder="192.168.1.0/24 or ::/0" style="width: 100%;">
                        </td>
                        <td>
                            <input type="text" name="gateway[]" value="" placeholder="192.168.1.1" style="width: 100%;">
                        </td>
                        <td>
                            <input type="number" name="metric[]" value="" placeholder="1-1000" min="1" max="1000" style="width: 100%;">
                        </td>
                        <td>
                            <select name="device[]" style="width: 100%;">
                                <option value="">Default</option>
                                <?php foreach ($interfaceNames ?? [] as $iface): ?>
                                <option value="<?= htmlspecialchars($iface) ?>">
                                    <?= htmlspecialchars($iface) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><span class="text-muted">Auto</span></td>
                    </tr>
                    <tr>
                        <td>
                            <input type="text" name="destination[]" value="" placeholder="192.168.1.0/24 or ::/0" style="width: 100%;">
                        </td>
                        <td>
                            <input type="text" name="gateway[]" value="" placeholder="192.168.1.1" style="width: 100%;">
                        </td>
                        <td>
                            <input type="number" name="metric[]" value="" placeholder="1-1000" min="1" max="1000" style="width: 100%;">
                        </td>
                        <td>
                            <select name="device[]" style="width: 100%;">
                                <option value="">Default</option>
                                <?php foreach ($interfaceNames ?? [] as $iface): ?>
                                <option value="<?= htmlspecialchars($iface) ?>">
                                    <?= htmlspecialchars($iface) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><span class="text-muted">Auto</span></td>
                    </tr>
                    <tr>
                        <td>
                            <input type="text" name="destination[]" value="" placeholder="192.168.1.0/24 or ::/0" style="width: 100%;">
                        </td>
                        <td>
                            <input type="text" name="gateway[]" value="" placeholder="192.168.1.1" style="width: 100%;">
                        </td>
                        <td>
                            <input type="number" name="metric[]" value="" placeholder="1-1000" min="1" max="1000" style="width: 100%;">
                        </td>
                        <td>
                            <select name="device[]" style="width: 100%;">
                                <option value="">Default</option>
                                <?php foreach ($interfaceNames ?? [] as $iface): ?>
                                <option value="<?= htmlspecialchars($iface) ?>">
                                    <?= htmlspecialchars($iface) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><span class="text-muted">Auto</span></td>
                    </tr>
                </tbody>
            </table>

            <div style="margin-top: 1rem;">
                <button type="submit" class="btn btn-primary">Save Routes</button>
                <a href="/network" class="btn">Back to Network</a>
                <small class="form-note">Changes are saved but not applied until you click "Apply Configuration"</small>
            </div>
        </form>

        <div style="margin-top: 1.5rem;">
            <h4>Notes:</h4>
            <ul>
                <li>Destination must be in CIDR notation (e.g., <code>192.168.1.0/24</code> or <code>::/0</code>)</li>
                <li>Default route: Use <code>0.0.0.0/0</code> for IPv4 or <code>::/0</code> for IPv6</li>
                <li>Gateway must be a valid IP address reachable from the specified device</li>
                <li>Metric is optional (1-1000); lower values have higher priority</li>
                <li>Device is optional; leave as "Default" to use automatic routing</li>
                <li>Address family (IPv4/IPv6) is auto-detected from destination format</li>
                <li>Empty rows will be ignored when saving</li>
            </ul>
        </div>
    </div>
</div>
