<?php

use Coyote\System\FirmwareManager;

function firmwareMenu(): void
{
    $manager = new FirmwareManager();
    $updateInfo = null;

    while (true) {
        $staged = $manager->getStagedUpdate();

        $items = [
            'status' => ['label' => 'Current / Staged Status'],
            'check' => ['label' => 'Check for Updates'],
            'download' => ['label' => 'Download Latest Update'],
        ];

        if ($staged !== null) {
            $items['apply'] = ['label' => 'Apply Staged Update & Reboot'];
            $items['clear'] = ['label' => 'Discard Staged Update'];
        }

        $choice = showMenu($items, 'Firmware Update');

        if ($choice === null) {
            return;
        }

        switch ($choice) {
            case 'status':
                showFirmwareStatus($manager, $staged);
                break;
            case 'check':
                $updateInfo = checkFirmwareUpdates($manager);
                break;
            case 'download':
                $updateInfo = downloadLatestFirmwareUpdate($manager, $updateInfo);
                break;
            case 'apply':
                applyStagedFirmwareUpdate($manager);
                break;
            case 'clear':
                clearStagedFirmwareUpdate($manager);
                break;
        }
    }
}

function showFirmwareStatus(FirmwareManager $manager, ?array $staged): void
{
    clearScreen();
    showHeader();
    echo "Firmware Status\n";
    echo "===============\n\n";

    echo "Current version: " . $manager->getCurrentVersion() . "\n\n";

    if ($staged === null) {
        echo "Staged update: none\n";
    } else {
        echo "Staged update:\n";
        echo "  File: " . ($staged['filename'] ?? 'unknown') . "\n";
        echo "  Size: " . formatFirmwareBytes((int)($staged['size'] ?? 0)) . "\n";
        echo "  Date: " . ($staged['date'] ?? 'unknown') . "\n";
    }

    waitForEnter();
}

function checkFirmwareUpdates(FirmwareManager $manager): ?array
{
    clearScreen();
    showHeader();
    echo "Check for Firmware Updates\n";
    echo "==========================\n\n";
    showInfo("Checking update server...");

    $result = $manager->checkForUpdates();

    echo "\n";

    if (!$result['success']) {
        showError($result['error'] ?? 'Failed to check for updates');
        waitForEnter();
        return null;
    }

    echo "Current version: " . ($result['current_version'] ?? 'unknown') . "\n";
    echo "Latest version: " . ($result['latest_version'] ?? 'unknown') . "\n";

    if (!empty($result['size'])) {
        echo "Update size: " . formatFirmwareBytes((int)$result['size']) . "\n";
    }

    echo "\n";

    if (!($result['available'] ?? false)) {
        showInfo('No updates available. Firmware is up to date.');
        waitForEnter();
        return null;
    }

    showSuccess('Update is available and ready to download.');
    waitForEnter();
    return $result;
}

function downloadLatestFirmwareUpdate(FirmwareManager $manager, ?array $updateInfo): ?array
{
    if ($updateInfo === null || empty($updateInfo['url'])) {
        $result = $manager->checkForUpdates();
        if (!$result['success']) {
            clearScreen();
            showHeader();
            echo "Download Firmware Update\n";
            echo "========================\n\n";
            showError($result['error'] ?? 'Failed to check for updates');
            waitForEnter();
            return null;
        }

        if (!($result['available'] ?? false) || empty($result['url'])) {
            clearScreen();
            showHeader();
            echo "Download Firmware Update\n";
            echo "========================\n\n";
            showInfo('No downloadable firmware update is currently available.');
            waitForEnter();
            return null;
        }

        $updateInfo = $result;
    }

    clearScreen();
    showHeader();
    echo "Download Firmware Update\n";
    echo "========================\n\n";
    echo "Latest version: " . ($updateInfo['latest_version'] ?? 'unknown') . "\n";
    if (!empty($updateInfo['size'])) {
        echo "Size: " . formatFirmwareBytes((int)$updateInfo['size']) . "\n";
    }
    echo "\n";

    if (!confirm('Download and stage this update now?')) {
        return $updateInfo;
    }

    showInfo('Downloading firmware archive. This may take a while...');
    echo "\n";
    $download = $manager->downloadUpdate(
        (string)$updateInfo['url'],
        isset($updateInfo['checksum']) ? (string)$updateInfo['checksum'] : null,
        isset($updateInfo['size']) ? (int)$updateInfo['size'] : null,
        isset($updateInfo['checksum_url']) ? (string)$updateInfo['checksum_url'] : null,
        isset($updateInfo['signature_url']) ? (string)$updateInfo['signature_url'] : null,
        static function (array $progress): void {
            renderFirmwareDownloadProgress($progress);
        }
    );

    echo "\n";

    if (!$download['success']) {
        showError('Download failed: ' . ($download['error'] ?? 'unknown error'));
        waitForEnter();
        return $updateInfo;
    }

    showSuccess('Firmware downloaded and staged successfully.');
    showInfo('Use "Apply Staged Update & Reboot" to activate it.');
    waitForEnter();
    return null;
}

function applyStagedFirmwareUpdate(FirmwareManager $manager): void
{
    clearScreen();
    showHeader();
    echo "Apply Staged Firmware Update\n";
    echo "============================\n\n";

    if (!confirm('Apply staged update and reboot now?')) {
        return;
    }

    $result = $manager->applyUpdate();

    if (!$result['success']) {
        showError('Failed to apply update: ' . ($result['error'] ?? 'unknown error'));
        waitForEnter();
        return;
    }

    showSuccess('Firmware update initiated. System rebooting...');
    waitForEnter();
}

function clearStagedFirmwareUpdate(FirmwareManager $manager): void
{
    clearScreen();
    showHeader();
    echo "Discard Staged Firmware Update\n";
    echo "==============================\n\n";

    if (!confirm('Discard the currently staged firmware update?')) {
        return;
    }

    if ($manager->clearStaged()) {
        showSuccess('Staged firmware update discarded.');
    } else {
        showError('Failed to discard staged firmware update.');
    }

    waitForEnter();
}

function renderFirmwareDownloadProgress(array $progress): void
{
    $phase = (string)($progress['phase'] ?? 'downloading');
    $message = (string)($progress['message'] ?? 'Downloading firmware');
    $percent = isset($progress['percent']) ? (float)$progress['percent'] : 0.0;
    $downloaded = isset($progress['downloaded_bytes']) ? (int)$progress['downloaded_bytes'] : 0;
    $total = isset($progress['total_bytes']) ? (int)$progress['total_bytes'] : 0;

    if ($percent < 0) {
        $percent = 0;
    }
    if ($percent > 100) {
        $percent = 100;
    }

    $details = '';
    if ($phase === 'downloading' && $total > 0) {
        $details = ' (' . formatFirmwareBytes($downloaded) . ' / ' . formatFirmwareBytes($total) . ')';
    } elseif ($phase === 'downloading' && $downloaded > 0) {
        $details = ' (' . formatFirmwareBytes($downloaded) . ')';
    }

    $line = sprintf("\r[%6.1f%%] %s%s", $percent, $message, $details);
    $line = str_pad($line, 96, ' ');

    echo $line;
    if (function_exists('ob_flush')) {
        @ob_flush();
    }
    flush();
}

function formatFirmwareBytes(int $bytes): string
{
    if ($bytes >= 1073741824) {
        return round($bytes / 1073741824, 2) . ' GB';
    }
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 2) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024, 2) . ' KB';
    }
    return $bytes . ' B';
}
