<?php

namespace App\V1\Services;

use App\Core\Database;
use App\Core\Logger;

/**
 * Library Service
 * 
 * Manages Arduino libraries via arduino-cli.
 */
class LibraryService
{
    private array $arduinoConfig;
    private Database $db;

    public function __construct()
    {
        $this->arduinoConfig = require __DIR__ . '/../../../config/arduino.php';
        $this->db = Database::getInstance();
    }

    /**
     * List all installed libraries
     */
    public function listInstalled(): array
    {
        $cliPath = $this->arduinoConfig['cli_path'];
        $globalFlags = $this->arduinoConfig['global_flags'];

        $command = escapeshellarg($cliPath) . " lib list {$globalFlags} --format json 2>&1";
        $output = shell_exec($command);

        $result = json_decode($output, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Logger::error('Failed to parse library list', ['output' => $output]);
            return ['raw_output' => $output, 'libraries' => []];
        }

        // Sync to database
        $this->syncInstalledLibraries($result);

        return [
            'libraries' => $result,
            'count'     => is_array($result) ? count($result) : 0,
        ];
    }

    /**
     * Search for libraries
     */
    public function search(string $query): array
    {
        $cliPath = $this->arduinoConfig['cli_path'];
        $globalFlags = $this->arduinoConfig['global_flags'];

        $command = escapeshellarg($cliPath) . " lib search " . escapeshellarg($query) . " {$globalFlags} --format json 2>&1";
        $output = shell_exec($command);

        $result = json_decode($output, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['raw_output' => $output, 'libraries' => []];
        }

        return [
            'libraries' => $result['libraries'] ?? $result,
            'query'     => $query,
        ];
    }

    /**
     * Install a library
     */
    public function install(string $libraryName, ?string $version = null): array
    {
        $cliPath = $this->arduinoConfig['cli_path'];
        $globalFlags = $this->arduinoConfig['global_flags'];

        $libSpec = $libraryName;
        if ($version) {
            $libSpec .= '@' . $version;
        }

        $command = escapeshellarg($cliPath) . " lib install " . escapeshellarg($libSpec) . " {$globalFlags} 2>&1";

        Logger::info('Installing library', ['library' => $libSpec]);

        $output = shell_exec($command);
        $success = strpos($output, 'Error') === false && strpos($output, 'error') === false;

        if ($success) {
            // Record in database
            $this->recordInstall($libraryName, $version);
            Logger::info('Library installed successfully', ['library' => $libSpec]);
        } else {
            Logger::error('Library install failed', ['library' => $libSpec, 'output' => $output]);
        }

        return [
            'library' => $libraryName,
            'version' => $version,
            'success' => $success,
            'output'  => trim($output),
        ];
    }

    /**
     * Uninstall a library
     */
    public function uninstall(string $libraryName): array
    {
        $cliPath = $this->arduinoConfig['cli_path'];
        $globalFlags = $this->arduinoConfig['global_flags'];

        $command = escapeshellarg($cliPath) . " lib uninstall " . escapeshellarg($libraryName) . " {$globalFlags} 2>&1";

        Logger::info('Uninstalling library', ['library' => $libraryName]);

        $output = shell_exec($command);
        $success = strpos($output, 'Error') === false && strpos($output, 'error') === false;

        if ($success) {
            // Remove from database
            $this->db->query(
                "DELETE FROM installed_libraries WHERE name = ?",
                [$libraryName]
            );
            Logger::info('Library uninstalled successfully', ['library' => $libraryName]);
        } else {
            Logger::error('Library uninstall failed', ['library' => $libraryName, 'output' => $output]);
        }

        return [
            'library' => $libraryName,
            'success' => $success,
            'output'  => trim($output),
        ];
    }

    /**
     * Record an installed library in the database
     */
    private function recordInstall(string $name, ?string $version): void
    {
        try {
            // Upsert: update if exists, insert if not
            $existing = $this->db->fetch(
                "SELECT id FROM installed_libraries WHERE name = ?",
                [$name]
            );

            if ($existing) {
                $this->db->query(
                    "UPDATE installed_libraries SET version = ?, installed_at = NOW() WHERE name = ?",
                    [$version, $name]
                );
            } else {
                $this->db->insert(
                    "INSERT INTO installed_libraries (name, version, installed_at) VALUES (?, ?, NOW())",
                    [$name, $version]
                );
            }
        } catch (\Exception $e) {
            Logger::error('Failed to record library install', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Sync installed libraries from arduino-cli to database
     */
    private function syncInstalledLibraries(array $libraries): void
    {
        try {
            if (!is_array($libraries)) {
                return;
            }

            foreach ($libraries as $lib) {
                $name = $lib['library']['name'] ?? ($lib['name'] ?? null);
                $version = $lib['library']['version'] ?? ($lib['version'] ?? null);

                if ($name) {
                    $this->recordInstall($name, $version);
                }
            }
        } catch (\Exception $e) {
            Logger::error('Failed to sync libraries', ['error' => $e->getMessage()]);
        }
    }
}
