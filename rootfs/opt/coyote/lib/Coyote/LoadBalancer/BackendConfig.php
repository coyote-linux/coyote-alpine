<?php

namespace Coyote\LoadBalancer;

/**
 * Represents an HAProxy backend configuration.
 *
 * Provides a fluent interface for building backend configurations.
 */
class BackendConfig
{
    /** @var string Backend name */
    private string $name;

    /** @var array Backend configuration */
    private array $config = [];

    /**
     * Create a new BackendConfig instance.
     *
     * @param string $name Backend name
     */
    public function __construct(string $name)
    {
        $this->name = $name;
        $this->config = [
            'mode' => 'http',
            'balance' => 'roundrobin',
            'health_check' => true,
            'servers' => [],
        ];
    }

    /**
     * Get the backend name.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
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
     * Set the balance algorithm.
     *
     * @param string $algorithm Balance algorithm (roundrobin, leastconn, source, etc.)
     * @return static
     */
    public function balance(string $algorithm): static
    {
        $this->config['balance'] = $algorithm;
        return $this;
    }

    /**
     * Add a server to the backend.
     *
     * @param string $address Server address
     * @param int $port Server port
     * @param string|null $name Server name (auto-generated if null)
     * @param int $weight Server weight
     * @return static
     */
    public function addServer(
        string $address,
        int $port = 80,
        ?string $name = null,
        int $weight = 1
    ): static {
        $server = [
            'address' => $address,
            'port' => $port,
            'weight' => $weight,
        ];

        if ($name !== null) {
            $server['name'] = $name;
        }

        $this->config['servers'][] = $server;
        return $this;
    }

    /**
     * Add a backup server.
     *
     * @param string $address Server address
     * @param int $port Server port
     * @param string|null $name Server name
     * @return static
     */
    public function addBackupServer(string $address, int $port = 80, ?string $name = null): static
    {
        $server = [
            'address' => $address,
            'port' => $port,
            'backup' => true,
        ];

        if ($name !== null) {
            $server['name'] = $name;
        }

        $this->config['servers'][] = $server;
        return $this;
    }

    /**
     * Enable or disable health checks.
     *
     * @param bool $enabled Whether to enable health checks
     * @return static
     */
    public function healthCheck(bool $enabled = true): static
    {
        $this->config['health_check'] = $enabled;
        return $this;
    }

    /**
     * Set the health check path.
     *
     * @param string $path Health check URL path
     * @return static
     */
    public function healthCheckPath(string $path): static
    {
        $this->config['health_check_path'] = "GET {$path}";
        return $this;
    }

    /**
     * Enable session persistence with a cookie.
     *
     * @param string $cookieName Cookie name
     * @return static
     */
    public function sessionPersistence(string $cookieName): static
    {
        $this->config['cookie'] = $cookieName;
        return $this;
    }

    /**
     * Set connection timeout.
     *
     * @param string $timeout Timeout value (e.g., "5s")
     * @return static
     */
    public function connectTimeout(string $timeout): static
    {
        $this->config['timeout_connect'] = $timeout;
        return $this;
    }

    /**
     * Set server timeout.
     *
     * @param string $timeout Timeout value (e.g., "30s")
     * @return static
     */
    public function serverTimeout(string $timeout): static
    {
        $this->config['timeout_server'] = $timeout;
        return $this;
    }

    /**
     * Set HTTP check method and options.
     *
     * @param string $method HTTP method
     * @param string $uri URI to check
     * @param string|null $version HTTP version
     * @return static
     */
    public function httpCheck(string $method = 'GET', string $uri = '/', ?string $version = null): static
    {
        $check = "{$method} {$uri}";
        if ($version !== null) {
            $check .= " HTTP/{$version}";
        }
        $this->config['health_check_path'] = $check;
        return $this;
    }

    /**
     * Set check interval.
     *
     * @param string $interval Interval (e.g., "2s")
     * @return static
     */
    public function checkInterval(string $interval): static
    {
        $this->config['check_interval'] = $interval;
        return $this;
    }

    /**
     * Set retry count for failed checks.
     *
     * @param int $retries Number of retries
     * @return static
     */
    public function retries(int $retries): static
    {
        $this->config['retries'] = $retries;
        return $this;
    }

    /**
     * Get the backend configuration as an array.
     *
     * @return array Configuration array
     */
    public function toArray(): array
    {
        return $this->config;
    }

    /**
     * Create a backend from an array configuration.
     *
     * @param string $name Backend name
     * @param array $config Configuration array
     * @return static
     */
    public static function fromArray(string $name, array $config): static
    {
        $backend = new static($name);
        $backend->config = array_merge($backend->config, $config);
        return $backend;
    }

    /**
     * Validate the backend configuration.
     *
     * @return array Validation errors (empty if valid)
     */
    public function validate(): array
    {
        $errors = [];

        if (empty($this->config['servers'])) {
            $errors[] = 'At least one server is required';
        }

        foreach ($this->config['servers'] as $i => $server) {
            if (empty($server['address'])) {
                $errors[] = "Server {$i}: address is required";
            }
        }

        return $errors;
    }
}
