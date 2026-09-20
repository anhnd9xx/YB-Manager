-- ============================================================
-- Migration: per-profile Window Placement (multi-monitor affinity).
-- monitor_mode: LAST | FIXED | AUTO (default LAST, backward-compatible).
-- last/fixed_monitor_device: device name (\\.\DISPLAY1...), KHONG luu HMONITOR.
-- last_window_rect: JSON absolute {x,y,width,height}; _norm: relative work area.
-- An toan chay nhieu lan (chi ALTER khi cot chua ton tai).
-- ============================================================
USE yt_manager;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='yt_manager' AND TABLE_NAME='profiles' AND COLUMN_NAME='monitor_mode');
SET @s := IF(@c = 0, 'ALTER TABLE profiles ADD COLUMN monitor_mode VARCHAR(10) NOT NULL DEFAULT ''LAST''', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='yt_manager' AND TABLE_NAME='profiles' AND COLUMN_NAME='fixed_monitor_device');
SET @s := IF(@c = 0, 'ALTER TABLE profiles ADD COLUMN fixed_monitor_device VARCHAR(64) NOT NULL DEFAULT ''''', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='yt_manager' AND TABLE_NAME='profiles' AND COLUMN_NAME='last_monitor_device');
SET @s := IF(@c = 0, 'ALTER TABLE profiles ADD COLUMN last_monitor_device VARCHAR(64) NOT NULL DEFAULT ''''', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='yt_manager' AND TABLE_NAME='profiles' AND COLUMN_NAME='last_window_rect');
SET @s := IF(@c = 0, 'ALTER TABLE profiles ADD COLUMN last_window_rect TEXT DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='yt_manager' AND TABLE_NAME='profiles' AND COLUMN_NAME='last_window_rect_norm');
SET @s := IF(@c = 0, 'ALTER TABLE profiles ADD COLUMN last_window_rect_norm TEXT DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='yt_manager' AND TABLE_NAME='profiles' AND COLUMN_NAME='last_window_state');
SET @s := IF(@c = 0, 'ALTER TABLE profiles ADD COLUMN last_window_state VARCHAR(10) NOT NULL DEFAULT ''normal''', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
