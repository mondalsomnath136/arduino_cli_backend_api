<?php

namespace App\V1\Services;

use App\Core\Database;
use App\Core\Logger;

/**
 * Compile Service
 * 
 * Handles Arduino code compilation and verification using arduino-cli.
 * Supports realtime log streaming via database-backed log entries.
 */
class CompileService
{
    private array $arduinoConfig;
    private array $appConfig;
    private FileService $fileService;
    private Database $db;

    public function __construct()
    {
        $this->arduinoConfig = require __DIR__ . '/../../../config/arduino.php';
        $this->appConfig     = require __DIR__ . '/../../../config/app.php';
        $this->fileService   = new FileService();
        $this->db            = Database::getInstance();
    }

    /**
     * Create a new compile job
     */
    public function createJob(string $code, string $fqbn, string $action = 'compile'): array
    {
        $jobId = $this->generateJobId();

        // Resolve board FQBN from shortname if applicable
        $resolvedFqbn = $this->resolveBoard($fqbn);

        // Insert job record
        $this->db->insert(
            "INSERT INTO compile_jobs (job_id, fqbn, action, status, code_hash, code_size, created_at) 
             VALUES (?, ?, ?, 'pending', ?, ?, NOW())",
            [$jobId, $resolvedFqbn, $action, md5($code), strlen($code)]
        );

        return [
            'job_id' => $jobId,
            'fqbn'   => $resolvedFqbn,
            'action' => $action,
            'status' => 'pending',
        ];
    }

    /**
     * Execute a compile/verify job
     */
    public function execute(string $jobId, string $code, string $fqbn, string $action = 'compile'): array
    {
        $resolvedFqbn = $this->resolveBoard($fqbn);

        // Update job status to running
        $this->updateJobStatus($jobId, 'running');
        $this->addLog($jobId, 'info', "Starting {$action} for board: {$resolvedFqbn}");

        try {
            // Create temp directory and save sketch
            $sketchDir = $this->fileService->createCompileDirectory($jobId);
            $sketchFile = $this->fileService->saveSketch($sketchDir, $code);
            $this->addLog($jobId, 'info', "Sketch saved: " . basename($sketchFile));

            // Build the command
            $cliPath = escapeshellarg($this->arduinoConfig['cli_path']);
            $globalFlags = $this->arduinoConfig['global_flags'];

            if ($action === 'verify') {
                // Verify = compile without output
                $command = "{$cliPath} compile {$globalFlags} --fqbn {$resolvedFqbn} " . escapeshellarg($sketchDir) . " 2>&1";
            } else {
                // Full compile with binary output
                $outputDir = $this->fileService->createOutputDirectory($jobId);
                $command = "{$cliPath} compile {$globalFlags} --fqbn {$resolvedFqbn} --output-dir " . escapeshellarg($outputDir) . " " . escapeshellarg($sketchDir) . " 2>&1";
            }

            $this->addLog($jobId, 'info', "Executing: arduino-cli compile --fqbn {$resolvedFqbn}");

            // Execute with timeout
            $output = $this->executeWithTimeout($command, $jobId);

            // Check result
            $exitCode = $output['exit_code'];
            $stdout   = $output['output'];

            if ($exitCode === 0) {
                $this->updateJobStatus($jobId, 'success');
                $this->addLog($jobId, 'success', 'Compilation successful!');

                $result = [
                    'job_id'    => $jobId,
                    'status'    => 'success',
                    'fqbn'      => $resolvedFqbn,
                    'output'    => $stdout,
                    'action'    => $action,
                ];

                // If compile (not verify), include binary info
                if ($action === 'compile') {
                    $binaries = $this->fileService->findBinaryFiles($outputDir ?? '');
                    $result['binaries'] = array_map(function ($b) use ($jobId) {
                        return [
                            'filename'  => $b['filename'],
                            'size'      => $b['size'],
                            'extension' => $b['extension'],
                            'download_url' => "/api/v1/compile/{$jobId}/download?file=" . urlencode($b['filename']),
                        ];
                    }, $binaries);
                }

                // Update database with results
                $this->db->query(
                    "UPDATE compile_jobs SET output_log = ?, completed_at = NOW() WHERE job_id = ?",
                    [$stdout, $jobId]
                );

                return $result;

            } else {
                $this->updateJobStatus($jobId, 'failed');
                $this->addLog($jobId, 'error', 'Compilation failed.');

                // Store error output
                $this->db->query(
                    "UPDATE compile_jobs SET output_log = ?, error_log = ?, completed_at = NOW() WHERE job_id = ?",
                    [$stdout, $stdout, $jobId]
                );

                return [
                    'job_id'    => $jobId,
                    'status'    => 'failed',
                    'fqbn'      => $resolvedFqbn,
                    'output'    => $stdout,
                    'action'    => $action,
                    'exit_code' => $exitCode,
                ];
            }

        } catch (\Throwable $e) {
            $this->updateJobStatus($jobId, 'error');
            $this->addLog($jobId, 'error', 'Exception: ' . $e->getMessage());
            Logger::error('Compile exception', ['job_id' => $jobId, 'error' => $e->getMessage()]);

            return [
                'job_id' => $jobId,
                'status' => 'error',
                'output' => $e->getMessage(),
                'action' => $action,
            ];

        } finally {
            // Cleanup temp sketch directory (keep output)
            if (isset($sketchDir)) {
                $this->fileService->cleanup($sketchDir);
            }
        }

        return [
            'job_id' => $jobId,
            'status' => 'error',
            'output' => 'Unexpected execution path',
            'action' => $action,
        ];
    }

    /**
     * Execute command with timeout and realtime log capture
     */
    private function executeWithTimeout(string $command, string $jobId): array
    {
        $timeout = $this->appConfig['compile']['timeout'];
        $output = '';
        $exitCode = -1;

        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $process = proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start compile process.');
        }

        // Close stdin
        fclose($pipes[0]);

        // Set non-blocking mode on stdout and stderr
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $startTime = time();

        while (true) {
            $status = proc_get_status($process);

            // Read available output
            $stdoutChunk = stream_get_contents($pipes[1]);
            $stderrChunk = stream_get_contents($pipes[2]);

            if ($stdoutChunk) {
                $output .= $stdoutChunk;
                // Log each line for realtime streaming
                $lines = explode("\n", trim($stdoutChunk));
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (!empty($line)) {
                        $this->addLog($jobId, 'stdout', $line);
                    }
                }
            }

            if ($stderrChunk) {
                $output .= $stderrChunk;
                $lines = explode("\n", trim($stderrChunk));
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (!empty($line)) {
                        $this->addLog($jobId, 'stderr', $line);
                    }
                }
            }

            // Process has exited
            if (!$status['running']) {
                $exitCode = $status['exitcode'];
                break;
            }

            // Timeout check
            if ((time() - $startTime) > $timeout) {
                proc_terminate($process, 9);
                $this->addLog($jobId, 'error', "Compilation timed out after {$timeout} seconds.");
                $exitCode = -1;
                break;
            }

            usleep(100000); // 100ms polling interval
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return [
            'output'    => $output,
            'exit_code' => $exitCode,
        ];
    }

    /**
     * Get job status
     */
    public function getJobStatus(string $jobId): ?array
    {
        return $this->db->fetch(
            "SELECT job_id, fqbn, action, status, output_log, error_log, code_size, 
                    created_at, completed_at 
             FROM compile_jobs WHERE job_id = ?",
            [$jobId]
        );
    }

    /**
     * Get realtime logs for a job (for SSE streaming)
     */
    public function getLogs(string $jobId, int $afterId = 0): array
    {
        return $this->db->fetchAll(
            "SELECT id, level, message, created_at FROM compile_logs 
             WHERE job_id = ? AND id > ? ORDER BY id ASC",
            [$jobId, $afterId]
        );
    }

    /**
     * Add a log entry
     */
    private function addLog(string $jobId, string $level, string $message): void
    {
        try {
            $this->db->insert(
                "INSERT INTO compile_logs (job_id, level, message, created_at) VALUES (?, ?, ?, NOW())",
                [$jobId, $level, $message]
            );
        } catch (\Exception $e) {
            // Don't let logging failures break compilation
            error_log("Failed to add compile log: " . $e->getMessage());
        }
    }

    /**
     * Update job status
     */
    private function updateJobStatus(string $jobId, string $status): void
    {
        $this->db->query(
            "UPDATE compile_jobs SET status = ? WHERE job_id = ?",
            [$status, $jobId]
        );
    }

    /**
     * Resolve board FQBN from shortname
     */
    private function resolveBoard(string $fqbn): string
    {
        $knownBoards = $this->arduinoConfig['known_boards'];
        $lower = strtolower($fqbn);

        if (isset($knownBoards[$lower])) {
            return $knownBoards[$lower];
        }

        // If it contains ':', treat it as an actual FQBN
        if (strpos($fqbn, ':') !== false) {
            return $fqbn;
        }

        // Default board
        return $this->arduinoConfig['default_fqbn'];
    }

    /**
     * Generate a unique job ID
     */
    private function generateJobId(): string
    {
        return bin2hex(random_bytes(12));
    }

    /**
     * Cleanup old jobs and files
     */
    public function cleanupOldJobs(): array
    {
        $cleanupAfter = $this->appConfig['compile']['cleanup_after'];
        $keepDays     = $this->appConfig['compile']['output_keep_days'];

        // Remove old temp files
        $tempCleaned = $this->fileService->cleanupOldFiles($cleanupAfter);

        // Remove old database entries
        $this->db->query(
            "DELETE FROM compile_logs WHERE job_id IN 
             (SELECT job_id FROM compile_jobs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY))",
            [$keepDays]
        );
        $this->db->query(
            "DELETE FROM compile_jobs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$keepDays]
        );

        return [
            'temp_dirs_cleaned' => $tempCleaned,
            'message' => "Cleaned up files older than {$cleanupAfter} seconds and DB records older than {$keepDays} days.",
        ];
    }
}
