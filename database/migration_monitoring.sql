-- ============================================================
-- Migration: module "Thong ke & Theo doi" (Monitoring).
-- profiles: monitor_enabled, watchlist, alert_on_* (default backward-compatible).
-- channel_alerts: dedup theo (profile,type) khi OPEN (update last_seen).
-- state_history: transition runtime/evaluation/proxy/monitoring.
-- monitor_snapshots: snapshot KPI theo gio cho trend that (khong fake).
-- An toan chay nhieu lan.
-- ============================================================
USE yt_manager;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='yt_manager' AND TABLE_NAME='profiles' AND COLUMN_NAME='monitor_enabled');
SET @s := IF(@c = 0, 'ALTER TABLE profiles ADD COLUMN monitor_enabled TINYINT(1) NOT NULL DEFAULT 1', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='yt_manager' AND TABLE_NAME='profiles' AND COLUMN_NAME='watchlist');
SET @s := IF(@c = 0, 'ALTER TABLE profiles ADD COLUMN watchlist TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='yt_manager' AND TABLE_NAME='profiles' AND COLUMN_NAME='alert_on_status_change');
SET @s := IF(@c = 0, 'ALTER TABLE profiles ADD COLUMN alert_on_status_change TINYINT(1) NOT NULL DEFAULT 1', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='yt_manager' AND TABLE_NAME='profiles' AND COLUMN_NAME='alert_on_login_required');
SET @s := IF(@c = 0, 'ALTER TABLE profiles ADD COLUMN alert_on_login_required TINYINT(1) NOT NULL DEFAULT 1', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='yt_manager' AND TABLE_NAME='profiles' AND COLUMN_NAME='alert_on_proxy_error');
SET @s := IF(@c = 0, 'ALTER TABLE profiles ADD COLUMN alert_on_proxy_error TINYINT(1) NOT NULL DEFAULT 1', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS channel_alerts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    profile_id INT NOT NULL,
    severity ENUM('CRITICAL','WARNING','INFO') NOT NULL DEFAULT 'WARNING',
    type VARCHAR(40) NOT NULL,
    message VARCHAR(255) NOT NULL DEFAULT '',
    first_seen DATETIME NOT NULL,
    last_seen DATETIME NOT NULL,
    seen_count INT NOT NULL DEFAULT 1,
    resolved_at DATETIME DEFAULT NULL,
    status ENUM('OPEN','RESOLVED') NOT NULL DEFAULT 'OPEN',
    FOREIGN KEY (profile_id) REFERENCES profiles(id) ON DELETE CASCADE,
    UNIQUE KEY uq_open_alert (profile_id, type, status),
    INDEX idx_status_seen (status, last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS state_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    profile_id INT NOT NULL,
    ts DATETIME NOT NULL,
    category ENUM('evaluation','runtime','proxy','monitoring') NOT NULL,
    old_value VARCHAR(40) DEFAULT NULL,
    new_value VARCHAR(40) DEFAULT NULL,
    reason VARCHAR(200) DEFAULT NULL,
    FOREIGN KEY (profile_id) REFERENCES profiles(id) ON DELETE CASCADE,
    INDEX idx_profile_ts (profile_id, ts),
    INDEX idx_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS monitor_snapshots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    taken_at DATETIME NOT NULL,
    total INT NOT NULL DEFAULT 0,
    active INT NOT NULL DEFAULT 0,
    issues INT NOT NULL DEFAULT 0,
    unchecked INT NOT NULL DEFAULT 0,
    running INT NOT NULL DEFAULT 0,
    proxy_dead INT NOT NULL DEFAULT 0,
    eval_failed INT NOT NULL DEFAULT 0,
    login_required INT NOT NULL DEFAULT 0,
    verification_required INT NOT NULL DEFAULT 0,
    INDEX idx_taken (taken_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
