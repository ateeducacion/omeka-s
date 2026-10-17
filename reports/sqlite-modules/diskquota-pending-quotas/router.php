<?php
$routes = [
    '/contradictory-item' => 'contradictory-item.php',
    '/pending-uploads' => 'pending-uploads.php',
    '/url-ingestion' => 'url-ingestion.php',
];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (!isset($routes[$path])) {
    http_response_code(404);
    exit;
}
require __DIR__ . '/' . $routes[$path];
