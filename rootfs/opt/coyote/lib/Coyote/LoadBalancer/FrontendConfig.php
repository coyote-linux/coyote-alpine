<?php

namespace Coyote\LoadBalancer;

/**
 * Represents an HAProxy frontend configuration.
 *
 * Provides a fluent interface for building frontend configurations.
 */
class FrontendConfig
{
    /** @var string Frontend name */
    private string $name;

    /** @var array Frontend configuration */
    private array $config = [];

    /**
     * Create a new FrontendConfig instance.
     *
     * @param string $name Frontend name
     */
    public function __construct(string $name)
    {
        $this->name = $name;
        $this->config = [
            'mode' => 'http',
            'acls' => [],
            'use_backend' => [],
        ];
    }

    /**
     * Get the frontend name.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Set the bind address and port.
     *
     * @param string $bind Bind address (e.g., "*:80", "192.168.1.1:8080")
     * @return static
     */
    public function bind(string $bind): static
    {
        $this->config['bind'] = $bind;
        return $this;
    }

    /**
     * Set the mode (http or tcp).
     *
     * @param string $mode Mode
     * @return static
     */
    public function mode(string $mode): static
    {
        $this->config['mode'] = $mode;
        return $this;
    }

    /**
     * Set the default backend.
     *
     * @param string $backend Backend name
     * @return static
     */
    public function defaultBackend(string $backend): static
    {
        $this->config['backend'] = $backend;
        return $this;
    }

    /**
     * Add an SSL certificate.
     *
     * @param string $certPath Path to certificate file
     * @return static
     */
    public function sslCert(string $certPath): static
    {
        $this->config['ssl_cert'] = $certPath;
        return $this;
    }

    /**
     * Add an ACL.
     *
     * @param string $name ACL name
     * @param string $condition ACL condition
     * @return static
     */
    public function acl(string $name, string $condition): static
    {
        $this->config['acls'][$name] = $condition;
        return $this;
    }

    /**
     * Add a use_backend rule.
     *
     * @param string $backend Backend name
     * @param string|null $condition ACL condition (null for unconditional)
     * @return static
     */
    public function useBackend(string $backend, ?string $condition = null): static
    {
        $rule = ['backend' => $backend];
        if ($condition !== null) {
            $rule['condition'] = $condition;
        }
        $this->config['use_backend'][] = $rule;
        return $this;
    }

    /**
     * Enable X-Forwarded headers.
     *
     * @param bool $enabled Whether to enable
     * @return static
     */
    public function forwardHeaders(bool $enabled = true): static
    {
        $this->config['http_request_add_header'] = $enabled;
        return $this;
    }

    /**
     * Set timeout for client connections.
     *
     * @param string $timeout Timeout value (e.g., "30s")
     * @return static
     */
    public function clientTimeout(string $timeout): static
    {
        $this->config['timeout_client'] = $timeout;
        return $this;
    }

    /**
     * Set maximum connections.
     *
     * @param int $maxconn Maximum connections
     * @return static
     */
    public function maxConnections(int $maxconn): static
    {
        $this->config['maxconn'] = $maxconn;
        return $this;
    }

    /**
     * Get the frontend configuration as an array.
     *
     * @return array Configuration array
     */
    public function toArray(): array
    {
        return $this->config;
    }

    /**
     * Create a frontend from an array configuration.
     *
     * @param string $name Frontend name
     * @param array $config Configuration array
     * @return static
     */
    public static function fromArray(string $name, array $config): static
    {
        $frontend = new static($name);
        $frontend->config = array_merge($frontend->config, $config);
        return $frontend;
    }

    /**
     * Validate the frontend configuration.
     *
     * @return array Validation errors (empty if valid)
     */
    public function validate(): array
    {
        $errors = [];

        if (empty($this->config['bind'])) {
            $errors[] = 'Bind address is required';
        }

        if (empty($this->config['backend']) && empty($this->config['use_backend'])) {
            $errors[] = 'At least one backend must be specified';
        }

        return $errors;
    }
}
