-- Migration: Smart Multi-Monitor Arrange settings.
-- Chay 1 lan cho DB cu. An toan chay lai (INSERT IGNORE).
-- layout_monitors: CSV device names (\\\\.\\DISPLAY1...), rong = khong gioi han.
INSERT IGNORE INTO settings (skey, svalue) VALUES
    ('layout_monitors',           ''),
    ('layout_distribution',       'smart'),
    ('layout_size_balance',       'similar'),
    ('layout_remember_monitors',  '1'),
    ('layout_respect_dpi',        '1'),
    ('layout_keep_inside',        '1'),
    ('layout_disconnect',         'ask'),
    ('layout_main_monitor',       ''),
    ('layout_controlled_monitors','');
