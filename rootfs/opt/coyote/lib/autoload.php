<?php
/**
 * Coyote Linux 4 PSR-4 Autoloader
 *
 * This autoloader handles class loading for the Coyote namespace
 * and any installed add-ons.
 */

spl_autoload_register(function ($class) {
    // Base directories for namespace prefixes
    $prefixes = [
        'Coyote\\' => __DIR__ . '/Coyote/',
    ];

    // Check add-on directories
    $addonDir = '/opt/coyote/addons/';
    if (is_dir($addonDir)) {
        foreach (scandir($addonDir) as $addon) {
            if ($addon === '.' || $addon === '..') {
                continue;
            }
            $addonSrc = $addonDir . $addon . '/src/';
            if (is_dir($addonSrc)) {
                $prefixes['Coyote\\' . ucfirst($addon) . '\\'] = $addonSrc . 'Coyote/' . ucfirst($addon) . '/';
            }
        }
    }

    // Check each namespace prefix
    foreach ($prefixes as $prefix => $baseDir) {
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            continue;
        }

        $relativeClass = substr($class, $len);
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

        if (file_exists($file)) {
            require $file;
            return;
        }
    }
});
