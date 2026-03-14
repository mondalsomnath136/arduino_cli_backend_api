<?php

namespace App\Core;

/**
 * File-based Logger
 * 
 * Writes log entries to daily log files.
 */
class Logger
{
    private static ?string $logDir = null;

    /**
     * Initialize the log directory
     */
    private static function init(): void
    {
        if (self::$logDir === null) {
            $config = require __DIR__ . '/../../config/app.php';
            self::$logDir = $config['storage']['logs'];

            if (!is_dir(self::$logDir)) {
                mkdir(self::$logDir, 0755, true);
            }
        }
    }

    /**
     * Write a log entry
     */
    public static function log(string $level, string $message, array $context = []): void
    {
        self::init();

        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        $entry = "[{$timestamp}] [{$level}] {$message}{$contextStr}" . PHP_EOL;

        $filename = self::$logDir . '/' . date('Y-m-d') . '.log';
        file_put_contents($filename, $entry, FILE_APPEND | LOCK_EX);
    }

    public static function info(string $message, array $context = []): void
    {
        self::log('INFO', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::log('ERROR', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::log('WARNING', $message, $context);
    }

    public static function debug(string $message, array $context = []): void
    {
        self::log('DEBUG', $message, $context);
    }
}
