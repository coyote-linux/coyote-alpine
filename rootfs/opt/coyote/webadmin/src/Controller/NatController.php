<?php

namespace Coyote\WebAdmin\Controller;

/**
 * NAT configuration controller.
 */
class NatController extends BaseController
{
    /**
     * Display NAT overview.
     */
    public function index(array $params = []): void
    {
        $data = [
            'forward_count' => 0,
        ];

        $this->render('pages/nat', $data);
    }

    /**
     * Save port forwards.
     */
    public function saveForwards(array $params = []): void
    {
        $this->flash('warning', 'NAT configuration not yet implemented');
        $this->redirect('/nat');
    }
}
