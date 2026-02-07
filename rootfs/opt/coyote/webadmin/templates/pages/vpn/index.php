<?php
$pageTitle = 'VPN';
$page = 'vpn';
$ipsecEnabled = (bool)($ipsecEnabled ?? false);
$ipsecRunning = (bool)($ipsecRunning ?? false);
$tunnelCount = (int)($tunnelCount ?? 0);
$activeCount = (int)($activeCount ?? 0);
$openvpnEnabled = (bool)($openvpnEnabled ?? false);
$openvpnCount = (int)($openvpnCount ?? 0);
$openvpnRunningCount = (int)($openvpnRunningCount ?? 0);
$openvpnClientCount = (int)($openvpnClientCount ?? 0);
$wireguardEnabled = (bool)($wireguardEnabled ?? false);
$wireguardInterfaceCount = (int)($wireguardInterfaceCount ?? 0);
$wireguardRunningCount = (int)($wireguardRunningCount ?? 0);
?>

<div class="dashboard-grid">
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
</div>
