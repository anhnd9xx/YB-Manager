-- Tach timestamp danh gia: completed (moi lan xong) / changed (doi status that).
-- last_attempt_at     = bat dau attempt gan nhat (gi nguyen y nghia).
-- last_completed_at   = ket thuc attempt gan nhat (success/failed/timeout deu set).
-- last_status_changed_at = lan cuoi channel status that su doi.
-- Backfill: completed = attempt (attempt cu deu da ket thuc); khong tu gan
-- successful/changed neu khong chac.
USE yt_manager;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='yt_manager' AND TABLE_NAME='account_states' AND COLUMN_NAME='last_completed_at');
SET @s := IF(@c = 0, 'ALTER TABLE account_states ADD COLUMN last_completed_at DATETIME DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='yt_manager' AND TABLE_NAME='account_states' AND COLUMN_NAME='last_status_changed_at');
SET @s := IF(@c = 0, 'ALTER TABLE account_states ADD COLUMN last_status_changed_at DATETIME DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE account_states SET last_completed_at = last_attempt_at
WHERE last_completed_at IS NULL AND last_attempt_at IS NOT NULL
  AND COALESCE(eval_status,'UNCHECKED') <> 'CHECKING';
