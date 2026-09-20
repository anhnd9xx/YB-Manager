-- ============================================================
-- YT Manager - Database Schema (fresh install)
-- ============================================================
-- Cách dùng (máy khác / khi clone từ GitHub):
--   Cách 1 - phpMyAdmin: import file này
--   Cách 2 - command line:
--     mysql -u root -p < database/db.sql
--   Sau đó sửa config.php nếu tên DB / user / pass khác mặc định
--   (mặc định: DB_NAME=yt_manager, DB_USER=root, DB_PASS='').
--
-- File này an toàn chạy nhiều lần (CREATE ... IF NOT EXISTS).
-- Với DB đã có từ phiên bản cũ, chạy thêm file migration_*.
-- ============================================================

CREATE DATABASE IF NOT EXISTS yt_manager
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE yt_manager;

-- ============ Bảng proxy ============
CREATE TABLE IF NOT EXISTS proxies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    host VARCHAR(255) NOT NULL,
    port INT NOT NULL,
    username VARCHAR(100) DEFAULT NULL,
    password VARCHAR(255) DEFAULT NULL,
    protocol ENUM('http','https','socks4','socks5','ssh') DEFAULT 'http',
    country VARCHAR(2) DEFAULT NULL,
    status ENUM('alive','dead','unknown') DEFAULT 'unknown',
    last_check DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============ Bảng profile (kênh) ============
-- Mỗi kênh = 1 profile Chrome riêng (user_data_dir) + debug port riêng (CDP)
CREATE TABLE IF NOT EXISTS profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    platform ENUM('youtube','tiktok','facebook','other') DEFAULT 'youtube',
    channel_handle VARCHAR(150) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    webrtc_protection ENUM('default','disable_nonproxied_udp') NOT NULL DEFAULT 'default',
    proxy_id INT DEFAULT NULL,
    monitor_enabled TINYINT(1) NOT NULL DEFAULT 1,
    watchlist TINYINT(1) NOT NULL DEFAULT 0,
    alert_on_status_change TINYINT(1) NOT NULL DEFAULT 1,
    alert_on_login_required TINYINT(1) NOT NULL DEFAULT 1,
    alert_on_proxy_error TINYINT(1) NOT NULL DEFAULT 1,
    user_data_dir VARCHAR(255) NOT NULL,
    status ENUM('running','stopped','error') DEFAULT 'stopped',
    sync_role ENUM('NONE','MAIN','CONTROLLED') NOT NULL DEFAULT 'NONE',
    monitor_mode VARCHAR(10) NOT NULL DEFAULT 'LAST',
    fixed_monitor_device VARCHAR(64) NOT NULL DEFAULT '',
    last_monitor_device VARCHAR(64) NOT NULL DEFAULT '',
    last_window_rect TEXT DEFAULT NULL,
    last_window_rect_norm TEXT DEFAULT NULL,
    last_window_state VARCHAR(10) NOT NULL DEFAULT 'normal',
    last_opened DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    debug_port INT DEFAULT NULL,
    FOREIGN KEY (proxy_id) REFERENCES proxies(id) ON DELETE SET NULL,
    UNIQUE KEY uq_user_data_dir (user_data_dir),
    INDEX idx_proxy (proxy_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============ Bảng nhật ký hoạt động ============
CREATE TABLE IF NOT EXISTS activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    profile_id INT DEFAULT NULL,
    action VARCHAR(200) NOT NULL,
    detail TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (profile_id) REFERENCES profiles(id) ON DELETE SET NULL,
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============ Bảng phiên đồng bộ (module Synchronize) ============
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

-- ============ Bảng cài đặt (key/value) ============
CREATE TABLE IF NOT EXISTS settings (
    skey VARCHAR(100) PRIMARY KEY,
    svalue TEXT DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============ Account Evaluation (danh gia Google Account noi bo) ============
CREATE TABLE IF NOT EXISTS account_states (
    profile_id INT PRIMARY KEY,
    imported_at DATETIME NOT NULL,
    last_checked_at DATETIME DEFAULT NULL,
    last_success_at DATETIME DEFAULT NULL,
    success_count INT NOT NULL DEFAULT 0,
    fail_count INT NOT NULL DEFAULT 0,
    consec_fails INT NOT NULL DEFAULT 0,
    login_state VARCHAR(20) NOT NULL DEFAULT 'unknown',
    session_state VARCHAR(20) NOT NULL DEFAULT 'unknown',
    youtube_state VARCHAR(20) NOT NULL DEFAULT 'unknown',
    channel_state VARCHAR(20) NOT NULL DEFAULT 'unknown',
    channel_name VARCHAR(255) DEFAULT NULL,
    security_challenge TINYINT(1) NOT NULL DEFAULT 0,
    recovery_required TINYINT(1) NOT NULL DEFAULT 0,
    stability INT NOT NULL DEFAULT 100,
    confidence INT NOT NULL DEFAULT 0,
    stage VARCHAR(30) NOT NULL DEFAULT 'NEW',
    eval_status VARCHAR(24) NOT NULL DEFAULT 'UNCHECKED',
    infra_status VARCHAR(24) DEFAULT NULL,
    eval_confidence VARCHAR(8) DEFAULT NULL,
    auth_status VARCHAR(24) DEFAULT NULL,
    auth_verified_at DATETIME DEFAULT NULL,
    channel_presence VARCHAR(24) NOT NULL DEFAULT 'NOT_CHECKED',
    channel_verified_at DATETIME DEFAULT NULL,
    last_known_presence VARCHAR(24) DEFAULT NULL,
    channel_legacy TINYINT(1) NOT NULL DEFAULT 0,
    last_known_status VARCHAR(24) DEFAULT NULL,
    last_successful_check_at DATETIME DEFAULT NULL,
    last_attempt_at DATETIME DEFAULT NULL,
    last_attempt_status VARCHAR(12) DEFAULT NULL,
    last_error VARCHAR(500) DEFAULT NULL,
    last_error_code VARCHAR(40) DEFAULT NULL,
    last_error_message VARCHAR(500) DEFAULT NULL,
    last_duration_ms INT DEFAULT NULL,
    eval_stage VARCHAR(24) DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (profile_id) REFERENCES profiles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS account_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    profile_id INT NOT NULL,
    checked_at DATETIME NOT NULL,
    stability INT NOT NULL,
    confidence INT NOT NULL,
    stage VARCHAR(30) NOT NULL,
    reasons TEXT DEFAULT NULL,
    warnings TEXT DEFAULT NULL,
    eval_status VARCHAR(24) DEFAULT NULL,
    prev_status VARCHAR(24) DEFAULT NULL,
    duration_ms INT DEFAULT NULL,
    reason VARCHAR(200) DEFAULT NULL,
    INDEX idx_profile_time (profile_id, checked_at),
    FOREIGN KEY (profile_id) REFERENCES profiles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

-- ============ Tab Session Manager (luu/khoi phuc tab theo profile) ============
CREATE TABLE IF NOT EXISTS tab_sessions (
    profile_id INT NOT NULL,
    kind ENUM('current','last_good') NOT NULL DEFAULT 'current',
    saved_at DATETIME NOT NULL,
    active_index INT NOT NULL DEFAULT 0,
    tabs TEXT DEFAULT NULL,
    fingerprint VARCHAR(64) DEFAULT NULL,
    PRIMARY KEY (profile_id, kind),
    FOREIGN KEY (profile_id) REFERENCES profiles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;-- Dữ liệu mặc định (chạy lần đầu; INSERT IGNORE giữ nguyên giá trị đã sửa)
INSERT IGNORE INTO settings (skey, svalue) VALUES
    ('chrome_path',   'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'),
    ('home_url',      'https://www.google.com/'),
    ('proxy_timeout', '5'),
    ('auto_refresh',  '1'),
    ('window_fixed',    '1'),
    ('window_preset',   'hd'),
    ('window_width',    '1280'),
    ('window_height',   '720'),
    ('window_position', 'auto'),
    ('window_gap',      '5'),
    ('window_x',        '0'),
    ('window_y',        '0'),
    ('window_monitor',  'primary'),
    ('layout_mode',            'smart_auto'),
    ('layout_size_mode',       'auto_fit'),
    ('layout_monitor',         'primary'),
    ('layout_multi',           '0'),
    ('layout_gap_x',           '5'),
    ('layout_gap_y',           '5'),
    ('layout_min_w',           '500'),
    ('layout_min_h',           '400'),
    ('layout_respect_taskbar', '1'),
    ('layout_keep_visible',    '1'),
    ('layout_auto_launch',     '0'),
    ('layout_reflow',          'ask'),
    ('layout_fallback',        'auto'),
    ('layout_compact_x',       '150'),
    ('layout_compact_y',       '40'),
    ('layout_monitors',           ''),
    ('layout_distribution',       'smart'),
    ('layout_size_balance',       'similar'),
    ('layout_remember_monitors',  '1'),
    ('layout_respect_dpi',        '1'),
    ('layout_keep_inside',        '1'),
    ('layout_disconnect',         'ask'),
    ('layout_main_monitor',       ''),
    ('layout_controlled_monitors',''),
    ('tab_autosave', '1'),
    ('tab_autorestore', '1'),
    ('tab_remember_active', '1'),
    ('tab_autosave_interval', '30');