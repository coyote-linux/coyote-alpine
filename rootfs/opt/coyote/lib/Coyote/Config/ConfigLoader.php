<?php

namespace Coyote\Config;

/**
 * Loads JSON configuration files.
 */
class ConfigLoader
{
    /**
     * Load configuration from a JSON file.
     *
     * @param string $path Path to the JSON configuration file
     * @return array Parsed configuration data
     * @throws \RuntimeException If file cannot be read or parsed
     */
    public function load(string $path): array
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("Configuration file not found: {$path}");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Cannot read configuration file: {$path}");
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(
                "Invalid JSON in configuration file {$path}: " . json_last_error_msg()
            );
        }

        return $data;
    }

    /**
     * Load configuration from a JSON string.
     *
     * @param string $json JSON string
     * @return array Parsed configuration data
     * @throws \RuntimeException If JSON cannot be parsed
     */
    public function loadFromString(string $json): array
    {
        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(
                "Invalid JSON: " . json_last_error_msg()
            );
        }

        return $data;
    }
}
