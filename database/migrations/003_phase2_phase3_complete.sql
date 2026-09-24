-- Oxford RMS Phase 2 / Phase 3 completion migration.
-- Run after 001 and 002 on existing databases. Fresh installs use database/init.sql.
USE oxford_rms;

-- Kitchen stations + richer menu metadata
ALTER TABLE menu_items
  ADD COLUMN IF NOT EXISTS station ENUM('grill','drinks','dessert','general') NOT NULL DEFAULT 'general' AFTER tags,
  ADD COLUMN IF NOT EXISTS calories INT UNSIGNED DEFAULT NULL AFTER station,
  ADD COLUMN IF NOT EXISTS allergens VARCHAR(255) DEFAULT NULL AFTER calories,
  ADD COLUMN IF NOT EXISTS ingredients TEXT DEFAULT NULL AFTER allergens;

-- Expand notes for structured customization JSON
ALTER TABLE order_items MODIFY notes TEXT DEFAULT NULL;

-- Reservation seating preferences
ALTER TABLE reservations
  ADD COLUMN IF NOT EXISTS seating_pref ENUM('any','indoor','outdoor','window') NOT NULL DEFAULT 'any' AFTER notes;

ALTER TABLE dining_tables
  ADD COLUMN IF NOT EXISTS seating_type ENUM('indoor','outdoor','window') NOT NULL DEFAULT 'indoor' AFTER zone;

-- Inventory expiry for waste prediction
ALTER TABLE inventory_items
  ADD COLUMN IF NOT EXISTS expires_at DATE DEFAULT NULL AFTER category;

-- Structured food customization options
CREATE TABLE IF NOT EXISTS menu_item_options (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  menu_item_id INT UNSIGNED DEFAULT NULL,
  option_group VARCHAR(60) NOT NULL,
  option_key VARCHAR(60) NOT NULL,
  label VARCHAR(120) NOT NULL,
  price_delta DECIMAL(10,2) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  INDEX idx_mio_item (menu_item_id),
  INDEX idx_mio_group (option_group),
  CONSTRAINT fk_mio_menu FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- True bill splits (equal / by seat / by item)
CREATE TABLE IF NOT EXISTS bill_splits (
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

-- Loyalty redeem → voucher that applies as discount
CREATE TABLE IF NOT EXISTS loyalty_vouchers (
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

-- Seed global customization options (menu_item_id NULL = all items)
INSERT INTO menu_item_options (menu_item_id, option_group, option_key, label, price_delta, sort_order)
SELECT NULL, 'spice_level', 'mild', 'Mild', 0, 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM menu_item_options WHERE option_group='spice_level' AND option_key='mild');
INSERT INTO menu_item_options (menu_item_id, option_group, option_key, label, price_delta, sort_order)
SELECT NULL, 'spice_level', 'medium', 'Medium', 0, 2 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM menu_item_options WHERE option_group='spice_level' AND option_key='medium');
INSERT INTO menu_item_options (menu_item_id, option_group, option_key, label, price_delta, sort_order)
SELECT NULL, 'spice_level', 'hot', 'Hot / spicy', 0, 3 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM menu_item_options WHERE option_group='spice_level' AND option_key='hot');
INSERT INTO menu_item_options (menu_item_id, option_group, option_key, label, price_delta, sort_order)
SELECT NULL, 'cooking', 'regular', 'Regular', 0, 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM menu_item_options WHERE option_group='cooking' AND option_key='regular');
INSERT INTO menu_item_options (menu_item_id, option_group, option_key, label, price_delta, sort_order)
SELECT NULL, 'cooking', 'well_done', 'Well done', 0, 2 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM menu_item_options WHERE option_group='cooking' AND option_key='well_done');
INSERT INTO menu_item_options (menu_item_id, option_group, option_key, label, price_delta, sort_order)
SELECT NULL, 'cooking', 'medium_rare', 'Medium rare', 0, 3 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM menu_item_options WHERE option_group='cooking' AND option_key='medium_rare');
INSERT INTO menu_item_options (menu_item_id, option_group, option_key, label, price_delta, sort_order)
SELECT NULL, 'extras', 'extra_sauce', 'Extra sauce', 40, 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM menu_item_options WHERE option_group='extras' AND option_key='extra_sauce');
INSERT INTO menu_item_options (menu_item_id, option_group, option_key, label, price_delta, sort_order)
SELECT NULL, 'extras', 'extra_rice', 'Extra rice', 50, 2 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM menu_item_options WHERE option_group='extras' AND option_key='extra_rice');
INSERT INTO menu_item_options (menu_item_id, option_group, option_key, label, price_delta, sort_order)
SELECT NULL, 'remove', 'no_onion', 'No onion', 0, 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM menu_item_options WHERE option_group='remove' AND option_key='no_onion');
INSERT INTO menu_item_options (menu_item_id, option_group, option_key, label, price_delta, sort_order)
SELECT NULL, 'remove', 'no_garlic', 'No garlic', 0, 2 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM menu_item_options WHERE option_group='remove' AND option_key='no_garlic');

-- Station assignments from category/tags
UPDATE menu_items SET station = 'grill' WHERE category_id = 3 OR tags LIKE '%beef%' OR tags LIKE '%lamb%';
UPDATE menu_items SET station = 'drinks' WHERE category_id = 5 OR tags LIKE '%cocktail%';
UPDATE menu_items SET station = 'dessert' WHERE category_id = 4 OR tags LIKE '%dessert%';

-- Seating types from zone names
UPDATE dining_tables SET seating_type = 'window' WHERE zone LIKE '%Window%';
UPDATE dining_tables SET seating_type = 'outdoor' WHERE zone LIKE '%Terrace%' OR zone LIKE '%Lounge%';
UPDATE dining_tables SET seating_type = 'indoor' WHERE seating_type = 'indoor' OR seating_type IS NULL OR seating_type = '';

-- Sample expiry dates for waste prediction demo
UPDATE inventory_items SET expires_at = DATE_ADD(CURDATE(), INTERVAL 3 DAY) WHERE sku IN ('PROD-001','DAIRY-001','DAIRY-002');
UPDATE inventory_items SET expires_at = DATE_ADD(CURDATE(), INTERVAL 7 DAY) WHERE sku IN ('FISH-001','FISH-002');
UPDATE inventory_items SET expires_at = DATE_ADD(CURDATE(), INTERVAL 14 DAY) WHERE sku IN ('MEAT-001','MEAT-003');

-- Sample active promotions
INSERT INTO promotions (code, name, type, value, starts_at, ends_at, is_active)
SELECT 'SUITE10', 'Suite 10% off', 'percentage', 10, DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 90 DAY), 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM promotions WHERE code='SUITE10');
INSERT INTO promotions (code, name, type, value, starts_at, ends_at, is_active)
SELECT 'BOGO', 'Buy 1 Take 1 (cheapest free)', 'buy_one_take_one', 0, DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 90 DAY), 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM promotions WHERE code='BOGO');
INSERT INTO promotions (code, name, type, value, starts_at, ends_at, is_active)
SELECT 'BDAY150', 'Birthday treat ₱150 off', 'birthday', 150, DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 365 DAY), 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM promotions WHERE code='BDAY150');
