<?php

namespace Coyote\WebAdmin\Controller;

use Coyote\System\Network;
use Coyote\WebAdmin\Service\ConfigService;
use Coyote\WebAdmin\Service\ApplyService;

/**
 * Network configuration controller.
 */
class NetworkController extends BaseController
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
     * Display network overview.
     */
    public function index(array $params = []): void
    {
        $network = new Network();

        // Get system interfaces (excluding loopback)
        $systemInterfaces = $network->getInterfaces();
        unset($systemInterfaces['lo']);

        // Get configured interfaces from working config
        $config = $this->configService->getWorkingConfig();
        $configuredInterfaces = $config->get('network.interfaces', []);

        // Index configured interfaces by name for easy lookup
        $configByName = [];
        foreach ($configuredInterfaces as $iface) {
            if (isset($iface['name'])) {
                $configByName[$iface['name']] = $iface;
            }
        }

        // Merge system state with configuration
        $interfaces = [];
        foreach ($systemInterfaces as $name => $sysIface) {
            $interfaces[$name] = [
                'name' => $name,
                'mac' => $sysIface['mac'] ?? '',
                'state' => $sysIface['state'] ?? 'down',
                'ipv4' => $sysIface['ipv4'] ?? [],
                'mtu' => $sysIface['mtu'] ?? 1500,
                'stats' => $sysIface['stats'] ?? ['rx_bytes' => 0, 'tx_bytes' => 0],
                'configured' => isset($configByName[$name]),
                'config' => $configByName[$name] ?? null,
            ];
        }

        // Get apply status
        $applyStatus = $this->applyService->getStatus();

        $data = [
            'interfaces' => $interfaces,
            'routes' => $network->getRoutes(),
            'applyStatus' => $applyStatus,
        ];

        $this->render('pages/network', $data);
    }

    /**
     * Display interface configuration.
     */
    public function interfaces(array $params = []): void
    {
        $this->index($params);
    }

    /**
     * Edit a single interface.
     */
    public function editInterface(array $params = []): void
    {
        $ifaceName = $params['name'] ?? '';

        // Validate interface exists in system
        $network = new Network();
        $systemInterfaces = $network->getInterfaces();

        if (!isset($systemInterfaces[$ifaceName]) || $ifaceName === 'lo') {
            $this->flash('error', 'Interface not found: ' . $ifaceName);
            $this->redirect('/network');
            return;
        }

        // Get current configuration
        $config = $this->configService->getWorkingConfig();
        $configuredInterfaces = $config->get('network.interfaces', []);

        // Find this interface's configuration
        $ifaceConfig = null;
        foreach ($configuredInterfaces as $iface) {
            if (($iface['name'] ?? '') === $ifaceName) {
                $ifaceConfig = $iface;
                break;
            }
        }

        // Default config for unconfigured interface
        if ($ifaceConfig === null) {
            $ifaceConfig = [
                'name' => $ifaceName,
                'type' => 'disabled',
                'address' => '',
                'gateway' => '',
            ];
        }

        $sysIface = $systemInterfaces[$ifaceName];

        $data = [
            'interface' => [
                'name' => $ifaceName,
                'mac' => $sysIface['mac'] ?? '',
                'state' => $sysIface['state'] ?? 'down',
                'currentIpv4' => $sysIface['ipv4'] ?? [],
            ],
            'config' => $ifaceConfig,
        ];

        $this->render('pages/network/interface-edit', $data);
    }

    /**
     * Save interface configuration.
     */
    public function saveInterface(array $params = []): void
    {
        $ifaceName = $params['name'] ?? '';

        // Validate interface exists
        $network = new Network();
        $systemInterfaces = $network->getInterfaces();

        if (!isset($systemInterfaces[$ifaceName]) || $ifaceName === 'lo') {
            $this->flash('error', 'Interface not found: ' . $ifaceName);
            $this->redirect('/network');
            return;
        }

        // Get form data
        $type = $this->post('type', 'disabled');
        $address = trim($this->post('address', ''));
        $gateway = trim($this->post('gateway', ''));

        // Validation
        $errors = [];

        if (!in_array($type, ['static', 'dhcp', 'disabled'], true)) {
            $errors[] = 'Invalid interface type';
        }

        if ($type === 'static') {
            if (empty($address)) {
                $errors[] = 'IP address is required for static configuration';
            } elseif (!$this->isValidCidr($address)) {
                $errors[] = 'Invalid IP address format. Use CIDR notation (e.g., 192.168.1.1/24)';
            }

            if (!empty($gateway) && !filter_var($gateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $errors[] = 'Invalid gateway IP address';
            }
        }

        if (!empty($errors)) {
            $this->flash('error', implode('. ', $errors));
            $this->redirect('/network/interface/' . urlencode($ifaceName));
            return;
        }

        // Build new interface config
        $newIfaceConfig = [
            'name' => $ifaceName,
            'type' => $type,
        ];

        if ($type === 'static') {
            $newIfaceConfig['address'] = $address;
            if (!empty($gateway)) {
                $newIfaceConfig['gateway'] = $gateway;
            }
        }

        // Update working config
        $config = $this->configService->getWorkingConfig();
        $interfaces = $config->get('network.interfaces', []);

        // Find and update or add the interface
        $found = false;
        foreach ($interfaces as $i => $iface) {
            if (($iface['name'] ?? '') === $ifaceName) {
                $interfaces[$i] = $newIfaceConfig;
                $found = true;
                break;
            }
        }

        if (!$found) {
            $interfaces[] = $newIfaceConfig;
        }

        $config->set('network.interfaces', $interfaces);

        if ($this->configService->saveWorkingConfig($config)) {
            $this->flash('success', "Interface {$ifaceName} configuration saved. Click \"Apply Configuration\" to activate changes.");
        } else {
            $this->flash('error', 'Failed to save configuration');
        }

        $this->redirect('/network');
    }

    /**
     * Delete interface configuration (set to unconfigured).
     */
    public function deleteInterface(array $params = []): void
    {
        $ifaceName = $params['name'] ?? '';

        // Validate interface exists
        $network = new Network();
        $systemInterfaces = $network->getInterfaces();

        if (!isset($systemInterfaces[$ifaceName]) || $ifaceName === 'lo') {
            $this->flash('error', 'Interface not found: ' . $ifaceName);
            $this->redirect('/network');
            return;
        }

        // Update working config - remove the interface
        $config = $this->configService->getWorkingConfig();
        $interfaces = $config->get('network.interfaces', []);

        $interfaces = array_filter($interfaces, function ($iface) use ($ifaceName) {
            return ($iface['name'] ?? '') !== $ifaceName;
        });

        // Re-index array
        $interfaces = array_values($interfaces);

        $config->set('network.interfaces', $interfaces);

        if ($this->configService->saveWorkingConfig($config)) {
            $this->flash('success', "Interface {$ifaceName} configuration removed. Click \"Apply Configuration\" to activate changes.");
        } else {
            $this->flash('error', 'Failed to save configuration');
        }

        $this->redirect('/network');
    }

    /**
     * Save interface configuration (legacy route).
     */
    public function saveInterfaces(array $params = []): void
    {
        $this->flash('info', 'Please use the interface edit page to configure individual interfaces.');
        $this->redirect('/network');
    }

    /**
     * Validate CIDR notation (e.g., 192.168.1.1/24).
     */
    private function isValidCidr(string $cidr): bool
    {
        if (strpos($cidr, '/') === false) {
            return false;
        }

        [$ip, $prefix] = explode('/', $cidr, 2);

        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        if (!is_numeric($prefix) || $prefix < 0 || $prefix > 32) {
            return false;
        }

        return true;
    }
}
