-- migration_activity_search_visit.sql — Search+Visit (mo rong, khong tao song song).
ALTER TABLE activity_websites
  ADD COLUMN min_reuse_minutes INT NOT NULL DEFAULT 60,
  ADD COLUMN usage_today DATE NULL,
  ADD COLUMN usage_today_count INT NOT NULL DEFAULT 0,
  ADD COLUMN total_usage INT NOT NULL DEFAULT 0;
ALTER TABLE activity_search_pool
  ADD COLUMN min_reuse_minutes INT NOT NULL DEFAULT 120;
ALTER TABLE activity_configs
  ADD COLUMN search_behavior VARCHAR(15) NOT NULL DEFAULT 'SEARCH_VISIT',
  ADD COLUMN max_result_depth INT NOT NULL DEFAULT 10;
