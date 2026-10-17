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
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  leased_at DATETIME NULL,
  finished_at DATETIME NULL,
  result_json LONGTEXT NULL,
  error_text TEXT NULL,
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
