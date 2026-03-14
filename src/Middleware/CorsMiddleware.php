<?php

namespace App\Middleware;

use App\Core\Request;

/**
 * CORS Middleware
 * 
 * Handles Cross-Origin Resource Sharing headers.
 */
class CorsMiddleware
{
    public function __invoke(Request $request): bool
    {
        $config = require __DIR__ . '/../../config/app.php';
        $cors = $config['cors'];

        $origin = $request->getHeader('origin') ?? '*';

        // Check if origin is allowed
        $allowedOrigins = $cors['allowed_origins'];
        $isAllowed = in_array('*', $allowedOrigins) || in_array($origin, $allowedOrigins);

        if ($isAllowed) {
            $allowOrigin = in_array('*', $allowedOrigins) ? '*' : $origin;
            header('Access-Control-Allow-Origin: ' . $allowOrigin);
        }

        header('Access-Control-Allow-Methods: ' . implode(', ', $cors['allowed_methods']));
        header('Access-Control-Allow-Headers: ' . implode(', ', $cors['allowed_headers']));
        header('Access-Control-Max-Age: ' . $cors['max_age']);
        header('Access-Control-Allow-Credentials: true');

        return true; // Continue to next middleware
    }
}
