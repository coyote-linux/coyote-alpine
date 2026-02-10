<?php

namespace Coyote\WebAdmin\Controller;

use Coyote\WebAdmin\Service\ConfigService;
use Coyote\WebAdmin\Service\ApplyService;

/**
 * Configuration apply controller.
 *
 * Dedicated page for applying, confirming, and managing configuration changes.
 */
class ApplyController extends BaseController
{
    /** @var ConfigService */
    private ConfigService $configService;

    /** @var ApplyService */
    private ApplyService $applyService;

    public function __construct()
    {
        parent::__construct();
        $this->configService = new ConfigService();
        $this->applyService = new ApplyService();
    }

    /**
     * Display the apply configuration page.
     */
    public function index(array $params = []): void
    {
        $status = $this->applyService->getStatus();
        $hasChanges = $this->configService->hasUncommittedChanges();

        $this->render('pages/apply', [
            'page' => 'apply',
            'title' => 'Apply Configuration',
            'status' => $status,
            'hasChanges' => $hasChanges,
        ]);
    }

    /**
     * Apply the working configuration.
     */
    public function apply(array $params = []): void
    {
        $result = $this->applyService->apply();

        if ($result['success']) {
            $this->flash('warning', $result['message']);
        } else {
            $this->flash('error', $result['message']);
        }

        $this->redirect('/apply');
    }

    /**
     * Confirm the applied configuration.
     */
    public function confirm(array $params = []): void
    {
        $result = $this->applyService->confirm();

        if ($result['success']) {
            $this->flash('success', $result['message']);
        } else {
            $this->flash('error', $result['message']);
        }

        $this->redirect('/apply');
    }

    /**
     * Cancel the pending configuration and rollback.
     */
    public function cancel(array $params = []): void
    {
        $result = $this->applyService->cancel();

        if ($result['success']) {
            $this->flash('info', $result['message']);
        } else {
            $this->flash('error', $result['message']);
        }

        $this->redirect('/apply');
    }

    /**
     * Discard uncommitted changes in working config.
     */
    public function discard(array $params = []): void
    {
        if ($this->configService->discardWorkingConfig()) {
            $this->flash('info', 'Uncommitted changes discarded');
        } else {
            $this->flash('error', 'Failed to discard changes');
        }

        $this->redirect('/apply');
    }

    /**
     * Get configuration status (AJAX endpoint for countdown timer).
     */
    public function status(array $params = []): void
    {
        header('Content-Type: application/json');
        echo json_encode($this->applyService->getStatus());
        exit;
    }
}
