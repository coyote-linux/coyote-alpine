<?php

namespace Coyote\WebAdmin\Controller;

use Coyote\WebAdmin\Service\ConfigService;
use Coyote\WebAdmin\Service\ApplyService;

/**
 * Firewall configuration controller.
 *
 * Manages firewall ACLs (Access Control Lists) and their application
 * to interface pairs.
 */
class FirewallController extends BaseController
{
    private const ADDRESS_LIST_PRESETS = [
        'cloudflare' => [
            'label' => 'Cloudflare Proxy IPs',
            'ipv4_url' => 'https://www.cloudflare.com/ips-v4',
            'ipv6_url' => 'https://www.cloudflare.com/ips-v6',
        ],
    ];
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
     * Display firewall overview.
     */
    public function index(array $params = []): void
    {
        $config = $this->configService->getWorkingConfig();
        $acls = $config->get('firewall.acls', []);
        $applied = $config->get('firewall.applied', []);
        $applyStatus = $this->applyService->getStatus();

        $data = [
            'acls' => $acls,
            'applied' => $applied,
            'applyStatus' => $applyStatus,
            'status' => [
                'enabled' => $config->get('firewall.enabled', true),
                'defaultPolicy' => $config->get('firewall.default_policy', 'drop'),
            ],
        ];

        $this->render('pages/firewall', $data);
    }

    /**
     * Display ACL list.
     */
    public function acls(array $params = []): void
    {
        $this->index($params);
    }

    /**
     * Create a new ACL.
     */
    public function createAcl(array $params = []): void
    {
        $data = [
            'acl' => null,
            'isNew' => true,
        ];

        $this->render('pages/firewall/acl-edit', $data);
    }

    /**
     * Save new ACL.
     */
    public function saveNewAcl(array $params = []): void
    {
        $name = trim($this->post('name', ''));
        $description = trim($this->post('description', ''));

        // Validation
        $errors = [];

        if (empty($name)) {
            $errors[] = 'ACL name is required';
        } elseif (!preg_match('/^[a-z][a-z0-9_-]*$/i', $name)) {
            $errors[] = 'ACL name must start with a letter and contain only letters, numbers, underscores, and hyphens';
        } elseif (strlen($name) > 32) {
            $errors[] = 'ACL name must be 32 characters or less';
        }

        // Check for duplicate name
        $config = $this->configService->getWorkingConfig();
        $acls = $config->get('firewall.acls', []);

        foreach ($acls as $acl) {
            if (strcasecmp($acl['name'] ?? '', $name) === 0) {
                $errors[] = "An ACL named '{$name}' already exists";
                break;
            }
        }

        if (!empty($errors)) {
            $this->flash('error', implode('. ', $errors));
            $this->redirect('/firewall/acl/new');
            return;
        }

        // Create new ACL
        $newAcl = [
            'name' => $name,
            'description' => $description,
            'rules' => [],
        ];

        $acls[] = $newAcl;
        $config->set('firewall.acls', $acls);

        if ($this->configService->saveWorkingConfig($config)) {
            $this->flash('success', "ACL '{$name}' created. Add rules to define what traffic to permit or deny.");
        } else {
            $this->flash('error', 'Failed to save configuration');
        }

        $this->redirect('/firewall/acl/' . urlencode($name));
    }

    /**
     * Edit an existing ACL (view/manage rules).
     */
    public function editAcl(array $params = []): void
    {
        $name = $params['name'] ?? '';

        $config = $this->configService->getWorkingConfig();
        $acls = $config->get('firewall.acls', []);

        // Find the ACL
        $acl = null;
        foreach ($acls as $a) {
            if (($a['name'] ?? '') === $name) {
                $acl = $a;
                break;
            }
        }

        if ($acl === null) {
            $this->flash('error', 'ACL not found: ' . $name);
            $this->redirect('/firewall');
            return;
        }

        $data = [
            'acl' => $acl,
            'isNew' => false,
        ];

        $this->render('pages/firewall/acl-edit', $data);
    }

    /**
     * Update ACL properties (name, description).
     */
    public function updateAcl(array $params = []): void
    {
        $name = $params['name'] ?? '';
        $newDescription = trim($this->post('description', ''));

        $config = $this->configService->getWorkingConfig();
        $acls = $config->get('firewall.acls', []);

        // Find and update the ACL
        $found = false;
        foreach ($acls as $i => $acl) {
            if (($acl['name'] ?? '') === $name) {
                $acls[$i]['description'] = $newDescription;
                $found = true;
                break;
            }
        }

        if (!$found) {
            $this->flash('error', 'ACL not found: ' . $name);
            $this->redirect('/firewall');
            return;
        }

        $config->set('firewall.acls', $acls);

        if ($this->configService->saveWorkingConfig($config)) {
            $this->flash('success', 'ACL updated');
        } else {
            $this->flash('error', 'Failed to save configuration');
        }

        $this->redirect('/firewall/acl/' . urlencode($name));
    }

    /**
     * Delete an ACL.
     */
    public function deleteAcl(array $params = []): void
    {
        $name = $params['name'] ?? '';

        $config = $this->configService->getWorkingConfig();
        $acls = $config->get('firewall.acls', []);
        $applied = $config->get('firewall.applied', []);

        // Check if ACL is in use
        foreach ($applied as $app) {
            if (($app['acl'] ?? '') === $name) {
                $this->flash('error', "Cannot delete ACL '{$name}': it is currently applied to interfaces. Remove the application first.");
                $this->redirect('/firewall/acl/' . urlencode($name));
                return;
            }
        }

        // Remove the ACL
        $acls = array_filter($acls, function ($acl) use ($name) {
            return ($acl['name'] ?? '') !== $name;
        });
        $acls = array_values($acls);

        $config->set('firewall.acls', $acls);

        if ($this->configService->saveWorkingConfig($config)) {
            $this->flash('success', "ACL '{$name}' deleted");
        } else {
            $this->flash('error', 'Failed to save configuration');
        }

        $this->redirect('/firewall');
    }

    /**
     * Add a rule to an ACL.
     */
    public function addRule(array $params = []): void
    {
        $aclName = $params['name'] ?? '';

        $config = $this->configService->getWorkingConfig();
        $acls = $config->get('firewall.acls', []);

        // Find the ACL
        $acl = null;
        foreach ($acls as $a) {
            if (($a['name'] ?? '') === $aclName) {
                $acl = $a;
                break;
            }
        }

        if ($acl === null) {
            $this->flash('error', 'ACL not found: ' . $aclName);
            $this->redirect('/firewall');
            return;
        }

        $data = [
            'acl' => $acl,
            'rule' => null,
            'ruleIndex' => null,
            'isNew' => true,
            'addressLists' => array_keys($this->getAddressLists()),
        ];

        $this->render('pages/firewall/rule-edit', $data);
    }

    /**
     * Edit an existing rule.
     */
    public function editRule(array $params = []): void
    {
        $aclName = $params['name'] ?? '';
        $ruleIndex = (int) ($params['index'] ?? -1);

        $config = $this->configService->getWorkingConfig();
        $acls = $config->get('firewall.acls', []);

        // Find the ACL
        $acl = null;
        foreach ($acls as $a) {
            if (($a['name'] ?? '') === $aclName) {
                $acl = $a;
                break;
            }
        }

        if ($acl === null) {
            $this->flash('error', 'ACL not found: ' . $aclName);
            $this->redirect('/firewall');
            return;
        }

        $rules = $acl['rules'] ?? [];
        if (!isset($rules[$ruleIndex])) {
            $this->flash('error', 'Rule not found');
            $this->redirect('/firewall/acl/' . urlencode($aclName));
            return;
        }

        $data = [
            'acl' => $acl,
            'rule' => $rules[$ruleIndex],
            'ruleIndex' => $ruleIndex,
            'isNew' => false,
            'addressLists' => array_keys($this->getAddressLists()),
        ];

        $this->render('pages/firewall/rule-edit', $data);
    }

    /**
     * Save a rule (new or existing).
     */
    public function saveRule(array $params = []): void
    {
        $aclName = $params['name'] ?? '';
        $ruleIndex = $this->post('rule_index');
        $isNew = $ruleIndex === null || $ruleIndex === '';

        // Get form data
        $action = $this->post('action', 'permit');
        $protocol = $this->post('protocol', 'any');
        $sourceType = $this->post('source_type', 'any');
        $sourceValue = trim($this->post('source_value', ''));
        $sourceList = trim($this->post('source_list', ''));
        $destType = $this->post('dest_type', 'any');
        $destValue = trim($this->post('dest_value', ''));
        $destList = trim($this->post('dest_list', ''));
        $ports = trim($this->post('ports', ''));
        $comment = trim($this->post('comment', ''));

        // Validation
        $errors = [];

        if (!in_array($action, ['permit', 'deny'], true)) {
            $errors[] = 'Invalid action';
        }

        if (!in_array($protocol, ['any', 'tcp', 'udp', 'icmp', 'gre', 'esp', 'ah'], true)) {
            $errors[] = 'Invalid protocol';
        }

        // Address list validation
        $addressLists = array_keys($this->getAddressLists());

        // Source validation
        if ($sourceType === 'list') {
            if (empty($sourceList)) {
                $errors[] = 'Source address list is required';
            } elseif (!in_array($sourceList, $addressLists, true)) {
                $errors[] = 'Source address list not found';
            }
            $source = 'any';
        } else {
            $source = $this->parseAddressField($sourceType, $sourceValue, 'Source', $errors);
            $sourceList = '';
        }

        // Destination validation
        if ($destType === 'list') {
            if (empty($destList)) {
                $errors[] = 'Destination address list is required';
            } elseif (!in_array($destList, $addressLists, true)) {
                $errors[] = 'Destination address list not found';
            }
            $dest = 'any';
        } else {
            $dest = $this->parseAddressField($destType, $destValue, 'Destination', $errors);
            $destList = '';
        }

        // Port validation (only for TCP/UDP)
        if (!empty($ports) && !in_array($protocol, ['tcp', 'udp'], true)) {
            $errors[] = 'Ports can only be specified for TCP or UDP protocols';
        }

        if (!empty($ports) && !$this->isValidPortSpec($ports)) {
            $errors[] = 'Invalid port specification. Use port number (80), range (80-443), or comma-separated (80,443,8080)';
        }

        if (!empty($errors)) {
            $this->flash('error', implode('. ', $errors));
            if ($isNew) {
                $this->redirect('/firewall/acl/' . urlencode($aclName) . '/rule/new');
            } else {
                $this->redirect('/firewall/acl/' . urlencode($aclName) . '/rule/' . $ruleIndex);
            }
            return;
        }

        // Build rule
        $rule = [
            'action' => $action,
            'protocol' => $protocol,
            'source' => $source,
            'destination' => $dest,
        ];

        if (!empty($sourceList)) {
            $rule['source_list'] = $sourceList;
        }

        if (!empty($destList)) {
            $rule['destination_list'] = $destList;
        }

        if (!empty($ports)) {
            $rule['ports'] = $ports;
            $rule['destination_port'] = $ports;
        }

        if (!empty($comment)) {
            $rule['comment'] = $comment;
        }

        // Update config
        $config = $this->configService->getWorkingConfig();
        $acls = $config->get('firewall.acls', []);

        // Find and update the ACL
        $found = false;
        foreach ($acls as $i => $acl) {
            if (($acl['name'] ?? '') === $aclName) {
                if ($isNew) {
                    $acls[$i]['rules'][] = $rule;
                } else {
                    $acls[$i]['rules'][(int)$ruleIndex] = $rule;
                }
                $found = true;
                break;
            }
        }

        if (!$found) {
            $this->flash('error', 'ACL not found: ' . $aclName);
            $this->redirect('/firewall');
            return;
        }

        $config->set('firewall.acls', $acls);

        if ($this->configService->saveWorkingConfig($config)) {
            $this->flash('success', $isNew ? 'Rule added' : 'Rule updated');
        } else {
            $this->flash('error', 'Failed to save configuration');
        }

        $this->redirect('/firewall/acl/' . urlencode($aclName));
    }

    /**
     * Delete a rule from an ACL.
     */
    public function deleteRule(array $params = []): void
    {
        $aclName = $params['name'] ?? '';
        $ruleIndex = (int) ($params['index'] ?? -1);

        $config = $this->configService->getWorkingConfig();
        $acls = $config->get('firewall.acls', []);

        // Find and update the ACL
        $found = false;
        foreach ($acls as $i => $acl) {
            if (($acl['name'] ?? '') === $aclName) {
                if (!isset($acls[$i]['rules'][$ruleIndex])) {
                    $this->flash('error', 'Rule not found');
                    $this->redirect('/firewall/acl/' . urlencode($aclName));
                    return;
                }

                // Remove the rule and re-index
                array_splice($acls[$i]['rules'], $ruleIndex, 1);
                $found = true;
                break;
            }
        }

        if (!$found) {
            $this->flash('error', 'ACL not found: ' . $aclName);
            $this->redirect('/firewall');
            return;
        }

        $config->set('firewall.acls', $acls);

        if ($this->configService->saveWorkingConfig($config)) {
            $this->flash('success', 'Rule deleted');
        } else {
            $this->flash('error', 'Failed to save configuration');
        }

        $this->redirect('/firewall/acl/' . urlencode($aclName));
    }

    /**
     * Move a rule up or down in the ACL.
     */
    public function moveRule(array $params = []): void
    {
        $aclName = $params['name'] ?? '';
        $ruleIndex = (int) ($params['index'] ?? -1);
        $direction = $this->post('direction', 'up');

        $config = $this->configService->getWorkingConfig();
        $acls = $config->get('firewall.acls', []);

        // Find and update the ACL
        foreach ($acls as $i => $acl) {
            if (($acl['name'] ?? '') === $aclName) {
                $rules = $acl['rules'] ?? [];
                $ruleCount = count($rules);

                if (!isset($rules[$ruleIndex])) {
                    $this->flash('error', 'Rule not found');
                    $this->redirect('/firewall/acl/' . urlencode($aclName));
                    return;
                }

                // Calculate new position
                $newIndex = $direction === 'up' ? $ruleIndex - 1 : $ruleIndex + 1;

                // Bounds check
                if ($newIndex < 0 || $newIndex >= $ruleCount) {
                    $this->redirect('/firewall/acl/' . urlencode($aclName));
                    return;
                }

                // Swap rules
                $temp = $rules[$ruleIndex];
                $rules[$ruleIndex] = $rules[$newIndex];
                $rules[$newIndex] = $temp;

                $acls[$i]['rules'] = $rules;
                $config->set('firewall.acls', $acls);

                if ($this->configService->saveWorkingConfig($config)) {
                    // No flash message for moves - it's a quick action
                } else {
                    $this->flash('error', 'Failed to save configuration');
                }

                $this->redirect('/firewall/acl/' . urlencode($aclName));
                return;
            }
        }

        $this->flash('error', 'ACL not found: ' . $aclName);
        $this->redirect('/firewall');
    }

    /**
     * Display ACL application management.
     */
    public function applications(array $params = []): void
    {
        $config = $this->configService->getWorkingConfig();
        $acls = $config->get('firewall.acls', []);
        $applied = $config->get('firewall.applied', []);

        // Get available interfaces
        $interfaces = $this->getAvailableInterfaces();

        $data = [
            'acls' => $acls,
            'applied' => $applied,
            'interfaces' => $interfaces,
        ];

        $this->render('pages/firewall/applications', $data);
    }

    /**
     * Add an ACL application to interface pair.
     */
    public function addApplication(array $params = []): void
    {
        $aclName = $this->post('acl', '');
        $inInterface = $this->post('in_interface', '');
        $outInterface = $this->post('out_interface', '');

        // Validation
        $errors = [];

        if (empty($aclName)) {
            $errors[] = 'ACL name is required';
        }

        if (empty($inInterface)) {
            $errors[] = 'Input interface is required';
        }

        if (empty($outInterface)) {
            $errors[] = 'Output interface is required';
        }

        $config = $this->configService->getWorkingConfig();
        $acls = $config->get('firewall.acls', []);
        $applied = $config->get('firewall.applied', []);

        // Verify ACL exists
        $aclExists = false;
        foreach ($acls as $acl) {
            if (($acl['name'] ?? '') === $aclName) {
                $aclExists = true;
                break;
            }
        }

        if (!$aclExists) {
            $errors[] = "ACL '{$aclName}' does not exist";
        }

        // Check for duplicate application
        foreach ($applied as $app) {
            if (($app['acl'] ?? '') === $aclName &&
                ($app['in_interface'] ?? '') === $inInterface &&
                ($app['out_interface'] ?? '') === $outInterface) {
                $errors[] = 'This ACL is already applied to this interface pair';
                break;
            }
        }

        if (!empty($errors)) {
            $this->flash('error', implode('. ', $errors));
            $this->redirect('/firewall/apply');
            return;
        }

        // Add application
        $applied[] = [
            'acl' => $aclName,
            'in_interface' => $inInterface,
            'out_interface' => $outInterface,
        ];

        $config->set('firewall.applied', $applied);

        if ($this->configService->saveWorkingConfig($config)) {
            $this->flash('success', "ACL '{$aclName}' applied to {$inInterface} -> {$outInterface}");
        } else {
            $this->flash('error', 'Failed to save configuration');
        }

        $this->redirect('/firewall/apply');
    }

    /**
     * Remove an ACL application.
     */
    public function removeApplication(array $params = []): void
    {
        $index = (int) ($params['index'] ?? -1);

        $config = $this->configService->getWorkingConfig();
        $applied = $config->get('firewall.applied', []);

        if (!isset($applied[$index])) {
            $this->flash('error', 'Application not found');
            $this->redirect('/firewall/apply');
            return;
        }

        array_splice($applied, $index, 1);
        $config->set('firewall.applied', $applied);

        if ($this->configService->saveWorkingConfig($config)) {
            $this->flash('success', 'ACL application removed');
        } else {
            $this->flash('error', 'Failed to save configuration');
        }

        $this->redirect('/firewall/apply');
    }

    /**
     * Legacy route redirect.
     */
    public function rules(array $params = []): void
    {
        $this->redirect('/firewall');
    }

    /**
     * Legacy route redirect.
     */
    public function saveRules(array $params = []): void
    {
        $this->flash('info', 'Use the ACL management interface to configure firewall rules.');
        $this->redirect('/firewall');
    }

    /**
     * Display address list management page.
     */
    public function addressLists(array $params = []): void
    {
        $lists = $this->getAddressLists();

        $this->render('pages/firewall/address-lists', [
            'lists' => $lists,
        ]);
    }

    /**
     * Show new address list form.
     */
    public function newAddressList(array $params = []): void
    {
        $this->render('pages/firewall/address-list-edit', [
            'list' => null,
            'isNew' => true,
        ]);
    }

    /**
     * Create a new address list.
     */
    public function createAddressList(array $params = []): void
    {
        $name = trim($this->post('name', ''));
        $description = trim($this->post('description', ''));

        $errors = [];
        if ($name === '') {
            $errors[] = 'List name is required';
        } elseif (!preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $name)) {
            $errors[] = 'List name must start with a letter and contain only letters, numbers, underscores, and hyphens';
        }

        $lists = $this->getAddressLists();
        if (isset($lists[$name])) {
            $errors[] = 'List name already exists';
        }

        if (!empty($errors)) {
            $this->flash('error', implode('. ', $errors));
            $this->redirect('/firewall/address-list/new');
            return;
        }

        $config = $this->configService->getWorkingConfig();
        $rawLists = $config->get('firewall.address_lists', []);
        $rawLists[$name] = [
            'description' => $description,
            'ipv4' => [],
            'ipv6' => [],
        ];
        $config->set('firewall.address_lists', $rawLists);

        if ($this->configService->saveWorkingConfig($config)) {
            $this->flash('success', 'Address list created');
            $this->redirect('/firewall/address-list/' . urlencode($name));
            return;
        }

        $this->flash('error', 'Failed to save configuration');
        $this->redirect('/firewall/address-list/new');
    }

    /**
     * Edit an address list.
     */
    public function editAddressList(array $params = []): void
    {
        $name = $params['name'] ?? '';
        $lists = $this->getAddressLists();

        if (!isset($lists[$name])) {
            $this->flash('error', 'Address list not found: ' . $name);
            $this->redirect('/firewall/address-lists');
            return;
        }

        $this->render('pages/firewall/address-list-edit', [
            'list' => $lists[$name],
            'isNew' => false,
            'presets' => $this->getAddressListPresets(),
        ]);
    }

    /**
     * Save an address list.
     */
    public function saveAddressList(array $params = []): void
    {
        $name = $params['name'] ?? '';
        $description = trim($this->post('description', ''));
        $ipv4Input = trim($this->post('ipv4_entries', ''));
        $ipv6Input = trim($this->post('ipv6_entries', ''));

        $lists = $this->getAddressLists();
        if (!isset($lists[$name])) {
            $this->flash('error', 'Address list not found: ' . $name);
            $this->redirect('/firewall/address-lists');
            return;
        }

        $ipv4Entries = $this->parseListEntries($ipv4Input);
        $ipv6Entries = $this->parseListEntries($ipv6Input);

        $errors = [];
        foreach ($ipv4Entries as $entry) {
            if (!$this->isValidAddressOrCidr($entry, 'ipv4')) {
                $errors[] = "Invalid IPv4 entry: {$entry}";
            }
        }
        foreach ($ipv6Entries as $entry) {
            if (!$this->isValidAddressOrCidr($entry, 'ipv6')) {
                $errors[] = "Invalid IPv6 entry: {$entry}";
            }
        }

        if (!empty($errors)) {
            $this->flash('error', implode('. ', $errors));
            $this->redirect('/firewall/address-list/' . urlencode($name));
            return;
        }

        $config = $this->configService->getWorkingConfig();
        $rawLists = $config->get('firewall.address_lists', []);
        $rawLists[$name] = [
            'description' => $description,
            'ipv4' => $ipv4Entries,
            'ipv6' => $ipv6Entries,
        ];
        $config->set('firewall.address_lists', $rawLists);

        if ($this->configService->saveWorkingConfig($config)) {
            $this->flash('success', 'Address list saved');
        } else {
            $this->flash('error', 'Failed to save configuration');
        }

        $this->redirect('/firewall/address-list/' . urlencode($name));
    }

    /**
     * Delete an address list.
     */
    public function deleteAddressList(array $params = []): void
    {
        $name = $params['name'] ?? '';
        $config = $this->configService->getWorkingConfig();
        $rawLists = $config->get('firewall.address_lists', []);

        if (!isset($rawLists[$name])) {
            $this->flash('error', 'Address list not found: ' . $name);
            $this->redirect('/firewall/address-lists');
            return;
        }

        $usage = $this->findAddressListUsage($name);
        if (!empty($usage)) {
            $this->flash('error', 'Address list is in use by ACLs: ' . implode(', ', $usage));
            $this->redirect('/firewall/address-lists');
            return;
        }

        unset($rawLists[$name]);
        $config->set('firewall.address_lists', $rawLists);

        if ($this->configService->saveWorkingConfig($config)) {
            $this->flash('success', 'Address list deleted');
        } else {
            $this->flash('error', 'Failed to save configuration');
        }

        $this->redirect('/firewall/address-lists');
    }

    /**
     * Import entries into an address list from preset or URL.
     */
    public function importAddressList(array $params = []): void
    {
        $name = $params['name'] ?? '';
        $preset = trim($this->post('preset', ''));
        $ipv4Url = trim($this->post('ipv4_url', ''));
        $ipv6Url = trim($this->post('ipv6_url', ''));

        $lists = $this->getAddressLists();
        if (!isset($lists[$name])) {
            $this->flash('error', 'Address list not found: ' . $name);
            $this->redirect('/firewall/address-lists');
            return;
        }

        if ($preset !== '') {
            $presets = $this->getAddressListPresets();
            if (!isset($presets[$preset])) {
                $this->flash('error', 'Unknown preset');
                $this->redirect('/firewall/address-list/' . urlencode($name));
                return;
            }
            $ipv4Url = $presets[$preset]['ipv4_url'] ?? '';
            $ipv6Url = $presets[$preset]['ipv6_url'] ?? '';
        }

        $errors = [];
        $ipv4Entries = $this->fetchAddressList($ipv4Url, 'ipv4', $errors);
        $ipv6Entries = $this->fetchAddressList($ipv6Url, 'ipv6', $errors);

        if (!empty($errors)) {
            $this->flash('error', implode('. ', $errors));
            $this->redirect('/firewall/address-list/' . urlencode($name));
            return;
        }

        $config = $this->configService->getWorkingConfig();
        $rawLists = $config->get('firewall.address_lists', []);
        $existing = $rawLists[$name] ?? [];

        $rawLists[$name] = [
            'description' => $existing['description'] ?? '',
            'ipv4' => $this->mergeListEntries($existing['ipv4'] ?? [], $ipv4Entries),
            'ipv6' => $this->mergeListEntries($existing['ipv6'] ?? [], $ipv6Entries),
        ];

        $config->set('firewall.address_lists', $rawLists);

        if ($this->configService->saveWorkingConfig($config)) {
            $this->flash('success', 'Address list imported');
        } else {
            $this->flash('error', 'Failed to save configuration');
        }

        $this->redirect('/firewall/address-list/' . urlencode($name));
    }

    /**
     * Parse an address field (source or destination).
     */
    private function parseAddressField(string $type, string $value, string $fieldName, array &$errors): string
    {
        switch ($type) {
            case 'any':
                return 'any';

            case 'ip':
                if (empty($value)) {
                    $errors[] = "{$fieldName} IP address is required";
                    return 'any';
                }
                if (!filter_var($value, FILTER_VALIDATE_IP)) {
                    $errors[] = "{$fieldName} IP address is invalid";
                    return 'any';
                }
                return $value;

            case 'network':
                if (empty($value)) {
                    $errors[] = "{$fieldName} network is required";
                    return 'any';
                }
                if (!$this->isValidCidr($value)) {
                    $errors[] = "{$fieldName} network must be in CIDR notation (e.g., 192.168.1.0/24)";
                    return 'any';
                }
                return $value;

            default:
                $errors[] = "Invalid {$fieldName} type";
                return 'any';
        }
    }

    /**
     * Validate CIDR notation.
     */
    private function isValidCidr(string $cidr): bool
    {
        if (strpos($cidr, '/') === false) {
            return false;
        }

        [$ip, $prefix] = explode('/', $cidr, 2);

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        if (!is_numeric($prefix)) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $prefix >= 0 && $prefix <= 128;
        }

        return $prefix >= 0 && $prefix <= 32;
    }

    private function getAddressLists(): array
    {
        $config = $this->configService->getWorkingConfig();
        $lists = $config->get('firewall.address_lists', []);

        return $this->normalizeAddressLists($lists);
    }

    private function getAddressListPresets(): array
    {
        return self::ADDRESS_LIST_PRESETS;
    }

    private function normalizeAddressLists(array $lists): array
    {
        $normalized = [];

        foreach ($lists as $key => $list) {
            $name = is_numeric($key) ? ($list['name'] ?? null) : $key;
            if (!$name) {
                continue;
            }

            $normalized[$name] = [
                'name' => $name,
                'description' => $list['description'] ?? '',
                'ipv4' => $list['ipv4'] ?? [],
                'ipv6' => $list['ipv6'] ?? [],
            ];
        }

        return $normalized;
    }

    private function parseListEntries(string $input): array
    {
        if ($input === '') {
            return [];
        }

        $entries = preg_split('/[\r\n,]+/', $input);
        $entries = array_map('trim', $entries);
        return array_values(array_filter($entries, fn($entry) => $entry !== ''));
    }

    private function isValidAddressOrCidr(string $value, string $family): bool
    {
        if ($family === 'ipv4') {
            if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return true;
            }
            return $this->isValidCidr($value) && !str_contains($value, ':');
        }

        if ($family === 'ipv6') {
            if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                return true;
            }
            return $this->isValidCidr($value) && str_contains($value, ':');
        }

        return false;
    }

    private function fetchAddressList(string $url, string $family, array &$errors): array
    {
        if ($url === '') {
            return [];
        }

        if (!str_starts_with($url, 'https://')) {
            $errors[] = 'Only https URLs are allowed';
            return [];
        }

        $content = @file_get_contents($url);
        if ($content === false) {
            $errors[] = "Failed to fetch {$url}";
            return [];
        }

        $entries = $this->parseListEntries($content);
        $valid = [];
        foreach ($entries as $entry) {
            if ($this->isValidAddressOrCidr($entry, $family)) {
                $valid[] = $entry;
            }
        }

        if (empty($valid)) {
            $errors[] = "No valid {$family} entries found in {$url}";
        }

        return $valid;
    }

    private function mergeListEntries(array $existing, array $incoming): array
    {
        $merged = array_merge($existing, $incoming);
        $merged = array_map('trim', $merged);
        $merged = array_filter($merged, fn($entry) => $entry !== '');
        $merged = array_values(array_unique($merged));
        sort($merged);
        return $merged;
    }

    private function findAddressListUsage(string $name): array
    {
        $config = $this->configService->getWorkingConfig();
        $acls = $config->get('firewall.acls', []);
        $usage = [];

        foreach ($acls as $acl) {
            $aclName = $acl['name'] ?? '';
            $rules = $acl['rules'] ?? [];
            foreach ($rules as $rule) {
                if (($rule['source_list'] ?? '') === $name || ($rule['destination_list'] ?? '') === $name) {
                    $usage[] = $aclName ?: 'Unnamed ACL';
                    break;
                }
            }
        }

        return array_values(array_unique($usage));
    }

    /**
     * Validate port specification.
     */
    private function isValidPortSpec(string $ports): bool
    {
        // Allow single port, range (80-443), or comma-separated (80,443,8080)
        $ports = trim($ports);

        // Remove spaces
        $ports = preg_replace('/\s+/', '', $ports);

        // Split by comma
        $parts = explode(',', $ports);

        foreach ($parts as $part) {
            // Check for range
            if (strpos($part, '-') !== false) {
                $range = explode('-', $part, 2);
                if (count($range) !== 2) {
                    return false;
                }
                if (!$this->isValidPort($range[0]) || !$this->isValidPort($range[1])) {
                    return false;
                }
                if ((int)$range[0] >= (int)$range[1]) {
                    return false;
                }
            } else {
                if (!$this->isValidPort($part)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Validate a single port number.
     */
    private function isValidPort(string $port): bool
    {
        if (!is_numeric($port)) {
            return false;
        }
        $port = (int)$port;
        return $port >= 1 && $port <= 65535;
    }

    /**
     * Get list of available network interfaces.
     */
    private function getAvailableInterfaces(): array
    {
        $interfaces = ['any' => 'Any Interface'];

        $network = new \Coyote\System\Network();
        foreach (array_keys($network->getInterfaces()) as $iface) {
            if ($iface === 'lo') {
                continue;
            }
            $interfaces[$iface] = $iface;
        }

        return $interfaces;
    }

    /**
     * Display access controls page (Web Admin and SSH hosts).
     */
    public function accessControls(array $params = []): void
    {
        $config = $this->configService->getWorkingConfig();

        $webadminHosts = $config->get('services.webadmin.allowed_hosts', []);
        $sshHosts = $config->get('services.ssh.allowed_hosts', []);
        $sshEnabled = $config->get('services.ssh.enabled', false);
        $sshPort = $config->get('services.ssh.port', 22);

        $this->render('pages/firewall/access-controls', [
            'webadminHosts' => $webadminHosts,
            'sshHosts' => $sshHosts,
            'sshEnabled' => $sshEnabled,
            'sshPort' => $sshPort,
        ]);
    }

    /**
     * Add a web admin allowed host.
     */
    public function addWebAdminHost(array $params = []): void
    {
        $host = trim($this->post('host', ''));

        if (empty($host)) {
            $this->flash('error', 'Please enter an IP address or network.');
            $this->redirect('/firewall/access');
            return;
        }

        if (!$this->isValidCidr($host)) {
            $this->flash('error', 'Invalid IP address or CIDR notation.');
            $this->redirect('/firewall/access');
            return;
        }

        // Add /32 if no mask specified
        if (strpos($host, '/') === false) {
            $host = "{$host}/32";
        }

        $config = $this->configService->getWorkingConfig();
        $hosts = $config->get('services.webadmin.allowed_hosts', []);

        // Check for duplicates
        if (in_array($host, $hosts)) {
            $this->flash('error', 'This host is already in the list.');
            $this->redirect('/firewall/access');
            return;
        }

        $hosts[] = $host;
        $config->set('services.webadmin.allowed_hosts', $hosts);

        if ($this->configService->saveWorkingConfig($config)) {
            $this->flash('success', "Added web admin host: {$host}");
        } else {
            $this->flash('error', 'Failed to save configuration');
        }

        $this->redirect('/firewall/access');
    }

    /**
     * Delete a web admin allowed host.
     */
    public function deleteWebAdminHost(array $params = []): void
    {
        $index = (int) ($params['index'] ?? -1);

        $config = $this->configService->getWorkingConfig();
        $hosts = $config->get('services.webadmin.allowed_hosts', []);

        if ($index < 0 || $index >= count($hosts)) {
            $this->flash('error', 'Invalid host index.');
            $this->redirect('/firewall/access');
            return;
        }

        $removed = $hosts[$index];
        array_splice($hosts, $index, 1);
        $config->set('services.webadmin.allowed_hosts', $hosts);

        if ($this->configService->saveWorkingConfig($config)) {
            $this->flash('success', "Removed web admin host: {$removed}");
        } else {
            $this->flash('error', 'Failed to save configuration');
        }

        $this->redirect('/firewall/access');
    }

    /**
     * Add an SSH allowed host.
     */
    public function addSshHost(array $params = []): void
    {
        $host = trim($this->post('host', ''));

        if (empty($host)) {
            $this->flash('error', 'Please enter an IP address or network.');
            $this->redirect('/firewall/access');
            return;
        }

        if (!$this->isValidCidr($host)) {
            $this->flash('error', 'Invalid IP address or CIDR notation.');
            $this->redirect('/firewall/access');
            return;
        }

        // Add /32 if no mask specified
        if (strpos($host, '/') === false) {
            $host = "{$host}/32";
        }

        $config = $this->configService->getWorkingConfig();
        $hosts = $config->get('services.ssh.allowed_hosts', []);

        // Check for duplicates
        if (in_array($host, $hosts)) {
            $this->flash('error', 'This host is already in the list.');
            $this->redirect('/firewall/access');
            return;
        }

        $hosts[] = $host;
        $config->set('services.ssh.allowed_hosts', $hosts);

        if ($this->configService->saveWorkingConfig($config)) {
            $this->flash('success', "Added SSH host: {$host}");
        } else {
            $this->flash('error', 'Failed to save configuration');
        }

        $this->redirect('/firewall/access');
    }

    /**
     * Delete an SSH allowed host.
     */
    public function deleteSshHost(array $params = []): void
    {
        $index = (int) ($params['index'] ?? -1);

        $config = $this->configService->getWorkingConfig();
        $hosts = $config->get('services.ssh.allowed_hosts', []);

        if ($index < 0 || $index >= count($hosts)) {
            $this->flash('error', 'Invalid host index.');
            $this->redirect('/firewall/access');
            return;
        }

        $removed = $hosts[$index];
        array_splice($hosts, $index, 1);
        $config->set('services.ssh.allowed_hosts', $hosts);

        if ($this->configService->saveWorkingConfig($config)) {
            $this->flash('success', "Removed SSH host: {$removed}");
        } else {
            $this->flash('error', 'Failed to save configuration');
        }

        $this->redirect('/firewall/access');
    }
}
