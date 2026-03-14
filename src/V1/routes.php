<?php

/**
 * V1 API Routes
 * 
 * All routes are prefixed with /api/v1
 */

use App\V1\Controllers\CompileController;
use App\V1\Controllers\LibraryController;
use App\V1\Controllers\BoardController;
use App\V1\Controllers\StatusController;

return function ($router) {

    // ──────────────────────────────────────────────
    //  Health Check
    // ──────────────────────────────────────────────
    $router->get('/api/v1/status', function ($request) {
        (new StatusController())->index($request);
    });

    // ──────────────────────────────────────────────
    //  Compile & Verify
    // ──────────────────────────────────────────────
    $router->post('/api/v1/compile', function ($request) {
        (new CompileController())->compile($request);
    });

    $router->post('/api/v1/verify', function ($request) {
        (new CompileController())->verify($request);
    });

    $router->get('/api/v1/compile/{id}/status', function ($request) {
        (new CompileController())->status($request);
    });

    $router->get('/api/v1/compile/{id}/download', function ($request) {
        (new CompileController())->download($request);
    });

    // ──────────────────────────────────────────────
    //  Library Management
    // ──────────────────────────────────────────────
    $router->get('/api/v1/libraries', function ($request) {
        (new LibraryController())->list($request);
    });

    $router->get('/api/v1/libraries/search', function ($request) {
        (new LibraryController())->search($request);
    });

    $router->post('/api/v1/libraries/install', function ($request) {
        (new LibraryController())->install($request);
    });

    $router->delete('/api/v1/libraries/{name}', function ($request) {
        (new LibraryController())->uninstall($request);
    });

    // ──────────────────────────────────────────────
    //  Board Management
    // ──────────────────────────────────────────────
    $router->get('/api/v1/boards', function ($request) {
        (new BoardController())->list($request);
    });

    $router->get('/api/v1/boards/search', function ($request) {
        (new BoardController())->search($request);
    });

    $router->get('/api/v1/boards/known', function ($request) {
        (new BoardController())->known($request);
    });

    $router->post('/api/v1/boards/install', function ($request) {
        (new BoardController())->install($request);
    });

    $router->post('/api/v1/boards/update-index', function ($request) {
        (new BoardController())->updateIndex($request);
    });

    $router->delete('/api/v1/boards/{platform}', function ($request) {
        (new BoardController())->uninstall($request);
    });
};
