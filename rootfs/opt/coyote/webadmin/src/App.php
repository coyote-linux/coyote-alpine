<?php

namespace Coyote\WebAdmin;

/**
 * Main web administration application class.
 */
class App
{
    /** @var Router */
    private Router $router;

    /** @var Auth */
    private Auth $auth;

    /** @var array Application configuration */
    private array $config;

    /**
     * Create a new App instance.
     *
     * @param array $config Application configuration
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->router = new Router();
        $this->auth = new Auth();
    }

    /**
     * Run the application.
     *
     * @return void
     */
    public function run(): void
    {
        // Start session if not API-only mode
        if (!($this->config['api_only'] ?? false)) {
            session_start();
        }

        // Get request info
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $uri = parse_url($uri, PHP_URL_PATH);

        // Check authentication for non-public routes
        if (!$this->isPublicRoute($uri) && !$this->auth->isAuthenticated()) {
            if ($this->config['api_only'] ?? false) {
                http_response_code(401);
                echo json_encode(['error' => 'Unauthorized']);
                return;
            }
            $this->redirect('/login');
            return;
        }

        // Dispatch the request
        $this->router->dispatch($method, $uri);
    }

    /**
     * Register web UI routes.
     *
     * @return void
     */
    public function registerRoutes(): void
    {
        // Dashboard
        $this->router->get('/', [Controller\DashboardController::class, 'index']);
        $this->router->get('/dashboard', [Controller\DashboardController::class, 'index']);

        // Authentication
        $this->router->get('/login', [Controller\AuthController::class, 'showLogin']);
        $this->router->post('/login', [Controller\AuthController::class, 'login']);
        $this->router->get('/logout', [Controller\AuthController::class, 'logout']);

        // Network
        $this->router->get('/network', [Controller\NetworkController::class, 'index']);
        $this->router->get('/network/interfaces', [Controller\NetworkController::class, 'interfaces']);
        $this->router->post('/network/interfaces', [Controller\NetworkController::class, 'saveInterfaces']);
        $this->router->get('/network/interface/{name}', [Controller\NetworkController::class, 'editInterface']);
        $this->router->post('/network/interface/{name}', [Controller\NetworkController::class, 'saveInterface']);
        $this->router->post('/network/interface/{name}/delete', [Controller\NetworkController::class, 'deleteInterface']);

        // Firewall
        $this->router->get('/firewall', [Controller\FirewallController::class, 'index']);
        $this->router->get('/firewall/rules', [Controller\FirewallController::class, 'rules']);
        $this->router->post('/firewall/rules', [Controller\FirewallController::class, 'saveRules']);

        // Firewall ACLs
        $this->router->get('/firewall/acl/new', [Controller\FirewallController::class, 'createAcl']);
        $this->router->post('/firewall/acl/new', [Controller\FirewallController::class, 'saveNewAcl']);
        $this->router->get('/firewall/acl/{name}', [Controller\FirewallController::class, 'editAcl']);
        $this->router->post('/firewall/acl/{name}', [Controller\FirewallController::class, 'updateAcl']);
        $this->router->post('/firewall/acl/{name}/delete', [Controller\FirewallController::class, 'deleteAcl']);

        // Firewall ACL Rules
        $this->router->get('/firewall/acl/{name}/rule/new', [Controller\FirewallController::class, 'addRule']);
        $this->router->get('/firewall/acl/{name}/rule/{index}', [Controller\FirewallController::class, 'editRule']);
        $this->router->post('/firewall/acl/{name}/rule', [Controller\FirewallController::class, 'saveRule']);
        $this->router->post('/firewall/acl/{name}/rule/{index}/delete', [Controller\FirewallController::class, 'deleteRule']);
        $this->router->post('/firewall/acl/{name}/rule/{index}/move', [Controller\FirewallController::class, 'moveRule']);

        // Firewall ACL Applications
        $this->router->get('/firewall/apply', [Controller\FirewallController::class, 'applications']);
        $this->router->post('/firewall/apply', [Controller\FirewallController::class, 'addApplication']);
        $this->router->post('/firewall/apply/{index}/delete', [Controller\FirewallController::class, 'removeApplication']);

        // Firewall Address Lists
        $this->router->get('/firewall/address-lists', [Controller\FirewallController::class, 'addressLists']);
        $this->router->get('/firewall/address-list/new', [Controller\FirewallController::class, 'newAddressList']);
        $this->router->post('/firewall/address-list/new', [Controller\FirewallController::class, 'createAddressList']);
        $this->router->get('/firewall/address-list/{name}', [Controller\FirewallController::class, 'editAddressList']);
        $this->router->post('/firewall/address-list/{name}', [Controller\FirewallController::class, 'saveAddressList']);
        $this->router->post('/firewall/address-list/{name}/import', [Controller\FirewallController::class, 'importAddressList']);
        $this->router->post('/firewall/address-list/{name}/delete', [Controller\FirewallController::class, 'deleteAddressList']);

        // Firewall Access Controls (Web Admin and SSH hosts)
        $this->router->get('/firewall/access', [Controller\FirewallController::class, 'accessControls']);
        $this->router->post('/firewall/access/webadmin/add', [Controller\FirewallController::class, 'addWebAdminHost']);
        $this->router->post('/firewall/access/webadmin/{index}/delete', [Controller\FirewallController::class, 'deleteWebAdminHost']);
        $this->router->post('/firewall/access/ssh/add', [Controller\FirewallController::class, 'addSshHost']);
        $this->router->post('/firewall/access/ssh/{index}/delete', [Controller\FirewallController::class, 'deleteSshHost']);

        // NAT
        $this->router->get('/nat', [Controller\NatController::class, 'index']);

        // Port Forwards
        $this->router->get('/nat/forward/new', [Controller\NatController::class, 'newForward']);
        $this->router->post('/nat/forward/new', [Controller\NatController::class, 'saveForward']);
        $this->router->get('/nat/forward/{id}', [Controller\NatController::class, 'editForward']);
        $this->router->post('/nat/forward/{id}', [Controller\NatController::class, 'saveForward']);
        $this->router->post('/nat/forward/{id}/delete', [Controller\NatController::class, 'deleteForward']);

        // Masquerade Rules
        $this->router->get('/nat/masquerade/new', [Controller\NatController::class, 'newMasquerade']);
        $this->router->post('/nat/masquerade/new', [Controller\NatController::class, 'saveMasquerade']);
        $this->router->get('/nat/masquerade/{id}', [Controller\NatController::class, 'editMasquerade']);
        $this->router->post('/nat/masquerade/{id}', [Controller\NatController::class, 'saveMasquerade']);
        $this->router->post('/nat/masquerade/{id}/delete', [Controller\NatController::class, 'deleteMasquerade']);

        // VPN
        $this->router->get('/vpn', [Controller\VpnController::class, 'index']);
        $this->router->get('/vpn/openvpn', [Controller\VpnController::class, 'openvpnInstances']);
        $this->router->get('/vpn/openvpn/new', [Controller\VpnController::class, 'newOpenvpnInstance']);
        $this->router->post('/vpn/openvpn/new', [Controller\VpnController::class, 'saveOpenvpnInstance']);
        $this->router->get('/vpn/openvpn/pki', [Controller\VpnController::class, 'openvpnPki']);
        $this->router->post('/vpn/openvpn/pki/init', [Controller\VpnController::class, 'initializePki']);
        $this->router->post('/vpn/openvpn/pki/server-cert', [Controller\VpnController::class, 'generateServerCert']);
        $this->router->post('/vpn/openvpn/pki/client-cert', [Controller\VpnController::class, 'generateClientCert']);
        $this->router->post('/vpn/openvpn/pki/revoke/{name}', [Controller\VpnController::class, 'revokeClientCert']);
        $this->router->get('/vpn/openvpn/client/{server}/{client}/download', [Controller\VpnController::class, 'downloadClientConfig']);
        $this->router->get('/vpn/openvpn/{name}', [Controller\VpnController::class, 'editOpenvpnInstance']);
        $this->router->post('/vpn/openvpn/{name}', [Controller\VpnController::class, 'saveOpenvpnInstance']);
        $this->router->post('/vpn/openvpn/{name}/delete', [Controller\VpnController::class, 'deleteOpenvpnInstance']);
        $this->router->get('/vpn/ipsec', [Controller\VpnController::class, 'ipsecTunnels']);
        $this->router->get('/vpn/ipsec/new', [Controller\VpnController::class, 'newIpsecTunnel']);
        $this->router->post('/vpn/ipsec/new', [Controller\VpnController::class, 'saveIpsecTunnel']);
        $this->router->get('/vpn/ipsec/{name}', [Controller\VpnController::class, 'editIpsecTunnel']);
        $this->router->post('/vpn/ipsec/{name}', [Controller\VpnController::class, 'saveIpsecTunnel']);
        $this->router->post('/vpn/ipsec/{name}/delete', [Controller\VpnController::class, 'deleteIpsecTunnel']);
        $this->router->post('/vpn/ipsec/{name}/connect', [Controller\VpnController::class, 'connectTunnel']);
        $this->router->post('/vpn/ipsec/{name}/disconnect', [Controller\VpnController::class, 'disconnectTunnel']);
        $this->router->get('/vpn/wireguard', [Controller\VpnController::class, 'wireguardInterfaces']);
        $this->router->get('/vpn/wireguard/new', [Controller\VpnController::class, 'newWireguardInterface']);
        $this->router->post('/vpn/wireguard/new', [Controller\VpnController::class, 'saveWireguardInterface']);
        $this->router->get('/vpn/wireguard/{name}', [Controller\VpnController::class, 'editWireguardInterface']);
        $this->router->post('/vpn/wireguard/{name}', [Controller\VpnController::class, 'saveWireguardInterface']);
        $this->router->post('/vpn/wireguard/{name}/delete', [Controller\VpnController::class, 'deleteWireguardInterface']);
        $this->router->get('/vpn/wireguard/{name}/peer/new', [Controller\VpnController::class, 'newWireguardPeer']);
        $this->router->post('/vpn/wireguard/{name}/peer', [Controller\VpnController::class, 'saveWireguardPeer']);
        $this->router->post('/vpn/wireguard/{name}/peer/{key}/delete', [Controller\VpnController::class, 'deleteWireguardPeer']);
        $this->router->get('/vpn/wireguard/{name}/peer/{key}/config', [Controller\VpnController::class, 'wireguardPeerConfig']);

        // Load Balancer
        $this->router->get('/loadbalancer', [Controller\LoadBalancerController::class, 'index']);
        $this->router->get('/loadbalancer/settings', [Controller\LoadBalancerController::class, 'settings']);
        $this->router->post('/loadbalancer/settings', [Controller\LoadBalancerController::class, 'saveSettings']);
        $this->router->get('/loadbalancer/stats', [Controller\LoadBalancerController::class, 'stats']);

        // Load Balancer Frontends
        $this->router->get('/loadbalancer/frontend/new', [Controller\LoadBalancerController::class, 'newFrontend']);
        $this->router->post('/loadbalancer/frontend/new', [Controller\LoadBalancerController::class, 'saveFrontend']);
        $this->router->get('/loadbalancer/frontend/{name}', [Controller\LoadBalancerController::class, 'editFrontend']);
        $this->router->post('/loadbalancer/frontend/{name}', [Controller\LoadBalancerController::class, 'saveFrontend']);
        $this->router->post('/loadbalancer/frontend/{name}/delete', [Controller\LoadBalancerController::class, 'deleteFrontend']);

        // Load Balancer Backends
        $this->router->get('/loadbalancer/backend/new', [Controller\LoadBalancerController::class, 'newBackend']);
        $this->router->post('/loadbalancer/backend/new', [Controller\LoadBalancerController::class, 'saveBackend']);
        $this->router->get('/loadbalancer/backend/{name}', [Controller\LoadBalancerController::class, 'editBackend']);
        $this->router->post('/loadbalancer/backend/{name}', [Controller\LoadBalancerController::class, 'saveBackend']);
        $this->router->post('/loadbalancer/backend/{name}/delete', [Controller\LoadBalancerController::class, 'deleteBackend']);

        $this->router->get('/certificates', [Controller\CertificateController::class, 'index']);
        $this->router->get('/certificates/upload', [Controller\CertificateController::class, 'upload']);
        $this->router->post('/certificates/upload', [Controller\CertificateController::class, 'saveUpload']);
        $this->router->get('/certificates/acme', [Controller\CertificateController::class, 'acme']);
        $this->router->post('/certificates/acme/register', [Controller\CertificateController::class, 'acmeRegister']);
        $this->router->post('/certificates/acme/request', [Controller\CertificateController::class, 'acmeRequest']);
        $this->router->post('/certificates/acme/renew', [Controller\CertificateController::class, 'acmeRenew']);
        $this->router->post('/certificates/acme/renew-all', [Controller\CertificateController::class, 'acmeRenewAll']);
        $this->router->get('/certificates/{id}', [Controller\CertificateController::class, 'view']);
        $this->router->post('/certificates/{id}/delete', [Controller\CertificateController::class, 'delete']);

        // Services
        $this->router->get('/services', [Controller\ServicesController::class, 'index']);
        $this->router->post('/services/{service}/start', [Controller\ServicesController::class, 'start']);
        $this->router->post('/services/{service}/stop', [Controller\ServicesController::class, 'stop']);
        $this->router->post('/services/{service}/restart', [Controller\ServicesController::class, 'restart']);
        $this->router->post('/services/{service}/enable', [Controller\ServicesController::class, 'enable']);
        $this->router->post('/services/{service}/disable', [Controller\ServicesController::class, 'disable']);

        // System
        $this->router->get('/system', [Controller\SystemController::class, 'index']);
        $this->router->post('/system', [Controller\SystemController::class, 'save']);
        $this->router->get('/system/ssl', [Controller\SystemController::class, 'sslCertificate']);
        $this->router->post('/system/ssl', [Controller\SystemController::class, 'saveSslCertificate']);
        $this->router->get('/system/password', [Controller\SystemController::class, 'showPasswordForm']);
        $this->router->post('/system/password', [Controller\SystemController::class, 'changePassword']);
        $this->router->post('/system/config/apply', [Controller\SystemController::class, 'applyConfig']);
        $this->router->post('/system/config/confirm', [Controller\SystemController::class, 'confirmConfig']);
        $this->router->post('/system/config/cancel', [Controller\SystemController::class, 'cancelConfig']);
        $this->router->post('/system/config/discard', [Controller\SystemController::class, 'discardChanges']);
        $this->router->get('/system/config/status', [Controller\SystemController::class, 'configStatus']);
        $this->router->post('/system/reboot', [Controller\SystemController::class, 'reboot']);
        $this->router->post('/system/shutdown', [Controller\SystemController::class, 'shutdown']);
        $this->router->post('/system/backup', [Controller\SystemController::class, 'backup']);
        $this->router->get('/system/backup/download', [Controller\SystemController::class, 'downloadBackup']);
        $this->router->post('/system/restore', [Controller\SystemController::class, 'restore']);
        $this->router->post('/system/restore/upload', [Controller\SystemController::class, 'uploadRestore']);
        $this->router->post('/system/backup/delete', [Controller\SystemController::class, 'deleteBackup']);

        // Firmware
        $this->router->get('/firmware', [Controller\FirmwareController::class, 'index']);
        $this->router->post('/firmware/upload', [Controller\FirmwareController::class, 'upload']);

        // Debug (public routes for troubleshooting)
        $this->router->get('/debug', [Controller\DebugController::class, 'index']);
        $this->router->get('/debug/logs/apply', [Controller\DebugController::class, 'applyLog']);
        $this->router->get('/debug/logs/access', [Controller\DebugController::class, 'accessLog']);
        $this->router->get('/debug/logs/error', [Controller\DebugController::class, 'errorLog']);
        $this->router->get('/debug/logs/php', [Controller\DebugController::class, 'phpLog']);
        $this->router->get('/debug/logs/syslog', [Controller\DebugController::class, 'syslog']);
        $this->router->get('/debug/phpinfo', [Controller\DebugController::class, 'phpInfo']);
        $this->router->get('/debug/config', [Controller\DebugController::class, 'config']);
    }

    /**
     * Register API routes.
     *
     * @return void
     */
    public function registerApiRoutes(): void
    {
        // Status
        $this->router->get('/api/status', [Api\StatusApi::class, 'index']);
        $this->router->get('/api/status/system', [Api\StatusApi::class, 'system']);
        $this->router->get('/api/status/network', [Api\StatusApi::class, 'network']);

        // Config
        $this->router->get('/api/config', [Api\ConfigApi::class, 'get']);
        $this->router->get('/api/config/status', [Api\ConfigApi::class, 'status']);
        $this->router->post('/api/config', [Api\ConfigApi::class, 'update']);
        $this->router->post('/api/config/apply', [Api\ConfigApi::class, 'apply']);
        $this->router->post('/api/config/confirm', [Api\ConfigApi::class, 'confirm']);
        $this->router->post('/api/config/rollback', [Api\ConfigApi::class, 'rollback']);

        // Firewall
        $this->router->get('/api/firewall/status', [Api\FirewallApi::class, 'status']);
        $this->router->get('/api/firewall/rules', [Api\FirewallApi::class, 'rules']);
        $this->router->post('/api/firewall/rules', [Api\FirewallApi::class, 'saveRules']);

        // Load Balancer
        $this->router->get('/api/loadbalancer/status', [Api\LoadBalancerApi::class, 'status']);
        $this->router->get('/api/loadbalancer/stats', [Api\LoadBalancerApi::class, 'stats']);
    }

    /**
     * Check if a route is public (no auth required).
     *
     * @param string $uri Request URI
     * @return bool True if public
     */
    private function isPublicRoute(string $uri): bool
    {
        $publicRoutes = ['/login', '/api/status'];

        // Exact match
        if (in_array($uri, $publicRoutes, true)) {
            return true;
        }

        // Debug routes are public for troubleshooting
        if (strpos($uri, '/debug') === 0) {
            return true;
        }

        return false;
    }

    /**
     * Redirect to a URI.
     *
     * @param string $uri Target URI
     * @return void
     */
    private function redirect(string $uri): void
    {
        header('Location: ' . $uri);
        exit;
    }

    /**
     * Get default configuration.
     *
     * @return array Default config values
     */
    private function getDefaultConfig(): array
    {
        return [
            'debug' => false,
            'api_only' => false,
            'session_timeout' => 3600,
            'templates_path' => COYOTE_WEBADMIN_ROOT . '/templates',
        ];
    }

    /**
     * Get the router instance.
     *
     * @return Router
     */
    public function getRouter(): Router
    {
        return $this->router;
    }

    /**
     * Get the auth instance.
     *
     * @return Auth
     */
    public function getAuth(): Auth
    {
        return $this->auth;
    }
}
