-- migration_ai_brain.sql — AI Dev V2: symbols, knowledge, issues, diagnoses + dev_jobs V2 fields
CREATE TABLE IF NOT EXISTS project_symbols (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL DEFAULT 0,
  symbol_name VARCHAR(190) NOT NULL DEFAULT '',
  symbol_type VARCHAR(20) NOT NULL DEFAULT 'CLASS',
  file_path VARCHAR(500) NOT NULL DEFAULT '',
  start_line INT NOT NULL DEFAULT 0,
  end_line INT NOT NULL DEFAULT 0,
  parent_symbol VARCHAR(190) NOT NULL DEFAULT '',
  module VARCHAR(40) NOT NULL DEFAULT '',
  language VARCHAR(10) NOT NULL DEFAULT 'php',
  signature VARCHAR(500) NOT NULL DEFAULT '',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_brain_symbol (project_id, symbol_type, symbol_name, file_path(255)),
  KEY idx_brain_search (project_id, symbol_name),
  KEY idx_brain_file (project_id, file_path(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_index_state (
  project_id INT PRIMARY KEY,
  head_commit VARCHAR(80) NOT NULL DEFAULT '',
  files_indexed INT NOT NULL DEFAULT 0,
  symbols_count INT NOT NULL DEFAULT 0,
  last_full_at DATETIME NULL,
  last_incremental_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_knowledge (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL DEFAULT 0,
  ktype VARCHAR(30) NOT NULL DEFAULT 'LESSON_LEARNED',
  title VARCHAR(190) NOT NULL DEFAULT '',
  body TEXT NOT NULL,
  source VARCHAR(40) NOT NULL DEFAULT '',
  source_id VARCHAR(64) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_know_proj (project_id, ktype)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS known_issues (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL DEFAULT 0,
  signature VARCHAR(120) NOT NULL DEFAULT '',
  symptoms VARCHAR(500) NOT NULL DEFAULT '',
  module VARCHAR(40) NOT NULL DEFAULT '',
  root_cause VARCHAR(500) NOT NULL DEFAULT '',
  fix_dev_job VARCHAR(20) NOT NULL DEFAULT '',
  regression_test VARCHAR(255) NOT NULL DEFAULT '',
  resolved_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_issue_sig (project_id, signature)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ai_diagnoses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  diag_code VARCHAR(20) NOT NULL DEFAULT '',
  project_id INT NOT NULL DEFAULT 0,
  problem TEXT NOT NULL,
  runtime_snapshot TEXT NULL,
  evidence TEXT NULL,
  root_cause VARCHAR(1000) NOT NULL DEFAULT '',
  confidence VARCHAR(10) NOT NULL DEFAULT 'LOW',
  affected TEXT NULL,
  recommended_fix TEXT NULL,
  test_plan TEXT NULL,
  dev_job_code VARCHAR(20) NOT NULL DEFAULT '',
  source VARCHAR(20) NOT NULL DEFAULT 'TELEGRAM',
  requested_by VARCHAR(64) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_diag_code (diag_code),
  KEY idx_diag_proj (project_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE dev_jobs
  ADD COLUMN planning_summary TEXT NULL,
  ADD COLUMN impact_report TEXT NULL,
  ADD COLUMN acceptance_criteria TEXT NULL,
  ADD COLUMN risk_level VARCHAR(15) NOT NULL DEFAULT '',
  ADD COLUMN review_result TEXT NULL,
  ADD COLUMN verification_result TEXT NULL,
  ADD COLUMN expected_files TEXT NULL,
  ADD COLUMN fix_iterations INT NOT NULL DEFAULT 0;
