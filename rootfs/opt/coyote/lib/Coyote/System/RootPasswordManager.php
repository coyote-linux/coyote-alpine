<?php

namespace Coyote\System;

/**
 * Manages the local Linux root account password.
 */
class RootPasswordManager
{
    public const CONFIG_PATH = 'system.root_password_hash';

    private const SHADOW_FILE = '/etc/shadow';

    public static function hashPassword(string $password): string
    {
        if ($password === '') {
            throw new \InvalidArgumentException('Password cannot be empty');
        }

        $salt = self::generateSalt();
        $setting = '$6$rounds=100000$' . $salt . '$';
        $hash = crypt($password, $setting);

        if (!is_string($hash) || !self::isValidHash($hash)) {
            throw new \RuntimeException('Failed to generate root password hash');
        }

        return $hash;
    }

    public static function isValidHash(string $hash): bool
    {
        return preg_match('/^\$6\$(rounds=[1-9][0-9]*\$)?[A-Za-z0-9.\/]{1,16}\$[A-Za-z0-9.\/]{43,128}$/', $hash) === 1;
    }

    public static function applyHashToShadow(string $hash, string $shadowFile = self::SHADOW_FILE): bool
    {
        if (!self::isValidHash($hash)) {
            return false;
        }

        if ($shadowFile === self::SHADOW_FILE && function_exists('posix_getuid') && posix_getuid() !== 0) {
            $executor = new PrivilegedExecutor();
            $result = $executor->setRootPasswordHash($hash);
            return $result['success'];
        }

        return self::applyHashDirect($hash, $shadowFile);
    }

    private static function applyHashDirect(string $hash, string $shadowFile): bool
    {
        if (!is_file($shadowFile) || !is_readable($shadowFile) || !is_writable($shadowFile)) {
            return false;
        }

        $lines = file($shadowFile, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return false;
        }

        $updated = false;
        foreach ($lines as &$line) {
            $parts = explode(':', $line);
            if (($parts[0] ?? '') === 'root') {
                if (count($parts) < 2) {
                    return false;
                }
                $parts[1] = $hash;
                $line = implode(':', $parts);
                $updated = true;
                break;
            }
        }
        unset($line);

        if (!$updated) {
            return false;
        }

        $dir = dirname($shadowFile);
        $tmpFile = tempnam($dir, '.shadow-');
        if ($tmpFile === false) {
            return false;
        }

        $contents = implode("\n", $lines) . "\n";
        $perms = fileperms($shadowFile);
        $owner = fileowner($shadowFile);
        $group = filegroup($shadowFile);

        if (file_put_contents($tmpFile, $contents, LOCK_EX) === false) {
            @unlink($tmpFile);
            return false;
        }

        if ($perms !== false) {
            @chmod($tmpFile, $perms & 0777);
        }
        if ($owner !== false) {
            @chown($tmpFile, $owner);
        }
        if ($group !== false) {
            @chgrp($tmpFile, $group);
        }

        if (!rename($tmpFile, $shadowFile)) {
            @unlink($tmpFile);
            return false;
        }

        return true;
    }

    private static function generateSalt(): string
    {
        $salt = rtrim(base64_encode(random_bytes(12)), '=');
        return substr(strtr($salt, '+', '.'), 0, 16);
    }
}
