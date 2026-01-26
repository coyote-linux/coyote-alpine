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

        $timezone = $this->getNestedValue($config, 'system.timezone', 'UTC');
        $zoneFile = "/usr/share/zoneinfo/{$timezone}";

        // Validate timezone exists
        if (!file_exists($zoneFile)) {
            return $this->failure('Invalid timezone', ["Timezone not found: {$timezone}"]);
        }

        // Update /etc/localtime symlink
        @unlink('/etc/localtime');
        if (!@symlink($zoneFile, '/etc/localtime')) {
            // Try copying if symlink fails (some filesystems)
            if (!@copy($zoneFile, '/etc/localtime')) {
                $errors[] = 'Failed to set /etc/localtime';
            }
        }

        // Write /etc/timezone
        if (file_put_contents('/etc/timezone', $timezone . "\n") === false) {
            $errors[] = 'Failed to write /etc/timezone';
        }

        if (!empty($errors)) {
            return $this->failure('Timezone configuration had errors', $errors);
        }

        return $this->success("Timezone set to {$timezone}");
    }
}
