-- Staged health check: infra_status rieng + doi eval UNAVAILABLE -> CHANNEL_UNAVAILABLE.
-- (lifecycle stage 'UNAVAILABLE' giu nguyen - khac eval channel status.)
USE yt_manager;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='yt_manager' AND TABLE_NAME='account_states' AND COLUMN_NAME='infra_status');
SET @s := IF(@c = 0, 'ALTER TABLE account_states ADD COLUMN infra_status VARCHAR(24) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='yt_manager' AND TABLE_NAME='account_states' AND COLUMN_NAME='eval_confidence');
SET @s := IF(@c = 0, 'ALTER TABLE account_states ADD COLUMN eval_confidence VARCHAR(8) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE account_states SET eval_status='CHANNEL_UNAVAILABLE' WHERE eval_status='UNAVAILABLE';
UPDATE account_states SET last_known_status='CHANNEL_UNAVAILABLE' WHERE last_known_status='UNAVAILABLE';
UPDATE account_history SET eval_status='CHANNEL_UNAVAILABLE' WHERE eval_status='UNAVAILABLE';
UPDATE account_history SET prev_status='CHANNEL_UNAVAILABLE' WHERE prev_status='UNAVAILABLE';
