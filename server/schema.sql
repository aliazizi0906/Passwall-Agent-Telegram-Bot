CREATE TABLE IF NOT EXISTS pw_devices (
  device_id VARCHAR(64) PRIMARY KEY,
  last_seen DATETIME NULL,
  last_ip VARCHAR(64) NULL,
  agent_version VARCHAR(32) NULL,
  hostname VARCHAR(128) NULL,
  meta_json LONGTEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pw_commands (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  device_id VARCHAR(64) NOT NULL,
  action VARCHAR(96) NOT NULL,
  params_json LONGTEXT NOT NULL,
  chat_id BIGINT NULL,
  user_id BIGINT NULL,
  status ENUM('queued','leased','done','failed') NOT NULL DEFAULT 'queued',
  lease_token CHAR(32) NULL,
  idempotency_key CHAR(32) NULL,
  attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts INT UNSIGNED NOT NULL DEFAULT 3,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  leased_at DATETIME NULL,
  expires_at DATETIME NULL,
  finished_at DATETIME NULL,
  result_json LONGTEXT NULL,
  error_text TEXT NULL,
  UNIQUE KEY ux_device_idempotency (device_id,idempotency_key),
  INDEX idx_device_status (device_id,status,id),
  INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pw_sessions (
  chat_id BIGINT NOT NULL,
  user_id BIGINT NOT NULL,
  device_id VARCHAR(64) NOT NULL,
  state VARCHAR(64) NOT NULL,
  context_json LONGTEXT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (chat_id,user_id,device_id),
  INDEX idx_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pw_metrics (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  device_id VARCHAR(64) NOT NULL,
  captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  uptime_seconds BIGINT NULL,
  load1 DOUBLE NULL,
  cpu_percent DOUBLE NULL,
  memory_json LONGTEXT NULL,
  memory_used_percent DOUBLE NULL,
  passwall_running TINYINT(1) NULL,
  wan_up TINYINT(1) NULL,
  wan_ip VARCHAR(64) NULL,
  rx_bytes BIGINT UNSIGNED NULL,
  tx_bytes BIGINT UNSIGNED NULL,
  INDEX idx_metric_device_time (device_id,captured_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pw_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  device_id VARCHAR(64) NOT NULL,
  kind VARCHAR(96) NOT NULL,
  data_json LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_event_device_time (device_id,created_at),
  INDEX idx_event_kind (kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pw_alert_state (
  device_id VARCHAR(64) PRIMARY KEY,
  offline_alerted_at DATETIME NULL,
  recovered_alerted_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE IF NOT EXISTS pw_provision_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  token_hash CHAR(64) NOT NULL UNIQUE,
  device_id VARCHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_provision_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE IF NOT EXISTS pw_device_groups (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(96) NOT NULL UNIQUE,
  description VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pw_device_registry (
  device_id VARCHAR(64) PRIMARY KEY,
  display_name VARCHAR(128) NOT NULL,
  secret_sealed TEXT NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  group_id BIGINT UNSIGNED NULL,
  location VARCHAR(128) NULL,
  tags_json LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_registry_group (group_id),
  INDEX idx_registry_enabled (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pw_users (
  telegram_user_id BIGINT PRIMARY KEY,
  role ENUM('owner','admin','operator','viewer') NOT NULL DEFAULT 'viewer',
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  device_scope_json LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pw_user_state (
  telegram_user_id BIGINT PRIMARY KEY,
  active_device_id VARCHAR(64) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pw_audit_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  command_id BIGINT UNSIGNED NULL,
  device_id VARCHAR(64) NOT NULL,
  telegram_user_id BIGINT NULL,
  source VARCHAR(32) NOT NULL DEFAULT 'telegram',
  action VARCHAR(96) NOT NULL,
  status VARCHAR(24) NOT NULL,
  params_redacted_json LONGTEXT NOT NULL,
  result_summary_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  UNIQUE KEY ux_audit_command (command_id),
  INDEX idx_audit_device_time (device_id,created_at),
  INDEX idx_audit_user_time (telegram_user_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pw_config_changes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  command_id BIGINT UNSIGNED NOT NULL UNIQUE,
  device_id VARCHAR(64) NOT NULL,
  telegram_user_id BIGINT NULL,
  action VARCHAR(96) NOT NULL,
  packages_json LONGTEXT NOT NULL,
  diff_json LONGTEXT NOT NULL,
  before_sealed LONGTEXT NOT NULL,
  before_hashes_json LONGTEXT NOT NULL,
  after_hashes_json LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_change_device_time (device_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pw_alert_rules (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  device_id VARCHAR(64) NOT NULL,
  metric VARCHAR(48) NOT NULL,
  operator VARCHAR(4) NOT NULL,
  threshold DOUBLE NOT NULL,
  duration_seconds INT UNSIGNED NOT NULL DEFAULT 0,
  cooldown_seconds INT UNSIGNED NOT NULL DEFAULT 900,
  notify_chat_id BIGINT NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  breach_since DATETIME NULL,
  last_triggered_at DATETIME NULL,
  created_by BIGINT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_alert_device_enabled (device_id,enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pw_maintenance (
  device_id VARCHAR(64) PRIMARY KEY,
  until_at DATETIME NOT NULL,
  reason VARCHAR(255) NULL,
  created_by BIGINT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pw_schedules (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  device_id VARCHAR(64) NOT NULL,
  name VARCHAR(128) NOT NULL,
  action VARCHAR(96) NOT NULL,
  params_json LONGTEXT NOT NULL,
  schedule_type ENUM('one_time','interval','daily','weekly') NOT NULL,
  schedule_spec_json LONGTEXT NOT NULL,
  next_run_at DATETIME NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT NULL,
  last_run_at DATETIME NULL,
  last_command_id BIGINT UNSIGNED NULL,
  last_error TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_schedule_due (enabled,next_run_at),
  INDEX idx_schedule_device (device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
