<?php

namespace Coyote\WebAdmin\Api;

use Coyote\WebAdmin\Service\ConfigService;

class FirewallApi extends BaseApi
{
    private ConfigService $configService;

    public function __construct()
    {
        $this->configService = new ConfigService();
    }

    public function status(array $params = []): void
    {
        $running = $this->configService->getRunningConfig()->toArray();
        $firewall = $running['firewall'] ?? [];

        $this->json([
            'enabled' => (bool)($firewall['enabled'] ?? true),
            'default_policy' => (string)($firewall['default_policy'] ?? 'drop'),
            'rules_count' => count($firewall['rules'] ?? []),
            'acls_count' => count($firewall['acls'] ?? []),
            'port_forwards_count' => count($firewall['port_forwards'] ?? []),
        ]);
    }

    public function rules(array $params = []): void
    {
        $working = $this->configService->getWorkingConfig()->toArray();
        $firewall = $working['firewall'] ?? [];

        $this->json([
            'rules' => $firewall['rules'] ?? [],
            'acls' => $firewall['acls'] ?? [],
        ]);
    }

    public function saveRules(array $params = []): void
    {
        $payload = $this->getJsonBody();
        $rules = $payload['rules'] ?? null;

        if (!is_array($rules)) {
            $this->error('Field \"rules\" must be an array');
            return;
        }

        $config = $this->configService->getWorkingConfig();
        $config->set('firewall.rules', $rules);

        if (!$this->configService->saveWorkingConfig($config)) {
            $this->error('Failed to save firewall rules', 500);
            return;
        }

        $this->success('Firewall rules updated', ['rules_count' => count($rules)]);
    }
}
