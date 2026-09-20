-- Tach auth vs channel presence (khong suy HAS_CHANNEL khi chua login).
-- auth_status: UNKNOWN|LOGGED_IN|LOGGED_OUT|LOGIN_REQUIRED|VERIFICATION_REQUIRED|CHECK_FAILED
-- channel_presence: NOT_CHECKED|CHECKING|HAS_CHANNEL|NO_CHANNEL|UNKNOWN
-- Du lieu cu: channel_state='exists' khong co verified_at/evidence -> current
-- ve NOT_CHECKED, giu last_known + danh dau legacy (khong coi la verified).
USE yt_manager;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='yt_manager' AND TABLE_NAME='account_states' AND COLUMN_NAME='auth_status');
SET @s := IF(@c = 0, 'ALTER TABLE account_states ADD COLUMN auth_status VARCHAR(24) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='yt_manager' AND TABLE_NAME='account_states' AND COLUMN_NAME='auth_verified_at');
SET @s := IF(@c = 0, 'ALTER TABLE account_states ADD COLUMN auth_verified_at DATETIME DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='yt_manager' AND TABLE_NAME='account_states' AND COLUMN_NAME='channel_presence');
SET @s := IF(@c = 0, 'ALTER TABLE account_states ADD COLUMN channel_presence VARCHAR(24) NOT NULL DEFAULT ''NOT_CHECKED''', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='yt_manager' AND TABLE_NAME='account_states' AND COLUMN_NAME='channel_verified_at');
SET @s := IF(@c = 0, 'ALTER TABLE account_states ADD COLUMN channel_verified_at DATETIME DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='yt_manager' AND TABLE_NAME='account_states' AND COLUMN_NAME='last_known_presence');
SET @s := IF(@c = 0, 'ALTER TABLE account_states ADD COLUMN last_known_presence VARCHAR(24) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='yt_manager' AND TABLE_NAME='account_states' AND COLUMN_NAME='channel_legacy');
SET @s := IF(@c = 0, 'ALTER TABLE account_states ADD COLUMN channel_legacy TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill legacy: exists cu -> current NOT_CHECKED + last_known HAS_CHANNEL + legacy flag
UPDATE account_states
SET last_known_presence = 'HAS_CHANNEL', channel_presence = 'NOT_CHECKED', channel_legacy = 1
WHERE channel_state = 'exists' AND COALESCE(channel_legacy, 0) = 0;
