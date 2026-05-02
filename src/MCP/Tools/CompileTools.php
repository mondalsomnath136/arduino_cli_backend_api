<?php

namespace App\MCP\Tools;

use App\V1\Services\CompileService;
use App\V1\Services\FileService;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Types\TextContent;

/**
 * MCP Compile Tools
 *
 * Exposes Arduino compile/verify capabilities to AI agents via MCP.
 * Wraps the existing CompileService without duplication.
 */
class CompileTools
{
    private CompileService $compileService;
    private FileService $fileService;
    private array $appConfig;

    public function __construct()
    {
        $this->compileService = new CompileService();
        $this->fileService    = new FileService();
        $this->appConfig      = require __DIR__ . '/../../../config/app.php';
    }

    /**
     * Compile Arduino code and produce a binary output.
     *
     * @param  string  $code   The Arduino sketch source code (C/C++).
     * @param  string  $board  Board short-name (e.g. "uno") or full FQBN (e.g. "arduino:avr:uno").
     * @return string  JSON-encoded compilation result with job_id, status, and binary download URLs.
     */
    #[McpTool(
        name: 'arduino_compile',
        description: 'Compile Arduino sketch code for a specified board. Returns job_id, compilation status, compiler output, and download URLs for the binary (.hex/.bin) files.'
    )]
    public function compile(string $code, string $board = 'uno'): string
    {
        if (empty(trim($code))) {
            return json_encode([
                'success' => false,
                'error'   => 'Arduino code is required.',
            ]);
        }

        if (strlen($code) > $this->appConfig['compile']['max_code_size']) {
            return json_encode([
                'success' => false,
                'error'   => 'Code exceeds maximum size of ' . $this->appConfig['compile']['max_code_size'] . ' bytes.',
            ]);
        }

        try {
            $job    = $this->compileService->createJob($code, $board, 'compile');
            $result = $this->compileService->execute($job['job_id'], $code, $board, 'compile');

            return json_encode([
                'success'  => $result['status'] === 'success',
                'job_id'   => $result['job_id'],
                'status'   => $result['status'],
                'board'    => $result['fqbn'] ?? $board,
                'output'   => $result['output'] ?? '',
                'binaries' => $result['binaries'] ?? [],
                'tip'      => $result['status'] === 'success'
                    ? 'Use arduino_download_binary tool with job_id to list available binary files.'
                    : 'Use arduino_compile_status to see detailed logs.',
            ]);
        } catch (\Throwable $e) {
            return json_encode([
                'success' => false,
                'error'   => 'Compilation failed: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Verify Arduino code syntax without producing binary output.
     *
     * @param  string  $code   The Arduino sketch source code.
     * @param  string  $board  Board short-name or FQBN.
     * @return string  JSON-encoded verification result with job_id and compiler messages.
     */
    #[McpTool(
        name: 'arduino_verify',
        description: 'Verify (syntax-check) Arduino sketch code for a board WITHOUT producing binary output. Faster than compile — use to check code correctness before compiling.'
    )]
    public function verify(string $code, string $board = 'uno'): string
    {
        if (empty(trim($code))) {
            return json_encode([
                'success' => false,
                'error'   => 'Arduino code is required.',
            ]);
        }

        try {
            $job    = $this->compileService->createJob($code, $board, 'verify');
            $result = $this->compileService->execute($job['job_id'], $code, $board, 'verify');

            return json_encode([
                'success' => $result['status'] === 'success',
                'job_id'  => $result['job_id'],
                'status'  => $result['status'],
                'board'   => $result['fqbn'] ?? $board,
                'output'  => $result['output'] ?? '',
                'message' => $result['status'] === 'success'
                    ? 'Code is valid — no syntax errors found.'
                    : 'Code has errors. Check the output field for details.',
            ]);
        } catch (\Throwable $e) {
            return json_encode([
                'success' => false,
                'error'   => 'Verification failed: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Get the status and logs of a compile/verify job.
     *
     * @param  string  $job_id  The job ID returned by arduino_compile or arduino_verify.
     * @return string  JSON with job status (pending/running/success/failed/error) and log entries.
     */
    #[McpTool(
        name: 'arduino_compile_status',
        description: 'Get the current status and full log output of a compile/verify job using its job_id. Returns status (pending/running/success/failed/error) and all log entries.'
    )]
    public function status(string $job_id): string
    {
        if (empty($job_id)) {
            return json_encode(['success' => false, 'error' => 'job_id is required.']);
        }

        try {
            $job  = $this->compileService->getJobStatus($job_id);
            $logs = $this->compileService->getLogs($job_id);

            if (!$job) {
                return json_encode(['success' => false, 'error' => "Job '{$job_id}' not found."]);
            }

            return json_encode([
                'success' => true,
                'job'     => $job,
                'logs'    => $logs,
            ]);
        } catch (\Throwable $e) {
            return json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * List compiled binary files available for download for a job.
     *
     * @param  string  $job_id  The successful compile job ID.
     * @return string  JSON with list of binary files and their download URLs.
     */
    #[McpTool(
        name: 'arduino_download_binary',
        description: 'List compiled binary files (.hex, .bin, .elf) available for a successful compile job. Returns filenames, sizes, and download URLs. The job must have status=success.'
    )]
    public function downloadBinary(string $job_id): string
    {
        if (empty($job_id)) {
            return json_encode(['success' => false, 'error' => 'job_id is required.']);
        }

        try {
            $job = $this->compileService->getJobStatus($job_id);

            if (!$job) {
                return json_encode(['success' => false, 'error' => "Job '{$job_id}' not found."]);
            }

            if ($job['status'] !== 'success') {
                return json_encode([
                    'success' => false,
                    'error'   => "Job status is '{$job['status']}', not 'success'. Binary files are only available for successful compilations.",
                    'job'     => $job,
                ]);
            }

            $outputDir = $this->appConfig['storage']['outputs'] . '/' . $job_id;
            $binaries  = $this->fileService->findBinaryFiles($outputDir);

            return json_encode([
                'success'  => true,
                'job_id'   => $job_id,
                'binaries' => array_map(fn($b) => [
                    'filename'     => $b['filename'],
                    'size_bytes'   => $b['size'],
                    'extension'    => $b['extension'],
                    'download_url' => "/api/v1/compile/{$job_id}/download?file=" . urlencode($b['filename']),
                ], $binaries),
            ]);
        } catch (\Throwable $e) {
            return json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }
}
