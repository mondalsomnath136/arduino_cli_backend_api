<?php

/**
 * Arduino CLI Backend — MCP Server (STDIO Transport)
 *
 * Entry point for MCP clients using STDIO transport:
 *   - VS Code Roo Code extension
 *   - Antigravity IDE
 *   - Claude Desktop
 *   - Any MCP client that spawns a subprocess
 *
 * Usage:
 *   php mcp/server.php
 *
 * Claude Desktop / Roo Code config:
 *   {
 *     "command": "C:\\xampp\\php\\php.exe",
 *     "args": ["C:\\path\\to\\arduino_cli_backend_api\\mcp\\server.php"]
 *   }
 *
 * IMPORTANT: This script MUST NOT output anything to stdout before the MCP
 * server starts — any extraneous output breaks the JSON-RPC protocol.
 * All logging goes to stderr or a log file.
 */

declare(strict_types=1);

// ──────────────────────────────────────────────
//  Suppress all HTML/display errors — STDIO MCP
//  uses stdout as a binary JSON-RPC channel.
// ──────────────────────────────────────────────
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// ──────────────────────────────────────────────
//  Bootstrap
// ──────────────────────────────────────────────
$projectRoot = dirname(__DIR__);

// Composer autoloader (mcp/sdk + App\MCP namespace)
$autoloader = $projectRoot . '/vendor/autoload.php';
if (!file_exists($autoloader)) {
    fwrite(STDERR, "[MCP] ERROR: vendor/autoload.php not found. Run: composer install\n");
    exit(1);
}
require_once $autoloader;

// ──────────────────────────────────────────────
//  Environment Setup (mirrors public/index.php)
// ──────────────────────────────────────────────

// Load .env if present
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

// Set HOME for arduino-cli (required for cores/libraries storage)
$arduinoHome = $projectRoot . '/storage/arduino_home';
if (!is_dir($arduinoHome)) {
    @mkdir($arduinoHome, 0755, true);
}
putenv('HOME=' . realpath($arduinoHome));
$_SERVER['HOME'] = realpath($arduinoHome);
$_ENV['HOME']    = realpath($arduinoHome);

// Set timezone
$appConfig = require $projectRoot . '/config/app.php';
date_default_timezone_set($appConfig['timezone']);

// Ensure storage directories exist
foreach (['logs', 'temp', 'outputs'] as $dir) {
    $path = $appConfig['storage'][$dir];
    if (!is_dir($path)) {
        @mkdir($path, 0755, true);
    }
}

// ──────────────────────────────────────────────
//  MCP Server Import
// ──────────────────────────────────────────────
use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;
use Mcp\Server\Session\InMemorySessionStore;

// ──────────────────────────────────────────────
//  Build the MCP Server
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
        // Auto-discover all #[McpTool], #[McpResource], #[McpPrompt] attributes
        ->setDiscovery(
            basePath: $projectRoot . '/src/MCP',
            scanDirs: ['Tools', 'Resources', 'Prompts']
        )
        // In-memory session — one session per STDIO process (correct for STDIO transport)
        ->setSession(sessionStore: new InMemorySessionStore())
        ->build();

    // ──────────────────────────────────────────
    //  Run with STDIO transport
    // ──────────────────────────────────────────
    $transport = new StdioTransport();
    $server->run($transport);

} catch (\Throwable $e) {
    fwrite(STDERR, "[MCP] FATAL: " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(1);
}
