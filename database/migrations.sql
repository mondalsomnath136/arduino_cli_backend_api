-- ============================================
-- Arduino CLI Backend - Database Schema
-- ============================================
-- Run this SQL to set up the database.
-- 
-- Usage:
--   mysql -u root -p < database/migrations.sql
-- ============================================

CREATE DATABASE IF NOT EXISTS `arduino_cli_backend`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `arduino_cli_backend`;

-- ──────────────────────────────────────────────
--  Compile Jobs
-- ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `compile_jobs` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `job_id` VARCHAR(50) NOT NULL UNIQUE,
    `fqbn` VARCHAR(255) NOT NULL,
    `action` ENUM('compile', 'verify') NOT NULL DEFAULT 'compile',
    `status` ENUM('pending', 'running', 'success', 'failed', 'error') NOT NULL DEFAULT 'pending',
    `code_hash` VARCHAR(32) DEFAULT NULL COMMENT 'MD5 hash of the source code',
    `code_size` INT UNSIGNED DEFAULT 0 COMMENT 'Size of source code in bytes',
    `output_log` LONGTEXT DEFAULT NULL COMMENT 'Compilation stdout output',
    `error_log` LONGTEXT DEFAULT NULL COMMENT 'Compilation stderr output',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completed_at` DATETIME DEFAULT NULL,

    INDEX `idx_status` (`status`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_code_hash` (`code_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────
--  Compile Logs (for realtime SSE streaming)
-- ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `compile_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `job_id` VARCHAR(50) NOT NULL,
    `level` VARCHAR(20) NOT NULL DEFAULT 'info' COMMENT 'Log level: info, stdout, stderr, error, success',
    `message` TEXT NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX `idx_job_id` (`job_id`),
    INDEX `idx_job_id_id` (`job_id`, `id`),

    CONSTRAINT `fk_compile_logs_job`
        FOREIGN KEY (`job_id`) REFERENCES `compile_jobs`(`job_id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────
--  Installed Libraries (tracking)
-- ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `installed_libraries` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL UNIQUE,
    `version` VARCHAR(50) DEFAULT NULL,
    `installed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

    INDEX `idx_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────
--  Installed Boards (tracking)
-- ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `installed_boards` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `platform` VARCHAR(255) NOT NULL UNIQUE COMMENT 'e.g., arduino:avr, esp32:esp32',
    `installed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

    INDEX `idx_platform` (`platform`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────
--  Rate Limiting
-- ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `rate_limits` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ip_address` VARCHAR(45) NOT NULL,
    `expires_at` DATETIME NOT NULL,

    INDEX `idx_ip_expires` (`ip_address`, `expires_at`),
    INDEX `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────
--  API Keys (optional authentication)
-- ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `api_keys` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL COMMENT 'Descriptive name for the key',
    `api_key` VARCHAR(64) NOT NULL UNIQUE,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `last_used_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX `idx_api_key` (`api_key`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────
--  Insert a default API key (for development)
-- ──────────────────────────────────────────────
INSERT IGNORE INTO `api_keys` (`name`, `api_key`, `is_active`)
VALUES ('Default Development Key', 'dev_key_arduino_cli_2024_change_me_in_production', 1);
