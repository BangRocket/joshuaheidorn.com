<?php

// Dev-only router for PHP's built-in server: serve real static files
// directly, route everything else through the Slim front controller.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && is_file(__DIR__ . $path)) {
    return false;
}
require __DIR__ . '/index.php';
