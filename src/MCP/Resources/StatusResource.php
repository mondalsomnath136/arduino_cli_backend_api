<?php

namespace App\MCP\Resources;

use Mcp\Capability\Attribute\McpResource;

/**
 * MCP Status Resource
 *
 * Exposes API health, configuration, and capability info as MCP resources
 * so AI agents can understand the server's capabilities before using tools.
 */
class StatusResource
{
    private array $appConfig;
    private array $arduinoConfig;

    public function __construct()
    {
        $this->appConfig     = require __DIR__ . '/../../../config/app.php';
        $this->arduinoConfig = require __DIR__ . '/../../../config/arduino.php';
    }

    /**
     * Read the Arduino CLI backend API health status.
     *
     * @return array  Health status and arduino-cli binary availability.
     */
    #[McpResource(
        uri: 'arduino://status',
        name: 'API Health Status',
        description: 'Reports the health status of the Arduino CLI backend server. Includes API version, arduino-cli binary availability, PHP version, and server timestamp.',
        mimeType: 'application/json'
    )]
    public function status(): array
    {
        $cliPath     = $this->arduinoConfig['cli_path'];
        $cliAvailable = false;
        $cliVersion   = null;

        // Check if arduino-cli is accessible
        $testOutput = [];
        $exitCode   = -1;
        @exec(escapeshellarg($cliPath) . ' version 2>&1', $testOutput, $exitCode);

        if ($exitCode === 0 && !empty($testOutput)) {
            $cliAvailable = true;
            $cliVersion   = implode(' ', $testOutput);
        }

        return [
            'status'          => 'ok',
            'api_name'        => $this->appConfig['name'],
            'api_version'     => $this->appConfig['version'],
            'php_version'     => PHP_VERSION,
            'mcp_server'      => 'arduino-cli-mcp',
            'mcp_version'     => '1.0.0',
            'arduino_cli'     => [
                'available'   => $cliAvailable,
                'path'        => $cliPath,
                'version'     => $cliVersion,
            ],
            'default_board'   => $this->arduinoConfig['default_fqbn'],
            'compile_timeout' => $this->appConfig['compile']['timeout'] . 's',
            'max_code_size'   => $this->appConfig['compile']['max_code_size'],
            'server_time'     => date('c'),
            'timezone'        => $this->appConfig['timezone'],
        ];
    }

    /**
     * Read a full capability map of this MCP server.
     *
     * @return array  All available MCP tools and resources with descriptions.
     */
    #[McpResource(
        uri: 'arduino://config',
        name: 'Arduino MCP Server Configuration',
        description: 'Complete capability map of this MCP server — all available tools, resources, and prompts with descriptions. Use this as a reference to understand what operations are available.',
        mimeType: 'application/json'
    )]
    public function config(): array
    {
        return [
            'server'    => 'Arduino CLI Backend MCP Server',
            'version'   => '1.0.0',
            'tools'     => [
                'compile'  => [
                    'arduino_compile'        => 'Compile Arduino sketch and get binary output',
                    'arduino_verify'         => 'Verify/syntax-check Arduino code without binary',
                    'arduino_compile_status' => 'Get job status and logs by job_id',
                    'arduino_download_binary' => 'List compiled binary files for a job',
                ],
                'boards' => [
                    'arduino_board_list'         => 'List installed board platforms',
                    'arduino_board_search'        => 'Search Arduino board index',
                    'arduino_board_install'       => 'Install a board platform',
                    'arduino_board_uninstall'     => 'Uninstall a board platform',
                    'arduino_board_update_index'  => 'Update the board package index',
                    'arduino_known_boards'        => 'List known board short-name aliases',
                ],
                'libraries' => [
                    'arduino_library_list'      => 'List installed libraries',
                    'arduino_library_search'    => 'Search Arduino Library Manager',
                    'arduino_library_install'   => 'Install a library',
                    'arduino_library_uninstall' => 'Uninstall a library',
                ],
            ],
            'resources' => [
                'arduino://status'             => 'API health and arduino-cli status',
                'arduino://config'             => 'This configuration document',
                'arduino://boards/installed'   => 'Installed board platforms',
                'arduino://boards/known'       => 'Known board FQBN mappings',
                'arduino://libraries/installed' => 'Installed libraries',
            ],
            'prompts'   => [
                'compile_sketch'    => 'Guided prompt to compile an Arduino sketch',
                'troubleshoot_error' => 'Help troubleshoot a compilation error',
                'board_setup'       => 'Guide through setting up a new board type',
            ],
            'transport' => [
                'stdio' => 'php mcp/server.php (for Claude Desktop / Roo Code)',
                'http'  => 'http://localhost/arduino_cli_backend_api/public/mcp.php',
            ],
            'default_board' => $this->arduinoConfig['default_fqbn'],
            'known_boards'  => array_keys($this->arduinoConfig['known_boards']),
        ];
    }
}
