<?php

namespace App\MCP\Tools;

use App\V1\Services\LibraryService;
use Mcp\Capability\Attribute\McpTool;

/**
 * MCP Library Tools
 *
 * Exposes Arduino library management capabilities to AI agents via MCP.
 * Wraps the existing LibraryService without duplication.
 */
class LibraryTools
{
    private LibraryService $libraryService;

    public function __construct()
    {
        $this->libraryService = new LibraryService();
    }

    /**
     * List all installed Arduino libraries.
     *
     * @return string  JSON with all installed libraries including name, version, and location.
     */
    #[McpTool(
        name: 'arduino_library_list',
        description: 'List all Arduino libraries currently installed on this server. Returns library names, versions, authors, and installation paths.'
    )]
    public function list(): string
    {
        try {
            $result = $this->libraryService->listInstalled();
            return json_encode([
                'success'   => true,
                'libraries' => $result,
                'count'     => is_array($result) ? count($result) : 0,
            ]);
        } catch (\Throwable $e) {
            return json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Search the Arduino Library Manager for available libraries.
     *
     * @param  string  $query  Library name or keyword (e.g. "Servo", "DHT", "MQTT", "WiFi").
     * @return string  JSON with matching libraries, their versions and descriptions.
     */
    #[McpTool(
        name: 'arduino_library_search',
        description: 'Search the Arduino Library Manager index for available libraries. Returns matching libraries with names, versions, authors, and descriptions. Example: "Servo", "DHT sensor", "FastLED", "MQTT".'
    )]
    public function search(string $query): string
    {
        if (empty(trim($query))) {
            return json_encode(['success' => false, 'error' => 'Search query is required.']);
        }

        try {
            $result = $this->libraryService->search($query);
            return json_encode([
                'success'   => true,
                'query'     => $query,
                'libraries' => $result,
                'tip'       => 'Use arduino_library_install with the exact library name to install.',
            ]);
        } catch (\Throwable $e) {
            return json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Install an Arduino library by name.
     *
     * @param  string       $name     Library name (exact, as shown in Library Manager, e.g. "Servo", "DHT sensor library").
     * @param  string|null  $version  Optional specific version (e.g. "1.2.0"). Omit for latest.
     * @return string  JSON with installation result.
     */
    #[McpTool(
        name: 'arduino_library_install',
        description: 'Install an Arduino library by its exact Library Manager name. Optionally specify a version; omit for latest. Example names: "Servo", "DHT sensor library", "FastLED", "PubSubClient".'
    )]
    public function install(string $name, string $version = ''): string
    {
        if (empty(trim($name))) {
            return json_encode(['success' => false, 'error' => 'Library name is required.']);
        }

        try {
            $ver    = empty(trim($version)) ? null : trim($version);
            $result = $this->libraryService->install($name, $ver);

            return json_encode([
                'success' => $result['success'],
                'library' => $name,
                'version' => $ver ?? 'latest',
                'output'  => $result['output'] ?? '',
                'message' => $result['success']
                    ? "Library '{$name}' installed successfully."
                    : "Failed to install library '{$name}'.",
            ]);
        } catch (\Throwable $e) {
            return json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Uninstall an installed Arduino library.
     *
     * @param  string  $name  The exact library name to remove.
     * @return string  JSON with uninstallation result.
     */
    #[McpTool(
        name: 'arduino_library_uninstall',
        description: 'Uninstall (remove) an installed Arduino library by its exact name. Use arduino_library_list first to see installed library names.'
    )]
    public function uninstall(string $name): string
    {
        if (empty(trim($name))) {
            return json_encode(['success' => false, 'error' => 'Library name is required.']);
        }

        try {
            $result = $this->libraryService->uninstall($name);

            return json_encode([
                'success' => $result['success'],
                'library' => $name,
                'output'  => $result['output'] ?? '',
                'message' => $result['success']
                    ? "Library '{$name}' uninstalled successfully."
                    : "Failed to uninstall library '{$name}'.",
            ]);
        } catch (\Throwable $e) {
            return json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }
}
