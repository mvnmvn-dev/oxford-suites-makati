<?php
/**
 * Oxford Suites Restaurant Management System — App Config
 */

$envFile = __DIR__ . '/../.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($key === '' || getenv($key) !== false) {
            continue;
        }

        if (strlen($value) >= 2 && $value[0] === '"' && $value[strlen($value) - 1] === '"') {
            $value = substr($value, 1, -1);
        }
        putenv($key . '=' . $value);
    }
}

return [
    'name' => 'Oxford Suites RMS',
    'brand' => 'Oxford Suites Makati',
    'tagline' => 'Restaurant of Suite',
    'version' => '1.0.0',
    'timezone' => 'Asia/Manila',
    'base_url' => getenv('RMS_BASE_URL') ?: '/oxford-rms',
    'api_prefix' => '/api',

    'db' => [
        'host' => getenv('RMS_DB_HOST') ?: '127.0.0.1',
        'port' => getenv('RMS_DB_PORT') ?: '3306',
        'name' => getenv('RMS_DB_NAME') ?: 'oxford_rms',
        'user' => getenv('RMS_DB_USER') ?: 'root',
        'pass' => getenv('RMS_DB_PASS') !== false ? getenv('RMS_DB_PASS') : '',
        'charset' => 'utf8mb4',
    ],

    'ml_service' => [
        'url' => getenv('RMS_ML_URL') ?: 'http://127.0.0.1:5001',
        // Skip remote ML when the Flask service isn't running (avoids ~2s timeout stalls).
        'enabled' => filter_var(getenv('RMS_ML_ENABLED') !== false ? getenv('RMS_ML_ENABLED') : '0', FILTER_VALIDATE_BOOLEAN),
    ],

    'jwt_secret' => getenv('RMS_JWT_SECRET') ?: 'oxford-suites-rms-dev-secret-change-me',
    'session_name' => 'oxford_rms_session',

    'roles' => ['admin', 'kitchen', 'cashier', 'customer'],

    'circuit_breaker' => [
        'failure_threshold' => 5,
        'recovery_timeout' => 30,
    ],
];
