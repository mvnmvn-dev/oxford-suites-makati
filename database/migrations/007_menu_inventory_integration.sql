-- Separate a manager's manual menu enablement from the effective stock-driven availability.
ALTER TABLE menu_items
  ADD COLUMN IF NOT EXISTS is_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER is_available;

UPDATE menu_items
SET is_enabled = is_available
WHERE is_enabled = 1 AND is_available = 0;

-- Effective availability is refreshed by InventorySync whenever stock or recipes change.
