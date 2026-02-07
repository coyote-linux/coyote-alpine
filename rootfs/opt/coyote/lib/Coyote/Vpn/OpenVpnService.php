<?php

namespace Coyote\Vpn;

use Coyote\Util\Filesystem;

class OpenVpnService
{
    public const CONFIG_DIR = '/etc/openvpn';
    public const LOG_DIR = '/var/log/openvpn';
    public const PID_DIR = '/var/run/openvpn';

    private EasyRsaService $easyRsaService;

    public function __construct()
    {
        $this->easyRsaService = new EasyRsaService();
    }

    public function applyConfig(array $config): bool
    {
        $enabled = (bool)($config['enabled'] ?? false);
        $instances = is_array($config['instances'] ?? null) ? $config['instances'] : [];

        if (!Filesystem::ensureDir(self::CONFIG_DIR, 0755)) {
            return false;
        }

        if (!Filesystem::ensureDir(self::LOG_DIR, 0755)) {
            return false;
        }

        if (!Filesystem::ensureDir(self::PID_DIR, 0755)) {
            return false;
        }

        $activeInstances = [];

        foreach ($instances as $name => $instanceConfig) {
            $instanceName = trim((string)$name);
            if ($instanceName === '' || !preg_match('/^[a-zA-Z0-9._-]+$/', $instanceName)) {
                continue;
            }

            $instance = is_array($instanceConfig) ? $instanceConfig : [];
            $instanceEnabled = $enabled && (bool)($instance['enabled'] ?? true);
            $configPath = self::CONFIG_DIR . '/' . $instanceName . '.conf';

            if (!$instanceEnabled) {
                $this->stop($instanceName);
                if (file_exists($configPath)) {
                    @unlink($configPath);
                }
                continue;
            }

            $mode = strtolower((string)($instance['mode'] ?? 'server'));
            $content = $mode === 'client'
                ? $this->generateClientConfig($instanceName, $instance)
                : $this->generateServerConfig($instanceName, $instance);

            if (!$this->writeConfigFile($configPath, $content)) {
                return false;
            }

            if ($this->isRunning($instanceName)) {
                if (!$this->restart($instanceName)) {
                    return false;
                }
            } elseif (!$this->start($instanceName)) {
                return false;
            }

            $activeInstances[$instanceName] = true;
        }

        $existingConfigs = glob(self::CONFIG_DIR . '/' . '*.conf');
        if (is_array($existingConfigs)) {
            foreach ($existingConfigs as $existingConfig) {
                $existingName = basename($existingConfig, '.conf');
                if (!isset($activeInstances[$existingName])) {
                    $this->stop($existingName);
                    @unlink($existingConfig);
                }
            }
        }

        return true;
    }

    public function generateServerConfig(string $name, array $instance): string
    {
        $port = (int)($instance['port'] ?? 1194);
        if ($port < 1 || $port > 65535) {
            $port = 1194;
        }

        $protocol = strtolower((string)($instance['protocol'] ?? 'udp'));
        if (!in_array($protocol, ['udp', 'tcp'], true)) {
            $protocol = 'udp';
        }

        $device = strtolower((string)($instance['device'] ?? 'tun'));
        if (!in_array($device, ['tun', 'tap'], true)) {
            $device = 'tun';
        }

        [$serverNetwork, $serverMask] = $this->networkToPair((string)($instance['network'] ?? '10.8.0.0/24'), '10.8.0.0', '255.255.255.0');
        $keepaliveInterval = max(1, (int)($instance['keepalive_interval'] ?? 10));
        $keepaliveTimeout = max(1, (int)($instance['keepalive_timeout'] ?? 120));
        $cipher = (string)($instance['cipher'] ?? 'AES-256-GCM');
        $auth = (string)($instance['auth'] ?? 'SHA256');
        $certificateName = trim((string)($instance['certificate_name'] ?? $name));

        $caPath = $this->easyRsaService->getCaCertPath() ?? (EasyRsaService::PKI_DIR . '/ca.crt');
        $certPath = $this->easyRsaService->getServerCertPath($certificateName) ?? (EasyRsaService::PKI_DIR . '/issued/' . $certificateName . '.crt');
        $keyPath = $this->easyRsaService->getServerKeyPath($certificateName) ?? (EasyRsaService::PKI_DIR . '/private/' . $certificateName . '.key');
        $dhPath = $this->easyRsaService->getDhPath() ?? (EasyRsaService::PKI_DIR . '/dh.pem');
        $crlPath = $this->easyRsaService->getCrlPath();

        $pushRoutes = is_array($instance['push_routes'] ?? null) ? $instance['push_routes'] : [];
        $pushDns = $this->normalizeDnsList($instance['push_dns'] ?? []);

        $lines = [
            'port ' . $port,
            'proto ' . $protocol,
            'dev ' . $device,
            'topology subnet',
            'ca ' . $caPath,
            'cert ' . $certPath,
            'key ' . $keyPath,
            'dh ' . $dhPath,
            'server ' . $serverNetwork . ' ' . $serverMask,
            'keepalive ' . $keepaliveInterval . ' ' . $keepaliveTimeout,
            'cipher ' . $cipher,
            'auth ' . $auth,
            'status ' . self::LOG_DIR . '/' . $name . '-status.log',
            'log-append ' . self::LOG_DIR . '/' . $name . '.log',
            'writepid ' . self::PID_DIR . '/' . $name . '.pid',
            'persist-key',
            'persist-tun',
            'verb 3',
        ];

        if ((bool)($instance['client_to_client'] ?? false)) {
            $lines[] = 'client-to-client';
        }

        if ($crlPath !== null) {
            $lines[] = 'crl-verify ' . $crlPath;
        }

        foreach ($pushRoutes as $route) {
            [$network, $mask] = $this->networkToPair((string)$route);
            if ($network === '' || $mask === '') {
                continue;
            }

            $lines[] = 'push "route ' . $network . ' ' . $mask . '"';
        }

        foreach ($pushDns as $dnsServer) {
            $lines[] = 'push "dhcp-option DNS ' . $dnsServer . '"';
        }

        return implode("\n", $lines) . "\n";
    }

    public function generateClientConfig(string $name, array $instance): string
    {
        $port = (int)($instance['port'] ?? 1194);
        if ($port < 1 || $port > 65535) {
            $port = 1194;
        }

        $protocol = strtolower((string)($instance['protocol'] ?? 'udp'));
        if (!in_array($protocol, ['udp', 'tcp'], true)) {
            $protocol = 'udp';
        }

        $device = strtolower((string)($instance['device'] ?? 'tun'));
        if (!in_array($device, ['tun', 'tap'], true)) {
            $device = 'tun';
        }

        $remoteHost = trim((string)($instance['remote_host'] ?? ''));
        $remotePort = (int)($instance['remote_port'] ?? $port);
        if ($remotePort < 1 || $remotePort > 65535) {
            $remotePort = $port;
        }

        $cipher = (string)($instance['cipher'] ?? 'AES-256-GCM');
        $auth = (string)($instance['auth'] ?? 'SHA256');
        $certificateName = trim((string)($instance['certificate_name'] ?? $name));

        $caPath = $this->easyRsaService->getCaCertPath() ?? (EasyRsaService::PKI_DIR . '/ca.crt');
        $certPath = $this->easyRsaService->getClientCertPath($certificateName) ?? (EasyRsaService::PKI_DIR . '/issued/' . $certificateName . '.crt');
        $keyPath = $this->easyRsaService->getClientKeyPath($certificateName) ?? (EasyRsaService::PKI_DIR . '/private/' . $certificateName . '.key');

        $lines = [
            'client',
            'proto ' . $protocol,
            'dev ' . $device,
            'remote ' . $remoteHost . ' ' . $remotePort,
            'resolv-retry infinite',
            'nobind',
            'ca ' . $caPath,
            'cert ' . $certPath,
            'key ' . $keyPath,
            'cipher ' . $cipher,
            'auth ' . $auth,
            'status ' . self::LOG_DIR . '/' . $name . '-status.log',
            'log-append ' . self::LOG_DIR . '/' . $name . '.log',
            'writepid ' . self::PID_DIR . '/' . $name . '.pid',
            'persist-key',
            'persist-tun',
            'remote-cert-tls server',
            'verb 3',
        ];

        return implode("\n", $lines) . "\n";
    }

    public function start(string $instanceName): bool
    {
        return $this->runServiceCommand($instanceName, 'start');
    }

    public function stop(string $instanceName): bool
    {
        return $this->runServiceCommand($instanceName, 'stop');
    }

    public function restart(string $instanceName): bool
    {
        return $this->runServiceCommand($instanceName, 'restart');
    }

    public function isRunning(string $instanceName): bool
    {
        return $this->runServiceCommand($instanceName, 'status');
    }

    public function getStatus(string $instanceName): array
    {
        $running = $this->isRunning($instanceName);
        $clients = $this->getConnectedClients($instanceName);
        $uptimeSeconds = 0;

        $pidPath = self::PID_DIR . '/' . $instanceName . '.pid';
        if (file_exists($pidPath)) {
            $pidContent = file_get_contents($pidPath);
            $pid = $pidContent === false ? 0 : (int)trim($pidContent);

            if ($pid > 0 && is_dir('/proc/' . $pid)) {
                $processMtime = @filemtime('/proc/' . $pid);
                if ($processMtime !== false) {
                    $uptimeSeconds = max(0, time() - $processMtime);
                }
            }

            if ($uptimeSeconds === 0) {
                $pidMtime = @filemtime($pidPath);
                if ($pidMtime !== false) {
                    $uptimeSeconds = max(0, time() - $pidMtime);
                }
            }
        }

        return [
            'running' => $running,
            'connected_clients' => count($clients),
            'clients' => $clients,
            'uptime' => $this->formatUptime($uptimeSeconds),
            'uptime_seconds' => $uptimeSeconds,
        ];
    }

    public function getConnectedClients(string $instanceName): array
    {
        return $this->parseStatusFile(self::LOG_DIR . '/' . $instanceName . '-status.log');
    }

    public function generateClientOvpn(
        string $serverName,
        string $clientName,
        array $serverConfig,
        string $caCert,
        string $clientCert,
        string $clientKey
    ): string {
        $protocol = strtolower((string)($serverConfig['protocol'] ?? 'udp'));
        if (!in_array($protocol, ['udp', 'tcp'], true)) {
            $protocol = 'udp';
        }

        $device = strtolower((string)($serverConfig['device'] ?? 'tun'));
        if (!in_array($device, ['tun', 'tap'], true)) {
            $device = 'tun';
        }

        $remoteHost = trim((string)($serverConfig['public_host'] ?? $serverConfig['remote_host'] ?? $serverConfig['server_host'] ?? ''));
        if ($remoteHost === '') {
            $remoteHost = $serverName;
        }

        $remotePort = (int)($serverConfig['port'] ?? 1194);
        if ($remotePort < 1 || $remotePort > 65535) {
            $remotePort = 1194;
        }

        $cipher = (string)($serverConfig['cipher'] ?? 'AES-256-GCM');
        $auth = (string)($serverConfig['auth'] ?? 'SHA256');

        $lines = [
            'client',
            'dev ' . $device,
            'proto ' . $protocol,
            'remote ' . $remoteHost . ' ' . $remotePort,
            'resolv-retry infinite',
            'nobind',
            'persist-key',
            'persist-tun',
            'remote-cert-tls server',
            'cipher ' . $cipher,
            'auth ' . $auth,
            'setenv CLIENT_NAME ' . $clientName,
            '<ca>',
            trim($caCert),
            '</ca>',
            '<cert>',
            trim($clientCert),
            '</cert>',
            '<key>',
            trim($clientKey),
            '</key>',
        ];

        return implode("\n", $lines) . "\n";
    }

    private function writeConfigFile(string $path, string $content): bool
    {
        return Filesystem::writeAtomic($path, $content, 0600);
    }

    private function parseStatusFile(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }

        $clients = [];
        $inClientList = false;

        foreach ($lines as $line) {
            if (str_starts_with($line, 'Common Name,')) {
                $inClientList = true;
                continue;
            }

            if (!$inClientList) {
                continue;
            }

            if (str_starts_with($line, 'ROUTING TABLE') || str_starts_with($line, 'GLOBAL STATS') || str_starts_with($line, 'END')) {
                break;
            }

            $columns = str_getcsv($line);
            if (count($columns) < 5) {
                continue;
            }

            $clients[] = [
                'common_name' => (string)$columns[0],
                'real_address' => (string)$columns[1],
                'bytes_received' => (int)$columns[2],
                'bytes_sent' => (int)$columns[3],
                'connected_since' => (string)$columns[4],
            ];
        }

        return $clients;
    }

    private function runServiceCommand(string $instanceName, string $action): bool
    {
        if ($instanceName === '' || !preg_match('/^[a-zA-Z0-9._-]+$/', $instanceName)) {
            return false;
        }

        $serviceName = 'openvpn.' . $instanceName;
        $prefix = posix_getuid() === 0 ? '' : 'doas ';
        $command = $prefix
            . 'rc-service '
            . escapeshellarg($serviceName)
            . ' '
            . escapeshellarg($action)
            . ' 2>&1';

        exec($command, $output, $returnCode);
        return $returnCode === 0;
    }

    private function networkToPair(string $value, string $fallbackNetwork = '', string $fallbackMask = ''): array
    {
        $trimmed = trim($value);
        if ($trimmed !== '' && strpos($trimmed, '/') !== false) {
            [$network, $prefix] = explode('/', $trimmed, 2);
            $network = trim($network);
            $prefixValue = (int)$prefix;

            if (
                filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
                && $prefixValue >= 0
                && $prefixValue <= 32
            ) {
                return [$network, $this->prefixToMask($prefixValue)];
            }
        }

        if ($trimmed !== '' && preg_match('/^(\d+\.\d+\.\d+\.\d+)\s+(\d+\.\d+\.\d+\.\d+)$/', $trimmed, $matches)) {
            return [$matches[1], $matches[2]];
        }

        return [$fallbackNetwork, $fallbackMask];
    }

    private function prefixToMask(int $prefix): string
    {
        if ($prefix <= 0) {
            return '0.0.0.0';
        }

        if ($prefix >= 32) {
            return '255.255.255.255';
        }

        $mask = (0xffffffff << (32 - $prefix)) & 0xffffffff;
        return long2ip($mask);
    }

    private function normalizeDnsList(mixed $source): array
    {
        $values = [];

        if (is_array($source)) {
            $values = $source;
        } elseif (is_string($source)) {
            $parts = preg_split('/[\s,]+/', $source);
            $values = is_array($parts) ? $parts : [];
        }

        $dnsList = [];

        foreach ($values as $value) {
            $dns = trim((string)$value);
            if ($dns === '') {
                continue;
            }

            if (filter_var($dns, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                continue;
            }

            $dnsList[] = $dns;
        }

        return array_values(array_unique($dnsList));
    }

    private function formatUptime(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0m';
        }

        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($days > 0) {
            return $days . 'd ' . $hours . 'h';
        }

        if ($hours > 0) {
            return $hours . 'h ' . $minutes . 'm';
        }

        return $minutes . 'm';
    }
}
