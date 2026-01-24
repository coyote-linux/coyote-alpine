<?php

namespace Coyote\WebAdmin;

/**
 * Simple HTTP router for the web admin interface.
 */
class Router
{
    /** @var array Registered routes */
    private array $routes = [];

    /**
     * Register a GET route.
     *
     * @param string $path Route path
     * @param array $handler Controller and method
     * @return void
     */
    public function get(string $path, array $handler): void
    {
        $this->addRoute('GET', $path, $handler);
    }

    /**
     * Register a POST route.
     *
     * @param string $path Route path
     * @param array $handler Controller and method
     * @return void
     */
    public function post(string $path, array $handler): void
    {
        $this->addRoute('POST', $path, $handler);
    }

    /**
     * Register a PUT route.
     *
     * @param string $path Route path
     * @param array $handler Controller and method
     * @return void
     */
    public function put(string $path, array $handler): void
    {
        $this->addRoute('PUT', $path, $handler);
    }

    /**
     * Register a DELETE route.
     *
     * @param string $path Route path
     * @param array $handler Controller and method
     * @return void
     */
    public function delete(string $path, array $handler): void
    {
        $this->addRoute('DELETE', $path, $handler);
    }

    /**
     * Add a route.
     *
     * @param string $method HTTP method
     * @param string $path Route path
     * @param array $handler Controller and method
     * @return void
     */
    private function addRoute(string $method, string $path, array $handler): void
    {
        // Convert path parameters to regex
        $pattern = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $path);
        $pattern = '#^' . $pattern . '$#';

        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'pattern' => $pattern,
            'handler' => $handler,
        ];
    }

    /**
     * Dispatch a request to the appropriate handler.
     *
     * @param string $method HTTP method
     * @param string $uri Request URI
     * @return void
     */
    public function dispatch(string $method, string $uri): void
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if (preg_match($route['pattern'], $uri, $matches)) {
                // Extract named parameters
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                $this->callHandler($route['handler'], $params);
                return;
            }
        }

        // No route found
        $this->notFound();
    }

    /**
     * Call a route handler.
     *
     * @param array $handler Controller class and method
     * @param array $params Route parameters
     * @return void
     */
    private function callHandler(array $handler, array $params): void
    {
        [$class, $method] = $handler;

        if (!class_exists($class)) {
            $this->serverError("Controller not found: {$class}");
            return;
        }

        $controller = new $class();

        if (!method_exists($controller, $method)) {
            $this->serverError("Method not found: {$class}::{$method}");
            return;
        }

        try {
            $controller->$method($params);
        } catch (\Exception $e) {
            $this->serverError($e->getMessage());
        }
    }

    /**
     * Send a 404 Not Found response.
     *
     * @return void
     */
    private function notFound(): void
    {
        http_response_code(404);

        if ($this->isApiRequest()) {
            echo json_encode(['error' => 'Not Found']);
        } else {
            echo '<h1>404 Not Found</h1>';
        }
    }

    /**
     * Send a 500 Server Error response.
     *
     * @param string $message Error message
     * @return void
     */
    private function serverError(string $message): void
    {
        http_response_code(500);

        if ($this->isApiRequest()) {
            echo json_encode(['error' => $message]);
        } else {
            echo '<h1>500 Internal Server Error</h1>';
            echo '<p>' . htmlspecialchars($message) . '</p>';
        }
    }

    /**
     * Check if this is an API request.
     *
     * @return bool True if API request
     */
    private function isApiRequest(): bool
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        return strpos($uri, '/api/') === 0;
    }
}
