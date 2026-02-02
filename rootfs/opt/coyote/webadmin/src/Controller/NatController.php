<?php

namespace Coyote\WebAdmin\Controller;

use Coyote\System\Network;
use Coyote\WebAdmin\Service\ConfigService;
use Coyote\WebAdmin\Service\ApplyService;

/**
 * NAT configuration controller.
 *
 * Handles port forwards (DNAT) and masquerade/SNAT rules.
 */
class NatController extends BaseController
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
     * Display NAT overview.
     */
    public function index(array $params = []): void
    {
        $config = $this->configService->getWorkingConfig();

        // Get port forwards
        $forwards = $config->get('firewall.port_forwards', []);

        // Get masquerade rules
        $natConfig = $config->get('firewall.nat', []);
        $masqueradeRules = $natConfig['masquerade'] ?? [];

        // Get network interfaces for display
        $network = new Network();
        $interfaces = array_keys($network->getInterfaces());

        // Get apply status
        $applyStatus = $this->applyService->getStatus();

        $data = [
            'forwards' => $forwards,
            'masqueradeRules' => $masqueradeRules,
            'interfaces' => $interfaces,
            'applyStatus' => $applyStatus,
        ];

        $this->render('pages/nat', $data);
    }

    /**
     * Show add port forward form.
     */
    public function newForward(array $params = []): void
    {
        $network = new Network();
        $interfaces = array_keys($network->getInterfaces());

        $data = [
            'forward' => [
                'enabled' => true,
                'protocol' => 'tcp',
                'external_port' => '',
                'internal_ip' => '',
                'internal_port' => '',
                'interface' => '',
                'source' => '',
                'comment' => '',
            ],
            'isNew' => true,
            'interfaces' => $interfaces,
        ];

        $this->render('pages/nat/forward-edit', $data);
    }

    /**
     * Show edit port forward form.
     */
    public function editForward(array $params = []): void
    {
        $id = $params['id'] ?? '';

        if (!is_numeric($id)) {
            $this->flash('error', 'Invalid port forward ID');
            $this->redirect('/nat');
            return;
        }

        $config = $this->configService->getWorkingConfig();
        $forwards = $config->get('firewall.port_forwards', []);
        $index = (int)$id;

        if (!isset($forwards[$index])) {
            $this->flash('error', 'Port forward not found');
            $this->redirect('/nat');
            return;
        }

        $network = new Network();
        $interfaces = array_keys($network->getInterfaces());

        $data = [
            'forward' => $forwards[$index],
            'isNew' => false,
            'id' => $index,
            'interfaces' => $interfaces,
        ];

        $this->render('pages/nat/forward-edit', $data);
    }

    /**
     * Save port forward (create or update).
     */
    public function saveForward(array $params = []): void
    {
        $id = $params['id'] ?? 'new';
        $isNew = ($id === 'new');

        // Get form data
        $protocol = $this->post('protocol', 'tcp');
        $externalPort = trim($this->post('external_port', ''));
        $internalIp = trim($this->post('internal_ip', ''));
        $internalPort = trim($this->post('internal_port', ''));
        $interface = trim($this->post('interface', ''));
        $source = trim($this->post('source', ''));
        $comment = trim($this->post('comment', ''));
        $enabled = $this->post('enabled', '') === '1';

        // Use external port as internal port if not specified
        if (empty($internalPort)) {
            $internalPort = $externalPort;
        }

        // Validation
        $errors = [];

        if (!in_array($protocol, ['tcp', 'udp', 'both'])) {
            $errors[] = 'Invalid protocol';
        }

        if (empty($externalPort) || !is_numeric($externalPort)) {
            $errors[] = 'External port is required and must be a number';
        } elseif ((int)$externalPort < 1 || (int)$externalPort > 65535) {
            $errors[] = 'External port must be between 1 and 65535';
        }

        if (empty($internalIp)) {
            $errors[] = 'Internal IP address is required';
        } elseif (!filter_var($internalIp, FILTER_VALIDATE_IP)) {
            $errors[] = 'Invalid internal IP address';
        }

        if (!empty($internalPort)) {
            if (!is_numeric($internalPort)) {
                $errors[] = 'Internal port must be a number';
            } elseif ((int)$internalPort < 1 || (int)$internalPort > 65535) {
                $errors[] = 'Internal port must be between 1 and 65535';
            }
        }

        // Validate source if provided (should be IP or CIDR)
        if (!empty($source)) {
            if (!$this->isValidIpOrCidr($source)) {
                $errors[] = 'Invalid source address. Use IP address or CIDR notation (e.g., 192.168.1.0/24)';
            }
        }

        if (!empty($errors)) {
            $this->flash('error', implode('. ', $errors));
            $this->redirect($isNew ? '/nat/forward/new' : '/nat/forward/' . $id);
            return;
        }

        // Build forward entry
        $forward = [
            'enabled' => $enabled,
            'protocol' => $protocol,
            'external_port' => (int)$externalPort,
            'internal_ip' => $internalIp,
            'internal_port' => (int)$internalPort,
        ];

        if (!empty($interface)) {
            $forward['interface'] = $interface;
        }
        if (!empty($source)) {
            $forward['source'] = $source;
        }
        if (!empty($comment)) {
            $forward['comment'] = $comment;
        }

        // Save to config
        $config = $this->configService->getWorkingConfig();
        $forwards = $config->get('firewall.port_forwards', []);

        if ($isNew) {
            $forwards[] = $forward;
        } else {
            $forwards[(int)$id] = $forward;
        }

        $config->set('firewall.port_forwards', array_values($forwards));

        if ($this->configService->saveWorkingConfig($config)) {
            $this->flash('success', 'Port forward saved. Click "Apply Configuration" to activate.');
        } else {
            $this->flash('error', 'Failed to save configuration');
        }

        $this->redirect('/nat');
    }

    /**
     * Delete a port forward.
     */
    public function deleteForward(array $params = []): void
    {
        $id = $params['id'] ?? '';

        if (!is_numeric($id)) {
            $this->flash('error', 'Invalid port forward ID');
            $this->redirect('/nat');
            return;
        }

        $config = $this->configService->getWorkingConfig();
        $forwards = $config->get('firewall.port_forwards', []);
        $index = (int)$id;

        if (!isset($forwards[$index])) {
            $this->flash('error', 'Port forward not found');
            $this->redirect('/nat');
            return;
        }

        // Remove the forward
        unset($forwards[$index]);

        // Re-index array
        $forwards = array_values($forwards);

        $config->set('firewall.port_forwards', $forwards);

        if ($this->configService->saveWorkingConfig($config)) {
            $this->flash('success', 'Port forward deleted. Click "Apply Configuration" to activate.');
        } else {
            $this->flash('error', 'Failed to save configuration');
        }

        $this->redirect('/nat');
    }

    /**
     * Show add masquerade form.
     */
    public function newMasquerade(array $params = []): void
    {
        $network = new Network();
        $interfaces = array_keys($network->getInterfaces());

        $data = [
            'masquerade' => [
                'enabled' => true,
                'interface' => '',
                'source' => '',
                'comment' => '',
            ],
            'isNew' => true,
            'interfaces' => $interfaces,
        ];

        $this->render('pages/nat/masquerade-edit', $data);
    }

    /**
     * Show edit masquerade form.
     */
    public function editMasquerade(array $params = []): void
    {
        $id = $params['id'] ?? '';

        if (!is_numeric($id)) {
            $this->flash('error', 'Invalid masquerade rule ID');
            $this->redirect('/nat');
            return;
        }

        $config = $this->configService->getWorkingConfig();
        $natConfig = $config->get('firewall.nat', []);
        $masqueradeRules = $natConfig['masquerade'] ?? [];
        $index = (int)$id;

        if (!isset($masqueradeRules[$index])) {
            $this->flash('error', 'Masquerade rule not found');
            $this->redirect('/nat');
            return;
        }

        $network = new Network();
        $interfaces = array_keys($network->getInterfaces());

        $data = [
            'masquerade' => $masqueradeRules[$index],
            'isNew' => false,
            'id' => $index,
            'interfaces' => $interfaces,
        ];

        $this->render('pages/nat/masquerade-edit', $data);
    }

    /**
     * Save masquerade rule (create or update).
     */
    public function saveMasquerade(array $params = []): void
    {
        $id = $params['id'] ?? 'new';
        $isNew = ($id === 'new');

        // Get form data
        $interface = trim($this->post('interface', ''));
        $source = trim($this->post('source', ''));
        $comment = trim($this->post('comment', ''));
        $enabled = $this->post('enabled', '') === '1';

        // Validation
        $errors = [];

        if (empty($interface)) {
            $errors[] = 'Output interface is required';
        }

        // Validate source if provided (should be IP or CIDR)
        if (!empty($source)) {
            if (!$this->isValidIpOrCidr($source)) {
                $errors[] = 'Invalid source network. Use CIDR notation (e.g., 192.168.1.0/24)';
            }
        }

        if (!empty($errors)) {
            $this->flash('error', implode('. ', $errors));
            $this->redirect($isNew ? '/nat/masquerade/new' : '/nat/masquerade/' . $id);
            return;
        }

        // Build masquerade entry
        $masquerade = [
            'enabled' => $enabled,
            'interface' => $interface,
        ];

        if (!empty($source)) {
            $masquerade['source'] = $source;
        }
        if (!empty($comment)) {
            $masquerade['comment'] = $comment;
        }

        // Save to config
        $config = $this->configService->getWorkingConfig();
        $natConfig = $config->get('firewall.nat', []);
        $masqueradeRules = $natConfig['masquerade'] ?? [];

        if ($isNew) {
            $masqueradeRules[] = $masquerade;
        } else {
            $masqueradeRules[(int)$id] = $masquerade;
        }

        $natConfig['masquerade'] = array_values($masqueradeRules);
        $config->set('firewall.nat', $natConfig);

        if ($this->configService->saveWorkingConfig($config)) {
            $this->flash('success', 'Masquerade rule saved. Click "Apply Configuration" to activate.');
        } else {
            $this->flash('error', 'Failed to save configuration');
        }

        $this->redirect('/nat');
    }

    /**
     * Delete a masquerade rule.
     */
    public function deleteMasquerade(array $params = []): void
    {
        $id = $params['id'] ?? '';

        if (!is_numeric($id)) {
            $this->flash('error', 'Invalid masquerade rule ID');
            $this->redirect('/nat');
            return;
        }

        $config = $this->configService->getWorkingConfig();
        $natConfig = $config->get('firewall.nat', []);
        $masqueradeRules = $natConfig['masquerade'] ?? [];
        $index = (int)$id;

        if (!isset($masqueradeRules[$index])) {
            $this->flash('error', 'Masquerade rule not found');
            $this->redirect('/nat');
            return;
        }

        // Remove the rule
        unset($masqueradeRules[$index]);

        // Re-index array
        $masqueradeRules = array_values($masqueradeRules);

        $natConfig['masquerade'] = $masqueradeRules;
        $config->set('firewall.nat', $natConfig);

        if ($this->configService->saveWorkingConfig($config)) {
            $this->flash('success', 'Masquerade rule deleted. Click "Apply Configuration" to activate.');
        } else {
            $this->flash('error', 'Failed to save configuration');
        }

        $this->redirect('/nat');
    }

    /**
     * Validate IP address or CIDR notation.
     */
    private function isValidIpOrCidr(string $value): bool
    {
        // Check for plain IP address
        if (filter_var($value, FILTER_VALIDATE_IP)) {
            return true;
        }

        // Check for CIDR notation
        if (strpos($value, '/') !== false) {
            [$ip, $prefix] = explode('/', $value, 2);

            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return false;
            }

            if (!is_numeric($prefix) || $prefix < 0 || $prefix > 32) {
                return false;
            }

            return true;
        }

        return false;
    }
}
