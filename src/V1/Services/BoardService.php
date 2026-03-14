<?php

namespace App\V1\Services;

use App\Core\Database;
use App\Core\Logger;

/**
 * Board Service
 * 
 * Manages Arduino boards and platforms via arduino-cli.
 */
class BoardService
{
    private array $arduinoConfig;
    private Database $db;

    public function __construct()
    {
        $this->arduinoConfig = require __DIR__ . '/../../../config/arduino.php';
        $this->db = Database::getInstance();
    }

    /**
     * List all installed board platforms
     */
    public function listInstalled(): array
    {
        $cliPath = $this->arduinoConfig['cli_path'];
        $globalFlags = $this->arduinoConfig['global_flags'];

        $command = escapeshellarg($cliPath) . " core list {$globalFlags} --format json 2>&1";
        $output = shell_exec($command);

        $result = json_decode($output, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Logger::error('Failed to parse board list', ['output' => $output]);
            return ['raw_output' => $output, 'platforms' => []];
        }

        // Sync to database
        $this->syncInstalledBoards($result);

        return [
            'platforms' => $result,
            'count'     => is_array($result) ? count($result) : 0,
        ];
    }

    /**
     * List all boards available across installed platforms
     */
    public function listAllBoards(): array
    {
        $cliPath = $this->arduinoConfig['cli_path'];
        $globalFlags = $this->arduinoConfig['global_flags'];

        $command = escapeshellarg($cliPath) . " board listall {$globalFlags} --format json 2>&1";
        $output = shell_exec($command);

        $result = json_decode($output, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['raw_output' => $output, 'boards' => []];
        }

        return [
            'boards' => $result['boards'] ?? $result,
        ];
    }

    /**
     * Search for board platforms
     */
    public function search(string $query): array
    {
        $cliPath = $this->arduinoConfig['cli_path'];
        $globalFlags = $this->arduinoConfig['global_flags'];

        $command = escapeshellarg($cliPath) . " core search " . escapeshellarg($query) . " {$globalFlags} --format json 2>&1";
        $output = shell_exec($command);

        $result = json_decode($output, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['raw_output' => $output, 'platforms' => []];
        }

        return [
            'platforms' => $result,
            'query'     => $query,
        ];
    }

    /**
     * Install a board platform
     */
    public function install(string $platform): array
    {
        $cliPath = $this->arduinoConfig['cli_path'];
        $globalFlags = $this->arduinoConfig['global_flags'];

        // Build additional URLs flag
        $urlsFlag = $this->getAdditionalUrlsFlag();

        $command = escapeshellarg($cliPath) . " core install " . escapeshellarg($platform) . " {$globalFlags} {$urlsFlag} 2>&1";

        Logger::info('Installing board platform', ['platform' => $platform]);

        $output = shell_exec($command);
        $success = strpos($output, 'Error') === false && strpos($output, 'error') === false;

        if ($success) {
            $this->recordInstall($platform);
            Logger::info('Board platform installed successfully', ['platform' => $platform]);
        } else {
            Logger::error('Board platform install failed', ['platform' => $platform, 'output' => $output]);
        }

        return [
            'platform' => $platform,
            'success'  => $success,
            'output'   => trim($output),
        ];
    }

    /**
     * Uninstall a board platform
     */
    public function uninstall(string $platform): array
    {
        $cliPath = $this->arduinoConfig['cli_path'];
        $globalFlags = $this->arduinoConfig['global_flags'];

        $command = escapeshellarg($cliPath) . " core uninstall " . escapeshellarg($platform) . " {$globalFlags} 2>&1";

        Logger::info('Uninstalling board platform', ['platform' => $platform]);

        $output = shell_exec($command);
        $success = strpos($output, 'Error') === false && strpos($output, 'error') === false;

        if ($success) {
            $this->db->query(
                "DELETE FROM installed_boards WHERE platform = ?",
                [$platform]
            );
            Logger::info('Board platform uninstalled successfully', ['platform' => $platform]);
        } else {
            Logger::error('Board platform uninstall failed', ['platform' => $platform, 'output' => $output]);
        }

        return [
            'platform' => $platform,
            'success'  => $success,
            'output'   => trim($output),
        ];
    }

    /**
     * Update the board index
     */
    public function updateIndex(): array
    {
        $cliPath = $this->arduinoConfig['cli_path'];
        $globalFlags = $this->arduinoConfig['global_flags'];
        $urlsFlag = $this->getAdditionalUrlsFlag();

        $command = escapeshellarg($cliPath) . " core update-index {$globalFlags} {$urlsFlag} 2>&1";

        $output = shell_exec($command);

        return [
            'success' => true,
            'output'  => trim($output),
        ];
    }

    /**
     * Get known boards mapping for quick FQBN lookup
     */
    public function getKnownBoards(): array
    {
        return $this->arduinoConfig['known_boards'];
    }

    /**
     * Build the --additional-urls flag string
     */
    private function getAdditionalUrlsFlag(): string
    {
        $urls = $this->arduinoConfig['additional_urls'] ?? [];
        if (empty($urls)) {
            return '';
        }
        return '--additional-urls ' . escapeshellarg(implode(',', $urls));
    }

    /**
     * Record an installed board platform in the database
     */
    private function recordInstall(string $platform): void
    {
        try {
            $existing = $this->db->fetch(
                "SELECT id FROM installed_boards WHERE platform = ?",
                [$platform]
            );

            if ($existing) {
                $this->db->query(
                    "UPDATE installed_boards SET installed_at = NOW() WHERE platform = ?",
                    [$platform]
                );
            } else {
                $this->db->insert(
                    "INSERT INTO installed_boards (platform, installed_at) VALUES (?, NOW())",
                    [$platform]
                );
            }
        } catch (\Exception $e) {
            Logger::error('Failed to record board install', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Sync installed boards from arduino-cli to database
     */
    private function syncInstalledBoards(array $platforms): void
    {
        try {
            if (!is_array($platforms)) {
                return;
            }

            foreach ($platforms as $platform) {
                $id = $platform['id'] ?? ($platform['ID'] ?? null);
                if ($id) {
                    $this->recordInstall($id);
                }
            }
        } catch (\Exception $e) {
            Logger::error('Failed to sync boards', ['error' => $e->getMessage()]);
        }
    }
}
