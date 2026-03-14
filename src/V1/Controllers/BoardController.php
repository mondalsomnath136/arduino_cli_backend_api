<?php

namespace App\V1\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Logger;
use App\V1\Services\BoardService;

/**
 * Board Controller
 * 
 * Handles board/platform management endpoints.
 */
class BoardController
{
    private BoardService $boardService;

    public function __construct()
    {
        $this->boardService = new BoardService();
    }

    /**
     * GET /api/v1/boards
     * 
     * List installed board platforms.
     * 
     * Query params:
     *   all=1  - List all individual boards across installed platforms
     */
    public function list(Request $request): void
    {
        try {
            if ($request->getQuery('all') === '1') {
                $result = $this->boardService->listAllBoards();
                Response::success($result, 'All available boards retrieved');
            } else {
                $result = $this->boardService->listInstalled();
                Response::success($result, 'Installed platforms retrieved');
            }
        } catch (\Exception $e) {
            Logger::error('Board list error', ['error' => $e->getMessage()]);
            Response::serverError('Failed to list boards.');
        }
    }

    /**
     * GET /api/v1/boards/search
     * 
     * Search for board platforms.
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
            $result = $this->boardService->search($query);
            Response::success($result, 'Search results');
        } catch (\Exception $e) {
            Logger::error('Board search error', ['error' => $e->getMessage()]);
            Response::serverError('Failed to search boards.');
        }
    }

    /**
     * POST /api/v1/boards/install
     * 
     * Install a board platform.
     * 
     * Request Body:
     *   {
     *     "platform": "esp32:esp32"
     *   }
     */
    public function install(Request $request): void
    {
        $platform = $request->getBody('platform');

        if (empty($platform)) {
            Response::validationError(['platform' => 'Platform identifier is required (e.g., "esp32:esp32").']);
            return;
        }

        try {
            $result = $this->boardService->install($platform);

            if ($result['success']) {
                Response::created($result, 'Board platform installed successfully');
            } else {
                Response::json([
                    'success' => false,
                    'message' => 'Board platform installation failed',
                    'data'    => $result,
                ], 422);
            }
        } catch (\Exception $e) {
            Logger::error('Board install error', ['error' => $e->getMessage()]);
            Response::serverError('Failed to install board platform.');
        }
    }

    /**
     * DELETE /api/v1/boards/{platform}
     * 
     * Uninstall a board platform.
     */
    public function uninstall(Request $request): void
    {
        $platform = $request->getParam('platform');

        if (empty($platform)) {
            Response::validationError(['platform' => 'Platform identifier is required.']);
            return;
        }

        $platform = urldecode($platform);

        try {
            $result = $this->boardService->uninstall($platform);

            if ($result['success']) {
                Response::success($result, 'Board platform uninstalled successfully');
            } else {
                Response::json([
                    'success' => false,
                    'message' => 'Board platform uninstall failed',
                    'data'    => $result,
                ], 422);
            }
        } catch (\Exception $e) {
            Logger::error('Board uninstall error', ['error' => $e->getMessage()]);
            Response::serverError('Failed to uninstall board platform.');
        }
    }

    /**
     * POST /api/v1/boards/update-index
     * 
     * Update the board index / package index.
     */
    public function updateIndex(Request $request): void
    {
        try {
            $result = $this->boardService->updateIndex();
            Response::success($result, 'Board index updated');
        } catch (\Exception $e) {
            Logger::error('Board index update error', ['error' => $e->getMessage()]);
            Response::serverError('Failed to update board index.');
        }
    }

    /**
     * GET /api/v1/boards/known
     * 
     * Get the known boards mapping (shortnames to FQBNs).
     */
    public function known(Request $request): void
    {
        $boards = $this->boardService->getKnownBoards();
        Response::success(['boards' => $boards], 'Known board mappings');
    }
}
