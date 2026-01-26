<?php

namespace Coyote\System\Subsystem;

/**
 * Timezone configuration subsystem.
 *
 * Handles:
 * - System timezone
 * - /etc/localtime symlink
 * - /etc/timezone
 */
class TimezoneSubsystem extends AbstractSubsystem
{
    public function getName(): string
    {
        return 'timezone';
    }

    public function requiresCountdown(): bool
    {
        // Changing timezone cannot cause loss of remote access
        return false;
    }

    public function getConfigKeys(): array
    {
        return [
            'system.timezone',
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

        $timezone = $this->getNestedValue($config, 'system.timezone', 'UTC');
        $zoneFile = "/usr/share/zoneinfo/{$timezone}";

        // Validate timezone exists
        if (!file_exists($zoneFile)) {
            return $this->failure('Invalid timezone', ["Timezone not found: {$timezone}"]);
        }

        // Update /etc/localtime symlink via privileged executor
        $result = $priv->writeSymlink('/etc/localtime', $zoneFile);
        if (!$result['success']) {
            $errors[] = 'Failed to set /etc/localtime: ' . $result['output'];
        }

        // Write /etc/timezone via privileged executor
        $result = $priv->writeFile('/etc/timezone', $timezone . "\n");
        if (!$result['success']) {
            $errors[] = 'Failed to write /etc/timezone: ' . $result['output'];
        }

        if (!empty($errors)) {
            return $this->failure('Timezone configuration had errors', $errors);
        }

        return $this->success("Timezone set to {$timezone}");
    }
}
