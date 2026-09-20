-- ============================================================
-- Migration: tach evaluation_status khoi runtime Chrome + batch state.
-- eval_status: UNCHECKED|CHECKING|ACTIVE|LOGIN_REQUIRED|VERIFICATION_REQUIRED|UNAVAILABLE|ERROR
--   CHECKING la runtime (khong persist sau restart - khoi dong lai ve UNCHECKED/previous).
-- last_known_status + last_successful_check_at giu ket qua tot cuoi khi lan moi loi tool.
-- eval_batches: batch danh gia (UI start 1 lan, chunk 4/lan).
-- An toan chay nhieu lan.
-- ============================================================
USE yt_manager;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='yt_manager' AND TABLE_NAME='account_states' AND COLUMN_NAME='eval_status');
SET @s := IF(@c = 0, 'ALTER TABLE account_states ADD COLUMN eval_status VARCHAR(24) NOT NULL DEFAULT ''UNCHECKED''', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='yt_manager' AND TABLE_NAME='account_states' AND COLUMN_NAME='last_known_status');
SET @s := IF(@c = 0, 'ALTER TABLE account_states ADD COLUMN last_known_status VARCHAR(24) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='yt_manager' AND TABLE_NAME='account_states' AND COLUMN_NAME='last_successful_check_at');
SET @s := IF(@c = 0, 'ALTER TABLE account_states ADD COLUMN last_successful_check_at DATETIME DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='yt_manager' AND TABLE_NAME='account_states' AND COLUMN_NAME='last_attempt_at');
SET @s := IF(@c = 0, 'ALTER TABLE account_states ADD COLUMN last_attempt_at DATETIME DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='yt_manager' AND TABLE_NAME='account_states' AND COLUMN_NAME='last_error');
SET @s := IF(@c = 0, 'ALTER TABLE account_states ADD COLUMN last_error VARCHAR(500) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='yt_manager' AND TABLE_NAME='account_states' AND COLUMN_NAME='last_duration_ms');
SET @s := IF(@c = 0, 'ALTER TABLE account_states ADD COLUMN last_duration_ms INT DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='yt_manager' AND TABLE_NAME='account_states' AND COLUMN_NAME='eval_stage');
SET @s := IF(@c = 0, 'ALTER TABLE account_states ADD COLUMN eval_stage VARCHAR(24) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- History: them eval_status/prev/duration/reason (giai han 100/profile khi doc)
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='yt_manager' AND TABLE_NAME='account_history' AND COLUMN_NAME='eval_status');
SET @s := IF(@c = 0, 'ALTER TABLE account_history ADD COLUMN eval_status VARCHAR(24) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='yt_manager' AND TABLE_NAME='account_history' AND COLUMN_NAME='prev_status');
SET @s := IF(@c = 0, 'ALTER TABLE account_history ADD COLUMN prev_status VARCHAR(24) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='yt_manager' AND TABLE_NAME='account_history' AND COLUMN_NAME='duration_ms');
SET @s := IF(@c = 0, 'ALTER TABLE account_history ADD COLUMN duration_ms INT DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='yt_manager' AND TABLE_NAME='account_history' AND COLUMN_NAME='reason');
SET @s := IF(@c = 0, 'ALTER TABLE account_history ADD COLUMN reason VARCHAR(200) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS eval_batches (
    batch_id VARCHAR(40) PRIMARY KEY,
    ids TEXT NOT NULL,
    total INT NOT NULL DEFAULT 0,
    pending INT NOT NULL DEFAULT 0,
    running INT NOT NULL DEFAULT 0,
    completed INT NOT NULL DEFAULT 0,
    failed INT NOT NULL DEFAULT 0,
    cancelled INT NOT NULL DEFAULT 0,
    status VARCHAR(12) NOT NULL DEFAULT 'running',
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
