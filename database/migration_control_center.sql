-- migration_control_center.sql — Job Center / Health / Alert / Scheduler
-- Chay 1 lan (idempotent: IF NOT EXISTS + ALTER co dieu kien bang procedure kho nen
-- dung ADD COLUMN rieng; neu cot da ton tai se bao loi — kiem tra SHOW COLUMNS truoc).

-- 1. Mo rong app_jobs (giu tuong thich cu)
ALTER TABLE app_jobs
  ADD COLUMN job_type VARCHAR(40) NOT NULL DEFAULT '' AFTER module,
  ADD COLUMN priority TINYINT NOT NULL DEFAULT 0 AFTER status,
  ADD COLUMN cancelled_count INT NOT NULL DEFAULT 0 AFTER failed_count,
  ADD COLUMN progress_percent TINYINT NOT NULL DEFAULT 0 AFTER progress_total,
  ADD COLUMN queued_at DATETIME NULL AFTER created_at,
  ADD COLUMN completed_at DATETIME NULL AFTER finished_at,
  ADD COLUMN created_by VARCHAR(64) NOT NULL DEFAULT '' AFTER source,
  ADD COLUMN resumable TINYINT(1) NOT NULL DEFAULT 0 AFTER created_by,
  ADD COLUMN notify_on_complete TINYINT(1) NOT NULL DEFAULT 1 AFTER resumable,
  ADD COLUMN notify_on_failure TINYINT(1) NOT NULL DEFAULT 1 AFTER notify_on_complete,
  ADD COLUMN error_code VARCHAR(40) NULL AFTER error,
  ADD INDEX idx_job_mod_status (module, status, created_at);

-- 2. Item-level results
CREATE TABLE IF NOT EXISTS job_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  job_id VARCHAR(32) NOT NULL,
  item_key VARCHAR(64) NOT NULL DEFAULT '',
  target_type VARCHAR(20) NOT NULL DEFAULT 'profile',
  target_id VARCHAR(64) NOT NULL DEFAULT '',
  target_name VARCHAR(190) NOT NULL DEFAULT '',
  status VARCHAR(15) NOT NULL DEFAULT 'QUEUED',
  attempt_count INT NOT NULL DEFAULT 0,
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  result TEXT NULL,
  error VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_job_item (job_id, item_key),
  KEY idx_job_items_job (job_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Generic alerts (dedup theo alert_key)
CREATE TABLE IF NOT EXISTS alerts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  alert_key VARCHAR(120) NOT NULL,
  module VARCHAR(30) NOT NULL DEFAULT 'SYSTEM',
  type VARCHAR(60) NOT NULL DEFAULT '',
  severity VARCHAR(15) NOT NULL DEFAULT 'WARNING',
  title VARCHAR(190) NOT NULL DEFAULT '',
  message VARCHAR(500) NOT NULL DEFAULT '',
  profile_id INT NULL,
  job_id VARCHAR(32) NULL,
  status VARCHAR(15) NOT NULL DEFAULT 'OPEN',
  first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  occurrence_count INT NOT NULL DEFAULT 1,
  UNIQUE KEY uq_alert_key (alert_key),
  KEY idx_alerts_status (status, severity, last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Scheduled jobs (scheduler tao JobManager job den gio)
CREATE TABLE IF NOT EXISTS scheduled_jobs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL DEFAULT '',
  job_type VARCHAR(40) NOT NULL DEFAULT '',
  module VARCHAR(30) NOT NULL DEFAULT 'SYSTEM',
  schedule_type VARCHAR(15) NOT NULL DEFAULT 'DAILY',
  schedule_config TEXT NULL,
  target_config TEXT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  last_run_at DATETIME NULL,
  next_run_at DATETIME NULL,
  last_job_id VARCHAR(32) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sched_next (enabled, next_run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
