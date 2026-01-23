<?php

namespace Coyote\Config;

/**
 * Writes configuration data to JSON files.
 */
class ConfigWriter
{
    /**
     * Write configuration to a JSON file.
     *
     * @param string $path Path to write the configuration file
     * @param array $data Configuration data to write
     * @return bool True if written successfully
     * @throws \RuntimeException If file cannot be written
     */
    public function write(string $path, array $data): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                throw new \RuntimeException("Cannot create directory: {$dir}");
            }
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException("Cannot encode configuration to JSON");
        }

        // Write to temporary file first, then rename for atomicity
        $tempPath = $path . '.tmp';
        if (file_put_contents($tempPath, $json) === false) {
            throw new \RuntimeException("Cannot write configuration file: {$path}");
        }

        if (!rename($tempPath, $path)) {
            unlink($tempPath);
            throw new \RuntimeException("Cannot finalize configuration file: {$path}");
        }

        return true;
    }

    /**
     * Serialize configuration to a JSON string.
     *
     * @param array $data Configuration data
     * @return string JSON string
     */
    public function toString(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
