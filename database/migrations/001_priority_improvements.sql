-- Oxford RMS Phase 1 additive migration.
-- Run once on an existing oxford_rms database. Fresh installs get this table from database/init.sql.
USE oxford_rms;

CREATE TABLE IF NOT EXISTS service_requests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  table_id INT UNSIGNED DEFAULT NULL,
  customer_id INT UNSIGNED DEFAULT NULL,
  request_type ENUM('water','utensils','follow_up_order','bill','assistance') NOT NULL,
  note VARCHAR(255) DEFAULT NULL,
  status ENUM('open','acknowledged','completed','cancelled') NOT NULL DEFAULT 'open',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  acknowledged_at TIMESTAMP NULL DEFAULT NULL,
  completed_at TIMESTAMP NULL DEFAULT NULL,
  INDEX idx_service_request_status (status, created_at),
  CONSTRAINT fk_sr_table FOREIGN KEY (table_id) REFERENCES dining_tables(id) ON DELETE SET NULL,
  CONSTRAINT fk_sr_customer FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
