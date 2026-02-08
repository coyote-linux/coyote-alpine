<?php

namespace Coyote\WebAdmin\Controller;

/**
 * Controller for network administration utility tools.
 */
class ToolsController extends BaseController
{
    /**
     * Display the tools index page.
     *
     * @return void
     */
    public function index(): void
    {
        $this->render('pages/tools/index', [
            'pageTitle' => 'Tools',
            'page' => 'tools',
        ]);
    }

    /**
     * Display the IP subnet calculator.
     *
     * @return void
     */
    public function subnetCalculator(): void
    {
        $this->render('pages/tools/subnet', [
            'pageTitle' => 'IP Subnet Calculator',
            'page' => 'tools',
        ]);
    }

    /**
     * Display the password generator.
     *
     * @return void
     */
    public function passwordGenerator(): void
    {
        $this->render('pages/tools/password', [
            'pageTitle' => 'Password Generator',
            'page' => 'tools',
        ]);
    }
}
