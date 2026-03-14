<?php

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\Database;

/**
 * Rate Limit Middleware
 * 
 * Limits API requests per IP using MySQL for tracking.
 */
class RateLimitMiddleware
{
    public function __invoke(Request $request): bool
    {
        $config = require __DIR__ . '/../../config/app.php';

        if (!$config['rate_limit']['enabled']) {
            return true;
        }

        $maxRequests   = $config['rate_limit']['max_requests'];
        $windowSeconds = $config['rate_limit']['window_seconds'];
        $clientIp      = $request->getClientIp();

        try {
            $db = Database::getInstance();

            // Clean up old entries
            $db->query(
                "DELETE FROM rate_limits WHERE expires_at < NOW()"
            );

            // Count current requests in window
            $result = $db->fetch(
                "SELECT COUNT(*) as request_count FROM rate_limits WHERE ip_address = ? AND expires_at > NOW()",
                [$clientIp]
            );

            $requestCount = (int)($result['request_count'] ?? 0);

            // Set rate limit headers
            header('X-RateLimit-Limit: ' . $maxRequests);
            header('X-RateLimit-Remaining: ' . max(0, $maxRequests - $requestCount - 1));

            if ($requestCount >= $maxRequests) {
                Response::tooManyRequests();
                return false;
            }

            // Record this request
            $db->query(
                "INSERT INTO rate_limits (ip_address, expires_at) VALUES (?, DATE_ADD(NOW(), INTERVAL ? SECOND))",
                [$clientIp, $windowSeconds]
            );

        } catch (\Exception $e) {
            // If rate limiting fails (e.g., DB issue), allow the request
            // but log the error
            error_log('Rate limit check failed: ' . $e->getMessage());
        }

        return true;
    }
}
