-- ============================================================
-- Migration: dam bao moi kenh co user_data_dir DUY NHAT
-- (mot kenh = mot Chrome profile - khong bao gio dung chung data
--  voi kenh khac, ke ca ten bi safe_name anh truong ve cung thu muc)
-- An toan chay nhieu lan.
-- ============================================================
USE yt_manager;

-- Xoa nhung kenh trung user_data_dir (giu kenh co id nho nhat)
DELETE p1 FROM profiles p1
JOIN profiles p2 ON p1.user_data_dir = p2.user_data_dir AND p1.id > p2.id;

-- Them rang buoc UNIQUE (bo qua neu da co)
SET @has_idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA='yt_manager' AND TABLE_NAME='profiles' AND INDEX_NAME='uq_user_data_dir');
SET @sql_idx := IF(@has_idx = 0,
    'ALTER TABLE profiles ADD UNIQUE KEY uq_user_data_dir (user_data_dir)',
    'SELECT 1');
PREPARE stmt FROM @sql_idx; EXECUTE stmt; DEALLOCATE PREPARE stmt;