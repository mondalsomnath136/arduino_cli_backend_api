<?php

/**
 * Arduino CLI Backend — MCP Server (HTTP / Streamable HTTP Transport)
 *
 * Entry point for MCP clients using HTTP or Server-Sent Events transport:
 *   - Web-based MCP clients
 *   - Remote agent integrations
 *   - Any client supporting the MCP HTTP transport spec
 *
 * URL: http://localhost/arduino_cli_backend_api/public/mcp.php
 *      or with your virtual host: http://arduino-api.local/mcp.php
 *
 * Configure in your MCP client:
 *   { "url": "http://localhost/arduino_cli_backend_api/public/mcp.php" }
 */

declare(strict_types=1);

// ──────────────────────────────────────────────
//  Error handling — never leak PHP errors into
//  MCP JSON-RPC responses
// ──────────────────────────────────────────────
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// ──────────────────────────────────────────────
//  Bootstrap
// ──────────────────────────────────────────────
$projectRoot = dirname(__DIR__);

$autoloader = $projectRoot . '/vendor/autoload.php';
if (!file_exists($autoloader)) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'MCP server not initialized. Run: composer install']);
    exit;
}
require_once $autoloader;

// Load .env
$envFile = $projectRoot . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if (str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$name, $value] = explode('=', $line, 2);
        $name  = trim($name);
        $value = trim(trim($value), '"\'');
        if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
            putenv("{$name}={$value}");
            $_ENV[$name]    = $value;
            $_SERVER[$name] = $value;
        }
    }
}

// arduino-cli HOME
$arduinoHome = $projectRoot . '/storage/arduino_home';
if (!is_dir($arduinoHome)) {
    @mkdir($arduinoHome, 0755, true);
}
putenv('HOME=' . realpath($arduinoHome));

// App config
$appConfig = require $projectRoot . '/config/app.php';
date_default_timezone_set($appConfig['timezone']);

// Storage dirs
foreach (['logs', 'temp', 'outputs'] as $key) {
    $path = $appConfig['storage'][$key];
    if (!is_dir($path)) {
        @mkdir($path, 0755, true);
    }
}

// ──────────────────────────────────────────────
//  CORS Headers for HTTP MCP clients
// ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, Mcp-Session-Id, Mcp-Protocol-Version, Accept, Last-Event-ID');
    header('Access-Control-Expose-Headers: Mcp-Session-Id');
    header('Access-Control-Max-Age: 86400');
    http_response_code(204);
    exit;
}

// ──────────────────────────────────────────────
//  MCP Imports
// ──────────────────────────────────────────────
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Mcp\Server;
use Mcp\Server\Transport\StreamableHttpTransport;
use Mcp\Server\Session\FileSessionStore;

// ──────────────────────────────────────────────
//  Build PSR-7 ServerRequest from globals
//  using php-http/discovery (already installed)
// ──────────────────────────────────────────────
$requestFactory  = Psr17FactoryDiscovery::findServerRequestFactory();
$responseFactory = Psr17FactoryDiscovery::findResponseFactory();
$streamFactory   = Psr17FactoryDiscovery::findStreamFactory();

$method  = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri     = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
         . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
         . ($_SERVER['REQUEST_URI'] ?? '/');

$request = $requestFactory->createServerRequest($method, $uri, $_SERVER);

// Add headers
foreach ($_SERVER as $key => $value) {
    if (str_starts_with($key, 'HTTP_')) {
        $name    = str_replace('_', '-', substr($key, 5));
        $request = $request->withHeader($name, $value);
    } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
        $name    = str_replace('_', '-', $key);
        $request = $request->withHeader($name, $value);
    }
}

// Add body
$rawBody = file_get_contents('php://input');
if (!empty($rawBody)) {
    $bodyStream = $streamFactory->createStream($rawBody);
    $request    = $request->withBody($bodyStream);
}

// ──────────────────────────────────────────────
//  Session Store — file-based for HTTP transport
// ──────────────────────────────────────────────
$sessionPath = $projectRoot . '/storage/mcp_sessions';
if (!is_dir($sessionPath)) {
    @mkdir($sessionPath, 0755, true);
}

// ──────────────────────────────────────────────
//  Build and Run MCP Server
// ──────────────────────────────────────────────
try {
    $server = Server::builder()
        ->setServerInfo(
            name: 'Arduino CLI Backend',
            version: '1.0.0',
            description: 'Arduino CLI Backend MCP Server — compile, verify, manage boards and libraries'
        )
        ->setInstructions(
            'This MCP server exposes the Arduino CLI Backend API. ' .
            'Use arduino_compile to compile sketches, arduino_verify to syntax-check code, ' .
            'arduino_board_* tools to manage boards, and arduino_library_* tools to manage libraries. ' .
            'Read arduino://config for a full capability map, or arduino://status to check server health.'
        )
        ->setDiscovery(
            basePath: $projectRoot . '/src/MCP',
            scanDirs: ['Tools', 'Resources', 'Prompts']
        )
        // File-based session — survives across multiple HTTP requests
        ->setSession(sessionStore: new FileSessionStore($sessionPath))
        ->build();

    $transport = new StreamableHttpTransport(
        request: $request,
        responseFactory: $responseFactory,
        streamFactory: $streamFactory,
        corsHeaders: [
            'Access-Control-Allow-Origin'  => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, Mcp-Session-Id, Mcp-Protocol-Version, Accept, Last-Event-ID',
            'Access-Control-Expose-Headers' => 'Mcp-Session-Id',
        ]
    );

    $response = $server->run($transport);

    // Emit PSR-7 response
    http_response_code($response->getStatusCode());
    foreach ($response->getHeaders() as $name => $values) {
        foreach ($values as $value) {
            header("{$name}: {$value}", false);
        }
    }
    echo (string) $response->getBody();

} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'jsonrpc' => '2.0',
        'error'   => [
            'code'    => -32603,
            'message' => 'Internal server error: ' . $e->getMessage(),
        ],
        'id' => null,
    ]);
    error_log('[MCP HTTP] Fatal: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
}
