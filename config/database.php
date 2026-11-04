<?php

declare(strict_types=1);

/**
 * =====================================================================
 * Database Configuration with Smart Environment Auto-Detection
 * =====================================================================
 * Automatically detects whether the application is running in a local
 * development environment (WAMP / Laragon / localhost / stationery.local)
 * or on an online production host (e.g. InfinityFree).
 */

$httpHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
$serverName = (string) ($_SERVER['SERVER_NAME'] ?? '');

$isLocal = false;

// 1. If HTTP_HOST is present, determine environment by the requested host domain
if (!empty($httpHost)) {
    $targetHost = strtolower($httpHost);
    // Strip port number if present (e.g. localhost:8000, 127.0.0.1:8080)
    $hostWithoutPort = (string) preg_replace('/:\d+$/', '', $targetHost);

    if (
        str_contains($hostWithoutPort, 'localhost') ||
        str_contains($hostWithoutPort, 'stationery.local') ||
        str_contains($hostWithoutPort, '127.0.0.1') ||
        str_ends_with($hostWithoutPort, '.local') ||
        str_ends_with($hostWithoutPort, '.test')
    ) {
        $isLocal = true;
    } else {
        $isLocal = false;
    }
} elseif (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
    // 2. CLI or automated testing without HTTP_HOST defaults to local environment
    $isLocal = true;
} elseif (!empty($serverName)) {
    // 3. Fallback to SERVER_NAME if HTTP_HOST is absent
    $targetHost = strtolower($serverName);
    $hostWithoutPort = (string) preg_replace('/:\d+$/', '', $targetHost);
    $isLocal = str_contains($hostWithoutPort, 'localhost') ||
               str_contains($hostWithoutPort, 'stationery.local') ||
               str_contains($hostWithoutPort, '127.0.0.1') ||
               str_ends_with($hostWithoutPort, '.local') ||
               str_ends_with($hostWithoutPort, '.test');
}

if ($isLocal) {
    // -----------------------------------------------------------------
    // 1. LOCAL ENVIRONMENT (WAMP / Laragon / Localhost)
    // -----------------------------------------------------------------
    $host = (string) env('DB_HOST', 'localhost');
    $port = (int) env('DB_PORT', 3306);
    $database = (string) env('DB_DATABASE', 'stationery_shop');
    $username = (string) env('DB_USERNAME', 'root');
    $password = (string) env('DB_PASSWORD', '');
} else {
    // -----------------------------------------------------------------
    // 2. ONLINE PRODUCTION HOST (InfinityFree)
    // -----------------------------------------------------------------
    $host = (string) env('DB_PROD_HOST', 'sql311.infinityfree.com');
    $port = (int) env('DB_PROD_PORT', 3306);
    $database = (string) env('DB_PROD_DATABASE', 'if0_43356455_stationery');
    $username = (string) env('DB_PROD_USERNAME', 'if0_43356455');

    // Read production password from environment if set; otherwise use the panel password
    $prodEnvPass = env('DB_PROD_PASSWORD');
    $password = is_string($prodEnvPass) && $prodEnvPass !== ''
        ? $prodEnvPass
        : '[رمز_شما_در_پنل]';
}

return [
    'is_local' => $isLocal,
    'host' => $host,
    'port' => $port,
    'database' => $database,
    'username' => $username,
    'password' => $password,
    'charset' => (string) env('DB_CHARSET', 'utf8mb4'),
    'collation' => (string) env('DB_COLLATION', 'utf8mb4_unicode_ci'),
    'options' => [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'",
    ],
];
