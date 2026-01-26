<?php

namespace Coyote\System\Subsystem;

/**
 * Hostname and domain configuration subsystem.
 *
 * Handles:
 * - System hostname
 * - Domain name
 * - /etc/hostname
 * - /etc/hosts
 */
class HostnameSubsystem extends AbstractSubsystem
{
    public function getName(): string
    {
        return 'hostname';
    }

    public function requiresCountdown(): bool
    {
        // Changing hostname cannot cause loss of remote access
        return false;
    }

    public function getConfigKeys(): array
    {
        return [
            'system.hostname',
            'system.domain',
        ];
    }

    public function hasChanges(array $working, array $running): bool
    {
        return $this->valuesChanged($working, $running, $this->getConfigKeys());
    }

    public function apply(array $config): array
    {
        $errors = [];

        $hostname = $this->getNestedValue($config, 'system.hostname', 'coyote');
        $domain = $this->getNestedValue($config, 'system.domain', '');

        // Validate hostname
        if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$/', $hostname)) {
            return $this->failure('Invalid hostname format', ['Invalid hostname: ' . $hostname]);
        }

        // Set hostname
        $result = $this->exec('hostname ' . escapeshellarg($hostname), true);
        if (!$result['success']) {
            $errors[] = 'Failed to set hostname: ' . $result['output'];
        }

        // Write /etc/hostname
        if (file_put_contents('/etc/hostname', $hostname . "\n") === false) {
            $errors[] = 'Failed to write /etc/hostname';
        }

        // Build /etc/hosts
        $fqdn = $domain ? "{$hostname}.{$domain}" : $hostname;
        $hosts = "127.0.0.1\tlocalhost\n";
        $hosts .= "127.0.1.1\t{$fqdn} {$hostname}\n";
        $hosts .= "::1\t\tlocalhost ip6-localhost ip6-loopback\n";

        if (file_put_contents('/etc/hosts', $hosts) === false) {
            $errors[] = 'Failed to write /etc/hosts';
        }

        if (!empty($errors)) {
            return $this->failure('Hostname configuration had errors', $errors);
        }

        return $this->success("Hostname set to {$hostname}");
    }
}
