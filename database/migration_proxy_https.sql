-- Them 'https' vao ENUM protocol (UI manual proxy ho tro HTTPS).
-- Chrome coi https nhu http (proxy_server_arg map ve http), khong doi launch flow.
USE yt_manager;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA='yt_manager' AND TABLE_NAME='proxies' AND COLUMN_NAME='protocol'
    AND COLUMN_TYPE LIKE '%https%');
SET @s := IF(@c = 0,
    'ALTER TABLE proxies MODIFY COLUMN protocol ENUM(''http'',''https'',''socks4'',''socks5'',''ssh'') DEFAULT ''http''',
    'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
