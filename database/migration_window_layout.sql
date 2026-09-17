-- Migration: Window Layout Settings cho Smart Auto Arrange (PHASE 2).
-- Chay 1 lan cho DB cu. An toan chay lai (INSERT IGNORE).
-- App cu thieu keys van chay: API merge defaults + normalizeLayout fallback.
INSERT IGNORE INTO settings (skey, svalue) VALUES
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
    ('layout_compact_y',       '40');
