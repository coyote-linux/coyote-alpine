<?php

namespace Coyote\WebAdmin\Controller;

/**
 * Firmware management controller.
 */
class FirmwareController extends BaseController
{
    /**
     * Display firmware status.
     */
    public function index(array $params = []): void
    {
        $firmwarePath = '/mnt/boot/firmware/current.squashfs';
        $firmwareExists = file_exists($firmwarePath);

        $data = [
            'firmware_date' => $firmwareExists ? date('Y-m-d H:i:s', filemtime($firmwarePath)) : 'N/A',
            'firmware_size' => $firmwareExists ? $this->formatBytes(filesize($firmwarePath)) : 'N/A',
        ];

        $this->render('pages/firmware', $data);
    }

    /**
     * Handle firmware upload.
     */
    public function upload(array $params = []): void
    {
        $this->flash('warning', 'Firmware upload not yet implemented');
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
