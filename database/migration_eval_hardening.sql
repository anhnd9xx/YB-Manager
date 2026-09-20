-- ============================================================
-- Migration: evaluation hardening (§9-10 attempt fields + §4/15 settings).
-- last_attempt_status: SUCCESS|FAILED|TIMEOUT|CANCELLED (ket qua LAN CHECK).
-- last_error_code/message: ma loi ro rang (khong chi ERROR).
-- settings: acc_concurrency (2/4/6/8), acc_auto_start, acc_close_after.
-- An toan chay nhieu lan.
-- ============================================================
USE yt_manager;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='yt_manager' AND TABLE_NAME='account_states' AND COLUMN_NAME='last_attempt_status');
SET @s := IF(@c = 0, 'ALTER TABLE account_states ADD COLUMN last_attempt_status VARCHAR(12) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='yt_manager' AND TABLE_NAME='account_states' AND COLUMN_NAME='last_error_code');
SET @s := IF(@c = 0, 'ALTER TABLE account_states ADD COLUMN last_error_code VARCHAR(40) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='yt_manager' AND TABLE_NAME='account_states' AND COLUMN_NAME='last_error_message');
SET @s := IF(@c = 0, 'ALTER TABLE account_states ADD COLUMN last_error_message VARCHAR(500) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO settings (skey, svalue) VALUES
    ('acc_concurrency', '4'),
    ('acc_auto_start', '0'),
    ('acc_close_after', '0');
