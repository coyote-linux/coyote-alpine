<?php
$pageTitle = 'VPN';
$page = 'vpn';
$ipsecAvailable = (bool)($ipsecAvailable ?? true);
$ipsecEnabled = (bool)($ipsecEnabled ?? false);
$ipsecRunning = (bool)($ipsecRunning ?? false);
$tunnelCount = (int)($tunnelCount ?? 0);
$activeCount = (int)($activeCount ?? 0);
$openvpnAvailable = (bool)($openvpnAvailable ?? true);
$openvpnEnabled = (bool)($openvpnEnabled ?? false);
$openvpnCount = (int)($openvpnCount ?? 0);
$openvpnRunningCount = (int)($openvpnRunningCount ?? 0);
$openvpnClientCount = (int)($openvpnClientCount ?? 0);
$wireguardAvailable = (bool)($wireguardAvailable ?? true);
$wireguardEnabled = (bool)($wireguardEnabled ?? false);
$wireguardInterfaceCount = (int)($wireguardInterfaceCount ?? 0);
$wireguardRunningCount = (int)($wireguardRunningCount ?? 0);
?>

<?php if (!$ipsecAvailable && !$openvpnAvailable && !$wireguardAvailable): ?>
<div class="card">
    <h3>VPN Features Disabled</h3>
    <p>This build has all VPN features disabled.</p>
</div>
<?php else: ?>
<div class="dashboard-grid">
    <?php if ($ipsecAvailable): ?>
    <div class="card">
        <h3>IPSec</h3>
        <dl>
            <dt>Configured</dt>
            <dd>
                <span class="status-badge status-<?= $ipsecEnabled ? 'up' : 'down' ?>">
                    <?= $ipsecEnabled ? 'Enabled' : 'Disabled' ?>
                </span>
            </dd>
            <dt>Service</dt>
            <dd>
                <span class="status-badge status-<?= $ipsecRunning ? 'up' : 'down' ?>">
                    <?= $ipsecRunning ? 'Running' : 'Stopped' ?>
                </span>
            </dd>
            <dt>Tunnels</dt>
            <dd><?= $tunnelCount ?></dd>
            <dt>Established</dt>
            <dd><?= $activeCount ?></dd>
        </dl>
        <p style="margin-top: 1rem;"><a href="/vpn/ipsec" class="btn btn-primary">Manage IPSec Tunnels</a></p>
    </div>
    <?php endif; ?>

    <?php if ($openvpnAvailable): ?>
    <div class="card">
        <h3>OpenVPN</h3>
        <dl>
            <dt>Configured</dt>
            <dd>
                <span class="status-badge status-<?= $openvpnEnabled ? 'up' : 'down' ?>">
                    <?= $openvpnEnabled ? 'Enabled' : 'Disabled' ?>
                </span>
            </dd>
            <dt>Instances</dt>
            <dd><?= $openvpnCount ?></dd>
            <dt>Running</dt>
            <dd><?= $openvpnRunningCount ?></dd>
            <dt>Connected Clients</dt>
            <dd><?= $openvpnClientCount ?></dd>
        </dl>
        <p style="margin-top: 1rem;"><a href="/vpn/openvpn" class="btn btn-primary">Manage OpenVPN</a></p>
    </div>
    <?php endif; ?>

    <?php if ($wireguardAvailable): ?>
    <div class="card">
        <h3>WireGuard</h3>
        <dl>
            <dt>Configured</dt>
            <dd>
                <span class="status-badge status-<?= $wireguardEnabled ? 'up' : 'down' ?>">
                    <?= $wireguardEnabled ? 'Enabled' : 'Disabled' ?>
                </span>
            </dd>
            <dt>Interfaces</dt>
            <dd><?= $wireguardInterfaceCount ?></dd>
            <dt>Running</dt>
            <dd><?= $wireguardRunningCount ?></dd>
        </dl>
        <p style="margin-top: 1rem;"><a href="/vpn/wireguard" class="btn btn-primary">Manage WireGuard</a></p>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>
