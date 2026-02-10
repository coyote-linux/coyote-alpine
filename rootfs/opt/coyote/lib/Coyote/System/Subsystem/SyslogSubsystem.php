<?php

namespace Coyote\System\Subsystem;

class SyslogSubsystem extends AbstractSubsystem
{
    public function getName(): string
    {
        return 'syslog';
    }

    public function requiresCountdown(): bool
    {
        return false;
    }

    public function getConfigKeys(): array
    {
        return [
            'services.syslog',
        ];
    }

    public function hasChanges(array $working, array $running): bool
    {
        return $this->valuesChanged($working, $running, $this->getConfigKeys());
    }

    public function apply(array $config): array
    {
        $errors = [];
        $priv = $this->getPrivilegedExecutor();

        $syslogConfig = $this->getNestedValue($config, 'services.syslog', []);
        $remoteEnabled = $syslogConfig['remote_enabled'] ?? false;
        $remoteHost = $syslogConfig['remote_host'] ?? '';
        $remotePort = $syslogConfig['remote_port'] ?? 514;
        $remoteProtocol = $syslogConfig['remote_protocol'] ?? 'udp';

        $confContent = '';

        if ($remoteEnabled && !empty($remoteHost)) {
            $protocol = strtolower($remoteProtocol) === 'tcp' ? '@' : '';
            $confContent = "SYSLOG_OPTS=\"-L -R {$protocol}{$remoteHost}:{$remotePort}\"\n";
        } else {
            $confContent = "SYSLOG_OPTS=\"-L\"\n";
        }

        $result = $priv->writeFile('/etc/conf.d/syslog', $confContent);
        if (!$result['success']) {
            $errors[] = 'Failed to write syslog config: ' . $result['output'];
        }

        $result = $priv->rcService('syslogd', 'restart');
        if (!$result['success']) {
            $errors[] = 'Failed to restart syslogd: ' . $result['output'];
        }

        if (!empty($errors)) {
            return $this->failure('Syslog configuration had errors', $errors);
        }

        $message = $remoteEnabled ? "Remote syslog configured: {$remoteHost}:{$remotePort}" : 'Remote syslog disabled';
        return $this->success($message);
    }
}
