<?php

namespace Coyote\WebAdmin\Api;

/**
 * Base API controller with common functionality.
 */
abstract class BaseApi
{
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
        echo json_encode($data, JSON_PRETTY_PRINT);
    }

    /**
     * Send an error response.
     *
     * @param string $message Error message
     * @param int $code HTTP status code
     * @return void
     */
    protected function error(string $message, int $code = 400): void
    {
        $this->json(['error' => $message], $code);
    }

    /**
     * Send a success response.
     *
     * @param string $message Success message
     * @param array $data Additional data
     * @return void
     */
    protected function success(string $message, array $data = []): void
    {
        $this->json(array_merge(['success' => true, 'message' => $message], $data));
    }

    /**
     * Get JSON request body.
     *
     * @return array Parsed JSON data
     */
    protected function getJsonBody(): array
    {
        $json = file_get_contents('php://input');
        return json_decode($json, true) ?? [];
    }

    /**
     * Get a value from the request body.
     *
     * @param string $key Key name
     * @param mixed $default Default value
     * @return mixed Value or default
     */
    protected function input(string $key, $default = null)
    {
        $data = $this->getJsonBody();
        return $data[$key] ?? $default;
    }

    /**
     * Validate required fields in request.
     *
     * @param array $required List of required field names
     * @return array|null Validation errors or null if valid
     */
    protected function validateRequired(array $required): ?array
    {
        $data = $this->getJsonBody();
        $errors = [];

        foreach ($required as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                $errors[] = "Field '{$field}' is required";
            }
        }

        return empty($errors) ? null : $errors;
    }
}
