-- migration_activity_advanced.sql — Custom cycle + random interval + custom tabs (mo rong, giu config cu).
ALTER TABLE activity_configs
  ADD COLUMN interval_mode VARCHAR(15) NOT NULL DEFAULT 'FIXED',
  ADD COLUMN random_min INT NOT NULL DEFAULT 30,
  ADD COLUMN random_max INT NOT NULL DEFAULT 90,
  ADD COLUMN scheduled_tabs_enabled TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN tab_source_mode VARCHAR(15) NOT NULL DEFAULT 'BOTH',
  ADD COLUMN tab_selection_mode VARCHAR(15) NOT NULL DEFAULT 'RANDOM',
  ADD COLUMN tabs_min INT NOT NULL DEFAULT 1,
  ADD COLUMN tabs_max INT NOT NULL DEFAULT 2,
  ADD COLUMN max_automation_tabs INT NOT NULL DEFAULT 2,
  ADD COLUMN url_cooldown_minutes INT NOT NULL DEFAULT 60,
  ADD COLUMN custom_tabs_json TEXT NULL;
