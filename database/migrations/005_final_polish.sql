-- Final polish: audit logs
CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED DEFAULT NULL,
  user_name VARCHAR(120) DEFAULT NULL,
  role VARCHAR(40) DEFAULT NULL,
  action VARCHAR(80) NOT NULL,
  module VARCHAR(60) NOT NULL DEFAULT 'system',
  details TEXT,
  ip_address VARCHAR(45) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_created (created_at),
  INDEX idx_audit_module (module),
  INDEX idx_audit_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
