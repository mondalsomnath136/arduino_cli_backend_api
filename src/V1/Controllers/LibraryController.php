<?php

namespace App\V1\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Logger;
use App\V1\Services\LibraryService;

/**
 * Library Controller
 * 
 * Handles library management endpoints.
 */
class LibraryController
{
    private LibraryService $libraryService;

    public function __construct()
    {
        $this->libraryService = new LibraryService();
    }

    /**
     * GET /api/v1/libraries
     * 
     * List all installed libraries.
     */
    public function list(Request $request): void
    {
        try {
            $result = $this->libraryService->listInstalled();
            Response::success($result, 'Installed libraries retrieved');
        } catch (\Exception $e) {
            Logger::error('Library list error', ['error' => $e->getMessage()]);
            Response::serverError('Failed to list libraries.');
        }
    }

    /**
     * GET /api/v1/libraries/search
     * 
     * Search for available libraries.
     * 
     * Query params:
     *   q=search-term
     */
    public function search(Request $request): void
    {
        $query = $request->getQuery('q');

        if (empty($query)) {
            Response::validationError(['q' => 'Search query (q) is required.']);
            return;
        }

        try {
            $result = $this->libraryService->search($query);
            Response::success($result, 'Search results');
        } catch (\Exception $e) {
            Logger::error('Library search error', ['error' => $e->getMessage()]);
            Response::serverError('Failed to search libraries.');
        }
    }

    /**
     * POST /api/v1/libraries/install
     * 
     * Install a library.
     * 
     * Request Body:
     *   {
     *     "name": "Servo",
     *     "version": "1.2.0"  // optional
     *   }
     */
    public function install(Request $request): void
    {
        $name    = $request->getBody('name');
        $version = $request->getBody('version');

        if (empty($name)) {
            Response::validationError(['name' => 'Library name is required.']);
            return;
        }

        try {
            $result = $this->libraryService->install($name, $version);

            if ($result['success']) {
                Response::created($result, 'Library installed successfully');
            } else {
                Response::json([
                    'success' => false,
                    'message' => 'Library installation failed',
                    'data'    => $result,
                ], 422);
            }
        } catch (\Exception $e) {
            Logger::error('Library install error', ['error' => $e->getMessage()]);
            Response::serverError('Failed to install library.');
        }
    }

    /**
     * DELETE /api/v1/libraries/{name}
     * 
     * Uninstall a library.
     */
    public function uninstall(Request $request): void
    {
        $name = $request->getParam('name');

        if (empty($name)) {
            Response::validationError(['name' => 'Library name is required.']);
            return;
        }

        // URL decode the library name (it may contain spaces encoded as %20)
        $name = urldecode($name);

        try {
            $result = $this->libraryService->uninstall($name);

            if ($result['success']) {
                Response::success($result, 'Library uninstalled successfully');
            } else {
                Response::json([
                    'success' => false,
                    'message' => 'Library uninstall failed',
                    'data'    => $result,
                ], 422);
            }
        } catch (\Exception $e) {
            Logger::error('Library uninstall error', ['error' => $e->getMessage()]);
            Response::serverError('Failed to uninstall library.');
        }
    }
}
