-- Migration: Global Window Settings (Single Source of Truth cho launch Chrome).
-- Chay 1 lan cho DB cu. An toan chay lai (INSERT IGNORE).
-- Profile KHONG can migration width/height: BrowserManager doc settings tai launch.
INSERT IGNORE INTO settings (skey, svalue) VALUES
    ('window_fixed',    '1'),
    ('window_preset',   'hd'),
    ('window_width',    '1280'),
    ('window_height',   '720'),
    ('window_position', 'auto'),
    ('window_gap',      '5'),
    ('window_x',        '0'),
    ('window_y',        '0'),
    ('window_monitor',  'primary');
