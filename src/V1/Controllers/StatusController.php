<?php

namespace App\V1\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Database;

/**
 * Status Controller
 * 
 * Health check and system status endpoints.
 */
class StatusController
{
    /**
     * GET /api/v1/status
     * 
     * System health check.
     */
    public function index(Request $request): void
    {
        $status = [
            'status'  => 'ok',
            'version' => 'v1',
            'app'     => 'Arduino CLI Backend',
        ];

        // Check database connection
        try {
            $db = Database::getInstance();
            $db->query("SELECT 1");
            $status['database'] = 'connected';
        } catch (\Exception $e) {
            $status['database'] = 'disconnected';
            $status['status'] = 'degraded';
        }

        // Check arduino-cli availability
        $arduinoConfig = require __DIR__ . '/../../../config/arduino.php';
        $cliPath = $arduinoConfig['cli_path'];
        $versionOutput = shell_exec(escapeshellarg($cliPath) . " version 2>&1");

        if ($versionOutput && strpos($versionOutput, 'arduino-cli') !== false) {
            $status['arduino_cli'] = trim($versionOutput);
        } else {
            $status['arduino_cli'] = 'not available';
            $status['status'] = 'degraded';
        }

        // System info
        $status['server_time'] = date('Y-m-d H:i:s T');
        $status['php_version'] = PHP_VERSION;

        // Check storage directories
        $appConfig = require __DIR__ . '/../../../config/app.php';
        $status['storage'] = [
            'temp_writable'    => is_writable($appConfig['storage']['temp'] ?? '/tmp'),
            'logs_writable'    => is_writable($appConfig['storage']['logs'] ?? '/tmp'),
            'outputs_writable' => is_writable($appConfig['storage']['outputs'] ?? '/tmp'),
        ];

        $httpCode = ($status['status'] === 'ok') ? 200 : 503;
        Response::json(['success' => true, 'data' => $status], $httpCode);
    }
}
