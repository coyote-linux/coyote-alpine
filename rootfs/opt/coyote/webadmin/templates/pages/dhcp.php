<?php $pageTitle = 'DHCP Server'; $page = 'dhcp'; ?>

<div class="dashboard-grid">
    <div class="card full-width">
        <h3>DHCP Server Configuration</h3>
        <form method="post" action="/dhcp">
            <div class="form-group-inline">
                <label>
                    <input type="checkbox" name="enabled" value="1" <?= ($dhcpConfig['enabled'] ?? false) ? 'checked' : '' ?>>
                    Enable DHCP Server
                </label>
            </div>

            <div class="form-group">
                <label for="interface">Interface</label>
                <select id="interface" name="interface" required>
                    <option value="">Select an interface</option>
                    <?php foreach ($availableInterfaces ?? [] as $iface): ?>
                    <option value="<?= htmlspecialchars($iface) ?>" <?= ($dhcpConfig['interface'] ?? '') === $iface ? 'selected' : '' ?>>
                        <?= htmlspecialchars($iface) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <small>Select the network interface to serve DHCP requests on</small>
            </div>

            <div class="form-group">
                <label for="domain">Domain Name</label>
                <input type="text" id="domain" name="domain" value="<?= htmlspecialchars($dhcpConfig['domain'] ?? '') ?>" placeholder="local.lan">
                <small>Optional domain name to provide to DHCP clients</small>
            </div>

            <div class="form-group">
                <label for="range_start">IP Range Start</label>
                <input type="text" id="range_start" name="range_start" value="<?= htmlspecialchars($dhcpConfig['range_start'] ?? '') ?>" placeholder="192.168.1.100" required>
            </div>

            <div class="form-group">
                <label for="range_end">IP Range End</label>
                <input type="text" id="range_end" name="range_end" value="<?= htmlspecialchars($dhcpConfig['range_end'] ?? '') ?>" placeholder="192.168.1.200" required>
            </div>

            <div class="form-group">
                <label for="subnet_mask">Subnet Mask</label>
                <input type="text" id="subnet_mask" name="subnet_mask" value="<?= htmlspecialchars($dhcpConfig['subnet_mask'] ?? '') ?>" placeholder="255.255.255.0">
                <small>Optional subnet mask to provide to DHCP clients</small>
            </div>

            <div class="form-group">
                <label for="gateway">Gateway</label>
                <input type="text" id="gateway" name="gateway" value="<?= htmlspecialchars($dhcpConfig['gateway'] ?? '') ?>" placeholder="192.168.1.1">
                <small>Optional gateway (router) address to provide to DHCP clients</small>
            </div>

            <div class="form-group">
                <label for="dns1">Primary DNS Server</label>
                <input type="text" id="dns1" name="dns1" value="<?= htmlspecialchars(($dhcpConfig['dns_servers'][0] ?? '')) ?>" placeholder="1.1.1.1">
            </div>

            <div class="form-group">
                <label for="dns2">Secondary DNS Server</label>
                <input type="text" id="dns2" name="dns2" value="<?= htmlspecialchars(($dhcpConfig['dns_servers'][1] ?? '')) ?>" placeholder="8.8.8.8">
            </div>

            <div class="form-group">
                <label for="lease_time">Lease Time (seconds)</label>
                <input type="number" id="lease_time" name="lease_time" value="<?= htmlspecialchars($dhcpConfig['lease_time'] ?? '86400') ?>" min="120" required>
                <small>Default: 86400 (24 hours). Minimum: 120 seconds.</small>
            </div>

            <button type="submit" class="btn btn-primary">Save DHCP Settings</button>
            <small class="form-note">Changes are saved but not applied until you click "Apply Configuration"</small>
        </form>
    </div>

    <div class="card full-width">
        <h3>DHCP Reservations</h3>
        <p>Configure static IP address assignments based on MAC addresses.</p>
        <p><strong>Current reservations:</strong> <?= $reservationCount ?? 0 ?></p>
        <a href="/dhcp/reservations" class="btn btn-primary">Manage Reservations</a>
    </div>
</div>
