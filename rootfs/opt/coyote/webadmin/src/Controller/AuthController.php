<?php

namespace Coyote\WebAdmin\Controller;

use Coyote\WebAdmin\Auth;

/**
 * Authentication controller.
 */
class AuthController extends BaseController
{
    /** @var Auth */
    private Auth $auth;

    /**
     * Create a new AuthController instance.
     */
    public function __construct()
    {
        parent::__construct();
        $this->auth = new Auth();
    }

    /**
     * Display the login form.
     *
     * @param array $params Route parameters
     * @return void
     */
    public function showLogin(array $params = []): void
    {
        // Already logged in?
        if ($this->auth->isAuthenticated()) {
            $this->redirect('/dashboard');
            return;
        }

        $this->renderLogin();
    }

    /**
     * Process login form submission.
     *
     * @param array $params Route parameters
     * @return void
     */
    public function login(array $params = []): void
    {
        $username = $this->post('username', '');
        $password = $this->post('password', '');

        if (empty($username) || empty($password)) {
            $this->renderLogin('Username and password are required');
            return;
        }

        if ($this->auth->login($username, $password)) {
            // Check if password needs to be changed
            if (!$this->isDevelopmentBuild() && $this->auth->needsPasswordChange()) {
                $this->flash('warning', 'Please change the default password');
                $this->redirect('/system/password');
                return;
            }

            $this->redirect('/dashboard');
            return;
        }

        $this->renderLogin('Invalid username or password');
    }

    /**
     * Log out the current user.
     *
     * @param array $params Route parameters
     * @return void
     */
    public function logout(array $params = []): void
    {
        $this->auth->logout();
        $this->redirect('/login');
    }

    /**
     * Render the login page.
     *
     * @param string|null $error Error message
     * @return void
     */
    private function renderLogin(?string $error = null): void
    {
        // Simple login page without the full layout
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Login - Coyote Linux</title>
            <style>
                * { box-sizing: border-box; }
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                    background: #1a1a2e;
                    color: #eee;
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    min-height: 100vh;
                    margin: 0;
                }
                .login-box {
                    background: #16213e;
                    padding: 2rem;
                    border-radius: 8px;
                    width: 100%;
                    max-width: 400px;
                    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.3);
                }
                h1 {
                    margin: 0 0 1.5rem;
                    text-align: center;
                    color: #4a9eff;
                }
                .form-group { margin-bottom: 1rem; }
                label {
                    display: block;
                    margin-bottom: 0.5rem;
                    color: #aaa;
                }
                input {
                    width: 100%;
                    padding: 0.75rem;
                    border: 1px solid #333;
                    border-radius: 4px;
                    background: #0f0f23;
                    color: #eee;
                    font-size: 1rem;
                }
                input:focus {
                    outline: none;
                    border-color: #4a9eff;
                }
                button {
                    width: 100%;
                    padding: 0.75rem;
                    background: #4a9eff;
                    color: #fff;
                    border: none;
                    border-radius: 4px;
                    font-size: 1rem;
                    cursor: pointer;
                    margin-top: 1rem;
                }
                button:hover { background: #3a8eef; }
                .error {
                    background: #ff4444;
                    color: #fff;
                    padding: 0.75rem;
                    border-radius: 4px;
                    margin-bottom: 1rem;
                    text-align: center;
                }
                .logo {
                    text-align: center;
                    margin-bottom: 1rem;
                }
                .logo img {
                    width: 72px;
                    height: 72px;
                    margin: 0 auto;
                }
            </style>
        </head>
        <body>
            <div class="login-box">
                <div class="logo"><img src="/assets/img/logo.png" alt="Coyote Linux"></div>
                <h1>Coyote Linux</h1>
                <?php if ($error): ?>
                    <div class="error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                <form method="post" action="/login">
                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" required autofocus>
                    </div>
                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" required>
                    </div>
                    <button type="submit">Log In</button>
                </form>
            </div>
        </body>
        </html>
        <?php
    }

    private function isDevelopmentBuild(): bool
    {
        return defined('COYOTE_DEV_BUILD') && COYOTE_DEV_BUILD === true;
    }
}
