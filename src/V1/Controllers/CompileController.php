<?php

namespace App\V1\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Logger;
use App\V1\Services\CompileService;
use App\V1\Services\FileService;

/**
 * Compile Controller
 * 
 * Handles compilation and verification endpoints.
 */
class CompileController
{
    private CompileService $compileService;
    private FileService $fileService;

    public function __construct()
    {
        $this->compileService = new CompileService();
        $this->fileService    = new FileService();
    }

    /**
     * POST /api/v1/compile
     * 
     * Compile Arduino code and return binary/logs.
     * 
     * Request Body:
     *   {
     *     "code": "void setup() {} void loop() {}",
     *     "board": "uno" | "arduino:avr:uno",
     *     "options": { ... }   // optional
     *   }
     */
    public function compile(Request $request): void
    {
        // Validate input
        $code  = $request->getBody('code');
        $board = $request->getBody('board');

        $errors = $this->validateCompileRequest($code, $board);
        if (!empty($errors)) {
            Response::validationError($errors);
            return;
        }

        $appConfig = require __DIR__ . '/../../../config/app.php';
        if (strlen($code) > $appConfig['compile']['max_code_size']) {
            Response::validationError(['code' => 'Code exceeds maximum size of ' . $appConfig['compile']['max_code_size'] . ' bytes.']);
            return;
        }

        try {
            // Create compile job
            $job = $this->compileService->createJob($code, $board, 'compile');

            Logger::info('Compile job created', ['job_id' => $job['job_id'], 'board' => $board]);

            // Execute compilation
            $result = $this->compileService->execute($job['job_id'], $code, $board, 'compile');

            if ($result['status'] === 'success') {
                Response::success($result, 'Compilation successful');
            } else {
                Response::json([
                    'success' => false,
                    'message' => 'Compilation failed',
                    'data'    => $result,
                ], 422);
            }

        } catch (\Exception $e) {
            Logger::error('Compile endpoint error', ['error' => $e->getMessage()]);
            Response::serverError('An error occurred during compilation.');
        }
    }

    /**
     * POST /api/v1/verify
     * 
     * Verify (compile without output) Arduino code.
     * 
     * Request Body:
     *   {
     *     "code": "void setup() {} void loop() {}",
     *     "board": "uno" | "arduino:avr:uno"
     *   }
     */
    public function verify(Request $request): void
    {
        $code  = $request->getBody('code');
        $board = $request->getBody('board');

        $errors = $this->validateCompileRequest($code, $board);
        if (!empty($errors)) {
            Response::validationError($errors);
            return;
        }

        try {
            $job = $this->compileService->createJob($code, $board, 'verify');

            Logger::info('Verify job created', ['job_id' => $job['job_id'], 'board' => $board]);

            $result = $this->compileService->execute($job['job_id'], $code, $board, 'verify');

            if ($result['status'] === 'success') {
                Response::success($result, 'Verification successful - code is valid');
            } else {
                Response::json([
                    'success' => false,
                    'message' => 'Verification failed - code has errors',
                    'data'    => $result,
                ], 422);
            }

        } catch (\Exception $e) {
            Logger::error('Verify endpoint error', ['error' => $e->getMessage()]);
            Response::serverError('An error occurred during verification.');
        }
    }

    /**
     * GET /api/v1/compile/{id}/status
     * 
     * Get compile job status. Supports SSE for realtime log streaming.
     * 
     * Query params:
     *   stream=1  - Enable SSE streaming
     */
    public function status(Request $request): void
    {
        $jobId = $request->getParam('id');

        if (empty($jobId)) {
            Response::validationError(['id' => 'Job ID is required.']);
            return;
        }

        $job = $this->compileService->getJobStatus($jobId);

        if (!$job) {
            Response::notFound('Compile job not found.');
            return;
        }

        // Check if SSE streaming is requested
        if ($request->getQuery('stream') === '1') {
            $this->streamLogs($jobId);
            return;
        }

        // Regular JSON response with current logs
        $logs = $this->compileService->getLogs($jobId);

        Response::success([
            'job'  => $job,
            'logs' => $logs,
        ]);
    }

    /**
     * Stream compile logs via Server-Sent Events
     */
    private function streamLogs(string $jobId): void
    {
        Response::sseHeaders();

        $lastLogId = 0;
        $maxWait   = 120; // seconds
        $startTime = time();

        Response::sseEvent('connected', ['job_id' => $jobId, 'message' => 'Connected to log stream']);

        while ((time() - $startTime) < $maxWait) {
            // Fetch new logs since last ID
            $logs = $this->compileService->getLogs($jobId, $lastLogId);

            foreach ($logs as $log) {
                Response::sseEvent('log', [
                    'id'      => $log['id'],
                    'level'   => $log['level'],
                    'message' => $log['message'],
                    'time'    => $log['created_at'],
                ], (string)$log['id']);

                $lastLogId = (int)$log['id'];
            }

            // Check if job is completed
            $job = $this->compileService->getJobStatus($jobId);
            if ($job && in_array($job['status'], ['success', 'failed', 'error'])) {
                Response::sseEvent('complete', [
                    'status'  => $job['status'],
                    'message' => 'Compilation ' . $job['status'],
                ]);
                break;
            }

            // Polling interval
            usleep(500000); // 500ms

            if (connection_aborted()) {
                break;
            }
        }

        Response::sseEvent('done', ['message' => 'Stream ended']);
    }

    /**
     * GET /api/v1/compile/{id}/download
     * 
     * Download compiled binary file.
     * 
     * Query params:
     *   file=filename.hex  - The binary file to download
     */
    public function download(Request $request): void
    {
        $jobId   = $request->getParam('id');
        $filename = $request->getQuery('file');

        if (empty($jobId)) {
            Response::validationError(['id' => 'Job ID is required.']);
            return;
        }

        if (empty($filename)) {
            // List available files
            $job = $this->compileService->getJobStatus($jobId);
            if (!$job || $job['status'] !== 'success') {
                Response::notFound('No compiled files available.');
                return;
            }

            $appConfig = require __DIR__ . '/../../../config/app.php';
            $outputDir = $appConfig['storage']['outputs'] . '/' . $jobId;
            $binaries = $this->fileService->findBinaryFiles($outputDir);

            Response::success([
                'files' => array_map(function ($b) use ($jobId) {
                    return [
                        'filename'     => $b['filename'],
                        'size'         => $b['size'],
                        'extension'    => $b['extension'],
                        'download_url' => "/api/v1/compile/{$jobId}/download?file=" . urlencode($b['filename']),
                    ];
                }, $binaries),
            ], 'Available binary files');
            return;
        }

        // Security: sanitize filename
        $filename = basename($filename);

        $filePath = $this->fileService->getBinaryPath($jobId, $filename);

        if (!$filePath) {
            Response::notFound('Binary file not found.');
            return;
        }

        Response::download($filePath, $filename);
    }

    /**
     * Validate compile/verify request
     */
    private function validateCompileRequest(?string $code, ?string $board): array
    {
        $errors = [];

        if (empty($code)) {
            $errors['code'] = 'Arduino code is required.';
        }

        if (empty($board)) {
            $errors['board'] = 'Board identifier (FQBN or shortname) is required.';
        }

        return $errors;
    }
}
