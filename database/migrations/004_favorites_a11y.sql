-- Favorites for guest portal (Phase polish)
CREATE TABLE IF NOT EXISTS customer_favorites (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  menu_item_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_fav_customer_item (customer_id, menu_item_id),
  INDEX idx_fav_customer (customer_id),
  CONSTRAINT fk_fav_customer FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_fav_menu FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
