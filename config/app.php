<?php

/**
 * Application Configuration
 */

return [
    'name'    => 'Arduino CLI Backend',
    'version' => '1.0.0',
    'debug'   => getenv('APP_DEBUG') ?: false,
    'timezone' => 'Asia/Kolkata',

    // CORS settings
    'cors' => [
        'allowed_origins' => ['*'],
        'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
        'allowed_headers' => ['Content-Type', 'Authorization', 'X-API-Key', 'X-Requested-With'],
        'max_age'         => 86400,
    ],

    // Rate limiting
    'rate_limit' => [
        'enabled'     => true,
        'max_requests' => 60,
        'window_seconds' => 60,
    ],

    // Storage paths
    'storage' => [
        'logs'    => __DIR__ . '/../storage/logs',
        'temp'    => __DIR__ . '/../storage/temp',
        'outputs' => __DIR__ . '/../storage/outputs',
    ],

    // Compile settings
    'compile' => [
        'timeout'          => 120,       // seconds
        'max_code_size'    => 1048576,   // 1MB
        'cleanup_after'    => 3600,      // cleanup temp files after 1 hour
        'output_keep_days' => 7,         // keep compiled outputs for 7 days
    ],
];
