<?php

namespace Coyote\WebAdmin\Controller;

use Coyote\Certificate\CertificateInfo;
use Coyote\Certificate\CertificateStore;
use Coyote\WebAdmin\Service\ConfigService;
use Coyote\WebAdmin\Service\ApplyService;
use Coyote\System\Services;

/**
 * Load balancer configuration controller.
 *
 * Manages HAProxy frontends, backends, and global settings via the web admin.
 */
class LoadBalancerController extends BaseController
{
    private ConfigService $configService;
    private ApplyService $applyService;

    public function __construct()
    {
        parent::__construct();
        $this->configService = new ConfigService();
        $this->applyService = new ApplyService();
    }

    // ── Overview ───────────────────────────────────────────────────────

    public function index(array $params = []): void
    {
        $config = $this->configService->getWorkingConfig();
        $lb = $config->get('loadbalancer', []);
        $services = new Services();

        $data = [
            'enabled' => $lb['enabled'] ?? false,
            'haproxy_running' => $services->isRunning('haproxy'),
            'frontends' => $lb['frontends'] ?? [],
            'backends' => $lb['backends'] ?? [],
            'stats_enabled' => $lb['stats']['enabled'] ?? false,
            'defaults' => $lb['defaults'] ?? [],
        ];

        $this->render('pages/loadbalancer', $data);
    }

    // ── Global Settings ───────────────────────────────────────────────

    public function settings(array $params = []): void
    {
        $config = $this->configService->getWorkingConfig();
        $lb = $config->get('loadbalancer', []);

        $data = [
            'enabled' => $lb['enabled'] ?? false,
            'defaults' => $lb['defaults'] ?? [
                'mode' => 'http',
                'timeout_connect' => '5s',
                'timeout_client' => '50s',
                'timeout_server' => '50s',
            ],
            'stats' => $lb['stats'] ?? [
                'enabled' => true,
                'port' => 8404,
                'uri' => '/stats',
            ],
        ];

        $this->render('pages/loadbalancer/settings', $data);
    }

    public function saveSettings(array $params = []): void
    {
        $config = $this->configService->getWorkingConfig();
        $lb = $config->get('loadbalancer', []);

        $lb['enabled'] = $this->post('enabled') === '1';
        $lb['defaults'] = [
            'mode' => in_array($this->post('default_mode'), ['http', 'tcp'], true)
                ? $this->post('default_mode') : 'http',
            'timeout_connect' => $this->sanitizeTimeout($this->post('timeout_connect', '5s')),
            'timeout_client' => $this->sanitizeTimeout($this->post('timeout_client', '50s')),
            'timeout_server' => $this->sanitizeTimeout($this->post('timeout_server', '50s')),
        ];
        $lb['stats'] = [
            'enabled' => $this->post('stats_enabled') === '1',
            'port' => max(1, min(65535, (int)$this->post('stats_port', '8404'))),
            'uri' => $this->post('stats_uri', '/stats') ?: '/stats',
        ];

        $config->set('loadbalancer', $lb);

        if ($this->configService->saveWorkingConfig($config)) {
            $this->flash('success', 'Load balancer settings saved');
        } else {
            $this->flash('error', 'Failed to save configuration');
        }

        $this->redirect('/loadbalancer/settings');
    }

    // ── Frontends ─────────────────────────────────────────────────────

    public function newFrontend(array $params = []): void
    {
        $config = $this->configService->getWorkingConfig();
        $backends = $config->get('loadbalancer.backends', []);

        $data = [
            'frontend' => null,
            'isNew' => true,
            'backendNames' => array_keys($backends),
            'serverCerts' => $this->getServerCertificates(),
        ];

        $this->render('pages/loadbalancer/frontend-edit', $data);
    }

    public function editFrontend(array $params = []): void
    {
        $name = $params['name'] ?? '';
        $config = $this->configService->getWorkingConfig();
        $frontends = $config->get('loadbalancer.frontends', []);
        $backends = $config->get('loadbalancer.backends', []);

        if (!isset($frontends[$name])) {
            $this->flash('error', 'Frontend not found: ' . $name);
            $this->redirect('/loadbalancer');
            return;
        }

        $data = [
            'frontend' => array_merge(['name' => $name], $frontends[$name]),
            'isNew' => false,
            'backendNames' => array_keys($backends),
            'serverCerts' => $this->getServerCertificates(),
        ];

        $this->render('pages/loadbalancer/frontend-edit', $data);
    }

    public function saveFrontend(array $params = []): void
    {
        $name = trim($this->post('name', ''));
        $isNew = $this->post('is_new') === '1';

        $errors = $this->validateFrontendInput($name, $isNew);
        if (!empty($errors)) {
            $this->flash('error', implode('. ', $errors));
            $this->redirect($isNew ? '/loadbalancer/frontend/new' : '/loadbalancer/frontend/' . urlencode($name));
            return;
        }

        $config = $this->configService->getWorkingConfig();
        $lb = $config->get('loadbalancer', []);

        $frontendConfig = [
            'bind' => trim($this->post('bind', '*:80')),
            'mode' => in_array($this->post('mode'), ['http', 'tcp'], true)
                ? $this->post('mode') : 'http',
            'backend' => trim($this->post('default_backend', '')),
            'ssl_cert' => trim($this->post('ssl_cert', '')),
            'http_request_add_header' => $this->post('forward_headers') === '1',
            'maxconn' => (int)$this->post('maxconn', '0') ?: null,
            'acls' => [],
            'use_backend' => [],
        ];

        if (empty($frontendConfig['ssl_cert'])) {
            unset($frontendConfig['ssl_cert']);
        }
        if ($frontendConfig['maxconn'] === null) {
            unset($frontendConfig['maxconn']);
        }

        $lb['frontends'][$name] = $frontendConfig;
        $config->set('loadbalancer', $lb);

        if ($this->configService->saveWorkingConfig($config)) {
            $this->flash('success', $isNew
                ? "Frontend '{$name}' created"
                : "Frontend '{$name}' updated");
        } else {
            $this->flash('error', 'Failed to save configuration');
        }

        $this->redirect('/loadbalancer/frontend/' . urlencode($name));
    }

    public function deleteFrontend(array $params = []): void
    {
        $name = $params['name'] ?? '';
        $config = $this->configService->getWorkingConfig();
        $lb = $config->get('loadbalancer', []);

        if (!isset($lb['frontends'][$name])) {
            $this->flash('error', 'Frontend not found: ' . $name);
            $this->redirect('/loadbalancer');
            return;
        }

        unset($lb['frontends'][$name]);
        $config->set('loadbalancer', $lb);

        if ($this->configService->saveWorkingConfig($config)) {
            $this->flash('success', "Frontend '{$name}' deleted");
        } else {
            $this->flash('error', 'Failed to save configuration');
        }

        $this->redirect('/loadbalancer');
    }

    // ── Backends ──────────────────────────────────────────────────────

    public function newBackend(array $params = []): void
    {
        $data = [
            'backend' => null,
            'isNew' => true,
        ];

        $this->render('pages/loadbalancer/backend-edit', $data);
    }

    public function editBackend(array $params = []): void
    {
        $name = $params['name'] ?? '';
        $config = $this->configService->getWorkingConfig();
        $backends = $config->get('loadbalancer.backends', []);

        if (!isset($backends[$name])) {
            $this->flash('error', 'Backend not found: ' . $name);
            $this->redirect('/loadbalancer');
            return;
        }

        $data = [
            'backend' => array_merge(['name' => $name], $backends[$name]),
            'isNew' => false,
        ];

        $this->render('pages/loadbalancer/backend-edit', $data);
    }

    public function saveBackend(array $params = []): void
    {
        $name = trim($this->post('name', ''));
        $isNew = $this->post('is_new') === '1';

        $errors = $this->validateBackendInput($name, $isNew);
        if (!empty($errors)) {
            $this->flash('error', implode('. ', $errors));
            $this->redirect($isNew ? '/loadbalancer/backend/new' : '/loadbalancer/backend/' . urlencode($name));
            return;
        }

        $config = $this->configService->getWorkingConfig();
        $lb = $config->get('loadbalancer', []);

        $servers = $this->parseServersInput();

        $backendConfig = [
            'mode' => in_array($this->post('mode'), ['http', 'tcp'], true)
                ? $this->post('mode') : 'http',
            'balance' => $this->sanitizeBalance($this->post('balance', 'roundrobin')),
            'health_check' => $this->post('health_check') === '1',
            'health_check_path' => trim($this->post('health_check_path', 'GET /')),
            'cookie' => trim($this->post('cookie', '')),
            'servers' => $servers,
        ];

        if (empty($backendConfig['cookie'])) {
            unset($backendConfig['cookie']);
        }
        if (!$backendConfig['health_check']) {
            unset($backendConfig['health_check_path']);
        }

        $lb['backends'][$name] = $backendConfig;
        $config->set('loadbalancer', $lb);

        if ($this->configService->saveWorkingConfig($config)) {
            $this->flash('success', $isNew
                ? "Backend '{$name}' created"
                : "Backend '{$name}' updated");
        } else {
            $this->flash('error', 'Failed to save configuration');
        }

        $this->redirect('/loadbalancer/backend/' . urlencode($name));
    }

    public function deleteBackend(array $params = []): void
    {
        $name = $params['name'] ?? '';
        $config = $this->configService->getWorkingConfig();
        $lb = $config->get('loadbalancer', []);

        if (!isset($lb['backends'][$name])) {
            $this->flash('error', 'Backend not found: ' . $name);
            $this->redirect('/loadbalancer');
            return;
        }

        foreach ($lb['frontends'] ?? [] as $feName => $fe) {
            if (($fe['backend'] ?? '') === $name) {
                $this->flash('error', "Cannot delete backend '{$name}': it is the default backend for frontend '{$feName}'.");
                $this->redirect('/loadbalancer/backend/' . urlencode($name));
                return;
            }
        }

        unset($lb['backends'][$name]);
        $config->set('loadbalancer', $lb);

        if ($this->configService->saveWorkingConfig($config)) {
            $this->flash('success', "Backend '{$name}' deleted");
        } else {
            $this->flash('error', 'Failed to save configuration');
        }

        $this->redirect('/loadbalancer');
    }

    // ── Stats ─────────────────────────────────────────────────────────

    public function stats(array $params = []): void
    {
        $config = $this->configService->getWorkingConfig();
        $lb = $config->get('loadbalancer', []);
        $services = new Services();

        $data = [
            'haproxy_running' => $services->isRunning('haproxy'),
            'frontends' => $lb['frontends'] ?? [],
            'backends' => $lb['backends'] ?? [],
            'stats_enabled' => $lb['stats']['enabled'] ?? false,
            'stats_port' => $lb['stats']['port'] ?? 8404,
            'stats_uri' => $lb['stats']['uri'] ?? '/stats',
        ];

        $this->render('pages/loadbalancer/stats', $data);
    }

    // ── Validation Helpers ────────────────────────────────────────────

    private function validateFrontendInput(string $name, bool $isNew): array
    {
        $errors = [];

        if (empty($name)) {
            $errors[] = 'Frontend name is required';
        } elseif (!preg_match('/^[a-z][a-z0-9_-]*$/i', $name)) {
            $errors[] = 'Frontend name must start with a letter and contain only letters, numbers, underscores, and hyphens';
        } elseif (strlen($name) > 32) {
            $errors[] = 'Frontend name must be 32 characters or less';
        }

        if ($isNew && !empty($name)) {
            $config = $this->configService->getWorkingConfig();
            $frontends = $config->get('loadbalancer.frontends', []);
            if (isset($frontends[$name])) {
                $errors[] = "A frontend named '{$name}' already exists";
            }
        }

        $bind = trim($this->post('bind', ''));
        if (empty($bind)) {
            $errors[] = 'Bind address is required (e.g. *:80)';
        }

        return $errors;
    }

    private function validateBackendInput(string $name, bool $isNew): array
    {
        $errors = [];

        if (empty($name)) {
            $errors[] = 'Backend name is required';
        } elseif (!preg_match('/^[a-z][a-z0-9_-]*$/i', $name)) {
            $errors[] = 'Backend name must start with a letter and contain only letters, numbers, underscores, and hyphens';
        } elseif (strlen($name) > 32) {
            $errors[] = 'Backend name must be 32 characters or less';
        }

        if ($isNew && !empty($name)) {
            $config = $this->configService->getWorkingConfig();
            $backends = $config->get('loadbalancer.backends', []);
            if (isset($backends[$name])) {
                $errors[] = "A backend named '{$name}' already exists";
            }
        }

        return $errors;
    }

    private function parseServersInput(): array
    {
        $addresses = $this->post('server_address', []);
        $ports = $this->post('server_port', []);
        $weights = $this->post('server_weight', []);
        $names = $this->post('server_name', []);
        $backups = $this->post('server_backup', []);

        if (!is_array($addresses)) {
            return [];
        }

        $servers = [];
        foreach ($addresses as $i => $address) {
            $address = trim($address);
            if (empty($address)) {
                continue;
            }

            $server = [
                'address' => $address,
                'port' => max(1, min(65535, (int)($ports[$i] ?? 80))),
                'weight' => max(0, min(256, (int)($weights[$i] ?? 1))),
            ];

            $serverName = trim($names[$i] ?? '');
            if (!empty($serverName)) {
                $server['name'] = $serverName;
            }

            if (!empty($backups[$i])) {
                $server['backup'] = true;
            }

            $servers[] = $server;
        }

        return $servers;
    }

    private function sanitizeTimeout(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^\d+[smhd]?$/', $value)) {
            return $value;
        }
        return '30s';
    }

    private function sanitizeBalance(string $value): string
    {
        $allowed = ['roundrobin', 'leastconn', 'source', 'first', 'uri', 'url_param', 'hdr', 'random'];
        return in_array($value, $allowed, true) ? $value : 'roundrobin';
    }

    private function getServerCertificates(): array
    {
        $store = new CertificateStore();
        if (!$store->initialize()) {
            return [];
        }

        $certificates = [];

        foreach ($store->listByType(CertificateStore::DIR_SERVER) as $entry) {
            $id = (string)($entry['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $content = $store->getContent($id);
            $entry['info'] = is_string($content) ? CertificateInfo::parse($content) : null;
            $certificates[] = $entry;
        }

        usort($certificates, static function (array $left, array $right): int {
            return strcmp((string)($left['name'] ?? ''), (string)($right['name'] ?? ''));
        });

        return $certificates;
    }
}
