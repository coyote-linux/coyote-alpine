<?php
/**
 * Coyote Linux REST API
 *
 * Entry point for API requests.
 */

require_once __DIR__ . '/../src/bootstrap.php';

use Coyote\WebAdmin\App;

// Set JSON content type
header('Content-Type: application/json');

$app = new App(['api_only' => true]);
$app->registerApiRoutes();
$app->run();
