<?php
$isNew = (bool)($isNew ?? false);
$tunnel = is_array($tunnel ?? null) ? $tunnel : [];
$serverCerts = is_array($serverCerts ?? null) ? $serverCerts : [];

$name = (string)($tunnel['name'] ?? '');
$enabled = (bool)($tunnel['enabled'] ?? true);
$remoteAddress = (string)($tunnel['remote_address'] ?? '');
$localAddress = (string)($tunnel['local_address'] ?? '%any');
$authMethod = (string)($tunnel['auth_method'] ?? 'psk');
$psk = (string)($tunnel['psk'] ?? '');
$certificateId = (string)($tunnel['certificate_id'] ?? '');
$localId = (string)($tunnel['local_id'] ?? '');
$remoteId = (string)($tunnel['remote_id'] ?? '');
$localTs = (string)($tunnel['local_ts'] ?? '');
$remoteTs = (string)($tunnel['remote_ts'] ?? '');
$ikeVersion = (int)($tunnel['ike_version'] ?? 2);
$proposals = $tunnel['proposals'] ?? 'aes256-sha256-modp2048';
$espProposals = $tunnel['esp_proposals'] ?? 'aes256-sha256';

if (is_array($proposals)) {
    $proposals = implode(',', $proposals);
}

if (is_array($espProposals)) {
    $espProposals = implode(',', $espProposals);
}

$startAction = (string)($tunnel['start_action'] ?? 'trap');
$dpdAction = (string)($tunnel['dpd_action'] ?? 'restart');
$closeAction = (string)($tunnel['close_action'] ?? 'restart');

$pageTitle = $isNew ? 'New IPSec Tunnel' : 'Edit IPSec Tunnel: ' . $name;
$page = 'vpn';
?>

<div class="page-header">
    <a href="/vpn/ipsec" class="btn btn-small">&larr; Back to IPSec Tunnels</a>
</div>

<div class="card">
    <h3><?= $isNew ? 'Create IPSec Tunnel' : 'Edit IPSec Tunnel' ?></h3>
    <form method="post" action="<?= $isNew ? '/vpn/ipsec/new' : '/vpn/ipsec/' . urlencode($name) ?>">
        <?php if ($isNew): ?>
        <div class="form-group">
            <label for="name">Name</label>
            <input
                type="text"
                id="name"
                name="name"
                required
                pattern="[a-zA-Z][a-zA-Z0-9_-]*"
                value="<?= htmlspecialchars($name) ?>"
            >
        </div>
        <?php else: ?>
        <div class="form-group">
            <label for="name_display">Name</label>
            <input type="text" id="name_display" value="<?= htmlspecialchars($name) ?>" disabled>
            <input type="hidden" name="name" value="<?= htmlspecialchars($name) ?>">
        </div>
        <?php endif; ?>

        <div class="form-group-inline">
            <label>
                <input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
                Enabled
            </label>
        </div>

        <div class="form-group">
            <label for="remote_address">Remote Address</label>
            <input type="text" id="remote_address" name="remote_address" required value="<?= htmlspecialchars($remoteAddress) ?>">
        </div>

        <div class="form-group">
            <label for="local_address">Local Address</label>
            <input type="text" id="local_address" name="local_address" value="<?= htmlspecialchars($localAddress) ?>">
        </div>

        <div class="form-group">
            <label for="auth_method">Authentication Method</label>
            <select id="auth_method" name="auth_method">
                <option value="psk" <?= $authMethod === 'psk' ? 'selected' : '' ?>>Pre-Shared Key</option>
                <option value="cert" <?= $authMethod === 'cert' ? 'selected' : '' ?>>X.509 Certificate</option>
            </select>
        </div>

        <div class="form-group" id="psk_group">
            <label for="psk">Pre-Shared Key</label>
            <input type="text" id="psk" name="psk" value="<?= htmlspecialchars($psk) ?>">
        </div>

        <div class="form-group" id="cert_group">
            <label for="certificate_id">Certificate</label>
            <select id="certificate_id" name="certificate_id">
                <option value="">Select a server certificate</option>
                <?php foreach ($serverCerts as $cert): ?>
                <?php
                $certId = (string)($cert['id'] ?? '');
                $certName = (string)($cert['name'] ?? $certId);
                ?>
                <option value="<?= htmlspecialchars($certId) ?>" <?= $certificateId === $certId ? 'selected' : '' ?>>
                    <?= htmlspecialchars($certName) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="local_id">Local ID</label>
            <input type="text" id="local_id" name="local_id" value="<?= htmlspecialchars($localId) ?>">
        </div>

        <div class="form-group">
            <label for="remote_id">Remote ID</label>
            <input type="text" id="remote_id" name="remote_id" value="<?= htmlspecialchars($remoteId) ?>">
        </div>

        <div class="form-group">
            <label for="local_ts">Local Network</label>
            <input type="text" id="local_ts" name="local_ts" required value="<?= htmlspecialchars($localTs) ?>">
        </div>

        <div class="form-group">
            <label for="remote_ts">Remote Network</label>
            <input type="text" id="remote_ts" name="remote_ts" required value="<?= htmlspecialchars($remoteTs) ?>">
        </div>

        <div class="form-group">
            <label for="ike_version">IKE Version</label>
            <select id="ike_version" name="ike_version">
                <option value="1" <?= $ikeVersion === 1 ? 'selected' : '' ?>>1</option>
                <option value="2" <?= $ikeVersion === 2 ? 'selected' : '' ?>>2</option>
            </select>
        </div>

        <div class="form-group">
            <label for="proposals">IKE Proposals</label>
            <input type="text" id="proposals" name="proposals" value="<?= htmlspecialchars((string)$proposals) ?>">
        </div>

        <div class="form-group">
            <label for="esp_proposals">ESP Proposals</label>
            <input type="text" id="esp_proposals" name="esp_proposals" value="<?= htmlspecialchars((string)$espProposals) ?>">
        </div>

        <div class="form-group">
            <label for="start_action">Start Action</label>
            <select id="start_action" name="start_action">
                <option value="none" <?= $startAction === 'none' ? 'selected' : '' ?>>none</option>
                <option value="trap" <?= $startAction === 'trap' ? 'selected' : '' ?>>trap</option>
                <option value="start" <?= $startAction === 'start' ? 'selected' : '' ?>>start</option>
            </select>
        </div>

        <div class="form-group">
            <label for="dpd_action">DPD Action</label>
            <select id="dpd_action" name="dpd_action">
                <option value="none" <?= $dpdAction === 'none' ? 'selected' : '' ?>>none</option>
                <option value="clear" <?= $dpdAction === 'clear' ? 'selected' : '' ?>>clear</option>
                <option value="restart" <?= $dpdAction === 'restart' ? 'selected' : '' ?>>restart</option>
            </select>
        </div>

        <div class="form-group">
            <label for="close_action">Close Action</label>
            <select id="close_action" name="close_action">
                <option value="none" <?= $closeAction === 'none' ? 'selected' : '' ?>>none</option>
                <option value="clear" <?= $closeAction === 'clear' ? 'selected' : '' ?>>clear</option>
                <option value="restart" <?= $closeAction === 'restart' ? 'selected' : '' ?>>restart</option>
            </select>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= $isNew ? 'Create Tunnel' : 'Save Tunnel' ?></button>
            <a href="/vpn/ipsec" class="btn">Cancel</a>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var authMethod = document.getElementById('auth_method');
    var pskGroup = document.getElementById('psk_group');
    var certGroup = document.getElementById('cert_group');
    var pskInput = document.getElementById('psk');
    var certSelect = document.getElementById('certificate_id');

    var updateAuthFields = function () {
        var method = authMethod.value;

        if (method === 'cert') {
            pskGroup.style.display = 'none';
            certGroup.style.display = 'block';
            pskInput.required = false;
            certSelect.required = true;
            return;
        }

        pskGroup.style.display = 'block';
        certGroup.style.display = 'none';
        pskInput.required = true;
        certSelect.required = false;
    };

    authMethod.addEventListener('change', updateAuthFields);
    updateAuthFields();
});
</script>
