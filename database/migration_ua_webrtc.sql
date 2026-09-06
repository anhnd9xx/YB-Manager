-- ============================================================
-- Migration: them cot user_agent + webrtc_protection cho bang profiles
-- (dung cho DB tao bang db.sql phien ban cu truoc UA/WebRTC)
-- An toan chay nhieu lan: chi ALTER khi cot chua ton tai.
-- ============================================================
USE yt_manager;

SET @has_ua := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA='yt_manager' AND TABLE_NAME='profiles' AND COLUMN_NAME='user_agent');
SET @sql_ua := IF(@has_ua = 0,
    'ALTER TABLE profiles ADD COLUMN user_agent VARCHAR(255) DEFAULT NULL AFTER channel_handle',
    'SELECT 1');
PREPARE stmt FROM @sql_ua; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_webrtc := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA='yt_manager' AND TABLE_NAME='profiles' AND COLUMN_NAME='webrtc_protection');
SET @sql_webrtc := IF(@has_webrtc = 0,
    'ALTER TABLE profiles ADD COLUMN webrtc_protection ENUM(''default'',''disable_nonproxied_udp'',''disabled'') NOT NULL DEFAULT ''default'' AFTER user_agent',
    'SELECT 1');
PREPARE stmt FROM @sql_webrtc; EXECUTE stmt; DEALLOCATE PREPARE stmt;