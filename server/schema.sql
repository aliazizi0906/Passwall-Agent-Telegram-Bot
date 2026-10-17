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
  memory_json LONGTEXT NULL,
  passwall_running TINYINT(1) NULL,
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
