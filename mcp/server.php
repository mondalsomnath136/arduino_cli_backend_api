<?php

/**
 * Arduino CLI Backend — MCP Server (STDIO Transport)
 *
 * Entry point for MCP clients using STDIO transport:
 *   - VS Code Roo Code extension (command-based MCP)
 *   - Antigravity IDE
 *   - Claude Desktop
 *   - Any MCP client that spawns a subprocess
 *
 * Usage:
 *   php mcp/server.php
 *
 * Claude Desktop / Roo Code STDIO config:
 *   {
 *     "command": "C:\\xampp\\php\\php.exe",
 *     "args": ["C:\\path\\to\\arduino_cli_backend_api\\mcp\\server.php"]
 *   }
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * CRITICAL: This script MUST NOT output ANYTHING to stdout before the MCP
 * server starts. STDIO transport uses stdout as a binary JSON-RPC channel.
 * Any stray output (warnings, whitespace, BOM) breaks the protocol entirely.
 * All diagnostic output goes to stderr or a log file.
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

// ── STEP 1: Silence ALL PHP output to stdout ──────────────────────────────────
// STDIO transport: stdout = JSON-RPC channel, stderr = debug log.
// display_errors MUST be 0 or any PHP warning will corrupt the protocol.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// ── STEP 2: Output buffering guard ────────────────────────────────────────────
// Belt-and-suspenders: capture any accidental output before SDK takes over.
// We discard it immediately once the SDK's STDIO loop starts.
ob_start();

// ── STEP 3: Bootstrap ─────────────────────────────────────────────────────────
$projectRoot = dirname(__DIR__);

// Composer autoloader — must come before any namespace use statement
$autoloader = $projectRoot . '/vendor/autoload.php';
if (!file_exists($autoloader)) {
    ob_end_clean();
    fwrite(STDERR, "[MCP STDIO] ERROR: vendor/autoload.php not found. Run: composer install\n");
    exit(1);
}
require_once $autoloader;

// ── STEP 4: Load environment variables from .env ──────────────────────────────
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

// ── STEP 5: Set HOME for arduino-cli ─────────────────────────────────────────
// arduino-cli requires a writable HOME to store cores, libraries, and cache.
$arduinoHome = $projectRoot . '/storage/arduino_home';
if (!is_dir($arduinoHome)) {
    @mkdir($arduinoHome, 0755, true);
}
putenv('HOME=' . realpath($arduinoHome));
$_SERVER['HOME'] = realpath($arduinoHome);
$_ENV['HOME']    = realpath($arduinoHome);

// ── STEP 6: App config and storage directories ────────────────────────────────
$appConfig = require $projectRoot . '/config/app.php';
date_default_timezone_set($appConfig['timezone']);

foreach (['logs', 'temp', 'outputs'] as $dir) {
    $path = $appConfig['storage'][$dir];
    if (!is_dir($path)) {
        @mkdir($path, 0755, true);
    }
}

// ── STEP 7: SDK imports (after autoloader is ready) ───────────────────────────
use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;
use Mcp\Server\Session\InMemorySessionStore;

// ── STEP 8: Discard any buffered stray output before STDIO loop ───────────────
// The STDIO channel must be clean before the first byte is written.
ob_end_clean();

// ── STEP 9: Build and run the MCP Server ─────────────────────────────────────
try {
    $server = Server::builder()
        ->setServerInfo(
            name:        'Arduino CLI Backend',
            version:     '1.0.0',
            description: 'Arduino CLI Backend MCP Server — compile, verify, manage boards and libraries',
        )
        ->setInstructions(
            'This MCP server exposes the Arduino CLI Backend API. ' .
            'Use arduino_compile to compile sketches, arduino_verify to syntax-check code, ' .
            'arduino_board_* tools to manage boards, and arduino_library_* tools to manage libraries. ' .
            'Read arduino://config for a full capability map, or arduino://status to check server health.'
        )
        // Auto-discovers all #[McpTool], #[McpResource], #[McpPrompt] attributes
        // in src/MCP/Tools, src/MCP/Resources, src/MCP/Prompts
        ->setDiscovery(
            basePath: $projectRoot . '/src/MCP',
            scanDirs: ['Tools', 'Resources', 'Prompts'],
        )
        // In-memory session — correct for STDIO transport (one session per process lifetime).
        // FileSessionStore is NOT needed here; STDIO is a single persistent process.
        ->setSession(sessionStore: new InMemorySessionStore())
        ->build();

    // StdioTransport reads from STDIN, writes JSON-RPC to STDOUT.
    // It runs in a blocking loop until EOF on STDIN (client disconnects).
    $transport = new StdioTransport();
    $server->run($transport);

} catch (\Throwable $e) {
    // All fatal errors go to stderr — NEVER to stdout (JSON-RPC channel).
    fwrite(STDERR, "[MCP STDIO] FATAL: " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(1);
}
