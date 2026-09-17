-- ============================================================
-- Migration: xoa gia tri 'disabled' khoi webrtc_protection
-- Ly do: Chrome desktop KHONG co flag tat han WebRTC (chi co extension/policy);
-- option 'disabled' truoc day thuc chat chay y het 'disable_nonproxied_udp',
-- gay hieu lam ve muc bao ve. Chuyen cac row 'disabled' ve 'disable_nonproxied_udp'
-- (muc bao ve manh nhat qua command-line) roi thu hep ENUM.
-- An toan chay nhieu lan.
-- ============================================================
USE yt_manager;

UPDATE profiles SET webrtc_protection = 'disable_nonproxied_udp' WHERE webrtc_protection = 'disabled';

SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA='yt_manager' AND TABLE_NAME='profiles' AND COLUMN_NAME='webrtc_protection');
SET @has_disabled := IF(@has_col = 0, 0, (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA='yt_manager' AND TABLE_NAME='profiles' AND COLUMN_NAME='webrtc_protection'
    AND COLUMN_TYPE LIKE '%''disabled''%'));
SET @sql_fix := IF(@has_disabled > 0,
    'ALTER TABLE profiles MODIFY COLUMN webrtc_protection ENUM(''default'',''disable_nonproxied_udp'') NOT NULL DEFAULT ''default''',
    'SELECT 1');
PREPARE stmt FROM @sql_fix; EXECUTE stmt; DEALLOCATE PREPARE stmt;
