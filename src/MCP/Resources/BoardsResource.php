<?php

namespace App\MCP\Resources;

use App\V1\Services\BoardService;
use Mcp\Capability\Attribute\McpResource;

/**
 * MCP Board Resources
 *
 * Exposes board data as readable MCP resources so AI agents can
 * access board information without calling tools.
 */
class BoardsResource
{
    private BoardService $boardService;

    public function __construct()
    {
        $this->boardService = new BoardService();
    }

    /**
     * Read the list of installed board platforms.
     *
     * @return array  Installed board platform data.
     */
    #[McpResource(
        uri: 'arduino://boards/installed',
        name: 'Installed Board Platforms',
        description: 'Lists all board platforms currently installed on this Arduino CLI server. Includes platform name, version, and installed FQBNs.',
        mimeType: 'application/json'
    )]
    public function installedBoards(): array
    {
        try {
            return [
                'installed_platforms' => $this->boardService->listInstalled(),
                'retrieved_at'        => date('c'),
            ];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Read all known board short-name to FQBN mappings.
     *
     * @return array  Map of short-names to FQBNs.
     */
    #[McpResource(
        uri: 'arduino://boards/known',
        name: 'Known Board Mappings',
        description: 'Pre-configured board short-name aliases mapped to their full FQBNs. Use these short-names in compile/verify calls instead of full FQBNs.',
        mimeType: 'application/json'
    )]
    public function knownBoards(): array
    {
        try {
            return [
                'boards'       => $this->boardService->getKnownBoards(),
                'retrieved_at' => date('c'),
            ];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }
}
