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

-- Add every recipe component once. Quantities are per menu serving.
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
