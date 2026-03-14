<?php

/**
 * Arduino CLI Backend - Entry Point
 * 
 * All HTTP requests are routed through this file.
 * Handles autoloading, middleware registration, and request dispatching.
 */

// ──────────────────────────────────────────────
//  Error Reporting
// ──────────────────────────────────────────────
error_reporting(E_ALL);
ini_set('display_errors', 0); // Never display errors in API responses
ini_set('log_errors', 1);

// ──────────────────────────────────────────────
//  Autoloader (PSR-4 style without Composer)
// ──────────────────────────────────────────────
spl_autoload_register(function (string $class) {
    // Map namespace prefix to directory
    $prefixes = [
        'App\\' => __DIR__ . '/../src/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            continue;
        }

        $relativeClass = substr($class, $len);
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

// ──────────────────────────────────────────────
//  Load Environment Variables (.env)
// ──────────────────────────────────────────────
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (strpos($line, '#') === 0) continue;
        
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        
        // Remove quotes if present
        $value = trim($value, '"\'');
        
        if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

// ──────────────────────────────────────────────
//  Ensure HOME Environment Variable is Set
// ──────────────────────────────────────────────
// arduino-cli requires a $HOME directory to store cores and libraries.
// Web servers (like Apache/Nginx www-data) often don't have this set.
$arduinoHome = __DIR__ . '/../storage/arduino_home';
if (!is_dir($arduinoHome)) {
    mkdir($arduinoHome, 0755, true);
}
putenv('HOME=' . realpath($arduinoHome));
$_SERVER['HOME'] = realpath($arduinoHome);
$_ENV['HOME'] = realpath($arduinoHome);

// ──────────────────────────────────────────────
//  Load Configuration
// ──────────────────────────────────────────────
$appConfig = require __DIR__ . '/../config/app.php';

// Set timezone
date_default_timezone_set($appConfig['timezone']);

// ──────────────────────────────────────────────
//  Ensure Storage Directories Exist
// ──────────────────────────────────────────────
$storageDirs = [
    $appConfig['storage']['logs'],
    $appConfig['storage']['temp'],
    $appConfig['storage']['outputs'],
];

foreach ($storageDirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// ──────────────────────────────────────────────
//  Initialize Core Components
// ──────────────────────────────────────────────
use App\Core\Router;
use App\Core\Request;
use App\Core\Response;
use App\Core\Logger;
use App\Middleware\CorsMiddleware;
use App\Middleware\RateLimitMiddleware;

// ──────────────────────────────────────────────
//  Global Exception Handler
// ──────────────────────────────────────────────
set_exception_handler(function (\Throwable $e) {
    Logger::error('Unhandled exception', [
        'message' => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ]);
    Response::serverError('An unexpected error occurred.');
});

// ──────────────────────────────────────────────
//  Create Router & Register Middleware
// ──────────────────────────────────────────────
$router  = new Router();
$request = new Request();

// Register global middleware
$router->addMiddleware(new CorsMiddleware());
$router->addMiddleware(new RateLimitMiddleware());

// Optional: Enable API key authentication
// Uncomment the line below to require API keys for all endpoints (except /status)
// $router->addMiddleware(new \App\Middleware\AuthMiddleware());

// ──────────────────────────────────────────────
//  Load API Version Routes
// ──────────────────────────────────────────────

// V1 Routes
$router->loadRoutes(__DIR__ . '/../src/V1/routes.php');

// Future versions can be added here:
// $router->loadRoutes(__DIR__ . '/../src/V2/routes.php');

// ──────────────────────────────────────────────
//  Root Endpoint (API Info)
// ──────────────────────────────────────────────
$router->get('/', function ($request) {
    Response::success([
        'name'     => 'Arduino CLI Backend API',
        'version'  => 'v1',
        'docs'     => '/api/v1/status',
        'endpoints' => [
            'compile'   => '/api/v1/compile',
            'verify'    => '/api/v1/verify',
            'libraries' => '/api/v1/libraries',
            'boards'    => '/api/v1/boards',
            'status'    => '/api/v1/status',
        ],
    ], 'Arduino CLI Backend API');
});

$router->get('/api', function ($request) {
    Response::success([
        'available_versions' => ['v1'],
        'current_version'    => 'v1',
        'v1_base_url'        => '/api/v1',
    ], 'API Versions');
});

// ──────────────────────────────────────────────
//  Dispatch the Request
// ──────────────────────────────────────────────
$router->dispatch($request);
