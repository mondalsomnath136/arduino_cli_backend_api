<?php

namespace App\MCP\Resources;

use App\V1\Services\LibraryService;
use Mcp\Capability\Attribute\McpResource;

/**
 * MCP Library Resources
 *
 * Exposes installed library data as a readable MCP resource.
 */
class LibrariesResource
{
    private LibraryService $libraryService;

    public function __construct()
    {
        $this->libraryService = new LibraryService();
    }

    /**
     * Read the list of all installed Arduino libraries.
     *
     * @return array  All installed libraries with metadata.
     */
    #[McpResource(
        uri: 'arduino://libraries/installed',
        name: 'Installed Arduino Libraries',
        description: 'Lists all Arduino libraries currently installed on this server. Each entry includes name, version, author, sentence (description), and installation path.',
        mimeType: 'application/json'
    )]
    public function installedLibraries(): array
    {
        try {
            $libraries = $this->libraryService->listInstalled();
            return [
                'libraries'    => $libraries,
                'count'        => is_array($libraries) ? count($libraries) : 0,
                'retrieved_at' => date('c'),
            ];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }
}
