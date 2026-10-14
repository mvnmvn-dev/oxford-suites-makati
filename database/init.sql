-- Oxford Suites Restaurant Management System
-- MySQL schema + seed data (XAMPP-friendly single database)
--
-- Fresh install on any device: import THIS file only (database/init.sql).
-- It already includes everything from migrations 001â€“004.
-- Migrations under database/migrations/ are only for upgrading an OLD existing DB.
--
-- Database-per-service can be split later via Docker; tables are service-prefixed.

CREATE DATABASE IF NOT EXISTS oxford_rms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE oxford_rms;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- USER SERVICE
-- ============================================================
DROP TABLE IF EXISTS users;
CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(160) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','kitchen','cashier','customer') NOT NULL DEFAULT 'customer',
  avatar_url VARCHAR(255) DEFAULT NULL,
  phone VARCHAR(40) DEFAULT NULL,
  manager_pin_hash VARCHAR(255) DEFAULT NULL,
  totp_secret VARCHAR(64) DEFAULT NULL,
  totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- MENU SERVICE
-- ============================================================
DROP TABLE IF EXISTS menu_categories;
CREATE TABLE menu_categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  slug VARCHAR(80) NOT NULL UNIQUE,
  description VARCHAR(255) DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  hero_image VARCHAR(255) DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

DROP TABLE IF EXISTS menu_items;
CREATE TABLE menu_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  description TEXT,
  price DECIMAL(10,2) NOT NULL,
  image_url VARCHAR(255) DEFAULT NULL,
  is_available TINYINT(1) NOT NULL DEFAULT 1,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  is_featured TINYINT(1) NOT NULL DEFAULT 0,
  prep_minutes INT DEFAULT 15,
  tags VARCHAR(255) DEFAULT NULL,
  station ENUM('grill','drinks','dessert','general') NOT NULL DEFAULT 'general',
  calories INT UNSIGNED DEFAULT NULL,
  allergens VARCHAR(255) DEFAULT NULL,
  ingredients TEXT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_menu_cat FOREIGN KEY (category_id) REFERENCES menu_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB;

DROP TABLE IF EXISTS menu_item_options;
CREATE TABLE menu_item_options (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  menu_item_id INT UNSIGNED DEFAULT NULL,
  option_group VARCHAR(60) NOT NULL,
  option_key VARCHAR(60) NOT NULL,
  label VARCHAR(120) NOT NULL,
  price_delta DECIMAL(10,2) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  INDEX idx_mio_item (menu_item_id),
  CONSTRAINT fk_mio_menu FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- TABLE SERVICE
-- ============================================================
DROP TABLE IF EXISTS dining_tables;
CREATE TABLE dining_tables (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  table_number VARCHAR(20) NOT NULL UNIQUE,
  capacity INT NOT NULL DEFAULT 4,
  zone VARCHAR(60) DEFAULT 'Main Dining',
  seating_type ENUM('indoor','outdoor','window') NOT NULL DEFAULT 'indoor',
  status ENUM('available','occupied','reserved','cleaning') NOT NULL DEFAULT 'available',
  qr_token VARCHAR(64) NOT NULL UNIQUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- RESERVATION SERVICE
-- ============================================================
DROP TABLE IF EXISTS reservations;
CREATE TABLE reservations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED DEFAULT NULL,
  customer_name VARCHAR(120) NOT NULL,
  customer_email VARCHAR(160) DEFAULT NULL,
  customer_phone VARCHAR(40) DEFAULT NULL,
  party_size INT NOT NULL,
  table_id INT UNSIGNED DEFAULT NULL,
  reservation_date DATE NOT NULL,
  reservation_time TIME NOT NULL,
  serve_time TIME DEFAULT NULL,
  status ENUM('pending','confirmed','seated','completed','cancelled','no_show') NOT NULL DEFAULT 'pending',
  notes TEXT,
  cancel_reason VARCHAR(255) DEFAULT NULL,
  seating_pref ENUM('any','indoor','outdoor','window') NOT NULL DEFAULT 'any',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_res_date (reservation_date),
  INDEX idx_res_customer (customer_id),
  CONSTRAINT fk_res_table FOREIGN KEY (table_id) REFERENCES dining_tables(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- ORDER SERVICE
-- ============================================================
DROP TABLE IF EXISTS orders;
CREATE TABLE orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_number VARCHAR(32) NOT NULL UNIQUE,
  table_id INT UNSIGNED DEFAULT NULL,
  customer_id INT UNSIGNED DEFAULT NULL,
  customer_name VARCHAR(120) DEFAULT 'Guest',
  status ENUM('pending','confirmed','preparing','ready','served','paid','cancelled') NOT NULL DEFAULT 'pending',
  source ENUM('qr','pos','online') NOT NULL DEFAULT 'qr',
  order_window ENUM('standard','advance','rush') NOT NULL DEFAULT 'standard',
  scheduled_for DATETIME DEFAULT NULL,
  subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,
  tax DECIMAL(10,2) NOT NULL DEFAULT 0,
  discount DECIMAL(10,2) NOT NULL DEFAULT 0,
  discount_code VARCHAR(60) DEFAULT NULL,
  discount_id_number VARCHAR(64) DEFAULT NULL,
  vat_exempt TINYINT(1) NOT NULL DEFAULT 0,
  total DECIMAL(10,2) NOT NULL DEFAULT 0,
  notes TEXT,
  cancel_reason VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_order_status (status),
  CONSTRAINT fk_order_table FOREIGN KEY (table_id) REFERENCES dining_tables(id) ON DELETE SET NULL
) ENGINE=InnoDB;

DROP TABLE IF EXISTS order_items;
CREATE TABLE order_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  menu_item_id INT UNSIGNED NOT NULL,
  item_name VARCHAR(120) NOT NULL,
  unit_price DECIMAL(10,2) NOT NULL,
  quantity INT NOT NULL DEFAULT 1,
  notes TEXT DEFAULT NULL,
  status ENUM('queued','preparing','ready','served','cancelled') NOT NULL DEFAULT 'queued',
  CONSTRAINT fk_oi_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_oi_menu FOREIGN KEY (menu_item_id) REFERENCES menu_items(id)
) ENGINE=InnoDB;

-- ============================================================
-- KITCHEN SERVICE (ticket view derived from orders; tickets table for KOT state)
-- ============================================================
DROP TABLE IF EXISTS kitchen_tickets;
CREATE TABLE kitchen_tickets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  order_number VARCHAR(32) NOT NULL,
  table_label VARCHAR(40) DEFAULT NULL,
  status ENUM('new','in_progress','ready','bumped') NOT NULL DEFAULT 'new',
  priority TINYINT NOT NULL DEFAULT 0,
  started_at TIMESTAMP NULL DEFAULT NULL,
  ready_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ticket_order (order_id),
  CONSTRAINT fk_kt_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- PAYMENT SERVICE
-- ============================================================
DROP TABLE IF EXISTS payments;
CREATE TABLE payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  method ENUM('cash','card','gcash','maya','other') NOT NULL DEFAULT 'cash',
  status ENUM('pending','completed','failed','refunded') NOT NULL DEFAULT 'pending',
  reference_no VARCHAR(64) DEFAULT NULL,
  gateway_ref VARCHAR(64) DEFAULT NULL,
  cashier_id INT UNSIGNED DEFAULT NULL,
  receipt_no VARCHAR(32) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pay_order FOREIGN KEY (order_id) REFERENCES orders(id)
) ENGINE=InnoDB;

DROP TABLE IF EXISTS bill_splits;
CREATE TABLE bill_splits (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  split_label VARCHAR(80) NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  method ENUM('cash','card','gcash','maya','other') NOT NULL DEFAULT 'cash',
  payment_id INT UNSIGNED DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_bill_split_order (order_id),
  CONSTRAINT fk_bs_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB;

DROP TABLE IF EXISTS feedback;
CREATE TABLE feedback (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL UNIQUE,
  customer_id INT UNSIGNED DEFAULT NULL,
  food_quality TINYINT UNSIGNED NOT NULL,
  service TINYINT UNSIGNED NOT NULL,
  cleanliness TINYINT UNSIGNED NOT NULL,
  overall TINYINT UNSIGNED NOT NULL,
  comment TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_feedback_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_feedback_customer FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

DROP TABLE IF EXISTS promotions;
CREATE TABLE promotions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(60) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  type ENUM('percentage','fixed','buy_one_take_one','birthday') NOT NULL DEFAULT 'percentage',
  value DECIMAL(10,2) NOT NULL DEFAULT 0,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

DROP TABLE IF EXISTS customer_favorites;
CREATE TABLE customer_favorites (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  menu_item_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_fav_customer_item (customer_id, menu_item_id),
  INDEX idx_fav_customer (customer_id),
  CONSTRAINT fk_fav_customer FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_fav_menu FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE
) ENGINE=InnoDB;

DROP TABLE IF EXISTS loyalty_accounts;
CREATE TABLE loyalty_accounts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL UNIQUE,
  points INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_loyalty_customer FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

DROP TABLE IF EXISTS loyalty_transactions;
CREATE TABLE loyalty_transactions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  order_id INT UNSIGNED DEFAULT NULL,
  points INT NOT NULL,
  reason VARCHAR(120) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_loyalty_tx_customer FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_loyalty_tx_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL
) ENGINE=InnoDB;

DROP TABLE IF EXISTS loyalty_vouchers;
CREATE TABLE loyalty_vouchers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  code VARCHAR(40) NOT NULL UNIQUE,
  points_spent INT NOT NULL,
  discount_value DECIMAL(10,2) NOT NULL,
  status ENUM('open','redeemed','expired') NOT NULL DEFAULT 'open',
  order_id INT UNSIGNED DEFAULT NULL,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_lv_customer FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_lv_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- INVENTORY SERVICE
-- ============================================================
DROP TABLE IF EXISTS inventory_items;
CREATE TABLE inventory_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  sku VARCHAR(60) DEFAULT NULL,
  unit VARCHAR(30) NOT NULL DEFAULT 'pcs',
  quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
  reorder_level DECIMAL(12,2) NOT NULL DEFAULT 10,
  cost_per_unit DECIMAL(10,2) DEFAULT 0,
  category VARCHAR(60) DEFAULT 'General',
  expires_at DATE DEFAULT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

DROP TABLE IF EXISTS recipe_ingredients;
CREATE TABLE recipe_ingredients (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  menu_item_id INT UNSIGNED NOT NULL,
  inventory_item_id INT UNSIGNED NOT NULL,
  quantity_used DECIMAL(12,3) NOT NULL,
  CONSTRAINT fk_ri_menu FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_ri_inv FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON DELETE CASCADE
) ENGINE=InnoDB;

DROP TABLE IF EXISTS inventory_movements;
CREATE TABLE inventory_movements (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  inventory_item_id INT UNSIGNED NOT NULL,
  change_qty DECIMAL(12,2) NOT NULL,
  reason VARCHAR(120) NOT NULL,
  reference_type VARCHAR(40) DEFAULT NULL,
  reference_id INT UNSIGNED DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_im_inv FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id)
) ENGINE=InnoDB;

DROP TABLE IF EXISTS inventory_waste;
CREATE TABLE inventory_waste (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  inventory_item_id INT UNSIGNED NOT NULL,
  quantity DECIMAL(12,2) NOT NULL,
  reason VARCHAR(120) NOT NULL,
  expires_at DATE DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_waste_inv FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON DELETE CASCADE
) ENGINE=InnoDB;

DROP TABLE IF EXISTS sales_daily;
CREATE TABLE sales_daily (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sale_date DATE NOT NULL,
  menu_item_id INT UNSIGNED NOT NULL,
  quantity_sold INT NOT NULL DEFAULT 0,
  revenue DECIMAL(12,2) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_sale_day_item (sale_date, menu_item_id)
) ENGINE=InnoDB;

-- ============================================================
-- NOTIFICATION SERVICE
-- ============================================================
DROP TABLE IF EXISTS notifications;
CREATE TABLE notifications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED DEFAULT NULL,
  role_target VARCHAR(40) DEFAULT NULL,
  title VARCHAR(160) NOT NULL,
  body TEXT,
  type VARCHAR(40) DEFAULT 'info',
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- SERVICE REQUESTS (customer call-waiter / bill workflow)
-- ============================================================
DROP TABLE IF EXISTS service_requests;
CREATE TABLE service_requests (
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

-- ============================================================
-- EVENT LOG (simple event bus persistence)
-- ============================================================
DROP TABLE IF EXISTS audit_logs;
CREATE TABLE audit_logs (
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
) ENGINE=InnoDB;

DROP TABLE IF EXISTS event_log;
CREATE TABLE event_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_name VARCHAR(80) NOT NULL,
  payload JSON NOT NULL,
  processed TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_event_name (event_name),
  INDEX idx_processed (processed)
) ENGINE=InnoDB;

-- ============================================================
-- SECURITY SUITE
-- ============================================================
DROP TABLE IF EXISTS manager_approvals;
CREATE TABLE manager_approvals (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  manager_id INT UNSIGNED NOT NULL,
  requester_id INT UNSIGNED DEFAULT NULL,
  action VARCHAR(80) NOT NULL,
  reference_id INT UNSIGNED DEFAULT NULL,
  ip_address VARCHAR(45) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_manager_approval_created (created_at)
) ENGINE=InnoDB;

DROP TABLE IF EXISTS trusted_devices;
CREATE TABLE trusted_devices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  device_id VARCHAR(255) NOT NULL,
  device_name VARCHAR(100) DEFAULT NULL,
  browser VARCHAR(100) DEFAULT NULL,
  ip_address VARCHAR(45) DEFAULT NULL,
  last_login TIMESTAMP NULL DEFAULT NULL,
  expires_at TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY uq_trusted_device (user_id, device_id),
  INDEX idx_trusted_expiry (expires_at)
) ENGINE=InnoDB;

DROP TABLE IF EXISTS table_sessions;
CREATE TABLE table_sessions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_token CHAR(64) NOT NULL UNIQUE,
  table_number VARCHAR(20) NOT NULL,
  reservation_id INT UNSIGNED DEFAULT NULL,
  customer_name VARCHAR(100) DEFAULT NULL,
  expires_at TIMESTAMP NOT NULL,
  status ENUM('ACTIVE','EXPIRED','CLOSED') NOT NULL DEFAULT 'ACTIVE',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_table_session_status (status, expires_at)
) ENGINE=InnoDB;

DROP TABLE IF EXISTS login_attempts;
CREATE TABLE login_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED DEFAULT NULL,
  email VARCHAR(160) DEFAULT NULL,
  ip_address VARCHAR(45) NOT NULL,
  attempts INT NOT NULL DEFAULT 0,
  last_attempt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  locked_until TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY uq_login_identity (email, ip_address)
) ENGINE=InnoDB;

DROP TABLE IF EXISTS security_events;
CREATE TABLE security_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED DEFAULT NULL,
  event_type VARCHAR(80) NOT NULL,
  severity ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
  details TEXT,
  ip_address VARCHAR(45) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_security_event_created (created_at),
  INDEX idx_security_event_type (event_type)
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- SEED DATA
-- ============================================================
-- Password for all demo users: password123
-- hash = password_hash('password123', PASSWORD_DEFAULT)
-- Demo password for all users: password123
INSERT INTO users (name, email, password_hash, role, phone) VALUES
('Elena Vargas', 'admin@oxfordsuites.ph', '$2y$10$GBfgEok68JIiAi20go3yOOe4EIpbcLBiK3ScQgzfVFqtdXwVh4UUq', 'admin', '+63 917 000 0001'),
('Chef Marco Reyes', 'kitchen@oxfordsuites.ph', '$2y$10$GBfgEok68JIiAi20go3yOOe4EIpbcLBiK3ScQgzfVFqtdXwVh4UUq', 'kitchen', '+63 917 000 0002'),
('Sofia Lim', 'cashier@oxfordsuites.ph', '$2y$10$GBfgEok68JIiAi20go3yOOe4EIpbcLBiK3ScQgzfVFqtdXwVh4UUq', 'cashier', '+63 917 000 0003'),
('Guest Patron', 'guest@example.com', '$2y$10$GBfgEok68JIiAi20go3yOOe4EIpbcLBiK3ScQgzfVFqtdXwVh4UUq', 'customer', '+63 917 000 0099');

INSERT INTO menu_categories (name, slug, description, sort_order, hero_image) VALUES
('Starters', 'starters', 'Light beginnings from the suite kitchen', 1, NULL),
('Mains', 'mains', 'Signature plates of the dining room', 2, NULL),
('From the Grill', 'grill', 'Wood-fired and charcoal finishes', 3, NULL),
('Desserts', 'desserts', 'Sweet closes to the evening', 4, NULL),
('Bar & Lounge', 'bar', 'Cocktails and cellar selections', 5, NULL);

INSERT INTO menu_items (category_id, name, description, price, image_url, is_available, is_featured, prep_minutes, tags, station, calories, allergens, ingredients) VALUES
(1, 'Suite Garden Salad', 'Heirloom greens, citrus vinaigrette, toasted seeds', 320.00, '/oxford-rms/public/assets/img/menu/dish-1.jpg', 1, 0, 8, 'vegetarian', 'general', 180, 'seeds', 'heirloom greens, citrus, toasted seeds'),
(1, 'Truffle Mushroom Soup', 'Cream of forest mushrooms, white truffle oil', 380.00, '/oxford-rms/public/assets/img/menu/dish-2.jpg', 1, 1, 10, 'signature', 'general', 320, 'dairy', 'mushrooms, cream, truffle oil'),
(1, 'Prawn Cocktail', 'Chilled prawns, suite cocktail sauce', 520.00, '/oxford-rms/public/assets/img/menu/dish-3.jpg', 1, 0, 12, 'seafood', 'general', 240, 'shellfish', 'prawns, cocktail sauce'),
(2, 'Oxford Short Rib', 'Slow-braised short rib, bone marrow jus, root vegetables', 980.00, '/oxford-rms/public/assets/img/menu/dish-4.jpg', 1, 1, 25, 'signature,beef', 'grill', 780, NULL, 'short rib, bone marrow, root vegetables'),
(2, 'Pan-Seared Sea Bass', 'Crispy skin sea bass, saffron beurre blanc', 890.00, '/oxford-rms/public/assets/img/menu/dish-5.jpg', 1, 1, 20, 'seafood', 'grill', 520, 'fish,dairy', 'sea bass, saffron, butter'),
(2, 'Suite Chicken Supreme', 'Herb-roasted free-range chicken, pan jus', 650.00, '/oxford-rms/public/assets/img/menu/dish-6.jpg', 1, 0, 22, 'poultry', 'grill', 610, NULL, 'chicken, herbs, pan jus'),
(2, 'Wild Mushroom Risotto', 'Arborio rice, porcini, aged Parmesan', 580.00, '/oxford-rms/public/assets/img/menu/dish-7.jpg', 1, 0, 18, 'vegetarian', 'general', 540, 'dairy', 'arborio rice, porcini, Parmesan'),
(3, 'Wagyu Ribeye 300g', 'Grade A5, smoked salt, bone marrow butter', 2850.00, '/oxford-rms/public/assets/img/menu/dish-8.jpg', 1, 1, 18, 'premium,beef', 'grill', 920, 'dairy', 'wagyu, smoked salt, marrow butter'),
(3, 'Lamb Chops', 'Charred Australian lamb, mint gremolata', 1250.00, '/oxford-rms/public/assets/img/menu/dish-9.jpg', 1, 0, 16, 'lamb', 'grill', 680, NULL, 'lamb, mint, garlic, lemon'),
(4, 'Dark Chocolate Fondant', 'Molten center, vanilla bean ice cream', 420.00, '/oxford-rms/public/assets/img/menu/dish-10.jpg', 1, 1, 14, 'dessert', 'dessert', 480, 'dairy,gluten', 'dark chocolate, cream, vanilla ice cream'),
(4, 'Yuzu Cheesecake', 'Light cheesecake, yuzu curd, biscuit crumb', 380.00, '/oxford-rms/public/assets/img/menu/dish-11.jpg', 1, 0, 5, 'dessert', 'dessert', 390, 'dairy,gluten', 'cream cheese, yuzu, biscuit'),
(5, 'Suite Old Fashioned', 'Bourbon, demerara, orange bitters', 480.00, '/oxford-rms/public/assets/img/menu/dish-12.jpg', 1, 0, 5, 'cocktail', 'drinks', 180, NULL, 'bourbon, demerara, bitters'),
(5, 'Makati Spritz', 'Prosecco, aperitivo, citrus', 420.00, '/oxford-rms/public/assets/img/menu/dish-13.jpg', 1, 0, 4, 'cocktail', 'drinks', 160, NULL, 'prosecco, aperitivo, citrus');

INSERT INTO menu_item_options (menu_item_id, option_group, option_key, label, price_delta, sort_order) VALUES
(NULL, 'spice_level', 'mild', 'Mild', 0, 1),
(NULL, 'spice_level', 'medium', 'Medium', 0, 2),
(NULL, 'spice_level', 'hot', 'Hot / spicy', 0, 3),
(NULL, 'cooking', 'regular', 'Regular', 0, 1),
(NULL, 'cooking', 'well_done', 'Well done', 0, 2),
(NULL, 'cooking', 'medium_rare', 'Medium rare', 0, 3),
(NULL, 'extras', 'extra_sauce', 'Extra sauce', 40, 1),
(NULL, 'extras', 'extra_rice', 'Extra rice', 50, 2),
(NULL, 'remove', 'no_onion', 'No onion', 0, 1),
(NULL, 'remove', 'no_garlic', 'No garlic', 0, 2);

INSERT INTO dining_tables (table_number, capacity, zone, seating_type, status, qr_token) VALUES
('T1', 2, 'Window Bay', 'window', 'available', 'qr_t1_oxford_suite'),
('T2', 2, 'Window Bay', 'window', 'available', 'qr_t2_oxford_suite'),
('T3', 4, 'Main Dining', 'indoor', 'available', 'qr_t3_oxford_suite'),
('T4', 4, 'Main Dining', 'indoor', 'occupied', 'qr_t4_oxford_suite'),
('T5', 4, 'Main Dining', 'indoor', 'available', 'qr_t5_oxford_suite'),
('T6', 6, 'Main Dining', 'indoor', 'reserved', 'qr_t6_oxford_suite'),
('T7', 8, 'Private Suite', 'indoor', 'available', 'qr_t7_oxford_suite'),
('T8', 10, 'Private Suite', 'indoor', 'available', 'qr_t8_oxford_suite'),
('T9', 4, 'Lounge', 'outdoor', 'available', 'qr_t9_oxford_suite'),
('T10', 2, 'Lounge', 'outdoor', 'cleaning', 'qr_t10_oxford_suite'),
('T11', 4, 'Terrace', 'outdoor', 'available', 'qr_t11_oxford_suite'),
('T12', 6, 'Terrace', 'outdoor', 'available', 'qr_t12_oxford_suite');

INSERT INTO reservations (customer_name, customer_email, customer_phone, party_size, table_id, reservation_date, reservation_time, status, notes) VALUES
('Aliana Custodio', 'aliana@email.com', '+63 917 111 2222', 4, 6, CURDATE(), '19:00:00', 'confirmed', 'Anniversary â€” request quiet corner'),
('James Park', 'james@email.com', '+63 918 333 4444', 2, 1, CURDATE(), '20:00:00', 'pending', NULL),
('Maria Santos', 'maria@email.com', '+63 919 555 6666', 8, 7, DATE_ADD(CURDATE(), INTERVAL 1 DAY), '18:30:00', 'confirmed', 'Private dining â€” birthday cake'),
('David Chen', 'david@email.com', '+63 920 777 8888', 4, 3, DATE_ADD(CURDATE(), INTERVAL 2 DAY), '19:30:00', 'confirmed', NULL),
('Sofia Reyes', 'sofia@email.com', '+63 921 999 0000', 6, 12, DATE_ADD(CURDATE(), INTERVAL 3 DAY), '12:00:00', 'pending', 'Business lunch');

INSERT INTO inventory_items (name, sku, unit, quantity, reorder_level, cost_per_unit, category, expires_at) VALUES
('Short Rib (kg)', 'MEAT-001', 'kg', 24.5, 8, 850.00, 'Meat', DATE_ADD(CURDATE(), INTERVAL 14 DAY)),
('Sea Bass Fillet', 'FISH-001', 'kg', 12.0, 5, 720.00, 'Seafood', DATE_ADD(CURDATE(), INTERVAL 7 DAY)),
('Wagyu Ribeye', 'MEAT-002', 'kg', 6.5, 3, 4500.00, 'Meat', DATE_ADD(CURDATE(), INTERVAL 10 DAY)),
('Arborio Rice', 'DRY-001', 'kg', 18.0, 5, 180.00, 'Dry Goods', NULL),
('Heavy Cream', 'DAIRY-001', 'L', 10.0, 3, 220.00, 'Dairy', DATE_ADD(CURDATE(), INTERVAL 3 DAY)),
('Heirloom Greens', 'PROD-001', 'kg', 8.0, 2, 150.00, 'Produce', DATE_ADD(CURDATE(), INTERVAL 3 DAY)),
('Dark Chocolate 70%', 'DRY-002', 'kg', 4.0, 1.5, 980.00, 'Dry Goods', NULL),
('Bourbon', 'BAR-001', 'bottle', 14, 4, 1800.00, 'Bar', NULL),
('Prosecco', 'BAR-002', 'bottle', 20, 6, 650.00, 'Bar', NULL),
('Free-range Chicken', 'MEAT-003', 'kg', 15.0, 5, 280.00, 'Meat', DATE_ADD(CURDATE(), INTERVAL 14 DAY)),
('Prawns', 'FISH-002', 'kg', 9.0, 2.5, 680.00, 'Seafood', DATE_ADD(CURDATE(), INTERVAL 7 DAY)),
('Lamb Rack', 'MEAT-004', 'kg', 10.0, 3, 920.00, 'Meat', DATE_ADD(CURDATE(), INTERVAL 12 DAY)),
('Cream Cheese', 'DAIRY-002', 'kg', 6.0, 1.5, 340.00, 'Dairy', DATE_ADD(CURDATE(), INTERVAL 3 DAY));

-- Complete the menu-to-inventory relationship for existing installations.
-- Existing seed rows are kept; missing pantry, produce, dairy, and bar ingredients are added.
INSERT INTO inventory_items (name, sku, unit, quantity, reorder_level, cost_per_unit, category)
SELECT * FROM (
  SELECT 'Citrus Juice', 'PROD-002', 'L', 8.0, 2.0, 120.00, 'Produce' UNION ALL
  SELECT 'Toasted Seeds', 'DRY-003', 'kg', 2.0, 0.5, 260.00, 'Dry Goods' UNION ALL
  SELECT 'Forest Mushrooms', 'PROD-003', 'kg', 8.0, 2.0, 360.00, 'Produce' UNION ALL
  SELECT 'Truffle Oil', 'PAN-001', 'L', 2.0, 0.5, 1800.00, 'Pantry' UNION ALL
  SELECT 'Cocktail Sauce', 'PAN-002', 'L', 4.0, 1.0, 220.00, 'Pantry' UNION ALL
  SELECT 'Bone Marrow', 'PAN-003', 'kg', 5.0, 1.0, 600.00, 'Meat' UNION ALL
  SELECT 'Root Vegetables', 'PROD-004', 'kg', 12.0, 3.0, 110.00, 'Produce' UNION ALL
  SELECT 'Saffron', 'DRY-004', 'kg', 0.5, 0.1, 18000.00, 'Dry Goods' UNION ALL
  SELECT 'Butter', 'DAIRY-003', 'kg', 6.0, 1.5, 360.00, 'Dairy' UNION ALL
  SELECT 'Fresh Herbs', 'PROD-005', 'kg', 3.0, 0.8, 280.00, 'Produce' UNION ALL
  SELECT 'Pan Jus', 'PAN-004', 'L', 4.0, 1.0, 180.00, 'Pantry' UNION ALL
  SELECT 'Porcini Mushrooms', 'PROD-006', 'kg', 4.0, 1.0, 900.00, 'Produce' UNION ALL
  SELECT 'Parmesan', 'DAIRY-004', 'kg', 4.0, 1.0, 780.00, 'Dairy' UNION ALL
  SELECT 'Smoked Salt', 'DRY-005', 'kg', 2.0, 0.5, 320.00, 'Dry Goods' UNION ALL
  SELECT 'Marrow Butter', 'DAIRY-005', 'kg', 3.0, 0.8, 900.00, 'Dairy' UNION ALL
  SELECT 'Mint', 'PROD-007', 'kg', 2.0, 0.5, 240.00, 'Produce' UNION ALL
  SELECT 'Garlic', 'PROD-008', 'kg', 3.0, 0.8, 180.00, 'Produce' UNION ALL
  SELECT 'Lemon', 'PROD-009', 'kg', 3.0, 0.8, 160.00, 'Produce' UNION ALL
  SELECT 'Vanilla Ice Cream', 'DAIRY-006', 'L', 8.0, 2.0, 300.00, 'Dairy' UNION ALL
  SELECT 'Yuzu', 'PROD-010', 'kg', 3.0, 0.8, 520.00, 'Produce' UNION ALL
  SELECT 'Biscuit Crumb', 'DRY-006', 'kg', 4.0, 1.0, 240.00, 'Dry Goods' UNION ALL
  SELECT 'Demerara Sugar', 'DRY-007', 'kg', 3.0, 0.8, 180.00, 'Dry Goods' UNION ALL
  SELECT 'Orange Bitters', 'BAR-003', 'bottle', 5.0, 1.0, 450.00, 'Bar' UNION ALL
  SELECT 'Aperitivo', 'BAR-004', 'bottle', 8.0, 2.0, 720.00, 'Bar'
) AS missing
WHERE NOT EXISTS (SELECT 1 FROM inventory_items existing WHERE existing.sku = missing.sku);

INSERT INTO recipe_ingredients (menu_item_id, inventory_item_id, quantity_used) VALUES
(4, 1, 0.350),
(5, 2, 0.220),
(8, 3, 0.300),
(7, 4, 0.180),
(2, 5, 0.150),
(1, 6, 0.120),
(10, 7, 0.080),
(12, 8, 0.060),
(13, 9, 0.150),
(6, 10, 0.280),
(3, 11, 0.180),
(9, 12, 0.320),
(11, 13, 0.100);

-- Complete recipe components for fresh installs.
-- -- Add every recipe component once. Quantities are per menu serving.
INSERT INTO recipe_ingredients (menu_item_id, inventory_item_id, quantity_used)
SELECT m.id, i.id, map.quantity_used
FROM (
  SELECT 'Suite Garden Salad' AS menu_name, 'PROD-001' AS sku, 0.120 AS quantity_used UNION ALL
  SELECT 'Suite Garden Salad', 'PROD-002', 0.030 UNION ALL SELECT 'Suite Garden Salad', 'DRY-003', 0.015 UNION ALL
  SELECT 'Truffle Mushroom Soup', 'PROD-003', 0.150 UNION ALL SELECT 'Truffle Mushroom Soup', 'DAIRY-001', 0.120 UNION ALL SELECT 'Truffle Mushroom Soup', 'PAN-001', 0.005 UNION ALL
  SELECT 'Prawn Cocktail', 'FISH-002', 0.180 UNION ALL SELECT 'Prawn Cocktail', 'PAN-002', 0.060 UNION ALL
  SELECT 'Oxford Short Rib', 'MEAT-001', 0.350 UNION ALL SELECT 'Oxford Short Rib', 'PAN-003', 0.050 UNION ALL SELECT 'Oxford Short Rib', 'PROD-004', 0.150 UNION ALL
  SELECT 'Pan-Seared Sea Bass', 'FISH-001', 0.220 UNION ALL SELECT 'Pan-Seared Sea Bass', 'DRY-004', 0.001 UNION ALL SELECT 'Pan-Seared Sea Bass', 'DAIRY-003', 0.030 UNION ALL
  SELECT 'Suite Chicken Supreme', 'MEAT-003', 0.280 UNION ALL SELECT 'Suite Chicken Supreme', 'PROD-005', 0.015 UNION ALL SELECT 'Suite Chicken Supreme', 'PAN-004', 0.040 UNION ALL
  SELECT 'Wild Mushroom Risotto', 'DRY-001', 0.180 UNION ALL SELECT 'Wild Mushroom Risotto', 'PROD-006', 0.080 UNION ALL SELECT 'Wild Mushroom Risotto', 'DAIRY-004', 0.030 UNION ALL
  SELECT 'Wagyu Ribeye 300g', 'MEAT-002', 0.300 UNION ALL SELECT 'Wagyu Ribeye 300g', 'DRY-005', 0.004 UNION ALL SELECT 'Wagyu Ribeye 300g', 'DAIRY-005', 0.025 UNION ALL
  SELECT 'Lamb Chops', 'MEAT-004', 0.320 UNION ALL SELECT 'Lamb Chops', 'PROD-007', 0.015 UNION ALL SELECT 'Lamb Chops', 'PROD-008', 0.010 UNION ALL SELECT 'Lamb Chops', 'PROD-009', 0.020 UNION ALL
  SELECT 'Dark Chocolate Fondant', 'DRY-002', 0.080 UNION ALL SELECT 'Dark Chocolate Fondant', 'DAIRY-001', 0.050 UNION ALL SELECT 'Dark Chocolate Fondant', 'DAIRY-006', 0.080 UNION ALL
  SELECT 'Yuzu Cheesecake', 'DAIRY-002', 0.100 UNION ALL SELECT 'Yuzu Cheesecake', 'PROD-010', 0.025 UNION ALL SELECT 'Yuzu Cheesecake', 'DRY-006', 0.030 UNION ALL
  SELECT 'Suite Old Fashioned', 'BAR-001', 0.060 UNION ALL SELECT 'Suite Old Fashioned', 'DRY-007', 0.010 UNION ALL SELECT 'Suite Old Fashioned', 'BAR-003', 0.003 UNION ALL
  SELECT 'Makati Spritz', 'BAR-002', 0.150 UNION ALL SELECT 'Makati Spritz', 'BAR-004', 0.050 UNION ALL SELECT 'Makati Spritz', 'PROD-002', 0.020
) AS map
JOIN menu_items m ON m.name = map.menu_name
JOIN inventory_items i ON i.sku = map.sku
WHERE NOT EXISTS (
  SELECT 1 FROM recipe_ingredients existing
  WHERE existing.menu_item_id = m.id AND existing.inventory_item_id = i.id
);

-- Sample open orders for KOT demo
INSERT INTO orders (order_number, table_id, customer_name, status, source, subtotal, tax, total, notes) VALUES
('ORD-20260920-001', 4, 'Walk-in', 'preparing', 'qr', 1360.00, 163.20, 1523.20, NULL),
('ORD-20260920-002', 3, 'Park Party', 'confirmed', 'qr', 980.00, 117.60, 1097.60, 'Allergy: peanuts'),
('ORD-20260920-003', NULL, 'Counter', 'ready', 'pos', 420.00, 50.40, 470.40, NULL);

INSERT INTO order_items (order_id, menu_item_id, item_name, unit_price, quantity, status) VALUES
(1, 2, 'Truffle Mushroom Soup', 380.00, 1, 'preparing'),
(1, 4, 'Oxford Short Rib', 980.00, 1, 'preparing'),
(2, 4, 'Oxford Short Rib', 980.00, 1, 'queued'),
(3, 10, 'Dark Chocolate Fondant', 420.00, 1, 'ready');

INSERT INTO kitchen_tickets (order_id, order_number, table_label, status, priority) VALUES
(1, 'ORD-20260920-001', 'T4', 'in_progress', 1),
(2, 'ORD-20260920-002', 'T3', 'new', 0),
(3, 'ORD-20260920-003', 'POS', 'ready', 0);

INSERT INTO notifications (role_target, title, body, type) VALUES
('admin', 'Low stock alert', 'Wagyu Ribeye is approaching reorder level.', 'warning'),
('kitchen', 'New order', 'ORD-20260920-002 from Table T3', 'order'),
('cashier', 'Payment pending', 'Table T4 order ready for billing.', 'info');

INSERT INTO promotions (code, name, type, value, starts_at, ends_at, is_active) VALUES
('SUITE10', 'Suite 10% off', 'percentage', 10, DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 90 DAY), 1),
('BOGO', 'Buy 1 Take 1 (cheapest free)', 'buy_one_take_one', 0, DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 90 DAY), 1),
('BDAY150', 'Birthday treat â‚±150 off', 'birthday', 150, DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 365 DAY), 1);

-- Historical sales for ML forecasting seed
INSERT INTO sales_daily (sale_date, menu_item_id, quantity_sold, revenue)
SELECT DATE_SUB(CURDATE(), INTERVAL seq DAY), mi.id,
       FLOOR(3 + RAND() * 12),
       ROUND((3 + RAND() * 12) * mi.price, 2)
FROM menu_items mi
CROSS JOIN (
  SELECT 0 AS seq UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4
  UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9
  UNION SELECT 10 UNION SELECT 11 UNION SELECT 12 UNION SELECT 13 UNION SELECT 14
  UNION SELECT 15 UNION SELECT 16 UNION SELECT 17 UNION SELECT 18 UNION SELECT 19
  UNION SELECT 20 UNION SELECT 21 UNION SELECT 22 UNION SELECT 23 UNION SELECT 24
  UNION SELECT 25 UNION SELECT 26 UNION SELECT 27 UNION SELECT 28 UNION SELECT 29
) days;
