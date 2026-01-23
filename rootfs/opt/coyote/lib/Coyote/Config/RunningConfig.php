<?php

namespace Coyote\Config;

/**
 * Represents the currently active system configuration in RAM.
 *
 * Provides convenient access to configuration sections and values
 * with support for nested key paths.
 */
class RunningConfig
{
    /** @var array Raw configuration data */
    private array $data;

    /** @var bool Whether the configuration has been modified */
    private bool $modified = false;

    /**
     * Create a new RunningConfig instance.
     *
     * @param array $data Initial configuration data
     */
    public function __construct(array $data = [])
    {
        $this->data = $data;
    }

    /**
     * Get a configuration value by dot-notation path.
     *
     * @param string $path Dot-notation path (e.g., 'system.hostname')
     * @param mixed $default Default value if path not found
     * @return mixed Configuration value or default
     */
    public function get(string $path, mixed $default = null): mixed
    {
        $keys = explode('.', $path);
        $value = $this->data;

        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return $default;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * Set a configuration value by dot-notation path.
     *
     * @param string $path Dot-notation path
     * @param mixed $value Value to set
     * @return self
     */
    public function set(string $path, mixed $value): self
    {
        $keys = explode('.', $path);
        $data = &$this->data;

        foreach ($keys as $i => $key) {
            if ($i === count($keys) - 1) {
                $data[$key] = $value;
            } else {
                if (!isset($data[$key]) || !is_array($data[$key])) {
                    $data[$key] = [];
                }
                $data = &$data[$key];
            }
        }

        $this->modified = true;
        return $this;
    }

    /**
     * Check if a configuration path exists.
     *
     * @param string $path Dot-notation path
     * @return bool True if path exists
     */
    public function has(string $path): bool
    {
        $keys = explode('.', $path);
        $value = $this->data;

        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return false;
            }
            $value = $value[$key];
        }

        return true;
    }

    /**
     * Remove a configuration value by dot-notation path.
     *
     * @param string $path Dot-notation path
     * @return self
     */
    public function remove(string $path): self
    {
        $keys = explode('.', $path);
        $data = &$this->data;

        foreach ($keys as $i => $key) {
            if ($i === count($keys) - 1) {
                unset($data[$key]);
            } else {
                if (!isset($data[$key]) || !is_array($data[$key])) {
                    return $this;
                }
                $data = &$data[$key];
            }
        }

        $this->modified = true;
        return $this;
    }

    /**
     * Get an entire configuration section.
     *
     * @param string $section Section name (e.g., 'system', 'network')
     * @return array Section data or empty array
     */
    public function getSection(string $section): array
    {
        return $this->data[$section] ?? [];
    }

    /**
     * Set an entire configuration section.
     *
     * @param string $section Section name
     * @param array $data Section data
     * @return self
     */
    public function setSection(string $section, array $data): self
    {
        $this->data[$section] = $data;
        $this->modified = true;
        return $this;
    }

    /**
     * Check if configuration has been modified since loading.
     *
     * @return bool True if modified
     */
    public function isModified(): bool
    {
        return $this->modified;
    }

    /**
     * Reset the modified flag.
     *
     * @return self
     */
    public function clearModified(): self
    {
        $this->modified = false;
        return $this;
    }

    /**
     * Get the raw configuration data as an array.
     *
     * @return array Complete configuration data
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * Merge additional configuration data.
     *
     * @param array $data Data to merge
     * @param bool $overwrite Whether to overwrite existing values
     * @return self
     */
    public function merge(array $data, bool $overwrite = true): self
    {
        if ($overwrite) {
            $this->data = array_replace_recursive($this->data, $data);
        } else {
            $this->data = array_replace_recursive($data, $this->data);
        }
        $this->modified = true;
        return $this;
    }
}
