<?php

namespace Coyote\WebAdmin\Controller;

use Coyote\Certificate\CertificateStore;
use Coyote\Vpn\EasyRsaService;
use Coyote\Vpn\OpenVpnInstance;
use Coyote\Vpn\OpenVpnService;
use Coyote\Vpn\StrongSwanService;
use Coyote\Vpn\WireGuardInterface;
use Coyote\Vpn\WireGuardPeer;
use Coyote\Vpn\WireGuardService;
use Coyote\WebAdmin\FeatureFlags;
use Coyote\WebAdmin\Service\ConfigService;

class VpnController extends BaseController
{
    private ConfigService $configService;
    private StrongSwanService $strongSwanService;
    private OpenVpnService $openVpnService;
    private EasyRsaService $easyRsaService;
    private CertificateStore $certificateStore;
    private WireGuardService $wireGuardService;
    private FeatureFlags $featureFlags;

    public function __construct()
    {
        parent::__construct();
        $this->configService = new ConfigService();
        $this->strongSwanService = new StrongSwanService();
        $this->openVpnService = new OpenVpnService();
        $this->easyRsaService = new EasyRsaService();
        $this->certificateStore = new CertificateStore();
        $this->wireGuardService = new WireGuardService();
        $this->featureFlags = new FeatureFlags();
    }

    public function index(array $params = []): void
    {
        if (!$this->featureFlags->isVpnAvailable()) {
            $this->flash('error', 'VPN features are disabled in this build');
            $this->redirect('/dashboard');
            return;
        }

        $ipsecAvailable = $this->featureFlags->isIpsecAvailable();
        $openVpnAvailable = $this->featureFlags->isOpenVpnAvailable();
        $wireGuardAvailable = $this->featureFlags->isWireGuardAvailable();

        $config = $this->configService->getWorkingConfig();
        $vpn = $config->get('vpn', []);

        $ipsec = $ipsecAvailable && is_array($vpn['ipsec'] ?? null) ? $vpn['ipsec'] : [];
        $tunnels = $ipsecAvailable && is_array($ipsec['tunnels'] ?? null) ? $ipsec['tunnels'] : [];
        $status = $ipsecAvailable ? $this->strongSwanService->getStatus() : ['running' => false, 'connections' => []];

        $openvpn = $openVpnAvailable && is_array($vpn['openvpn'] ?? null) ? $vpn['openvpn'] : [];
        $openvpnInstances = $openVpnAvailable && is_array($openvpn['instances'] ?? null) ? $openvpn['instances'] : [];
        $openvpnRunningCount = 0;
        $openvpnClientCount = 0;

        if ($openVpnAvailable) {
            foreach ($openvpnInstances as $name => $openvpnInstance) {
                if (!is_array($openvpnInstance) || !((bool)($openvpnInstance['enabled'] ?? true))) {
                    continue;
                }

                $openvpnStatus = $this->openVpnService->getStatus((string)$name);
                if ((bool)($openvpnStatus['running'] ?? false)) {
                    $openvpnRunningCount++;
                }

                $openvpnClientCount += (int)($openvpnStatus['connected_clients'] ?? 0);
            }
        }

        $wireguard = $wireGuardAvailable && is_array($vpn['wireguard'] ?? null) ? $vpn['wireguard'] : [];
        $wireguardInterfaces = $wireGuardAvailable && is_array($wireguard['interfaces'] ?? null) ? $wireguard['interfaces'] : [];
        $wireguardStatus = $wireGuardAvailable ? $this->wireGuardService->getInterfaceStatus() : [];
        $wireguardRunning = 0;

        if ($wireGuardAvailable) {
            foreach ($wireguardInterfaces as $name => $interfaceConfig) {
                if (!is_array($interfaceConfig)) {
                    continue;
                }

                $interfaceStatus = $wireguardStatus[(string)$name] ?? [];
                if ((bool)($interfaceStatus['up'] ?? false)) {
                    $wireguardRunning++;
                }
            }
        }

        $data = [
            'ipsecAvailable' => $ipsecAvailable,
            'ipsecEnabled' => (bool)($ipsec['enabled'] ?? false),
            'ipsecRunning' => (bool)($status['running'] ?? false),
            'tunnelCount' => count($tunnels),
            'activeCount' => count($status['connections'] ?? []),
            'openvpnAvailable' => $openVpnAvailable,
            'openvpnEnabled' => (bool)($openvpn['enabled'] ?? false),
            'openvpnCount' => count($openvpnInstances),
            'openvpnRunningCount' => $openvpnRunningCount,
            'openvpnClientCount' => $openvpnClientCount,
            'wireguardAvailable' => $wireGuardAvailable,
            'wireguardEnabled' => (bool)($wireguard['enabled'] ?? false),
            'wireguardInterfaceCount' => count($wireguardInterfaces),
            'wireguardRunningCount' => $wireguardRunning,
        ];

        $this->render('pages/vpn/index', $data);
    }

    public function ipsecTunnels(array $params = []): void
    {
        $config = $this->configService->getWorkingConfig();
        $tunnels = $config->get('vpn.ipsec.tunnels', []);

        if (!is_array($tunnels)) {
            $tunnels = [];
        }

        $tunnelsWithStatus = [];

        foreach ($tunnels as $name => $tunnelConfig) {
            if (!is_array($tunnelConfig)) {
                continue;
            }

            $status = $this->strongSwanService->getTunnelStatus((string)$name);
            $tunnelConfig['name'] = (string)$name;
            $tunnelConfig['status'] = $status;
            $tunnelsWithStatus[(string)$name] = $tunnelConfig;
        }

        $data = [
            'tunnels' => $tunnelsWithStatus,
        ];

        $this->render('pages/vpn/ipsec', $data);
    }

    public function newIpsecTunnel(array $params = []): void
    {
        $data = [
            'tunnel' => $this->getDefaultTunnelConfig(),
            'isNew' => true,
            'serverCerts' => $this->getServerCertificates(),
        ];

        $this->render('pages/vpn/ipsec-edit', $data);
    }

    public function editIpsecTunnel(array $params = []): void
    {
        $name = (string)($params['name'] ?? '');
        $config = $this->configService->getWorkingConfig();
        $tunnels = $config->get('vpn.ipsec.tunnels', []);

        if (!is_array($tunnels) || !isset($tunnels[$name]) || !is_array($tunnels[$name])) {
            $this->flash('error', 'Tunnel not found: ' . $name);
            $this->redirect('/vpn/ipsec');
            return;
        }

        $data = [
            'tunnel' => $this->buildTunnelForForm($name, $tunnels[$name]),
            'isNew' => false,
            'serverCerts' => $this->getServerCertificates(),
        ];

        $this->render('pages/vpn/ipsec-edit', $data);
    }

    public function saveIpsecTunnel(array $params = []): void
    {
        $routeName = (string)($params['name'] ?? '');
        $isNew = $routeName === '';
        $name = $isNew ? trim((string)$this->post('name', '')) : $routeName;
        $enabled = $this->post('enabled', '') === '1';
        $localAddress = trim((string)$this->post('local_address', '%any'));
        $remoteAddress = trim((string)$this->post('remote_address', ''));
        $localId = trim((string)$this->post('local_id', ''));
        $remoteId = trim((string)$this->post('remote_id', ''));
        $authMethod = trim((string)$this->post('auth_method', 'psk'));
        $psk = trim((string)$this->post('psk', ''));
        $certificateId = trim((string)$this->post('certificate_id', ''));
        $localTs = trim((string)$this->post('local_ts', ''));
        $remoteTs = trim((string)$this->post('remote_ts', ''));
        $ikeVersion = (int)$this->post('ike_version', '2');
        $proposalsInput = trim((string)$this->post('proposals', 'aes256-sha256-modp2048'));
        $espProposalsInput = trim((string)$this->post('esp_proposals', 'aes256-sha256'));
        $startAction = trim((string)$this->post('start_action', 'trap'));
        $dpdAction = trim((string)$this->post('dpd_action', 'restart'));
        $closeAction = trim((string)$this->post('close_action', 'restart'));

        $errors = [];

        if ($name === '') {
            $errors[] = 'Tunnel name is required';
        } elseif (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $name)) {
            $errors[] = 'Tunnel name must start with a letter and contain only letters, numbers, underscores, and hyphens';
        }

        if ($remoteAddress === '') {
            $errors[] = 'Remote address is required';
        }

        if ($localTs === '') {
            $errors[] = 'Local network is required';
        }

        if ($remoteTs === '') {
            $errors[] = 'Remote network is required';
        }

        if (!in_array($authMethod, ['psk', 'cert'], true)) {
            $errors[] = 'Invalid authentication method';
        }

        if ($authMethod === 'psk' && $psk === '') {
            $errors[] = 'Pre-shared key is required for PSK authentication';
        }

        if ($authMethod === 'cert') {
            if ($certificateId === '') {
                $errors[] = 'Certificate is required for X.509 authentication';
            } elseif (!$this->certificateStore->initialize() || !$this->certificateStore->exists($certificateId)) {
                $errors[] = 'Selected certificate was not found';
            }
        }

        if (!in_array($ikeVersion, [1, 2], true)) {
            $errors[] = 'IKE version must be 1 or 2';
        }

        if (!in_array($startAction, ['none', 'trap', 'start'], true)) {
            $errors[] = 'Invalid start action';
        }

        if (!in_array($dpdAction, ['none', 'clear', 'restart'], true)) {
            $errors[] = 'Invalid DPD action';
        }

        if (!in_array($closeAction, ['none', 'clear', 'restart'], true)) {
            $errors[] = 'Invalid close action';
        }

        $config = $this->configService->getWorkingConfig();
        $tunnels = $config->get('vpn.ipsec.tunnels', []);

        if (!is_array($tunnels)) {
            $tunnels = [];
        }

        if ($isNew && isset($tunnels[$name])) {
            $errors[] = "A tunnel named '{$name}' already exists";
        }

        if (!$isNew && !isset($tunnels[$routeName])) {
            $errors[] = 'Tunnel not found: ' . $routeName;
        }

        if (!empty($errors)) {
            $this->flash('error', implode('. ', $errors));
            $this->redirect($isNew ? '/vpn/ipsec/new' : '/vpn/ipsec/' . urlencode($routeName));
            return;
        }

        $tunnel = [
            'enabled' => $enabled,
            'local_address' => $localAddress !== '' ? $localAddress : '%any',
            'remote_address' => $remoteAddress,
            'local_ts' => $localTs,
            'remote_ts' => $remoteTs,
            'version' => $ikeVersion,
            'proposals' => $this->parseProposalList($proposalsInput, 'aes256-sha256-modp2048'),
            'esp_proposals' => $this->parseProposalList($espProposalsInput, 'aes256-sha256'),
            'start_action' => $startAction,
            'dpd_action' => $dpdAction,
            'close_action' => $closeAction,
            'auth_method' => $authMethod,
        ];

        if ($localId !== '') {
            $tunnel['local_id'] = $localId;
        }

        if ($remoteId !== '') {
            $tunnel['remote_id'] = $remoteId;
        }

        if ($authMethod === 'psk') {
            $tunnel['local_auth'] = 'psk';
            $tunnel['remote_auth'] = 'psk';
            $tunnel['psk'] = $psk;
        } else {
            $tunnel['local_auth'] = 'pubkey';
            $tunnel['remote_auth'] = 'pubkey';
            $tunnel['certificate_id'] = $certificateId;
        }

        if (!$isNew && $routeName !== $name && isset($tunnels[$routeName])) {
            unset($tunnels[$routeName]);
        }

        $tunnels[$name] = $tunnel;
        $config->set('vpn.ipsec.tunnels', $tunnels);

        if ($this->configService->saveWorkingConfig($config)) {
            $this->flash('success', $isNew ? "Tunnel '{$name}' created" : "Tunnel '{$name}' updated");
        } else {
            $this->flash('error', 'Failed to save configuration');
        }

        $this->redirect('/vpn/ipsec');
    }

    public function deleteIpsecTunnel(array $params = []): void
    {
        $name = (string)($params['name'] ?? '');
        $config = $this->configService->getWorkingConfig();
        $tunnels = $config->get('vpn.ipsec.tunnels', []);

        if (!is_array($tunnels) || !isset($tunnels[$name])) {
            $this->flash('error', 'Tunnel not found: ' . $name);
            $this->redirect('/vpn/ipsec');
            return;
        }

        unset($tunnels[$name]);
        $config->set('vpn.ipsec.tunnels', $tunnels);

        if ($this->configService->saveWorkingConfig($config)) {
            $this->flash('success', "Tunnel '{$name}' deleted");
        } else {
            $this->flash('error', 'Failed to save configuration');
        }

        $this->redirect('/vpn/ipsec');
    }

    public function connectTunnel(array $params = []): void
    {
        $name = (string)($params['name'] ?? '');

        if ($name === '') {
            $this->json(['success' => false], 400);
            return;
        }

        $success = $this->strongSwanService->initiateConnection($name);
        $this->json(['success' => $success]);
    }

    public function disconnectTunnel(array $params = []): void
    {
        $name = (string)($params['name'] ?? '');

        if ($name === '') {
            $this->json(['success' => false], 400);
            return;
        }

        $success = $this->strongSwanService->terminateConnection($name);
        $this->json(['success' => $success]);
    }

    public function openvpnInstances(array $params = []): void
    {
        $config = $this->configService->getWorkingConfig();
        $instances = $config->get('vpn.openvpn.instances', []);

        if (!is_array($instances)) {
            $instances = [];
        }

        $instancesWithStatus = [];

        foreach ($instances as $name => $instanceConfig) {
            if (!is_array($instanceConfig)) {
                continue;
            }

            $instance = $this->buildOpenVpnInstanceForForm((string)$name, $instanceConfig);
            $instance['status'] = $this->openVpnService->getStatus((string)$name);
            $instancesWithStatus[(string)$name] = $instance;
        }

        $this->render('pages/vpn/openvpn', [
            'instances' => $instancesWithStatus,
            'pkiInitialized' => $this->easyRsaService->isInitialized(),
        ]);
    }

    public function newOpenvpnInstance(array $params = []): void
    {
        $this->render('pages/vpn/openvpn-edit', [
            'instance' => $this->getDefaultOpenVpnInstanceConfig(),
            'isNew' => true,
        ]);
    }

    public function editOpenvpnInstance(array $params = []): void
    {
        $name = (string)($params['name'] ?? '');
        $config = $this->configService->getWorkingConfig();
        $instances = $config->get('vpn.openvpn.instances', []);

        if (!is_array($instances) || !isset($instances[$name]) || !is_array($instances[$name])) {
            $this->flash('error', 'OpenVPN instance not found: ' . $name);
            $this->redirect('/vpn/openvpn');
            return;
        }

        $this->render('pages/vpn/openvpn-edit', [
            'instance' => $this->buildOpenVpnInstanceForForm($name, $instances[$name]),
            'isNew' => false,
        ]);
    }

    public function saveOpenvpnInstance(array $params = []): void
    {
        $routeName = (string)($params['name'] ?? '');
        $isNew = $routeName === '';
        $name = $isNew ? trim((string)$this->post('name', '')) : trim((string)$this->post('name', $routeName));
        $mode = strtolower(trim((string)$this->post('mode', 'server')));
        $enabled = $this->post('enabled', '') === '1';
        $protocol = strtolower(trim((string)$this->post('protocol', 'udp')));
        $port = (int)$this->post('port', '1194');
        $device = strtolower(trim((string)$this->post('device', 'tun')));
        $network = trim((string)$this->post('network', '10.8.0.0/24'));
        $pushRoutes = $this->parseTextAreaLines(trim((string)$this->post('push_routes', '')));
        $pushDns = $this->parseDnsList(trim((string)$this->post('push_dns', '')));
        $clientToClient = $this->post('client_to_client', '') === '1';
        $remoteHost = trim((string)$this->post('remote_host', ''));
        $remotePort = (int)$this->post('remote_port', '1194');
        $cipher = trim((string)$this->post('cipher', 'AES-256-GCM'));
        $auth = trim((string)$this->post('auth', 'SHA256'));
        $keepaliveInterval = (int)$this->post('keepalive_interval', '10');
        $keepaliveTimeout = (int)$this->post('keepalive_timeout', '120');

        $openVpnInstance = OpenVpnInstance::fromArray($name, [
            'mode' => $mode,
            'enabled' => $enabled,
            'protocol' => $protocol,
            'port' => $port,
            'device' => $device,
            'network' => $network,
            'cipher' => $cipher,
            'auth' => $auth,
            'push_routes' => $pushRoutes,
            'push_dns' => $pushDns,
            'client_to_client' => $clientToClient,
            'keepalive_interval' => $keepaliveInterval,
            'keepalive_timeout' => $keepaliveTimeout,
            'remote_host' => $remoteHost,
            'remote_port' => $remotePort,
        ]);

        $errors = $openVpnInstance->validate();
        $config = $this->configService->getWorkingConfig();
        $instances = $config->get('vpn.openvpn.instances', []);

        if (!is_array($instances)) {
            $instances = [];
        }

        if ($isNew && isset($instances[$name])) {
            $errors[] = "An OpenVPN instance named '{$name}' already exists";
        }

        if (!$isNew && !isset($instances[$routeName])) {
            $errors[] = 'OpenVPN instance not found: ' . $routeName;
        }

        if (!$isNew && $routeName !== $name && isset($instances[$name])) {
            $errors[] = "An OpenVPN instance named '{$name}' already exists";
        }

        if (!empty($errors)) {
            $this->flash('error', implode('. ', $errors));
            $this->redirect($isNew ? '/vpn/openvpn/new' : '/vpn/openvpn/' . urlencode($routeName));
            return;
        }

        if (!$isNew && $routeName !== $name && isset($instances[$routeName])) {
            unset($instances[$routeName]);
        }

        $instances[$name] = $openVpnInstance->toArray();
        $config->set('vpn.openvpn.enabled', true);
        $config->set('vpn.openvpn.instances', $instances);

        if ($this->configService->saveWorkingConfig($config)) {
            $this->flash('success', $isNew ? "OpenVPN instance '{$name}' created" : "OpenVPN instance '{$name}' updated");
        } else {
            $this->flash('error', 'Failed to save configuration');
        }

        $this->redirect('/vpn/openvpn');
    }

    public function deleteOpenvpnInstance(array $params = []): void
    {
        $name = (string)($params['name'] ?? '');
        $config = $this->configService->getWorkingConfig();
        $instances = $config->get('vpn.openvpn.instances', []);

        if (!is_array($instances) || !isset($instances[$name])) {
            $this->flash('error', 'OpenVPN instance not found: ' . $name);
            $this->redirect('/vpn/openvpn');
            return;
        }

        unset($instances[$name]);
        $config->set('vpn.openvpn.instances', $instances);

        if (empty($instances)) {
            $config->set('vpn.openvpn.enabled', false);
        }

        if ($this->configService->saveWorkingConfig($config)) {
            $this->flash('success', "OpenVPN instance '{$name}' deleted");
        } else {
            $this->flash('error', 'Failed to save configuration');
        }

        $this->redirect('/vpn/openvpn');
    }

    public function openvpnPki(array $params = []): void
    {
        if (!$this->easyRsaService->isInitialized()) {
            $this->render('pages/vpn/openvpn-pki', [
                'initialized' => false,
                'caInfo' => [],
                'serverCerts' => [],
                'clientCerts' => [],
                'dhGenerated' => false,
                'serverInstances' => [],
            ]);
            return;
        }

        $downloadType = trim((string)$this->query('download', ''));
        $downloadName = trim((string)$this->query('name', ''));

        if ($downloadType === 'server-cert' && $downloadName !== '') {
            $path = $this->easyRsaService->getServerCertPath($downloadName);
            if ($path !== null && file_exists($path)) {
                $content = file_get_contents($path);
                if ($content !== false) {
                    header('Content-Type: application/x-pem-file');
                    header('Content-Disposition: attachment; filename="' . $downloadName . '.crt"');
                    header('Content-Length: ' . strlen($content));
                    echo $content;
                    exit;
                }
            }
        }

        $config = $this->configService->getWorkingConfig();
        $instances = $config->get('vpn.openvpn.instances', []);
        if (!is_array($instances)) {
            $instances = [];
        }

        $serverInstances = [];
        foreach ($instances as $name => $instanceConfig) {
            if (!is_array($instanceConfig)) {
                continue;
            }

            if (strtolower((string)($instanceConfig['mode'] ?? 'server')) !== 'server') {
                continue;
            }

            $serverInstances[(string)$name] = $instanceConfig;
        }

        $this->render('pages/vpn/openvpn-pki', [
            'initialized' => true,
            'caInfo' => $this->parseCertificateInfo($this->easyRsaService->getCaCertContent()),
            'serverCerts' => $this->easyRsaService->listServerCerts(),
            'clientCerts' => $this->easyRsaService->listClientCerts(),
            'dhGenerated' => $this->easyRsaService->getDhPath() !== null,
            'serverInstances' => $serverInstances,
        ]);
    }

    public function initializePki(array $params = []): void
    {
        $action = trim((string)$this->post('action', 'init'));

        if ($action === 'dh') {
            if ($this->easyRsaService->generateDhParams()) {
                $this->flash('success', 'DH parameters generated');
            } else {
                $this->flash('error', 'Failed to generate DH parameters');
            }

            $this->redirect('/vpn/openvpn/pki');
            return;
        }

        if ($this->easyRsaService->initializePki()) {
            $this->flash('success', 'OpenVPN PKI initialized');
        } else {
            $this->flash('error', 'Failed to initialize OpenVPN PKI');
        }

        $this->redirect('/vpn/openvpn/pki');
    }

    public function generateServerCert(array $params = []): void
    {
        $name = trim((string)$this->post('name', ''));

        if (!$this->isValidOpenVpnName($name)) {
            $this->flash('error', 'Server certificate name is invalid');
            $this->redirect('/vpn/openvpn/pki');
            return;
        }

        if ($this->easyRsaService->generateServerCert($name)) {
            $this->flash('success', "Server certificate '{$name}' generated");
        } else {
            $this->flash('error', 'Failed to generate server certificate');
        }

        $this->redirect('/vpn/openvpn/pki');
    }

    public function generateClientCert(array $params = []): void
    {
        $name = trim((string)$this->post('name', ''));

        if (!$this->isValidOpenVpnName($name)) {
            $this->flash('error', 'Client certificate name is invalid');
            $this->redirect('/vpn/openvpn/pki');
            return;
        }

        if ($this->easyRsaService->generateClientCert($name)) {
            $this->flash('success', "Client certificate '{$name}' generated");
        } else {
            $this->flash('error', 'Failed to generate client certificate');
        }

        $this->redirect('/vpn/openvpn/pki');
    }

    public function revokeClientCert(array $params = []): void
    {
        $name = trim((string)($params['name'] ?? ''));

        if (!$this->isValidOpenVpnName($name)) {
            $this->flash('error', 'Client certificate name is invalid');
            $this->redirect('/vpn/openvpn/pki');
            return;
        }

        if ($this->easyRsaService->revokeCert($name)) {
            $this->flash('success', "Client certificate '{$name}' revoked");
        } else {
            $this->flash('error', 'Failed to revoke client certificate');
        }

        $this->redirect('/vpn/openvpn/pki');
    }

    public function downloadClientConfig(array $params = []): void
    {
        $serverName = trim((string)($params['server'] ?? ''));
        $clientName = trim((string)($params['client'] ?? ''));

        if (!$this->isValidOpenVpnName($serverName) || !$this->isValidOpenVpnName($clientName)) {
            $this->flash('error', 'Invalid server or client name');
            $this->redirect('/vpn/openvpn/pki');
            return;
        }

        $config = $this->configService->getWorkingConfig();
        $instances = $config->get('vpn.openvpn.instances', []);
        if (!is_array($instances)) {
            $instances = [];
        }

        $serverConfig = $instances[$serverName] ?? null;
        if (!is_array($serverConfig) || strtolower((string)($serverConfig['mode'] ?? 'server')) !== 'server') {
            $this->flash('error', 'OpenVPN server instance not found: ' . $serverName);
            $this->redirect('/vpn/openvpn/pki');
            return;
        }

        if ($this->query('view', '') === '1') {
            $this->render('pages/vpn/openvpn-client-download', [
                'serverName' => $serverName,
                'clientName' => $clientName,
                'serverConfig' => $serverConfig,
            ]);
            return;
        }

        $caCert = $this->easyRsaService->getCaCertContent();
        $clientCert = $this->easyRsaService->getClientCertContent($clientName);
        $clientKey = $this->easyRsaService->getClientKeyContent($clientName);

        if ($caCert === null || $clientCert === null || $clientKey === null) {
            $this->flash('error', 'Required PKI assets for client config were not found');
            $this->redirect('/vpn/openvpn/pki');
            return;
        }

        $ovpn = $this->openVpnService->generateClientOvpn(
            $serverName,
            $clientName,
            $serverConfig,
            $caCert,
            $clientCert,
            $clientKey
        );

        $filename = $serverName . '-' . $clientName . '.ovpn';
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($ovpn));
        echo $ovpn;
        exit;
    }

    public function wireguardInterfaces(array $params = []): void
    {
        $config = $this->configService->getWorkingConfig();
        $wireguard = $config->get('vpn.wireguard', []);

        if (!is_array($wireguard)) {
            $wireguard = [];
        }

        $interfaces = is_array($wireguard['interfaces'] ?? null) ? $wireguard['interfaces'] : [];
        $statusByInterface = $this->wireGuardService->getInterfaceStatus();
        $items = [];

        foreach ($interfaces as $name => $interfaceConfig) {
            if (!is_array($interfaceConfig)) {
                continue;
            }

            $wireguardInterface = WireGuardInterface::fromArray((string)$name, $interfaceConfig)->toArray();
            $peers = is_array($wireguardInterface['peers'] ?? null) ? $wireguardInterface['peers'] : [];
            $status = $statusByInterface[(string)$name] ?? [
                'up' => $this->wireGuardService->isInterfaceUp((string)$name),
                'peers' => [],
            ];

            $items[(string)$name] = [
                'name' => (string)$name,
                'enabled' => (bool)($wireguardInterface['enabled'] ?? true),
                'listen_port' => (int)($wireguardInterface['listen_port'] ?? 51820),
                'address' => (string)($wireguardInterface['address'] ?? ''),
                'peer_count' => count($peers),
                'status' => $status,
            ];
        }

        ksort($items);

        $data = [
            'wireguardEnabled' => (bool)($wireguard['enabled'] ?? false),
            'interfaces' => $items,
        ];

        $this->render('pages/vpn/wireguard', $data);
    }

    public function newWireguardInterface(array $params = []): void
    {
        $pair = $this->wireGuardService->generateKeyPair();
        $wireguardInterface = WireGuardInterface::fromArray('', [
            'private_key' => (string)($pair['private_key'] ?? ''),
            'public_key' => (string)($pair['public_key'] ?? ''),
        ])->toArray();

        $data = [
            'isNew' => true,
            'interface' => array_merge($wireguardInterface, ['name' => '']),
            'interfaceStatus' => ['up' => false, 'peers' => []],
        ];

        $this->render('pages/vpn/wireguard-edit', $data);
    }

    public function editWireguardInterface(array $params = []): void
    {
        $name = (string)($params['name'] ?? '');
        $config = $this->configService->getWorkingConfig();
        $interfaces = $config->get('vpn.wireguard.interfaces', []);

        if (!is_array($interfaces) || !isset($interfaces[$name]) || !is_array($interfaces[$name])) {
            $this->flash('error', 'WireGuard interface not found: ' . $name);
            $this->redirect('/vpn/wireguard');
            return;
        }

        $wireguardInterface = WireGuardInterface::fromArray($name, $interfaces[$name])->toArray();
        $wireguardInterface['name'] = $name;
        $peers = is_array($wireguardInterface['peers'] ?? null) ? $wireguardInterface['peers'] : [];
        $peerRows = [];

        foreach ($peers as $peer) {
            if (!is_array($peer)) {
                continue;
            }

            $publicKey = (string)($peer['public_key'] ?? '');
            if ($publicKey === '') {
                continue;
            }

            $peer['status'] = $this->wireGuardService->getPeerStatus($name, $publicKey);
            $peerRows[] = $peer;
        }

        $wireguardInterface['peers'] = $peerRows;

        $data = [
            'isNew' => false,
            'interface' => $wireguardInterface,
            'interfaceStatus' => $this->wireGuardService->getStatus($name),
        ];

        $this->render('pages/vpn/wireguard-edit', $data);
    }

    public function saveWireguardInterface(array $params = []): void
    {
        $routeName = (string)($params['name'] ?? '');
        $isNew = $routeName === '';
        $name = $isNew ? trim((string)$this->post('name', '')) : $routeName;
        $enabled = $this->post('enabled', '') === '1';
        $listenPort = (int)$this->post('listen_port', '51820');
        $address = trim((string)$this->post('address', ''));
        $privateKey = trim((string)$this->post('private_key', ''));
        $publicKey = trim((string)$this->post('public_key', ''));
        $dns = trim((string)$this->post('dns', ''));
        $mtu = (int)$this->post('mtu', '0');

        $errors = [];

        if ($name === '') {
            $errors[] = 'Interface name is required';
        } elseif (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,14}$/', $name)) {
            $errors[] = 'Interface name must start with a letter and contain only letters, numbers, underscores, and hyphens';
        }

        if ($privateKey === '' && $enabled) {
            $pair = $this->wireGuardService->generateKeyPair();
            $privateKey = trim((string)($pair['private_key'] ?? ''));
            $publicKey = trim((string)($pair['public_key'] ?? ''));
        }

        if ($privateKey !== '' && $publicKey === '') {
            $publicKey = $this->wireGuardService->getPublicKey($privateKey);
        }

        $config = $this->configService->getWorkingConfig();
        $interfaces = $config->get('vpn.wireguard.interfaces', []);

        if (!is_array($interfaces)) {
            $interfaces = [];
        }

        if ($isNew && isset($interfaces[$name])) {
            $errors[] = "A WireGuard interface named '{$name}' already exists";
        }

        if (!$isNew && !isset($interfaces[$routeName])) {
            $errors[] = 'WireGuard interface not found: ' . $routeName;
        }

        $existingPeers = [];
        if (!$isNew && isset($interfaces[$routeName]) && is_array($interfaces[$routeName])) {
            $existingPeers = is_array($interfaces[$routeName]['peers'] ?? null) ? $interfaces[$routeName]['peers'] : [];
        }

        $wireguardInterface = new WireGuardInterface($name);
        $wireguardInterface
            ->enabled($enabled)
            ->listenPort($listenPort)
            ->address($address)
            ->privateKey($privateKey)
            ->publicKey($publicKey)
            ->dns($dns)
            ->mtu($mtu)
            ->peers($existingPeers);

        $errors = array_merge($errors, $wireguardInterface->validate());

        if (!empty($errors)) {
            $this->flash('error', implode('. ', $errors));
            $this->redirect($isNew ? '/vpn/wireguard/new' : '/vpn/wireguard/' . urlencode($routeName));
            return;
        }

        if (!$isNew && $routeName !== $name && isset($interfaces[$routeName])) {
            unset($interfaces[$routeName]);
        }

        $interfaces[$name] = $wireguardInterface->toArray();
        $config->set('vpn.wireguard.interfaces', $interfaces);

        if (!$config->has('vpn.wireguard.enabled')) {
            $config->set('vpn.wireguard.enabled', true);
        }

        if ($this->configService->saveWorkingConfig($config)) {
            $this->flash('success', $isNew ? "WireGuard interface '{$name}' created" : "WireGuard interface '{$name}' updated");
        } else {
            $this->flash('error', 'Failed to save configuration');
        }

        $this->redirect('/vpn/wireguard/' . urlencode($name));
    }

    public function deleteWireguardInterface(array $params = []): void
    {
        $name = (string)($params['name'] ?? '');
        $config = $this->configService->getWorkingConfig();
        $interfaces = $config->get('vpn.wireguard.interfaces', []);

        if (!is_array($interfaces) || !isset($interfaces[$name])) {
            $this->flash('error', 'WireGuard interface not found: ' . $name);
            $this->redirect('/vpn/wireguard');
            return;
        }

        unset($interfaces[$name]);
        $config->set('vpn.wireguard.interfaces', $interfaces);

        if ($this->configService->saveWorkingConfig($config)) {
            $this->flash('success', "WireGuard interface '{$name}' deleted");
        } else {
            $this->flash('error', 'Failed to save configuration');
        }

        $this->redirect('/vpn/wireguard');
    }

    public function newWireguardPeer(array $params = []): void
    {
        $interfaceName = (string)($params['name'] ?? '');
        $config = $this->configService->getWorkingConfig();
        $interfaces = $config->get('vpn.wireguard.interfaces', []);

        if (!is_array($interfaces) || !isset($interfaces[$interfaceName]) || !is_array($interfaces[$interfaceName])) {
            $this->flash('error', 'WireGuard interface not found: ' . $interfaceName);
            $this->redirect('/vpn/wireguard');
            return;
        }

        $name = trim((string)$this->query('peer_name', ''));
        $publicKey = trim((string)$this->query('public_key', ''));
        $privateKey = trim((string)$this->query('private_key', ''));
        $presharedKey = trim((string)$this->query('preshared_key', ''));
        $allowedIps = trim((string)$this->query('allowed_ips', '10.0.0.2/32'));
        $endpoint = trim((string)$this->query('endpoint', ''));
        $persistentKeepalive = (int)$this->query('persistent_keepalive', '25');
        $generateKeys = $this->query('generate_keys', '') === '1';
        $generatePreshared = $this->query('generate_psk', '') === '1';

        if ($generateKeys || ($privateKey === '' && $publicKey === '')) {
            $pair = $this->wireGuardService->generateKeyPair();
            $privateKey = trim((string)($pair['private_key'] ?? ''));
            $publicKey = trim((string)($pair['public_key'] ?? ''));
        }

        if ($privateKey !== '' && $publicKey === '') {
            $publicKey = $this->wireGuardService->getPublicKey($privateKey);
        }

        if ($generatePreshared) {
            $presharedKey = $this->wireGuardService->generatePresharedKey();
        }

        $peer = WireGuardPeer::fromArray([
            'name' => $name,
            'public_key' => $publicKey,
            'preshared_key' => $presharedKey,
            'allowed_ips' => $allowedIps,
            'endpoint' => $endpoint,
            'persistent_keepalive' => $persistentKeepalive,
            'private_key' => $privateKey,
        ])->toArray();

        $data = [
            'interfaceName' => $interfaceName,
            'peer' => $peer,
        ];

        $this->render('pages/vpn/wireguard-peer', $data);
    }

    public function saveWireguardPeer(array $params = []): void
    {
        $interfaceName = (string)($params['name'] ?? '');
        $config = $this->configService->getWorkingConfig();
        $interfaces = $config->get('vpn.wireguard.interfaces', []);

        if (!is_array($interfaces) || !isset($interfaces[$interfaceName]) || !is_array($interfaces[$interfaceName])) {
            $this->flash('error', 'WireGuard interface not found: ' . $interfaceName);
            $this->redirect('/vpn/wireguard');
            return;
        }

        $name = trim((string)$this->post('name', ''));
        $privateKey = trim((string)$this->post('private_key', ''));
        $publicKey = trim((string)$this->post('public_key', ''));
        $presharedKey = trim((string)$this->post('preshared_key', ''));
        $allowedIps = trim((string)$this->post('allowed_ips', ''));
        $endpoint = trim((string)$this->post('endpoint', ''));
        $persistentKeepalive = (int)$this->post('persistent_keepalive', '25');

        if ($privateKey !== '' && $publicKey === '') {
            $publicKey = $this->wireGuardService->getPublicKey($privateKey);
        }

        $peer = WireGuardPeer::fromArray([])
            ->name($name)
            ->publicKey($publicKey)
            ->privateKey($privateKey)
            ->presharedKey($presharedKey)
            ->allowedIps($allowedIps)
            ->endpoint($endpoint)
            ->persistentKeepalive($persistentKeepalive);

        $errors = $peer->validate();

        if (!empty($errors)) {
            $this->flash('error', implode('. ', $errors));
            $this->redirect('/vpn/wireguard/' . urlencode($interfaceName) . '/peer/new');
            return;
        }

        $interfaceConfig = $interfaces[$interfaceName];
        $peers = is_array($interfaceConfig['peers'] ?? null) ? $interfaceConfig['peers'] : [];
        $newPeer = $peer->toArray();
        $updatedPeers = [];
        $replaced = false;

        foreach ($peers as $existingPeer) {
            if (!is_array($existingPeer)) {
                continue;
            }

            if ((string)($existingPeer['public_key'] ?? '') === $publicKey) {
                $updatedPeers[] = $newPeer;
                $replaced = true;
                continue;
            }

            $updatedPeers[] = $existingPeer;
        }

        if (!$replaced) {
            $updatedPeers[] = $newPeer;
        }

        $interfaceConfig['peers'] = array_values($updatedPeers);
        $interfaces[$interfaceName] = $interfaceConfig;
        $config->set('vpn.wireguard.interfaces', $interfaces);

        if ($this->configService->saveWorkingConfig($config)) {
            $this->flash('success', "Peer '{$name}' saved for interface '{$interfaceName}'");
        } else {
            $this->flash('error', 'Failed to save configuration');
        }

        $this->redirect('/vpn/wireguard/' . urlencode($interfaceName));
    }

    public function deleteWireguardPeer(array $params = []): void
    {
        $interfaceName = (string)($params['name'] ?? '');
        $publicKey = rawurldecode((string)($params['key'] ?? ''));
        $config = $this->configService->getWorkingConfig();
        $interfaces = $config->get('vpn.wireguard.interfaces', []);

        if (!is_array($interfaces) || !isset($interfaces[$interfaceName]) || !is_array($interfaces[$interfaceName])) {
            $this->flash('error', 'WireGuard interface not found: ' . $interfaceName);
            $this->redirect('/vpn/wireguard');
            return;
        }

        $wireguardInterface = WireGuardInterface::fromArray($interfaceName, $interfaces[$interfaceName]);
        $wireguardInterface->removePeer($publicKey);
        $interfaces[$interfaceName] = $wireguardInterface->toArray();
        $config->set('vpn.wireguard.interfaces', $interfaces);

        if ($this->configService->saveWorkingConfig($config)) {
            $this->flash('success', 'Peer deleted');
        } else {
            $this->flash('error', 'Failed to save configuration');
        }

        $this->redirect('/vpn/wireguard/' . urlencode($interfaceName));
    }

    public function wireguardPeerConfig(array $params = []): void
    {
        $interfaceName = (string)($params['name'] ?? '');
        $publicKey = rawurldecode((string)($params['key'] ?? ''));
        $config = $this->configService->getWorkingConfig();
        $interfaces = $config->get('vpn.wireguard.interfaces', []);

        if (!is_array($interfaces) || !isset($interfaces[$interfaceName]) || !is_array($interfaces[$interfaceName])) {
            $this->flash('error', 'WireGuard interface not found: ' . $interfaceName);
            $this->redirect('/vpn/wireguard');
            return;
        }

        $interfaceConfig = $interfaces[$interfaceName];
        $peers = is_array($interfaceConfig['peers'] ?? null) ? $interfaceConfig['peers'] : [];
        $peerConfig = null;

        foreach ($peers as $peer) {
            if (!is_array($peer)) {
                continue;
            }

            if ((string)($peer['public_key'] ?? '') === $publicKey) {
                $peerConfig = $peer;
                break;
            }
        }

        if ($peerConfig === null) {
            $this->flash('error', 'WireGuard peer not found');
            $this->redirect('/vpn/wireguard/' . urlencode($interfaceName));
            return;
        }

        $serverPublicKey = trim((string)($interfaceConfig['public_key'] ?? ''));
        $privateKey = trim((string)($interfaceConfig['private_key'] ?? ''));

        if ($serverPublicKey === '' && $privateKey !== '') {
            $serverPublicKey = $this->wireGuardService->getPublicKey($privateKey);
        }

        $host = (string)($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? ''));
        $host = preg_replace('/:\\d+$/', '', $host) ?? '';
        $serverEndpoint = trim((string)$this->query('endpoint', $host));
        $serverPort = (int)($interfaceConfig['listen_port'] ?? 51820);
        $dns = trim((string)($interfaceConfig['dns'] ?? ''));

        $peer = WireGuardPeer::fromArray($peerConfig);
        $clientConfig = $peer->generateClientConfig($serverPublicKey, $serverEndpoint, $serverPort, $dns);
        $peerName = trim((string)($peerConfig['name'] ?? 'peer'));
        $safePeerName = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $peerName) ?? 'peer';
        $safePeerName = trim($safePeerName, '-');
        if ($safePeerName === '') {
            $safePeerName = 'peer';
        }
        $filename = $interfaceName . '-' . $safePeerName . '.conf';

        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($clientConfig));

        echo $clientConfig;
        exit;
    }

    private function getDefaultTunnelConfig(): array
    {
        return [
            'name' => '',
            'enabled' => true,
            'local_address' => '%any',
            'remote_address' => '',
            'local_id' => '',
            'remote_id' => '',
            'auth_method' => 'psk',
            'psk' => '',
            'certificate_id' => '',
            'local_ts' => '',
            'remote_ts' => '',
            'ike_version' => 2,
            'proposals' => 'aes256-sha256-modp2048',
            'esp_proposals' => 'aes256-sha256',
            'start_action' => 'trap',
            'dpd_action' => 'restart',
            'close_action' => 'restart',
        ];
    }

    private function buildTunnelForForm(string $name, array $tunnel): array
    {
        $authMethod = (string)($tunnel['auth_method'] ?? '');

        if ($authMethod === '') {
            $authMethod = (($tunnel['local_auth'] ?? 'psk') === 'psk') ? 'psk' : 'cert';
        }

        $proposals = $tunnel['proposals'] ?? 'aes256-sha256-modp2048';
        if (is_array($proposals)) {
            $proposals = implode(',', $proposals);
        }

        $espProposals = $tunnel['esp_proposals'] ?? 'aes256-sha256';
        if (is_array($espProposals)) {
            $espProposals = implode(',', $espProposals);
        }

        return [
            'name' => $name,
            'enabled' => (bool)($tunnel['enabled'] ?? true),
            'local_address' => (string)($tunnel['local_address'] ?? '%any'),
            'remote_address' => (string)($tunnel['remote_address'] ?? ''),
            'local_id' => (string)($tunnel['local_id'] ?? ''),
            'remote_id' => (string)($tunnel['remote_id'] ?? ''),
            'auth_method' => $authMethod,
            'psk' => (string)($tunnel['psk'] ?? ''),
            'certificate_id' => (string)($tunnel['certificate_id'] ?? ''),
            'local_ts' => (string)($tunnel['local_ts'] ?? ''),
            'remote_ts' => (string)($tunnel['remote_ts'] ?? ''),
            'ike_version' => (int)($tunnel['version'] ?? 2),
            'proposals' => (string)$proposals,
            'esp_proposals' => (string)$espProposals,
            'start_action' => (string)($tunnel['start_action'] ?? 'trap'),
            'dpd_action' => (string)($tunnel['dpd_action'] ?? 'restart'),
            'close_action' => (string)($tunnel['close_action'] ?? 'restart'),
        ];
    }

    private function getServerCertificates(): array
    {
        if (!$this->certificateStore->initialize()) {
            return [];
        }

        $certificates = $this->certificateStore->listByType(CertificateStore::DIR_SERVER);

        usort($certificates, static function (array $left, array $right): int {
            return strcmp((string)($left['name'] ?? ''), (string)($right['name'] ?? ''));
        });

        return $certificates;
    }

    private function getDefaultOpenVpnInstanceConfig(): array
    {
        $instance = OpenVpnInstance::fromArray('', []);
        return $this->buildOpenVpnInstanceForForm('', $instance->toArray());
    }

    private function buildOpenVpnInstanceForForm(string $name, array $instance): array
    {
        $normalized = OpenVpnInstance::fromArray($name, $instance)->toArray();

        $pushRoutes = $normalized['push_routes'] ?? [];
        if (!is_array($pushRoutes)) {
            $pushRoutes = [];
        }

        $pushDns = $normalized['push_dns'] ?? [];
        if (!is_array($pushDns)) {
            $pushDns = [];
        }

        return [
            'name' => $name,
            'mode' => strtolower((string)($normalized['mode'] ?? 'server')),
            'enabled' => (bool)($normalized['enabled'] ?? true),
            'protocol' => strtolower((string)($normalized['protocol'] ?? 'udp')),
            'port' => (int)($normalized['port'] ?? 1194),
            'device' => strtolower((string)($normalized['device'] ?? 'tun')),
            'network' => (string)($normalized['network'] ?? '10.8.0.0/24'),
            'push_routes' => implode("\n", array_map('strval', $pushRoutes)),
            'push_dns' => implode(', ', array_map('strval', $pushDns)),
            'client_to_client' => (bool)($normalized['client_to_client'] ?? false),
            'remote_host' => (string)($normalized['remote_host'] ?? ''),
            'remote_port' => (int)($normalized['remote_port'] ?? 1194),
            'cipher' => (string)($normalized['cipher'] ?? 'AES-256-GCM'),
            'auth' => (string)($normalized['auth'] ?? 'SHA256'),
            'keepalive_interval' => (int)($normalized['keepalive_interval'] ?? 10),
            'keepalive_timeout' => (int)($normalized['keepalive_timeout'] ?? 120),
        ];
    }

    private function parseTextAreaLines(string $input): array
    {
        if ($input === '') {
            return [];
        }

        $lines = preg_split('/\r\n|\r|\n/', $input);
        if (!is_array($lines)) {
            return [];
        }

        $values = [];

        foreach ($lines as $line) {
            $value = trim((string)$line);
            if ($value === '') {
                continue;
            }

            $values[] = $value;
        }

        return array_values(array_unique($values));
    }

    private function parseDnsList(string $input): array
    {
        if ($input === '') {
            return [];
        }

        $parts = preg_split('/[\s,]+/', $input);
        if (!is_array($parts)) {
            return [];
        }

        $dnsServers = [];

        foreach ($parts as $part) {
            $value = trim((string)$part);
            if ($value === '') {
                continue;
            }

            if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                continue;
            }

            $dnsServers[] = $value;
        }

        return array_values(array_unique($dnsServers));
    }

    private function parseCertificateInfo(?string $certificatePem): array
    {
        if ($certificatePem === null || trim($certificatePem) === '') {
            return [];
        }

        $parsed = openssl_x509_parse($certificatePem);
        if (!is_array($parsed)) {
            return [];
        }

        $subjectData = $parsed['subject'] ?? [];
        $subject = '';

        if (is_array($subjectData) && !empty($subjectData)) {
            $parts = [];
            foreach ($subjectData as $key => $value) {
                $parts[] = (string)$key . '=' . (string)$value;
            }
            $subject = implode(', ', $parts);
        }

        $expiryTimestamp = (int)($parsed['validTo_time_t'] ?? 0);

        return [
            'subject' => $subject,
            'expires' => $expiryTimestamp > 0 ? date('Y-m-d H:i:s', $expiryTimestamp) : '',
        ];
    }

    private function isValidOpenVpnName(string $name): bool
    {
        return $name !== '' && (bool)preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $name);
    }

    private function parseProposalList(string $value, string $fallback): array
    {
        $source = $value !== '' ? $value : $fallback;
        $parts = preg_split('/\s*,\s*/', $source);

        if (!is_array($parts)) {
            return [$fallback];
        }

        $parts = array_values(array_filter(array_map('trim', $parts), static fn(string $part): bool => $part !== ''));

        if (empty($parts)) {
            return [$fallback];
        }

        return $parts;
    }
}
