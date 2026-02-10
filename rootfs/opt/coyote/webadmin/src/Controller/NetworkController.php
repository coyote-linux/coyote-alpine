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
            $ifaceConfig = $configByName[$name] ?? null;
            $interfaces[$name] = [
                'name' => $name,
                'mac' => $sysIface['mac'] ?? '',
                'state' => $sysIface['state'] ?? 'down',
                'ipv4' => $sysIface['ipv4'] ?? [],
                'mtu' => $sysIface['mtu'] ?? 1500,
                'stats' => $sysIface['stats'] ?? ['rx_bytes' => 0, 'tx_bytes' => 0],
                'configured' => $ifaceConfig !== null,
                'config' => $ifaceConfig,
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

        // Check if any interface already has dynamic addressing (DHCP or PPPoE)
        $hasDynamicInterface = false;
        $dynamicInterfaceName = '';
        foreach ($configuredInterfaces as $iface) {
            $type = $iface['type'] ?? 'disabled';
            if ($type === 'dhcp' || $type === 'pppoe') {
                $hasDynamicInterface = true;
                $dynamicInterfaceName = $iface['name'] ?? '';
                break;
            }
        }

        // Default config for unconfigured interface
        if ($ifaceConfig === null) {
            $ifaceConfig = [
                'name' => $ifaceName,
                'type' => 'disabled',
                'enabled' => true,
                'mtu' => 1500,
                'mac_override' => '',
                'addresses' => [],
                'dhcp_hostname' => '',
                'pppoe_username' => '',
                'pppoe_password' => '',
                'vlans' => [],
            ];
        }

        $sysIface = $systemInterfaces[$ifaceName];

        // Check if this is a VLAN interface (contains a dot)
        $isVlanInterface = strpos($ifaceName, '.') !== false;

        $data = [
            'interface' => [
                'name' => $ifaceName,
                'mac' => $sysIface['mac'] ?? '',
                'state' => $sysIface['state'] ?? 'down',
                'currentIpv4' => $sysIface['ipv4'] ?? [],
                'isVlan' => $isVlanInterface,
            ],
            'config' => $ifaceConfig,
            'hasDynamicInterface' => $hasDynamicInterface,
            'dynamicInterfaceName' => $dynamicInterfaceName,
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
        $enabled = $this->post('enabled', '') === '1';
        $mtu = (int) $this->post('mtu', '1500');
        $macOverride = trim($this->post('mac_override', ''));

        // Static IP fields
        $primaryAddress = trim($this->post('address', ''));
        $secondaryAddresses = $this->post('secondary_addresses', []);

        // DHCP fields
        $dhcpHostname = trim($this->post('dhcp_hostname', ''));

        // PPPoE fields
        $pppoeUsername = trim($this->post('pppoe_username', ''));
        $pppoePassword = trim($this->post('pppoe_password', ''));

        // VLAN fields
        $vlans = array_filter(array_map('intval', $this->post('vlans', [])));

        // Validation
        $errors = [];

        if (!in_array($type, ['static', 'dhcp', 'pppoe', 'bridge', 'disabled'], true)) {
            $errors[] = 'Invalid configuration type';
        }

        // MTU validation
        if ($mtu < 576 || $mtu > 9000) {
            $errors[] = 'MTU must be between 576 and 9000';
        }

        // MAC address validation
        if (!empty($macOverride) && !$this->isValidMac($macOverride)) {
            $errors[] = 'Invalid MAC address format';
        }

        // Static IP validation
        if ($type === 'static') {
            if (empty($primaryAddress)) {
                $errors[] = 'Primary IP address is required for static configuration';
            } elseif (!$this->isValidCidr($primaryAddress)) {
                $errors[] = 'Invalid primary IP address format. Use CIDR notation (e.g., 192.168.1.1/24)';
            }

            // Validate secondary addresses
            foreach ($secondaryAddresses as $addr) {
                $addr = trim($addr);
                if (!empty($addr) && !$this->isValidCidr($addr)) {
                    $errors[] = "Invalid secondary IP address: {$addr}";
                }
            }
        }

        // DHCP/PPPoE - check that no other interface uses dynamic addressing
        if ($type === 'dhcp' || $type === 'pppoe') {
            $config = $this->configService->getWorkingConfig();
            $interfaces = $config->get('network.interfaces', []);

            foreach ($interfaces as $iface) {
                if (($iface['name'] ?? '') === $ifaceName) {
                    continue; // Skip self
                }
                $existingType = $iface['type'] ?? 'disabled';
                if ($existingType === 'dhcp' || $existingType === 'pppoe') {
                    $errors[] = "Another interface ({$iface['name']}) already uses dynamic addressing. Only one interface can use DHCP or PPPoE.";
                    break;
                }
            }
        }

        // PPPoE validation
        if ($type === 'pppoe') {
            if (empty($pppoeUsername)) {
                $errors[] = 'PPPoE username is required';
            }
            if (empty($pppoePassword)) {
                $errors[] = 'PPPoE password is required';
            }
        }

        // VLAN validation
        foreach ($vlans as $vlanId) {
            if ($vlanId < 1 || $vlanId > 4094) {
                $errors[] = "Invalid VLAN ID: {$vlanId}. Must be between 1 and 4094.";
            }
        }

        // VLANs only allowed on static interfaces
        if (!empty($vlans) && $type !== 'static') {
            $errors[] = 'VLAN sub-interfaces can only be configured on static interfaces';
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
            'enabled' => $enabled,
            'mtu' => $mtu,
        ];

        // Add MAC override if specified
        if (!empty($macOverride)) {
            $newIfaceConfig['mac_override'] = $macOverride;
        }

        // Add type-specific fields
        if ($type === 'static') {
            $addresses = [$primaryAddress];
            foreach ($secondaryAddresses as $addr) {
                $addr = trim($addr);
                if (!empty($addr)) {
                    $addresses[] = $addr;
                }
            }
            $newIfaceConfig['addresses'] = $addresses;

            if (!empty($vlans)) {
                $newIfaceConfig['vlans'] = array_values(array_unique($vlans));
            }
        } elseif ($type === 'dhcp') {
            if (!empty($dhcpHostname)) {
                $newIfaceConfig['dhcp_hostname'] = $dhcpHostname;
            }
        } elseif ($type === 'pppoe') {
            $newIfaceConfig['pppoe_username'] = $pppoeUsername;
            $newIfaceConfig['pppoe_password'] = $pppoePassword;
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

    /**
     * Validate MAC address format.
     */
    private function isValidMac(string $mac): bool
    {
        // Accept formats: 00:00:00:00:00:00 or 00-00-00-00-00-00
        return preg_match('/^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/', $mac) === 1;
    }

    /**
     * Display static routes configuration.
     */
    public function routes(array $params = []): void
    {
        $config = $this->configService->getWorkingConfig();
        $routes = $config->get('network.routes', []);
        $interfaces = $config->get('network.interfaces', []);

        // Build list of interface names for dropdown
        $interfaceNames = [];
        foreach ($interfaces as $iface) {
            if (!empty($iface['name'])) {
                $interfaceNames[] = $iface['name'];
            }
        }

        $this->render('pages/network/routes', [
            'page' => 'network',
            'title' => 'Static Routes',
            'routes' => $routes,
            'interfaceNames' => $interfaceNames,
        ]);
    }

    /**
     * Save static routes configuration.
     */
    public function saveRoutes(array $params = []): void
    {
        $destinations = $this->post('destination', []);
        $gateways = $this->post('gateway', []);
        $metrics = $this->post('metric', []);
        $devices = $this->post('device', []);

        $errors = [];
        $routes = [];

        if (!is_array($destinations)) {
            $destinations = [];
        }
        if (!is_array($gateways)) {
            $gateways = [];
        }
        if (!is_array($metrics)) {
            $metrics = [];
        }
        if (!is_array($devices)) {
            $devices = [];
        }

        $count = max(count($destinations), count($gateways), count($metrics), count($devices));

        for ($i = 0; $i < $count; $i++) {
            $dest = trim($destinations[$i] ?? '');
            $gw = trim($gateways[$i] ?? '');
            $metric = trim($metrics[$i] ?? '');
            $device = trim($devices[$i] ?? '');

            // Skip empty rows
            if (empty($dest) && empty($gw)) {
                continue;
            }

            // Validate destination (CIDR notation required)
            if (empty($dest)) {
                $errors[] = "Row " . ($i + 1) . ": Destination is required";
                continue;
            }

            if (!$this->isValidCidr($dest) && !$this->isValidCidrV6($dest)) {
                $errors[] = "Row " . ($i + 1) . ": Invalid destination CIDR: $dest";
                continue;
            }

            // Validate gateway (IP address required)
            if (empty($gw)) {
                $errors[] = "Row " . ($i + 1) . ": Gateway is required";
                continue;
            }

            if (!filter_var($gw, FILTER_VALIDATE_IP)) {
                $errors[] = "Row " . ($i + 1) . ": Invalid gateway IP: $gw";
                continue;
            }

            // Validate metric (optional, 1-1000)
            if (!empty($metric)) {
                if (!is_numeric($metric) || $metric < 1 || $metric > 1000) {
                    $errors[] = "Row " . ($i + 1) . ": Metric must be between 1 and 1000";
                    continue;
                }
                $metric = (int)$metric;
            } else {
                $metric = null;
            }

            // Detect address family from destination
            $family = 'ipv4';
            if (strpos($dest, ':') !== false) {
                $family = 'ipv6';
            }

            $route = [
                'destination' => $dest,
                'gateway' => $gw,
                'interface' => $device ?: null,
                'metric' => $metric,
                'family' => $family,
            ];

            $routes[] = $route;
        }

        if (!empty($errors)) {
            $this->flash('error', implode('. ', $errors));
            $this->redirect('/network/routes');
            return;
        }

        $config = $this->configService->getWorkingConfig();
        $config->set('network.routes', $routes);

        if ($this->configService->saveWorkingConfig($config)) {
            $this->flash('success', 'Static routes saved. Click "Apply Configuration" to activate changes.');
        } else {
            $this->flash('error', 'Failed to save configuration');
        }

        $this->redirect('/network/routes');
    }

    /**
     * Validate IPv6 CIDR notation.
     */
    private function isValidCidrV6(string $cidr): bool
    {
        if (strpos($cidr, '/') === false) {
            return false;
        }

        [$ip, $prefix] = explode('/', $cidr, 2);

        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return false;
        }

        if (!is_numeric($prefix) || $prefix < 0 || $prefix > 128) {
            return false;
        }

        return true;
    }
}
