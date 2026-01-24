<?php

namespace Coyote\WebAdmin;

use Coyote\Config\ConfigManager;

/**
 * Authentication handler for the web admin interface.
 */
class Auth
{
    /** @var string Session key for authenticated user */
    private const SESSION_KEY = 'coyote_user';

    /** @var string Session key for last activity time */
    private const ACTIVITY_KEY = 'coyote_last_activity';

    /** @var int Session timeout in seconds */
    private int $timeout = 3600;

    /**
     * Check if the current session is authenticated.
     *
     * @return bool True if authenticated
     */
    public function isAuthenticated(): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        if (!isset($_SESSION[self::SESSION_KEY])) {
            return false;
        }

        // Check session timeout
        if (isset($_SESSION[self::ACTIVITY_KEY])) {
            $lastActivity = $_SESSION[self::ACTIVITY_KEY];
            if (time() - $lastActivity > $this->timeout) {
                $this->logout();
                return false;
            }
        }

        // Update last activity time
        $_SESSION[self::ACTIVITY_KEY] = time();

        return true;
    }

    /**
     * Authenticate a user with username and password.
     *
     * @param string $username Username
     * @param string $password Password
     * @return bool True if authentication succeeded
     */
    public function login(string $username, string $password): bool
    {
        $configManager = new ConfigManager();
        $configManager->load();
        $config = $configManager->getRunningConfig();

        if ($config === null) {
            return false;
        }

        $users = $config->get('users', []);

        foreach ($users as $user) {
            if ($user['username'] === $username) {
                if ($this->verifyPassword($password, $user['password_hash'] ?? '')) {
                    $this->startSession($username);
                    return true;
                }
            }
        }

        // Check for default admin account if no users configured
        if (empty($users) && $username === 'admin') {
            // Default password is 'coyote' - should be changed on first login
            if ($password === 'coyote') {
                $this->startSession($username);
                return true;
            }
        }

        return false;
    }

    /**
     * Log out the current user.
     *
     * @return void
     */
    public function logout(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION[self::SESSION_KEY]);
            unset($_SESSION[self::ACTIVITY_KEY]);
            session_destroy();
        }
    }

    /**
     * Get the currently authenticated username.
     *
     * @return string|null Username or null if not authenticated
     */
    public function getCurrentUser(): ?string
    {
        if (!$this->isAuthenticated()) {
            return null;
        }

        return $_SESSION[self::SESSION_KEY] ?? null;
    }

    /**
     * Start an authenticated session.
     *
     * @param string $username Username
     * @return void
     */
    private function startSession(string $username): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        // Regenerate session ID to prevent fixation
        session_regenerate_id(true);

        $_SESSION[self::SESSION_KEY] = $username;
        $_SESSION[self::ACTIVITY_KEY] = time();
    }

    /**
     * Verify a password against a hash.
     *
     * @param string $password Plain text password
     * @param string $hash Password hash
     * @return bool True if password matches
     */
    private function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Hash a password for storage.
     *
     * @param string $password Plain text password
     * @return string Password hash
     */
    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /**
     * Set the session timeout.
     *
     * @param int $seconds Timeout in seconds
     * @return void
     */
    public function setTimeout(int $seconds): void
    {
        $this->timeout = $seconds;
    }

    /**
     * Check if password needs to be changed (default password).
     *
     * @return bool True if password should be changed
     */
    public function needsPasswordChange(): bool
    {
        $username = $this->getCurrentUser();
        if ($username !== 'admin') {
            return false;
        }

        $configManager = new ConfigManager();
        $configManager->load();
        $config = $configManager->getRunningConfig();

        if ($config === null) {
            return true;
        }

        $users = $config->get('users', []);

        // If no users configured, admin is using default password
        return empty($users);
    }
}
