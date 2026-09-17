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
    protocol ENUM('http','socks4','socks5','ssh') DEFAULT 'http',
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
    user_data_dir VARCHAR(255) NOT NULL,
    status ENUM('running','stopped','error') DEFAULT 'stopped',
    sync_role ENUM('NONE','MAIN','CONTROLLED') NOT NULL DEFAULT 'NONE',
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
    INDEX idx_profile_time (profile_id, checked_at),
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
    ('layout_controlled_monitors','');