-- Migration: Account Evaluation (danh gia Google Account noi bo).
-- Chay 1 lan cho DB cu. An toan chay lai (IF NOT EXISTS + INSERT IGNORE).
-- Backward compat: account cu duoc backfill imported_at = profiles.created_at.
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Backfill: moi profile hien co co 1 dong state (imported_at = ngay tao profile)
INSERT IGNORE INTO account_states (profile_id, imported_at)
SELECT id, created_at FROM profiles;
