<?php
/**
 * Order ↔ inventory sync: stock checks, deductions, restocks, menu auto-86.
 */
class InventorySync
{
    /** True if every recipe ingredient can cover $portions servings. */
    public static function canServe(PDO $pdo, int $menuItemId, int $portions = 1): bool
    {
        $portions = max(1, $portions);
        $recipe = $pdo->prepare(
            'SELECT ri.quantity_used, ii.quantity
             FROM recipe_ingredients ri
             JOIN inventory_items ii ON ii.id = ri.inventory_item_id
             WHERE ri.menu_item_id = ?'
        );
        $recipe->execute([$menuItemId]);
        $rows = $recipe->fetchAll();
        if (!$rows) {
            return true;
        }
        foreach ($rows as $ri) {
            $need = (float)$ri['quantity_used'] * $portions;
            if ((float)$ri['quantity'] < $need) {
                return false;
            }
        }
        return true;
    }

    /**
     * Three-state stock for menu badges: available | low_stock | sold_out.
     * Low stock = can still serve one portion but any ingredient is at/below reorder.
     */
    public static function stockLevel(PDO $pdo, int $menuItemId): string
    {
        $recipe = $pdo->prepare(
            'SELECT ri.quantity_used, ii.quantity, ii.reorder_level
             FROM recipe_ingredients ri
             JOIN inventory_items ii ON ii.id = ri.inventory_item_id
             WHERE ri.menu_item_id = ?'
        );
        $recipe->execute([$menuItemId]);
        $rows = $recipe->fetchAll();
        if (!$rows) {
            return 'available';
        }
        $low = false;
        foreach ($rows as $ri) {
            if ((float)$ri['quantity'] < (float)$ri['quantity_used']) {
                return 'sold_out';
            }
            if ((float)$ri['quantity'] <= (float)$ri['reorder_level']) {
                $low = true;
            }
        }
        return $low ? 'low_stock' : 'available';
    }

    public static function assertCanFulfill(PDO $pdo, int $menuItemId, int $qty, string $name = ''): void
    {
        $qty = max(1, $qty);
        $recipe = $pdo->prepare(
            'SELECT ri.quantity_used, ii.id, ii.name, ii.quantity, ii.unit
             FROM recipe_ingredients ri
             JOIN inventory_items ii ON ii.id = ri.inventory_item_id
             WHERE ri.menu_item_id = ?'
        );
        $recipe->execute([$menuItemId]);
        $rows = $recipe->fetchAll();
        if (!$rows) {
            return;
        }
        $label = $name !== '' ? $name : ('item #' . $menuItemId);
        foreach ($rows as $ri) {
            $need = (float)$ri['quantity_used'] * $qty;
            if ((float)$ri['quantity'] < $need) {
                throw new RuntimeException(
                    sprintf(
                        'Insufficient stock for %s: need %.2f %s of %s (have %.2f)',
                        $label,
                        $need,
                        $ri['unit'],
                        $ri['name'],
                        (float)$ri['quantity']
                    )
                );
            }
        }
    }

    /** Deduct recipe ingredients for an order; returns affected menu item ids. */
    public static function deductForOrder(PDO $pdo, int $orderId): array
    {
        $items = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ?');
        $items->execute([$orderId]);
        $affectedMenus = [];
        $affectedInv = [];

        foreach ($items->fetchAll() as $item) {
            $menuId = (int)$item['menu_item_id'];
            $affectedMenus[$menuId] = true;
            $recipe = $pdo->prepare('SELECT * FROM recipe_ingredients WHERE menu_item_id = ?');
            $recipe->execute([$menuId]);
            foreach ($recipe->fetchAll() as $ri) {
                $deduct = (float)$ri['quantity_used'] * (int)$item['quantity'];
                $invId = (int)$ri['inventory_item_id'];
                $pdo->prepare('UPDATE inventory_items SET quantity = GREATEST(0, quantity - ?) WHERE id = ?')
                    ->execute([$deduct, $invId]);
                $pdo->prepare(
                    'INSERT INTO inventory_movements (inventory_item_id, change_qty, reason, reference_type, reference_id)
                     VALUES (?, ?, ?, ?, ?)'
                )->execute([$invId, -$deduct, 'Order deduction', 'order', $orderId]);
                $affectedInv[$invId] = true;
            }
        }

        $menuIds = self::menuIdsForInventory($pdo, array_keys($affectedInv));
        foreach (array_keys($affectedMenus) as $mid) {
            $menuIds[$mid] = true;
        }
        self::syncMenuAvailability($pdo, array_keys($menuIds));
        self::notifyLowStock($pdo, array_keys($affectedInv));
        return array_keys($menuIds);
    }

    /** Reverse stock for a cancelled order (once). */
    public static function restockForOrder(PDO $pdo, int $orderId): void
    {
        $done = $pdo->prepare(
            "SELECT id FROM inventory_movements WHERE reference_type = 'order_cancel' AND reference_id = ? LIMIT 1"
        );
        $done->execute([$orderId]);
        if ($done->fetch()) {
            return;
        }

        $prior = $pdo->prepare(
            "SELECT inventory_item_id, SUM(change_qty) AS total
             FROM inventory_movements
             WHERE reference_type = 'order' AND reference_id = ?
             GROUP BY inventory_item_id"
        );
        $prior->execute([$orderId]);
        $rows = $prior->fetchAll();
        if (!$rows) {
            return;
        }

        $affectedInv = [];
        foreach ($rows as $row) {
            $invId = (int)$row['inventory_item_id'];
            $restore = abs((float)$row['total']);
            if ($restore <= 0) {
                continue;
            }
            $pdo->prepare('UPDATE inventory_items SET quantity = quantity + ? WHERE id = ?')
                ->execute([$restore, $invId]);
            $pdo->prepare(
                'INSERT INTO inventory_movements (inventory_item_id, change_qty, reason, reference_type, reference_id)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$invId, $restore, 'Order cancelled — restock', 'order_cancel', $orderId]);
            $affectedInv[] = $invId;
        }

        self::syncMenuAvailability($pdo, array_keys(self::menuIdsForInventory($pdo, $affectedInv)));
    }

    /**
     * Auto-86 dishes only when they cannot cover one serving.
     * Low-stock items stay available with a Low Stock badge.
     */
    public static function syncMenuAvailability(PDO $pdo, ?array $menuItemIds = null): void
    {
        if ($menuItemIds !== null && !$menuItemIds) {
            return;
        }

        $sql = 'SELECT DISTINCT menu_item_id FROM recipe_ingredients';
        $params = [];
        if ($menuItemIds !== null) {
            $placeholders = implode(',', array_fill(0, count($menuItemIds), '?'));
            $sql .= " WHERE menu_item_id IN ($placeholders)";
            $params = array_map('intval', $menuItemIds);
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $upd = $pdo->prepare('UPDATE menu_items SET is_available = ? WHERE id = ?');
        foreach ($stmt->fetchAll() as $row) {
            $menuId = (int)$row['menu_item_id'];
            $ok = self::canServe($pdo, $menuId, 1);
            $upd->execute([$ok ? 1 : 0, $menuId]);
        }
    }

    public static function syncAfterInventoryChange(PDO $pdo, int $inventoryItemId): void
    {
        $menus = self::menuIdsForInventory($pdo, [$inventoryItemId]);
        self::syncMenuAvailability($pdo, array_keys($menus));
        self::notifyLowStock($pdo, [$inventoryItemId]);
    }

    /** Alert kitchen/admin when ingredients fall to or below reorder level. */
    public static function notifyLowStock(PDO $pdo, array $inventoryIds): void
    {
        $inventoryIds = array_values(array_unique(array_filter(array_map('intval', $inventoryIds))));
        if (!$inventoryIds) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($inventoryIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT id, name, quantity, reorder_level, unit
             FROM inventory_items
             WHERE id IN ($placeholders) AND quantity <= reorder_level"
        );
        $stmt->execute($inventoryIds);
        $low = $stmt->fetchAll();
        if (!$low) {
            return;
        }
        // Avoid spam: skip if an unread inventory alert already exists in the last hour
        $recent = (int)$pdo->query(
            "SELECT COUNT(*) FROM notifications
             WHERE type = 'inventory' AND is_read = 0 AND created_at >= (NOW() - INTERVAL 1 HOUR)"
        )->fetchColumn();
        if ($recent > 0) {
            return;
        }
        $names = array_map(static fn ($r) => $r['name'] . ' (' . rtrim(rtrim(number_format((float)$r['quantity'], 2), '0'), '.') . ' ' . $r['unit'] . ')', $low);
        $body = 'Below reorder: ' . implode(', ', array_slice($names, 0, 5));
        $pdo->prepare('INSERT INTO notifications (role_target, title, body, type) VALUES (?,?,?,?)')
            ->execute(['admin', 'Low stock alert', $body, 'inventory']);
        $pdo->prepare('INSERT INTO notifications (role_target, title, body, type) VALUES (?,?,?,?)')
            ->execute(['kitchen', 'Low stock alert', $body, 'inventory']);
    }

    /** @return array<int,true> */
    private static function menuIdsForInventory(PDO $pdo, array $inventoryIds): array
    {
        $out = [];
        if (!$inventoryIds) {
            return $out;
        }
        $placeholders = implode(',', array_fill(0, count($inventoryIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT DISTINCT menu_item_id FROM recipe_ingredients WHERE inventory_item_id IN ($placeholders)"
        );
        $stmt->execute(array_values($inventoryIds));
        foreach ($stmt->fetchAll() as $row) {
            $out[(int)$row['menu_item_id']] = true;
        }
        return $out;
    }
}
