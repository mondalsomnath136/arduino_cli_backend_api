<?php

namespace App\V1\Services;

/**
 * File Service
 * 
 * Handles temporary file and directory management for compilations.
 */
class FileService
{
    private string $tempDir;
    private string $outputDir;

    public function __construct()
    {
        $config = require __DIR__ . '/../../../config/app.php';
        $this->tempDir   = $config['storage']['temp'];
        $this->outputDir = $config['storage']['outputs'];

        $this->ensureDirectories();
    }

    /**
     * Ensure storage directories exist
     */
    private function ensureDirectories(): void
    {
        $dirs = [$this->tempDir, $this->outputDir];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }

    /**
     * Create a unique compile directory
     * Arduino CLI requires the .ino file to be inside a directory with the same name.
     */
    public function createCompileDirectory(string $jobId): string
    {
        $sketchName = 'sketch_' . $jobId;
        $dirPath = $this->tempDir . '/' . $sketchName;

        if (!is_dir($dirPath)) {
            mkdir($dirPath, 0755, true);
        }

        return $dirPath;
    }

    /**
     * Save the Arduino sketch code to a .ino file
     * The filename must match the directory name.
     */
    public function saveSketch(string $dirPath, string $code): string
    {
        $dirName  = basename($dirPath);
        $filePath = $dirPath . '/' . $dirName . '.ino';

        file_put_contents($filePath, $code);

        return $filePath;
    }

    /**
     * Create the output directory for a compile job
     */
    public function createOutputDirectory(string $jobId): string
    {
        $outputPath = $this->outputDir . '/' . $jobId;

        if (!is_dir($outputPath)) {
            mkdir($outputPath, 0755, true);
        }

        return $outputPath;
    }

    /**
     * Find compiled binary files (hex, bin, elf) in the output directory
     */
    public function findBinaryFiles(string $outputDir): array
    {
        $binaries = [];
        $extensions = ['hex', 'bin', 'elf'];

        if (!is_dir($outputDir)) {
            return $binaries;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($outputDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            $ext = strtolower($file->getExtension());
            if (in_array($ext, $extensions)) {
                $binaries[] = [
                    'filename'  => $file->getFilename(),
                    'path'      => $file->getPathname(),
                    'size'      => $file->getSize(),
                    'extension' => $ext,
                ];
            }
        }

        return $binaries;
    }

    /**
     * Clean up a compile directory
     */
    public function cleanup(string $dirPath): void
    {
        if (!is_dir($dirPath)) {
            return;
        }

        $this->removeDirectory($dirPath);
    }

    /**
     * Remove a directory and all its contents recursively
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }

    /**
     * Cleanup old temporary files beyond the configured age
     */
    public function cleanupOldFiles(int $maxAgeSeconds = 3600): int
    {
        $cleaned = 0;
        $now = time();

        if (!is_dir($this->tempDir)) {
            return $cleaned;
        }

        $dirs = scandir($this->tempDir);
        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..') {
                continue;
            }

            $dirPath = $this->tempDir . '/' . $dir;
            if (is_dir($dirPath) && ($now - filemtime($dirPath)) > $maxAgeSeconds) {
                $this->removeDirectory($dirPath);
                $cleaned++;
            }
        }

        return $cleaned;
    }

    /**
     * Get the full path for a binary file
     */
    public function getBinaryPath(string $jobId, string $filename): ?string
    {
        $outputDir = $this->outputDir . '/' . $jobId;
        $binaries = $this->findBinaryFiles($outputDir);

        foreach ($binaries as $binary) {
            if ($binary['filename'] === $filename) {
                return $binary['path'];
            }
        }

        return null;
    }
}
