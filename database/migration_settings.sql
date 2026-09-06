-- Migration: bang settings luu cau hinh app (key/value)
USE yt_manager;

CREATE TABLE IF NOT EXISTS settings (
    skey VARCHAR(100) PRIMARY KEY,
    svalue TEXT DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO settings (skey, svalue) VALUES
    ('chrome_path', 'C:\Program Files\Google\Chrome\Application\chrome.exe'),
    ('home_url', 'https://www.youtube.com'),
    ('proxy_timeout', '5'),
    ('auto_refresh', '1')
ON DUPLICATE KEY UPDATE skey = VALUES(skey);