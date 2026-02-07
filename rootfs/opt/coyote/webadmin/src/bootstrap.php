<?php
/**
 * Coyote Web Admin Bootstrap
 *
 * Initializes the application environment.
 */

// Error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Set timezone
date_default_timezone_set('UTC');

// Load core Coyote autoloader
require_once '/opt/coyote/lib/autoload.php';

// Register WebAdmin namespace
spl_autoload_register(function (string $class): void {
    $prefix = 'Coyote\\WebAdmin\\';
    $baseDir = __DIR__ . '/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Define common constants
define('COYOTE_VERSION', '4.0.0');
define('COYOTE_CONFIG_PATH', '/mnt/config');
define('COYOTE_RUNNING_CONFIG', '/tmp/running-config');
define('COYOTE_WEBADMIN_ROOT', dirname(__DIR__));

// Authentication bypass - set to true to skip login during development/initial setup
define('COYOTE_AUTH_BYPASS', false);
