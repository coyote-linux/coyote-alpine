<?php

namespace Coyote\WebAdmin;

class Csrf
{
    private const SESSION_KEY = 'coyote_csrf_token';

    public static function getToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $existing = $_SESSION[self::SESSION_KEY] ?? '';
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $token = bin2hex(random_bytes(32));
        $_SESSION[self::SESSION_KEY] = $token;
        return $token;
    }

    public static function validate(?string $token): bool
    {
        if (!is_string($token) || $token === '') {
            return false;
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        $sessionToken = $_SESSION[self::SESSION_KEY] ?? '';
        if (!is_string($sessionToken) || $sessionToken === '') {
            return false;
        }

        return hash_equals($sessionToken, $token);
    }

    public static function getRequestToken(): ?string
    {
        $postToken = $_POST['_csrf_token'] ?? null;
        if (is_string($postToken) && $postToken !== '') {
            return $postToken;
        }

        $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (is_string($headerToken) && $headerToken !== '') {
            return $headerToken;
        }

        return null;
    }
}
