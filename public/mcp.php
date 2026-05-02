<?php

/**
 * Arduino CLI Backend — MCP Server (Standard HTTP/SSE Transport)
 *
 * This entry point implements the STANDARD MCP Server-Sent Events protocol.
 * It is fully compatible with strict clients like Roo Code, Claude Desktop,
 * and the official @modelcontextprotocol/sdk TypeScript client.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Protocol Flow:
 * 1. GET /mcp.php
 *    -> Returns 200 OK (text/event-stream)
 *    -> Sends `endpoint` event with POST URL (?sessionId=...)
 *    -> Keeps connection open, looping to poll for outgoing messages
 * 2. POST /mcp.php?sessionId=...
 *    -> Passes JSON-RPC input to the SDK Protocol
 *    -> The SDK processes the tool and saves the response in FileSessionStore
 *    -> Returns 202 Accepted immediately
 *    -> The GET loop picks up the response from FileSessionStore and emits it
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// Output buffering guard
ob_start();

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/vendor/autoload.php';

// Environment setup
$envFile = $projectRoot . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if (str_starts_with($line, '#') || !str_contains($line, '=')) { continue; }
        [$name, $value] = explode('=', $line, 2);
        putenv(trim($name) . "=" . trim(trim($value), '"\''));
    }
}

// Set HOME for arduino-cli
$arduinoHome = $projectRoot . '/storage/arduino_home';
if (!is_dir($arduinoHome)) { @mkdir($arduinoHome, 0755, true); }
putenv('HOME=' . realpath($arduinoHome));

$appConfig = require $projectRoot . '/config/app.php';
date_default_timezone_set($appConfig['timezone']);

use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Session\SessionManager;
use App\MCP\Transport\StandardSseTransport;
use Symfony\Component\Uid\Uuid;

// CORS Headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_end_clean();
    http_response_code(204);
    exit;
}

// Ensure session directory exists
$sessionDir = $projectRoot . '/storage/mcp_sessions';
if (!is_dir($sessionDir)) { @mkdir($sessionDir, 0755, true); }

$sessionManager = new SessionManager(new FileSessionStore($sessionDir));

$server = Server::builder()
    ->setServerInfo('Arduino CLI Backend', '1.0.0')
    ->setDiscovery($projectRoot . '/src/MCP', ['Tools', 'Resources', 'Prompts'])
    ->setSession(new FileSessionStore($sessionDir)) // Provide the store to the builder
    ->build();

// --- GET Request: SSE Stream Connection ---
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    ob_end_clean(); // We manage output manually
    set_time_limit(0); // Allow long-running SSE connection
    
    $bridgeSessionId = bin2hex(random_bytes(16));
    
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no'); // Disable Nginx buffering
    
    $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $postEndpoint = "{$scheme}://{$host}{$path}?sessionId={$bridgeSessionId}";
    
    echo "event: endpoint\n";
    echo "data: {$postEndpoint}\n\n";
    @ob_flush(); flush();
    
    $bridgeFile = $appConfig['storage']['temp'] . '/mcp_bridge_' . $bridgeSessionId . '.txt';
    file_put_contents($bridgeFile, ""); 
    
    $realSessionId = null;
    
    while (!connection_aborted()) {
        if (!$realSessionId) {
            $contents = @file_get_contents($bridgeFile);
            if ($contents && strlen(trim($contents)) > 10) {
                $realSessionId = Uuid::fromString(trim($contents));
            }
        }
        
        if ($realSessionId) {
            // Read outgoing messages directly from the SDK's session store
            $session = $sessionManager->createWithId($realSessionId);
            $queue = $session->get('_mcp.outgoing_queue', []);
            
            if (!empty($queue)) {
                $session->set('_mcp.outgoing_queue', []);
                $session->save();
                
                foreach ($queue as $item) {
                    echo "event: message\n";
                    echo "data: " . $item['message'] . "\n\n";
                    @ob_flush(); flush();
                }
            }
        }
        usleep(100000); // 100ms polling
    }
    
    @unlink($bridgeFile);
    if ($realSessionId) {
        $sessionManager->destroy($realSessionId);
    }
    exit;
}

// --- POST Request: JSON-RPC Message Endpoint ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_end_clean();
    
    $bridgeSessionId = $_GET['sessionId'] ?? null;
    if (!$bridgeSessionId) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing sessionId in query string']);
        exit;
    }
    
    $bridgeFile = $appConfig['storage']['temp'] . '/mcp_bridge_' . $bridgeSessionId . '.txt';
    $realSessionId = null;
    $contents = @file_get_contents($bridgeFile);
    if ($contents && strlen(trim($contents)) > 10) {
        $realSessionId = Uuid::fromString(trim($contents));
    }
    
    $input = file_get_contents('php://input');
    
    $transport = new StandardSseTransport($input, $realSessionId);
    $server->run($transport);
    
    if ($transport->newSessionId) {
        // SDK created a real session during the 'initialize' request
        file_put_contents($bridgeFile, $transport->newSessionId->toRfc4122());
    }
    
    // Standard MCP protocol dictates POST endpoints return 202 Accepted
    http_response_code(202);
    echo "Accepted";
    exit;
}

ob_end_clean();
http_response_code(405);
echo "Method Not Allowed";
