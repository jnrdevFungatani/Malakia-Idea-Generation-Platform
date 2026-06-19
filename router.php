<?php
/**
 * Dev router for: php -S localhost:8000 router.php
 * Serves static assets directly, routes everything else to index.php.
 */
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $uri;

if ($uri !== '/' && file_exists($file) && !is_dir($file) && !str_contains($uri, 'database/')) {
    return false; // let the built-in server serve the asset
}
require __DIR__ . '/index.php';
