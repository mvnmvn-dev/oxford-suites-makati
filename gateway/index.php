<?php
/**
 * API Gateway — single entry for all restaurant microservices.
 * Routes: /oxford-rms/api/{service}/{...}
 */
require_once __DIR__ . '/../shared/Response.php';
require_once __DIR__ . '/../shared/Auth.php';
require_once __DIR__ . '/../shared/Security.php';
require_once __DIR__ . '/../shared/bootstrap_events.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    Response::json(['ok' => true]);
}

$config = require __DIR__ . '/../config/app.php';
date_default_timezone_set($config['timezone']);
Auth::startSession();

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$base = rtrim($config['base_url'], '/');
$path = $uri;
if (str_starts_with($path, $base . '/api')) {
    $path = substr($path, strlen($base . '/api'));
} elseif (str_starts_with($path, '/api')) {
    $path = substr($path, 4);
}
$path = '/' . trim($path, '/');
$parts = array_values(array_filter(explode('/', $path)));
$service = $parts[0] ?? '';

if ($service === 'auth' && ($parts[1] ?? '') === 'csrf' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    Response::success(['token' => Security::csrfToken()]);
}

if (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT', 'PATCH', 'DELETE'], true)
    && !($service === 'auth' && in_array(($parts[1] ?? ''), ['login', 'register'], true))) {
    Security::requireCsrf();
}

$map = [
    'auth' => __DIR__ . '/../services/user/index.php',
    'users' => __DIR__ . '/../services/user/index.php',
    'menu' => __DIR__ . '/../services/menu/index.php',
    'orders' => __DIR__ . '/../services/order/index.php',
    'reservations' => __DIR__ . '/../services/reservation/index.php',
    'tables' => __DIR__ . '/../services/table/index.php',
    'inventory' => __DIR__ . '/../services/inventory/index.php',
    'payments' => __DIR__ . '/../services/payment/index.php',
    'kitchen' => __DIR__ . '/../services/kitchen/index.php',
    'notifications' => __DIR__ . '/../services/notification/index.php',
    'feedback' => __DIR__ . '/../services/feedback/index.php',
    'promotions' => __DIR__ . '/../services/promotion/index.php',
    'loyalty' => __DIR__ . '/../services/loyalty/index.php',
    'ml' => __DIR__ . '/ml_proxy.php',
    'analytics' => __DIR__ . '/analytics.php',
    'search' => __DIR__ . '/search.php',
    'demo' => __DIR__ . '/demo.php',
    'audit' => __DIR__ . '/audit.php',
];

if ($service === '' || $service === 'health') {
    Response::success([
        'service' => 'api-gateway',
        'version' => $config['version'],
        'brand' => $config['brand'],
        'services' => array_keys($map),
    ], 'Oxford Suites RMS API Gateway');
}

if (!isset($map[$service])) {
    Response::error('Unknown service: ' . $service, 404);
}

$_SERVER['RMS_SERVICE'] = $service;
$_SERVER['RMS_SUBPATH'] = implode('/', array_slice($parts, 1));
require $map[$service];
