<?php
/**
 * PHPUnit Bootstrap
 *
 * Load the autoloader for running tests.
 */

// Load the main Coyote autoloader
require_once __DIR__ . '/../rootfs/opt/coyote/lib/autoload.php';

// Set error reporting
error_reporting(E_ALL);

// Define constants if not set
if (!defined('COYOTE_VERSION')) {
    define('COYOTE_VERSION', '4.0.0');
}
if (!defined('COYOTE_CONFIG_PATH')) {
    define('COYOTE_CONFIG_PATH', sys_get_temp_dir() . '/coyote-test-config');
}
if (!defined('COYOTE_RUNNING_CONFIG')) {
    define('COYOTE_RUNNING_CONFIG', sys_get_temp_dir() . '/coyote-test-running');
}
