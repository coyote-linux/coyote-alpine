<?php
/**
 * Coyote Linux PSR-4 Autoloader
 *
 * Handles automatic class loading for all Coyote namespaces.
 * Classes are loaded from /opt/coyote/lib/ following PSR-4 conventions.
 *
 * Supported namespaces:
 *   - Coyote\Config\       Configuration management
 *   - Coyote\System\       System operations (hardware, network, services)
 *   - Coyote\Firewall\     Firewall and ACL management
 *   - Coyote\Vpn\          VPN/IPSec management
 *   - Coyote\LoadBalancer\ HAProxy load balancer
 *   - Coyote\Util\         Utility classes
 */

spl_autoload_register(function (string $class): void {
    // Base directory for Coyote namespace
    $baseDir = __DIR__ . '/Coyote/';
    $prefix = 'Coyote\\';
    $len = strlen($prefix);

    // Only handle Coyote namespace
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    // Get the relative class name
    $relativeClass = substr($class, $len);

    // Replace namespace separators with directory separators
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});
