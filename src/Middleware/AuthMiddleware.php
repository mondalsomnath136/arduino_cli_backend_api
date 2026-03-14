<?php

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\Database;

/**
 * Auth Middleware (Optional API Key Authentication)
 * 
 * Validates API key from X-API-Key header or api_key query parameter.
 * Can be enabled/disabled via config.
 */
class AuthMiddleware
{
    public function __invoke(Request $request): bool
    {
        // Skip auth for status/health endpoints
        $path = $request->getPath();
        if (strpos($path, '/status') !== false) {
            return true;
        }

        $apiKey = $request->getApiKey();

        if (empty($apiKey)) {
            Response::unauthorized('API key is required. Pass it via X-API-Key header or api_key query parameter.');
            return false;
        }

        try {
            $db = Database::getInstance();
            $result = $db->fetch(
                "SELECT id, name, is_active FROM api_keys WHERE api_key = ? AND is_active = 1",
                [$apiKey]
            );

            if (!$result) {
                Response::unauthorized('Invalid or inactive API key.');
                return false;
            }

            // Update last used timestamp
            $db->query(
                "UPDATE api_keys SET last_used_at = NOW() WHERE id = ?",
                [$result['id']]
            );

        } catch (\Exception $e) {
            // If auth check fails, deny the request
            error_log('Auth check failed: ' . $e->getMessage());
            Response::serverError('Authentication service unavailable.');
            return false;
        }

        return true;
    }
}
