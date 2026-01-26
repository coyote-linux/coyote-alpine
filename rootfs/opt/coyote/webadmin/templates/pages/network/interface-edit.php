<?php
$ifaceName = $interface['name'] ?? 'unknown';
$pageTitle = "Edit Interface: {$ifaceName}";
$page = 'network';

// Check if another interface already has dynamic addressing
$hasDynamicInterface = $hasDynamicInterface ?? false;
$dynamicInterfaceName = $dynamicInterfaceName ?? '';
$isVlanInterface = $interface['isVlan'] ?? false;
?>

<div class="page-header">
    <a href="/network" class="btn btn-small">&larr; Back to Network</a>
</div>

<div class="dashboard-grid">
    <div class="card">
        <h3>Interface Information</h3>
        <dl>
            <dt>Interface Name</dt>
            <dd><strong><?= htmlspecialchars($ifaceName) ?></strong></dd>

            <dt>Hardware MAC</dt>
            <dd><code><?= htmlspecialchars($interface['mac'] ?? '-') ?></code></dd>

            <dt>Current State</dt>
            <dd>
                <span class="status-badge status-<?= ($interface['state'] ?? 'down') === 'up' ? 'up' : 'down' ?>">
                    <?= htmlspecialchars($interface['state'] ?? 'unknown') ?>
                </span>
            </dd>

            <dt>Current IPv4</dt>
            <dd>
                <?php if (!empty($interface['currentIpv4'])): ?>
                    <?= htmlspecialchars(implode(', ', $interface['currentIpv4'])) ?>
                <?php else: ?>
                    <span class="text-muted">None</span>
                <?php endif; ?>
            </dd>
        </dl>
    </div>

    <div class="card full-width">
        <h3>Interface Configuration</h3>
        <form method="post" action="/network/interface/<?= urlencode($ifaceName) ?>" id="interface-form">
            <!-- Basic Settings -->
            <div class="form-row">
                <div class="form-group form-group-inline">
                    <label>
                        <input type="checkbox" name="enabled" value="1" <?= ($config['enabled'] ?? true) ? 'checked' : '' ?>>
                        Interface Enabled
                    </label>
                </div>
            </div>

            <div class="form-group">
                <label for="type">Configuration Method</label>
                <select id="type" name="type" onchange="toggleConfigSections()">
                    <option value="disabled" <?= ($config['type'] ?? 'disabled') === 'disabled' ? 'selected' : '' ?>>Disabled (No Configuration)</option>
                    <option value="static" <?= ($config['type'] ?? '') === 'static' ? 'selected' : '' ?>>Static IP Address</option>
                    <?php if (!$isVlanInterface): ?>
                    <option value="dhcp" <?= ($config['type'] ?? '') === 'dhcp' ? 'selected' : '' ?>
                        <?= ($hasDynamicInterface && $dynamicInterfaceName !== $ifaceName) ? 'disabled' : '' ?>>
                        DHCP Assigned Address
                        <?= ($hasDynamicInterface && $dynamicInterfaceName !== $ifaceName) ? "(used by {$dynamicInterfaceName})" : '' ?>
                    </option>
                    <option value="pppoe" <?= ($config['type'] ?? '') === 'pppoe' ? 'selected' : '' ?>
                        <?= ($hasDynamicInterface && $dynamicInterfaceName !== $ifaceName) ? 'disabled' : '' ?>>
                        PPPoE Assigned Address
                        <?= ($hasDynamicInterface && $dynamicInterfaceName !== $ifaceName) ? "(used by {$dynamicInterfaceName})" : '' ?>
                    </option>
                    <option value="bridge" <?= ($config['type'] ?? '') === 'bridge' ? 'selected' : '' ?>>Bridge Member</option>
                    <?php endif; ?>
                </select>
                <small class="text-muted">Only one interface can use DHCP or PPPoE addressing.</small>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="mtu">MTU</label>
                    <input type="number" id="mtu" name="mtu" value="<?= htmlspecialchars($config['mtu'] ?? '1500') ?>"
                           min="576" max="9000" style="width: 120px;">
                    <small class="text-muted">Maximum transmission unit (default: 1500)</small>
                </div>

                <?php if (!$isVlanInterface): ?>
                <div class="form-group">
                    <label for="mac_override">MAC Address Override</label>
                    <input type="text" id="mac_override" name="mac_override"
                           value="<?= htmlspecialchars($config['mac_override'] ?? '') ?>"
                           placeholder="00:00:00:00:00:00" style="width: 200px;">
                    <small class="text-muted">Override hardware MAC (for ISP requirements). Leave blank to use hardware MAC.</small>
                </div>
                <?php endif; ?>
            </div>

            <!-- Static IP Configuration -->
            <div id="static-section" class="config-section" style="display: none;">
                <h4>Static IP Configuration</h4>

                <div class="form-group">
                    <label for="address">Primary IP Address (CIDR)</label>
                    <input type="text" id="address" name="address"
                           value="<?= htmlspecialchars($config['addresses'][0] ?? '') ?>"
                           placeholder="192.168.1.1/24">
                    <small class="text-muted">Primary IP address in CIDR notation (e.g., 192.168.1.1/24)</small>
                </div>

                <div class="form-group">
                    <label>Secondary IP Addresses</label>
                    <div id="secondary-addresses">
                        <?php
                        $secondaryAddrs = array_slice($config['addresses'] ?? [], 1);
                        if (empty($secondaryAddrs)) $secondaryAddrs = [''];
                        foreach ($secondaryAddrs as $i => $addr):
                        ?>
                        <div class="secondary-addr-row">
                            <input type="text" name="secondary_addresses[]"
                                   value="<?= htmlspecialchars($addr) ?>"
                                   placeholder="192.168.2.1/24">
                            <button type="button" class="btn btn-small btn-danger" onclick="removeSecondaryAddr(this)">Remove</button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-small" onclick="addSecondaryAddr()">Add Secondary Address</button>
                    <small class="text-muted">Additional IP addresses for this interface (aliases)</small>
                </div>
            </div>

            <!-- DHCP Configuration -->
            <div id="dhcp-section" class="config-section" style="display: none;">
                <h4>DHCP Client Configuration</h4>

                <div class="form-group">
                    <label for="dhcp_hostname">DHCP Hostname (optional)</label>
                    <input type="text" id="dhcp_hostname" name="dhcp_hostname"
                           value="<?= htmlspecialchars($config['dhcp_hostname'] ?? '') ?>"
                           placeholder="my-hostname">
                    <small class="text-muted">Some ISPs require a specific hostname to be sent with DHCP requests.</small>
                </div>

                <div class="alert alert-info">
                    This interface will obtain its IP address automatically from a DHCP server.
                </div>
            </div>

            <!-- PPPoE Configuration -->
            <div id="pppoe-section" class="config-section" style="display: none;">
                <h4>PPPoE Client Configuration</h4>

                <div class="form-group">
                    <label for="pppoe_username">PPPoE Username</label>
                    <input type="text" id="pppoe_username" name="pppoe_username"
                           value="<?= htmlspecialchars($config['pppoe_username'] ?? '') ?>"
                           placeholder="user@isp.com">
                </div>

                <div class="form-group">
                    <label for="pppoe_password">PPPoE Password</label>
                    <input type="password" id="pppoe_password" name="pppoe_password"
                           value="<?= htmlspecialchars($config['pppoe_password'] ?? '') ?>">
                </div>

                <div class="alert alert-info">
                    This interface will establish a PPPoE connection using the credentials above.
                </div>
            </div>

            <!-- Bridge Configuration -->
            <div id="bridge-section" class="config-section" style="display: none;">
                <h4>Bridge Configuration</h4>
                <div class="alert alert-warning">
                    This interface will be added to a network bridge. No IP address will be assigned directly to this interface.
                </div>
            </div>

            <!-- Disabled Configuration -->
            <div id="disabled-section" class="config-section" style="display: none;">
                <div class="alert alert-warning">
                    This interface will not be configured and will remain down.
                </div>
            </div>

            <?php if (!$isVlanInterface): ?>
            <!-- VLAN Sub-interfaces -->
            <div id="vlan-section" class="config-section">
                <h4>802.1q VLAN Sub-interfaces</h4>
                <div id="vlan-list">
                    <?php
                    $vlans = $config['vlans'] ?? [];
                    if (empty($vlans)) $vlans = [];
                    foreach ($vlans as $vlanId):
                    ?>
                    <div class="vlan-row">
                        <input type="number" name="vlans[]" value="<?= htmlspecialchars($vlanId) ?>"
                               min="1" max="4094" placeholder="VLAN ID" style="width: 100px;">
                        <button type="button" class="btn btn-small btn-danger" onclick="removeVlan(this)">Remove</button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn btn-small" onclick="addVlan()" id="add-vlan-btn">Add VLAN</button>
                <small class="text-muted">VLAN sub-interfaces are only available when using static addressing.</small>
            </div>
            <?php endif; ?>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Save Configuration</button>
                <a href="/network" class="btn">Cancel</a>
            </div>

            <p class="text-muted" style="margin-top: 1rem;">
                Changes are saved but not applied until you click "Apply Configuration" on the Network page.
            </p>
        </form>
    </div>

    <?php if (!empty($config['type']) && $config['type'] !== 'disabled'): ?>
    <div class="card">
        <h3>Remove Configuration</h3>
        <p>Remove the configuration for this interface, returning it to an unconfigured state.</p>
        <form method="post" action="/network/interface/<?= urlencode($ifaceName) ?>/delete">
            <button type="submit" class="btn btn-danger" data-confirm="Remove configuration for <?= htmlspecialchars($ifaceName) ?>? The interface will become unconfigured.">Remove Configuration</button>
        </form>
    </div>
    <?php endif; ?>
</div>

<script>
function toggleConfigSections() {
    var type = document.getElementById('type').value;

    // Hide all sections
    document.querySelectorAll('.config-section').forEach(function(el) {
        el.style.display = 'none';
    });

    // Show the appropriate section
    var sectionId = type + '-section';
    var section = document.getElementById(sectionId);
    if (section) {
        section.style.display = 'block';
    }

    // Show/hide VLAN section based on type
    var vlanSection = document.getElementById('vlan-section');
    var addVlanBtn = document.getElementById('add-vlan-btn');
    if (vlanSection) {
        if (type === 'static') {
            vlanSection.style.display = 'block';
            if (addVlanBtn) addVlanBtn.disabled = false;
        } else {
            vlanSection.style.display = 'none';
            if (addVlanBtn) addVlanBtn.disabled = true;
        }
    }
}

function addSecondaryAddr() {
    var container = document.getElementById('secondary-addresses');
    var row = document.createElement('div');
    row.className = 'secondary-addr-row';
    row.innerHTML = '<input type="text" name="secondary_addresses[]" placeholder="192.168.2.1/24">' +
                    '<button type="button" class="btn btn-small btn-danger" onclick="removeSecondaryAddr(this)">Remove</button>';
    container.appendChild(row);
}

function removeSecondaryAddr(btn) {
    btn.parentElement.remove();
}

function addVlan() {
    var container = document.getElementById('vlan-list');
    var row = document.createElement('div');
    row.className = 'vlan-row';
    row.innerHTML = '<input type="number" name="vlans[]" min="1" max="4094" placeholder="VLAN ID" style="width: 100px;">' +
                    '<button type="button" class="btn btn-small btn-danger" onclick="removeVlan(this)">Remove</button>';
    container.appendChild(row);
}

function removeVlan(btn) {
    btn.parentElement.remove();
}

document.addEventListener('DOMContentLoaded', function() {
    toggleConfigSections();
});
</script>

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

.secondary-addr-row,
.vlan-row {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 0.5rem;
    align-items: center;
}

.secondary-addr-row input {
    flex: 1;
    max-width: 300px;
}

code {
    background: var(--bg-dark);
    padding: 0.2rem 0.4rem;
    border-radius: 3px;
    font-family: monospace;
}

#vlan-section {
    border-left-color: var(--warning);
}
</style>
