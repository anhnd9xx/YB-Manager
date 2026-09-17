-- ============================================================
-- Migration: SyncSession cho module Synchronize (PHASE 6)
-- + bang sync_sessions (1 row = 1 phien; chi 1 phien ACTIVE tai 1 thoi diem)
-- + cot profiles.sync_role (NONE/MAIN/CONTROLLED)
-- An toan chay nhieu lan.
-- ============================================================
USE yt_manager;

CREATE TABLE IF NOT EXISTS sync_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL DEFAULT 'Default Session',
    main_profile_id INT DEFAULT NULL,
    controlled_ids TEXT DEFAULT NULL,
    state ENUM('IDLE','STARTING','RUNNING','PAUSED','STOPPING','STOPPED','ERROR') NOT NULL DEFAULT 'IDLE',
    config TEXT DEFAULT NULL,
    engine_pid INT DEFAULT NULL,
    last_error VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_state (state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @has_role := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA='yt_manager' AND TABLE_NAME='profiles' AND COLUMN_NAME='sync_role');
SET @sql_role := IF(@has_role = 0,
    'ALTER TABLE profiles ADD COLUMN sync_role ENUM(''NONE'',''MAIN'',''CONTROLLED'') NOT NULL DEFAULT ''NONE''',
    'SELECT 1');
PREPARE stmt FROM @sql_role; EXECUTE stmt; DEALLOCATE PREPARE stmt;
