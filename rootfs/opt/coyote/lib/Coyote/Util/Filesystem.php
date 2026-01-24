<?php

namespace Coyote\Util;

/**
 * Filesystem utilities.
 *
 * Provides helpers for common filesystem operations.
 */
class Filesystem
{
    /**
     * Ensure a directory exists, creating it if necessary.
     *
     * @param string $path Directory path
     * @param int $mode Directory permissions
     * @return bool True if directory exists or was created
     */
    public static function ensureDir(string $path, int $mode = 0755): bool
    {
        if (is_dir($path)) {
            return true;
        }

        return mkdir($path, $mode, true);
    }

    /**
     * Write content to a file atomically.
     *
     * Writes to a temporary file first, then renames to ensure
     * the file is never in a partially-written state.
     *
     * @param string $path File path
     * @param string $content Content to write
     * @param int $mode File permissions
     * @return bool True if successful
     */
    public static function writeAtomic(string $path, string $content, int $mode = 0644): bool
    {
        $dir = dirname($path);
        if (!self::ensureDir($dir)) {
            return false;
        }

        $tempFile = $path . '.tmp.' . getmypid();

        if (file_put_contents($tempFile, $content) === false) {
            return false;
        }

        chmod($tempFile, $mode);

        if (!rename($tempFile, $path)) {
            unlink($tempFile);
            return false;
        }

        return true;
    }

    /**
     * Read file contents safely.
     *
     * @param string $path File path
     * @return string|null File contents or null if not readable
     */
    public static function read(string $path): ?string
    {
        if (!is_readable($path)) {
            return null;
        }

        $content = file_get_contents($path);
        return $content !== false ? $content : null;
    }

    /**
     * Copy a file or directory recursively.
     *
     * @param string $source Source path
     * @param string $dest Destination path
     * @return bool True if successful
     */
    public static function copy(string $source, string $dest): bool
    {
        if (is_file($source)) {
            return copy($source, $dest);
        }

        if (!is_dir($source)) {
            return false;
        }

        if (!self::ensureDir($dest)) {
            return false;
        }

        $dir = opendir($source);
        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $sourcePath = $source . '/' . $file;
            $destPath = $dest . '/' . $file;

            if (!self::copy($sourcePath, $destPath)) {
                closedir($dir);
                return false;
            }
        }
        closedir($dir);

        return true;
    }

    /**
     * Delete a file or directory recursively.
     *
     * @param string $path Path to delete
     * @return bool True if successful
     */
    public static function delete(string $path): bool
    {
        if (!file_exists($path)) {
            return true;
        }

        if (is_file($path) || is_link($path)) {
            return unlink($path);
        }

        $dir = opendir($path);
        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            if (!self::delete($path . '/' . $file)) {
                closedir($dir);
                return false;
            }
        }
        closedir($dir);

        return rmdir($path);
    }

    /**
     * Get file size in human-readable format.
     *
     * @param int $bytes Size in bytes
     * @return string Formatted size (e.g., "1.5 MB")
     */
    public static function formatSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unit = 0;
        $size = (float)$bytes;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return round($size, 2) . ' ' . $units[$unit];
    }

    /**
     * List files in a directory matching a pattern.
     *
     * @param string $dir Directory path
     * @param string $pattern Glob pattern (default: '*')
     * @param bool $recursive Recurse into subdirectories
     * @return array List of matching file paths
     */
    public static function listFiles(
        string $dir,
        string $pattern = '*',
        bool $recursive = false
    ): array {
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        $globPath = rtrim($dir, '/') . '/' . $pattern;

        foreach (glob($globPath) as $file) {
            if (is_file($file)) {
                $files[] = $file;
            }
        }

        if ($recursive) {
            foreach (glob(rtrim($dir, '/') . '/*', GLOB_ONLYDIR) as $subdir) {
                $files = array_merge($files, self::listFiles($subdir, $pattern, true));
            }
        }

        return $files;
    }

    /**
     * Check if a path is writable (creating parent dirs if needed).
     *
     * @param string $path Path to check
     * @return bool True if writable
     */
    public static function isWritable(string $path): bool
    {
        if (file_exists($path)) {
            return is_writable($path);
        }

        // Check if parent directory is writable
        $parent = dirname($path);
        while (!file_exists($parent)) {
            $parent = dirname($parent);
        }

        return is_writable($parent);
    }

    /**
     * Get disk usage information.
     *
     * @param string $path Path to check
     * @return array{total: int, free: int, used: int}
     */
    public static function diskUsage(string $path): array
    {
        $total = disk_total_space($path);
        $free = disk_free_space($path);

        return [
            'total' => (int)$total,
            'free' => (int)$free,
            'used' => (int)($total - $free),
        ];
    }

    /**
     * Create a temporary file.
     *
     * @param string $prefix File name prefix
     * @param string $dir Directory (default: system temp)
     * @return string|null Path to temp file or null on failure
     */
    public static function tempFile(string $prefix = 'coyote', ?string $dir = null): ?string
    {
        $dir = $dir ?? sys_get_temp_dir();
        $path = tempnam($dir, $prefix);
        return $path !== false ? $path : null;
    }
}
