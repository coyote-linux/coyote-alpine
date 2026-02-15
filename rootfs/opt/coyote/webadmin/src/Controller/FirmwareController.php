<?php

namespace Coyote\WebAdmin\Controller;

use Coyote\WebAdmin\Service\FirmwareService;

class FirmwareController extends BaseController
{
    private FirmwareService $firmwareService;

    public function __construct()
    {
        parent::__construct();
        $this->firmwareService = new FirmwareService();
    }

    public function index(array $params = []): void
    {
        $firmwarePath = '/mnt/boot/firmware/current.squashfs';
        $firmwareExists = file_exists($firmwarePath);

        $currentVersion = $this->firmwareService->getCurrentVersion();
        $stagedUpdate = $this->firmwareService->getStagedUpdate();

        $data = [
            'current_version' => $currentVersion,
            'firmware_date' => $firmwareExists ? date('Y-m-d H:i:s', filemtime($firmwarePath)) : 'N/A',
            'firmware_size' => $firmwareExists ? $this->formatBytes(filesize($firmwarePath)) : 'N/A',
            'staged_update' => $stagedUpdate,
        ];

        $this->render('pages/firmware', $data);
    }

    public function checkUpdate(array $params = []): void
    {
        $result = $this->firmwareService->checkForUpdates();

        if (!$result['success']) {
            $this->flash('error', $result['error']);
            $this->redirect('/firmware');
            return;
        }

        if ($result['available']) {
            $this->flash('success', "Update available: version {$result['latest_version']}");
            $_SESSION['firmware_update_info'] = $result;
        } else {
            $this->flash('info', 'Your firmware is up to date (version ' . $result['current_version'] . ')');
        }

        $this->redirect('/firmware');
    }

    public function downloadUpdate(array $params = []): void
    {
        $isAjax = $this->isAjax();
        $updateInfo = $_SESSION['firmware_update_info'] ?? null;

        if (!$updateInfo || empty($updateInfo['url'])) {
            if ($isAjax) {
                $this->json([
                    'success' => false,
                    'error' => 'No update information available. Please check for updates first.',
                ], 400);
            } else {
                $this->flash('error', 'No update information available. Please check for updates first.');
                $this->redirect('/firmware');
            }
            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $result = $this->firmwareService->downloadUpdate(
            $updateInfo['url'],
            $updateInfo['checksum'] ?? null,
            isset($updateInfo['size']) ? (int)$updateInfo['size'] : null,
            isset($updateInfo['checksum_url']) ? (string)$updateInfo['checksum_url'] : null,
            isset($updateInfo['signature_url']) ? (string)$updateInfo['signature_url'] : null
        );

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if ($result['success']) {
            unset($_SESSION['firmware_update_info']);

            if ($isAjax) {
                $this->json([
                    'success' => true,
                    'message' => 'Firmware downloaded and staged successfully. You can now apply the update.',
                ]);
                return;
            }

            $this->flash('success', 'Firmware downloaded and staged successfully. You can now apply the update.');
        } else {
            if ($isAjax) {
                $this->json([
                    'success' => false,
                    'error' => 'Download failed: ' . ($result['error'] ?? 'unknown error'),
                ], 500);
                return;
            }

            $this->flash('error', 'Download failed: ' . $result['error']);
        }

        $this->redirect('/firmware');
    }

    public function downloadProgress(array $params = []): void
    {
        $this->json($this->firmwareService->getDownloadProgress());
    }

    public function upload(array $params = []): void
    {
        if (empty($_FILES['firmware_file'])) {
            $this->flash('error', 'No file uploaded');
            $this->redirect('/firmware');
            return;
        }

        $result = $this->firmwareService->uploadUpdate($_FILES['firmware_file']);

        if ($result['success']) {
            $this->flash('success', 'Firmware uploaded and staged successfully. You can now apply the update.');
        } else {
            $this->flash('error', 'Upload failed: ' . $result['error']);
        }

        $this->redirect('/firmware');
    }

    public function applyUpdate(array $params = []): void
    {
        $result = $this->firmwareService->applyUpdate();

        if ($result['success']) {
            $this->flash('success', 'Firmware update initiated. System will reboot now...');
        } else {
            $this->flash('error', 'Failed to apply update: ' . $result['error']);
        }

        $this->redirect('/firmware');
    }

    public function clearStaged(array $params = []): void
    {
        if ($this->firmwareService->clearStaged()) {
            $this->flash('success', 'Staged firmware cleared');
        } else {
            $this->flash('error', 'Failed to clear staged firmware');
        }

        $this->redirect('/firmware');
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
        return $bytes . ' B';
    }
}
