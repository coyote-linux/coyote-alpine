<?php
$isNew = $isNew ?? true;
$acl = $acl ?? null;
$aclName = $acl['name'] ?? '';
$rule = $rule ?? null;
$ruleIndex = $ruleIndex ?? null;
$addressLists = $addressLists ?? [];

// Extract rule values
$action = $rule['action'] ?? 'permit';
$protocol = $rule['protocol'] ?? 'any';
$source = $rule['source'] ?? 'any';
$sourceList = $rule['source_list'] ?? '';
$dest = $rule['destination'] ?? 'any';
$destList = $rule['destination_list'] ?? '';
$ports = $rule['ports'] ?? '';
$comment = $rule['comment'] ?? '';

// Determine source/dest types
$sourceType = 'any';
$sourceValue = '';
if (!empty($sourceList)) {
    $sourceType = 'list';
    $sourceValue = $sourceList;
} elseif ($source !== 'any') {
    if (strpos($source, '/') !== false) {
        $sourceType = 'network';
        $sourceValue = $source;
    } else {
        $sourceType = 'ip';
        $sourceValue = $source;
    }
}

$destType = 'any';
$destValue = '';
if (!empty($destList)) {
    $destType = 'list';
    $destValue = $destList;
} elseif ($dest !== 'any') {
    if (strpos($dest, '/') !== false) {
        $destType = 'network';
        $destValue = $dest;
    } else {
        $destType = 'ip';
        $destValue = $dest;
    }
}

$pageTitle = $isNew ? 'Add Rule to ' . $aclName : 'Edit Rule in ' . $aclName;
$page = 'firewall';
?>

<div class="page-header">
    <a href="/firewall/acl/<?= urlencode($aclName) ?>" class="btn btn-small">&larr; Back to <?= htmlspecialchars($aclName) ?></a>
</div>

<div class="dashboard-grid">
    <div class="card full-width">
        <h3><?= $isNew ? 'Add New Rule' : 'Edit Rule #' . ($ruleIndex + 1) ?></h3>
        <form method="post" action="/firewall/acl/<?= urlencode($aclName) ?>/rule">
            <?php if (!$isNew): ?>
            <input type="hidden" name="rule_index" value="<?= $ruleIndex ?>">
            <?php endif; ?>

            <!-- Action -->
            <div class="form-group">
                <label>Action</label>
                <div class="radio-group">
                    <label class="radio-label">
                        <input type="radio" name="action" value="permit" <?= $action === 'permit' ? 'checked' : '' ?>>
                        <span class="radio-text permit">PERMIT</span>
                        <span class="radio-desc">Allow matching traffic</span>
                    </label>
                    <label class="radio-label">
                        <input type="radio" name="action" value="deny" <?= $action === 'deny' ? 'checked' : '' ?>>
                        <span class="radio-text deny">DENY</span>
                        <span class="radio-desc">Block matching traffic</span>
                    </label>
                </div>
            </div>

            <!-- Protocol -->
            <div class="form-group">
                <label for="protocol">Protocol</label>
                <select id="protocol" name="protocol" onchange="togglePortField()">
                    <option value="any" <?= $protocol === 'any' ? 'selected' : '' ?>>Any</option>
                    <option value="tcp" <?= $protocol === 'tcp' ? 'selected' : '' ?>>TCP</option>
                    <option value="udp" <?= $protocol === 'udp' ? 'selected' : '' ?>>UDP</option>
                    <option value="icmp" <?= $protocol === 'icmp' ? 'selected' : '' ?>>ICMP</option>
                    <option value="gre" <?= $protocol === 'gre' ? 'selected' : '' ?>>GRE</option>
                    <option value="esp" <?= $protocol === 'esp' ? 'selected' : '' ?>>ESP (IPSec)</option>
                    <option value="ah" <?= $protocol === 'ah' ? 'selected' : '' ?>>AH (IPSec)</option>
                </select>
            </div>

            <!-- Source -->
            <fieldset class="address-fieldset">
                <legend>Source</legend>
                <div class="form-group">
                    <label for="source_type">Source Type</label>
                    <select id="source_type" name="source_type" onchange="toggleAddressField('source')">
                        <option value="any" <?= $sourceType === 'any' ? 'selected' : '' ?>>Any</option>
                        <option value="ip" <?= $sourceType === 'ip' ? 'selected' : '' ?>>Single IP Address</option>
                        <option value="network" <?= $sourceType === 'network' ? 'selected' : '' ?>>Network (CIDR)</option>
                        <option value="list" <?= $sourceType === 'list' ? 'selected' : '' ?>>Address List</option>
                    </select>
                </div>
                <div class="form-group" id="source_value_group" style="<?= $sourceType === 'any' || $sourceType === 'list' ? 'display:none' : '' ?>">
                    <label for="source_value">
                        <span id="source_value_label"><?= $sourceType === 'network' ? 'Network (CIDR)' : 'IP Address' ?></span>
                    </label>
                    <input type="text" id="source_value" name="source_value"
                           value="<?= htmlspecialchars($sourceValue) ?>"
                           placeholder="<?= $sourceType === 'network' ? '192.168.1.0/24' : '192.168.1.100' ?>">
                </div>
                <div class="form-group" id="source_list_group" style="<?= $sourceType === 'list' ? '' : 'display:none' ?>">
                    <label for="source_list">Address List</label>
                    <select id="source_list" name="source_list">
                        <option value="">Select list...</option>
                        <?php foreach ($addressLists as $listName): ?>
                            <option value="<?= htmlspecialchars($listName) ?>" <?= $sourceValue === $listName ? 'selected' : '' ?>>
                                <?= htmlspecialchars($listName) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </fieldset>

            <!-- Destination -->
            <fieldset class="address-fieldset">
                <legend>Destination</legend>
                <div class="form-group">
                    <label for="dest_type">Destination Type</label>
                    <select id="dest_type" name="dest_type" onchange="toggleAddressField('dest')">
                        <option value="any" <?= $destType === 'any' ? 'selected' : '' ?>>Any</option>
                        <option value="ip" <?= $destType === 'ip' ? 'selected' : '' ?>>Single IP Address</option>
                        <option value="network" <?= $destType === 'network' ? 'selected' : '' ?>>Network (CIDR)</option>
                        <option value="list" <?= $destType === 'list' ? 'selected' : '' ?>>Address List</option>
                    </select>
                </div>
                <div class="form-group" id="dest_value_group" style="<?= $destType === 'any' || $destType === 'list' ? 'display:none' : '' ?>">
                    <label for="dest_value">
                        <span id="dest_value_label"><?= $destType === 'network' ? 'Network (CIDR)' : 'IP Address' ?></span>
                    </label>
                    <input type="text" id="dest_value" name="dest_value"
                           value="<?= htmlspecialchars($destValue) ?>"
                           placeholder="<?= $destType === 'network' ? '10.0.0.0/8' : '10.0.0.1' ?>">
                </div>
                <div class="form-group" id="dest_list_group" style="<?= $destType === 'list' ? '' : 'display:none' ?>">
                    <label for="dest_list">Address List</label>
                    <select id="dest_list" name="dest_list">
                        <option value="">Select list...</option>
                        <?php foreach ($addressLists as $listName): ?>
                            <option value="<?= htmlspecialchars($listName) ?>" <?= $destValue === $listName ? 'selected' : '' ?>>
                                <?= htmlspecialchars($listName) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </fieldset>

            <!-- Ports (TCP/UDP only) -->
            <div class="form-group" id="ports_group" style="<?= in_array($protocol, ['tcp', 'udp']) ? '' : 'display:none' ?>">
                <label for="ports">Destination Port(s)</label>
                <input type="text" id="ports" name="ports"
                       value="<?= htmlspecialchars($ports) ?>"
                       placeholder="80 or 80,443 or 80-443">
                <small class="text-muted">Single port, comma-separated, or range (e.g., 80-443). Leave blank for any port.</small>
            </div>

            <!-- Comment -->
            <div class="form-group">
                <label for="comment">Comment (optional)</label>
                <input type="text" id="comment" name="comment"
                       value="<?= htmlspecialchars($comment) ?>"
                       placeholder="Allow web traffic">
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?= $isNew ? 'Add Rule' : 'Save Changes' ?></button>
                <a href="/firewall/acl/<?= urlencode($aclName) ?>" class="btn">Cancel</a>
            </div>
        </form>
    </div>

    <!-- Rule Preview -->
    <div class="card full-width">
        <h3>Rule Preview</h3>
        <div id="rule-preview" class="rule-preview">
            <code id="preview-text"></code>
        </div>
        <small class="text-muted">This is an approximate representation of how the rule will appear.</small>
    </div>
</div>

<script>
function togglePortField() {
    var protocol = document.getElementById('protocol').value;
    var portsGroup = document.getElementById('ports_group');
    if (protocol === 'tcp' || protocol === 'udp') {
        portsGroup.style.display = '';
    } else {
        portsGroup.style.display = 'none';
    }
    updatePreview();
}

function toggleAddressField(prefix) {
    var typeSelect = document.getElementById(prefix + '_type');
    var valueGroup = document.getElementById(prefix + '_value_group');
    var listGroup = document.getElementById(prefix + '_list_group');
    var valueLabel = document.getElementById(prefix + '_value_label');
    var valueInput = document.getElementById(prefix + '_value');

    if (typeSelect.value === 'any') {
        valueGroup.style.display = 'none';
        if (listGroup) {
            listGroup.style.display = 'none';
        }
    } else if (typeSelect.value === 'list') {
        valueGroup.style.display = 'none';
        if (listGroup) {
            listGroup.style.display = '';
        }
    } else {
        valueGroup.style.display = '';
        if (listGroup) {
            listGroup.style.display = 'none';
        }
        if (typeSelect.value === 'network') {
            valueLabel.textContent = 'Network (CIDR)';
            valueInput.placeholder = '192.168.1.0/24';
        } else {
            valueLabel.textContent = 'IP Address';
            valueInput.placeholder = '192.168.1.100';
        }
    }
    updatePreview();
}

function updatePreview() {
    var action = document.querySelector('input[name="action"]:checked').value;
    var protocol = document.getElementById('protocol').value;
    var sourceType = document.getElementById('source_type').value;
    var sourceValue = document.getElementById('source_value').value;
    var sourceList = document.getElementById('source_list');
    var destType = document.getElementById('dest_type').value;
    var destValue = document.getElementById('dest_value').value;
    var destList = document.getElementById('dest_list');
    var ports = document.getElementById('ports').value;

    var source = sourceType === 'any' ? 'any' : (sourceType === 'list' ? '@' + (sourceList ? sourceList.value : '?') : (sourceValue || '?'));
    var dest = destType === 'any' ? 'any' : (destType === 'list' ? '@' + (destList ? destList.value : '?') : (destValue || '?'));

    var preview = action.toUpperCase() + ' ';
    preview += protocol.toUpperCase() + ' ';
    preview += 'FROM ' + source + ' ';
    preview += 'TO ' + dest;

    if (ports && (protocol === 'tcp' || protocol === 'udp')) {
        preview += ' PORT ' + ports;
    }

    document.getElementById('preview-text').textContent = preview;
}

// Add event listeners
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('input, select').forEach(function(el) {
        el.addEventListener('change', updatePreview);
        el.addEventListener('input', updatePreview);
    });
    updatePreview();
});
</script>

