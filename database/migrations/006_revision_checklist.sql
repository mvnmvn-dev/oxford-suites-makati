-- Revision checklist: MFA, reservation times/limits, order windows, payment/discount IDs
-- Run: C:\xampp\mysql\bin\mysql.exe -u root oxford_rms < database/migrations/006_revision_checklist.sql
USE oxford_rms;

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS totp_secret VARCHAR(64) DEFAULT NULL AFTER manager_pin_hash,
  ADD COLUMN IF NOT EXISTS totp_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER totp_secret;

ALTER TABLE reservations
  ADD COLUMN IF NOT EXISTS serve_time TIME DEFAULT NULL AFTER reservation_time,
  ADD COLUMN IF NOT EXISTS cancel_reason VARCHAR(255) DEFAULT NULL AFTER notes;

ALTER TABLE orders
  ADD COLUMN IF NOT EXISTS order_window ENUM('standard','advance','rush') NOT NULL DEFAULT 'standard' AFTER source,
  ADD COLUMN IF NOT EXISTS scheduled_for DATETIME DEFAULT NULL AFTER order_window,
  ADD COLUMN IF NOT EXISTS cancel_reason VARCHAR(255) DEFAULT NULL AFTER notes,
  ADD COLUMN IF NOT EXISTS discount_id_number VARCHAR(64) DEFAULT NULL AFTER discount_code;

ALTER TABLE payments
  ADD COLUMN IF NOT EXISTS gateway_ref VARCHAR(64) DEFAULT NULL AFTER reference_no;
