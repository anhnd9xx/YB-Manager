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
    webrtc_protection ENUM('default','disable_nonproxied_udp','disabled') NOT NULL DEFAULT 'default',
    proxy_id INT DEFAULT NULL,
    user_data_dir VARCHAR(255) NOT NULL,
    status ENUM('running','stopped','error') DEFAULT 'stopped',
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

-- ============ Bảng cài đặt (key/value) ============
CREATE TABLE IF NOT EXISTS settings (
    skey VARCHAR(100) PRIMARY KEY,
    svalue TEXT DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Dữ liệu mặc định (chạy lần đầu; INSERT IGNORE giữ nguyên giá trị đã sửa)
INSERT IGNORE INTO settings (skey, svalue) VALUES
    ('chrome_path',   'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'),
    ('home_url',      'https://www.youtube.com'),
    ('proxy_timeout', '5'),
    ('auto_refresh',  '1');