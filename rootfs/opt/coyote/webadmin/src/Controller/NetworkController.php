<?php

namespace Coyote\WebAdmin\Controller;

use Coyote\System\Network;

/**
 * Network configuration controller.
 */
class NetworkController extends BaseController
{
    /**
     * Display network overview.
     */
    public function index(array $params = []): void
    {
        $network = new Network();

        $data = [
            'interfaces' => $network->getInterfaces(),
            'routes' => $network->getRoutes(),
        ];

        $this->render('pages/network', $data);
    }

    /**
     * Display interface configuration.
     */
    public function interfaces(array $params = []): void
    {
        $network = new Network();

        $data = [
            'interfaces' => $network->getInterfaces(),
        ];

        $this->render('pages/network', $data);
    }

    /**
     * Save interface configuration.
     */
    public function saveInterfaces(array $params = []): void
    {
        $this->flash('warning', 'Interface configuration not yet implemented');
        $this->redirect('/network/interfaces');
    }
}
