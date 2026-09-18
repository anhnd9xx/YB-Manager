-- Migration: Tab Session Manager (luu/khoi phuc tab theo tung profile).
-- Chay 1 lan cho DB cu. An toan chay lai (IF NOT EXISTS).
-- kind: 'current' (snapshot moi nhat, ke ca rong) | 'last_good' (>= 1 URL tot).
CREATE TABLE IF NOT EXISTS tab_sessions (
    profile_id INT NOT NULL,
    kind ENUM('current','last_good') NOT NULL DEFAULT 'current',
    saved_at DATETIME NOT NULL,
    active_index INT NOT NULL DEFAULT 0,
    tabs TEXT DEFAULT NULL,
    fingerprint VARCHAR(64) DEFAULT NULL,
    PRIMARY KEY (profile_id, kind),
    FOREIGN KEY (profile_id) REFERENCES profiles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO settings (skey, svalue) VALUES
    ('tab_autosave', '1'),
    ('tab_autorestore', '1'),
    ('tab_remember_active', '1'),
    ('tab_autosave_interval', '30');
