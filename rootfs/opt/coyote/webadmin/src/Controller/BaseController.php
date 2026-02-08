<?php

namespace Coyote\WebAdmin\Controller;

use Coyote\WebAdmin\Csrf;
use Coyote\WebAdmin\FeatureFlags;

/**
 * Base controller with common functionality.
 */
abstract class BaseController
{
    /** @var string Templates directory */
    protected string $templatesPath;

    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        $this->templatesPath = COYOTE_WEBADMIN_ROOT . '/templates';
    }

    /**
     * Render a template with data.
     *
     * @param string $template Template name (without .php)
     * @param array $data Data to pass to template
     * @return void
     */
    protected function render(string $template, array $data = []): void
    {
        $templateFile = $this->templatesPath . '/' . $template . '.php';

        if (!file_exists($templateFile)) {
            $this->renderError("Template not found: {$template}");
            return;
        }

        if (!isset($data['csrfToken']) || !is_string($data['csrfToken']) || $data['csrfToken'] === '') {
            $data['csrfToken'] = Csrf::getToken();
        }

        if (!isset($data['features']) || !is_array($data['features'])) {
            $data['features'] = (new FeatureFlags())->toArray();
        }

        extract($data);

        ob_start();
        include $this->templatesPath . '/layout/main.php';
        $output = ob_get_clean();

        if (!is_string($output)) {
            $output = '';
        }

        echo $this->injectCsrfIntoPostForms($output, $data['csrfToken']);
    }

    /**
     * Render an error page.
     *
     * @param string $message Error message
     * @param int $code HTTP status code
     * @return void
     */
    protected function renderError(string $message, int $code = 500): void
    {
        http_response_code($code);
        echo "<h1>Error {$code}</h1>";
        echo "<p>" . htmlspecialchars($message) . "</p>";
    }

    /**
     * Send a JSON response.
     *
     * @param array $data Response data
     * @param int $code HTTP status code
     * @return void
     */
    protected function json(array $data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data);
    }

    /**
     * Redirect to another URL.
     *
     * @param string $url Target URL
     * @return void
     */
    protected function redirect(string $url): void
    {
        header('Location: ' . $url);
        exit;
    }

    /**
     * Get POST data as array.
     *
     * @return array POST data
     */
    protected function getPostData(): array
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        if (strpos($contentType, 'application/json') !== false) {
            $json = file_get_contents('php://input');
            return json_decode($json, true) ?? [];
        }

        return $_POST;
    }

    /**
     * Get a specific POST value.
     *
     * @param string $key Key name
     * @param mixed $default Default value
     * @return mixed Value or default
     */
    protected function post(string $key, $default = null)
    {
        $data = $this->getPostData();
        return $data[$key] ?? $default;
    }

    /**
     * Get a query string parameter.
     *
     * @param string $key Key name
     * @param mixed $default Default value
     * @return mixed Value or default
     */
    protected function query(string $key, $default = null)
    {
        return $_GET[$key] ?? $default;
    }

    /**
     * Check if request is AJAX/XHR.
     *
     * @return bool True if AJAX request
     */
    protected function isAjax(): bool
    {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }

    /**
     * Set a flash message for the next request.
     *
     * @param string $type Message type (success, error, warning, info)
     * @param string $message Message text
     * @return void
     */
    protected function flash(string $type, string $message): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $_SESSION['flash_messages'][] = [
            'type' => $type,
            'message' => $message,
        ];
    }

    /**
     * Get and clear flash messages.
     *
     * @return array Flash messages
     */
    protected function getFlashMessages(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return [];
        }

        $messages = $_SESSION['flash_messages'] ?? [];
        unset($_SESSION['flash_messages']);
        return $messages;
    }

    private function injectCsrfIntoPostForms(string $html, string $token): string
    {
        if ($token === '') {
            return $html;
        }

        $encoded = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');

        return preg_replace_callback(
            '/<form\b(?=[^>]*\bmethod\s*=\s*["\']?post["\']?)[^>]*>/i',
            static function (array $matches) use ($encoded): string {
                return $matches[0] . '<input type="hidden" name="_csrf_token" value="' . $encoded . '">';
            },
            $html
        ) ?? $html;
    }
}
