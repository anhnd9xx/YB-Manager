-- migration_activity_daily.sql — Mo rong Auto Activity (giu config cu).
-- activity_configs/activity_history/activity_logs giu nguyen + them cot.
ALTER TABLE activity_configs
  ADD COLUMN active_days VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5,6,7',
  ADD COLUMN sessions_min INT NOT NULL DEFAULT 6,
  ADD COLUMN sessions_max INT NOT NULL DEFAULT 10,
  ADD COLUMN tasks_min INT NOT NULL DEFAULT 1,
  ADD COLUMN tasks_max INT NOT NULL DEFAULT 3,
  ADD COLUMN gap_min INT NOT NULL DEFAULT 30,
  ADD COLUMN gap_max INT NOT NULL DEFAULT 120,
  ADD COLUMN limits_json TEXT NULL,
  ADD COLUMN template VARCHAR(20) NOT NULL DEFAULT 'NORMAL',
  ADD COLUMN planner_enabled TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN next_run_at DATETIME NULL;

-- Website Pool (chung, khong hard-code) (§5-6)
CREATE TABLE IF NOT EXISTS activity_websites (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL DEFAULT '',
  url VARCHAR(500) NOT NULL DEFAULT '',
  domain VARCHAR(190) NOT NULL DEFAULT '',
  category VARCHAR(20) NOT NULL DEFAULT 'CUSTOM',
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  weight INT NOT NULL DEFAULT 1,
  last_used_at DATETIME NULL,
  use_count INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_act_web_url (url(255)),
  KEY idx_act_web (enabled, category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Google Search Pool (§8)
CREATE TABLE IF NOT EXISTS activity_search_pool (
  id INT AUTO_INCREMENT PRIMARY KEY,
  query VARCHAR(200) NOT NULL DEFAULT '',
  category VARCHAR(20) NOT NULL DEFAULT 'CUSTOM',
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  weight INT NOT NULL DEFAULT 1,
  last_used_at DATETIME NULL,
  use_count INT NOT NULL DEFAULT 0,
  use_today DATE NULL,
  use_today_count INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_act_search_query (query(191)),
  KEY idx_act_search (enabled, category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Activity sessions (plan ngay) (§12, §17-19, §40, §56-57)
CREATE TABLE IF NOT EXISTS activity_sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  session_key VARCHAR(80) NOT NULL DEFAULT '',
  profile_id INT NOT NULL,
  plan_date DATE NOT NULL,
  run_at DATETIME NOT NULL,
  tasks_json TEXT NOT NULL,
  status VARCHAR(15) NOT NULL DEFAULT 'PLANNED',
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  success_count INT NOT NULL DEFAULT 0,
  failed_count INT NOT NULL DEFAULT 0,
  error_code VARCHAR(40) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_act_session (session_key),
  KEY idx_act_sess_profile (profile_id, plan_date, status, run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
