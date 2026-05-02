<?php

namespace App\MCP\Tools;

use App\V1\Services\BoardService;
use Mcp\Capability\Attribute\McpTool;

/**
 * MCP Board Tools
 *
 * Exposes Arduino board/platform management capabilities to AI agents via MCP.
 * Wraps the existing BoardService without duplication.
 */
class BoardTools
{
    private BoardService $boardService;

    public function __construct()
    {
        $this->boardService = new BoardService();
    }

    /**
     * List installed board platforms.
     *
     * @param  string  $mode  'platforms' (default) lists installed platforms; 'all' lists every individual board.
     * @return string  JSON with platform/board list.
     */
    #[McpTool(
        name: 'arduino_board_list',
        description: 'List installed board platforms on this Arduino CLI instance. Use mode="all" to list every individual board across all installed platforms, or mode="platforms" (default) for a summary by platform.'
    )]
    public function list(string $mode = 'platforms'): string
    {
        try {
            if ($mode === 'all') {
                $result = $this->boardService->listAllBoards();
                $label  = 'all_boards';
            } else {
                $result = $this->boardService->listInstalled();
                $label  = 'installed_platforms';
            }

            return json_encode(['success' => true, $label => $result]);
        } catch (\Throwable $e) {
            return json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Search for available board platforms in the Arduino registry.
     *
     * @param  string  $query  Search term (e.g. "esp32", "nano", "arduino avr").
     * @return string  JSON with matching platforms from the board index.
     */
    #[McpTool(
        name: 'arduino_board_search',
        description: 'Search the Arduino board index for available board platforms. Returns matching platforms with their names, versions, and FQBNs. Example queries: "esp32", "nano", "avr".'
    )]
    public function search(string $query): string
    {
        if (empty(trim($query))) {
            return json_encode(['success' => false, 'error' => 'Search query is required.']);
        }

        try {
            $result = $this->boardService->search($query);
            return json_encode([
                'success'  => true,
                'query'    => $query,
                'results'  => $result,
                'tip'      => 'Use arduino_board_install with the platform identifier (e.g. "esp32:esp32") to install.',
            ]);
        } catch (\Throwable $e) {
            return json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Install a board platform.
     *
     * @param  string  $platform  Platform identifier in vendor:arch format (e.g. "esp32:esp32", "arduino:avr").
     * @return string  JSON with installation result.
     */
    #[McpTool(
        name: 'arduino_board_install',
        description: 'Install a board platform by its identifier (format: vendor:arch, e.g. "esp32:esp32", "arduino:avr", "arduino:samd"). Required before compiling for that board type.'
    )]
    public function install(string $platform): string
    {
        if (empty(trim($platform))) {
            return json_encode(['success' => false, 'error' => 'Platform identifier is required (e.g. "esp32:esp32").']);
        }

        try {
            $result = $this->boardService->install($platform);

            return json_encode([
                'success'  => $result['success'],
                'platform' => $platform,
                'output'   => $result['output'] ?? '',
                'message'  => $result['success']
                    ? "Platform '{$platform}' installed successfully."
                    : "Failed to install platform '{$platform}'.",
            ]);
        } catch (\Throwable $e) {
            return json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Uninstall a board platform.
     *
     * @param  string  $platform  Platform identifier (e.g. "esp32:esp32").
     * @return string  JSON with uninstallation result.
     */
    #[McpTool(
        name: 'arduino_board_uninstall',
        description: 'Uninstall (remove) an installed board platform by its identifier (e.g. "esp32:esp32", "arduino:avr").'
    )]
    public function uninstall(string $platform): string
    {
        if (empty(trim($platform))) {
            return json_encode(['success' => false, 'error' => 'Platform identifier is required.']);
        }

        try {
            $result = $this->boardService->uninstall($platform);

            return json_encode([
                'success'  => $result['success'],
                'platform' => $platform,
                'output'   => $result['output'] ?? '',
                'message'  => $result['success']
                    ? "Platform '{$platform}' uninstalled successfully."
                    : "Failed to uninstall platform '{$platform}'.",
            ]);
        } catch (\Throwable $e) {
            return json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Update the Arduino board package index.
     *
     * @return string  JSON confirming the index was updated.
     */
    #[McpTool(
        name: 'arduino_board_update_index',
        description: 'Update the Arduino board package index from all configured URLs. Run this before searching for or installing new board platforms to ensure you have the latest versions.'
    )]
    public function updateIndex(): string
    {
        try {
            $result = $this->boardService->updateIndex();
            return json_encode([
                'success' => true,
                'output'  => $result['output'] ?? '',
                'message' => 'Board index updated successfully.',
            ]);
        } catch (\Throwable $e) {
            return json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Get all known board short-name to FQBN mappings.
     *
     * @return string  JSON map of short-names to fully-qualified board names.
     */
    #[McpTool(
        name: 'arduino_known_boards',
        description: 'List all pre-configured board short-name aliases and their FQBNs. Use these short-names (e.g. "uno", "nano", "esp32") instead of full FQBNs when calling arduino_compile or arduino_verify.'
    )]
    public function knownBoards(): string
    {
        try {
            $boards = $this->boardService->getKnownBoards();
            return json_encode([
                'success' => true,
                'boards'  => $boards,
                'tip'     => 'You can use any of these short-names as the "board" parameter in arduino_compile or arduino_verify.',
            ]);
        } catch (\Throwable $e) {
            return json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }
}
