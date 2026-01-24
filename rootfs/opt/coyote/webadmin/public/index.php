<?php
/**
 * Coyote Linux Web Administration Interface
 *
 * Main entry point for the web admin application.
 */

require_once __DIR__ . '/../src/bootstrap.php';

use Coyote\WebAdmin\App;

$app = new App();
$app->registerRoutes();
$app->run();
