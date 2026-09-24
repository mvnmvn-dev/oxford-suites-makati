<?php
require_once __DIR__ . '/../../shared/Response.php';
require_once __DIR__ . '/../../shared/Database.php';
require_once __DIR__ . '/../../shared/Auth.php';
require_once __DIR__ . '/../../shared/ServiceHelpers.php';
require_once __DIR__ . '/../../shared/InventorySync.php';
require_once __DIR__ . '/../../shared/Security.php';

class InventoryService
{
    use ServiceHelpers;

    public function handle(): void
    {
        $sub = $_SERVER['RMS_SUBPATH'] ?? '';
        $method = $this->method();
        $pdo = Database::connection();

        if ($method === 'GET' && $sub === '') {
            Auth::requireLogin(['admin', 'kitchen']);
            $rows = $pdo->query(
                "SELECT ii.*, (ii.quantity <= ii.reorder_level) AS is_low,
                        (SELECT GROUP_CONCAT(mi.name ORDER BY mi.name SEPARATOR ', ')
                         FROM recipe_ingredients ri
                         JOIN menu_items mi ON mi.id = ri.menu_item_id
                         WHERE ri.inventory_item_id = ii.id) AS used_by
                 FROM inventory_items ii
                 ORDER BY (ii.quantity <= ii.reorder_level) DESC, ii.name"
            )->fetchAll();
            Response::success($rows);
        }

        if ($method === 'POST' && $sub === '') {
            Auth::requireLogin(['admin']);
            $b = $this->body();
            $stmt = $pdo->prepare(
                'INSERT INTO inventory_items (name, sku, unit, quantity, reorder_level, cost_per_unit, category)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $b['name'],
                $b['sku'] ?? null,
                $b['unit'] ?? 'pcs',
                (float)($b['quantity'] ?? 0),
                (float)($b['reorder_level'] ?? 10),
                (float)($b['cost_per_unit'] ?? 0),
                $b['category'] ?? 'General',
            ]);
            Response::success(['id' => (int)$pdo->lastInsertId()], 'Inventory item created', 201);
        }

        if ($method === 'PATCH' && preg_match('/^(\d+)$/', $sub, $m)) {
            Auth::requireLogin(['admin', 'kitchen']);
            $b = $this->body();
            $id = (int)$m[1];
            Security::requireManagerPin($b, 'adjust inventory', $id);
            if (isset($b['adjust_qty'])) {
                $adj = (float)$b['adjust_qty'];
                $pdo->prepare('UPDATE inventory_items SET quantity = GREATEST(0, quantity + ?) WHERE id = ?')
                    ->execute([$adj, $id]);
                $pdo->prepare(
                    'INSERT INTO inventory_movements (inventory_item_id, change_qty, reason, reference_type)
                     VALUES (?, ?, ?, ?)'
                )->execute([$id, $adj, $b['reason'] ?? 'Manual adjustment', 'manual']);
            } else {
                $pdo->prepare(
                    'UPDATE inventory_items SET name=COALESCE(?,name), quantity=COALESCE(?,quantity),
                     reorder_level=COALESCE(?,reorder_level), cost_per_unit=COALESCE(?,cost_per_unit)
                     WHERE id=?'
                )->execute([
                    $b['name'] ?? null,
                    isset($b['quantity']) ? (float)$b['quantity'] : null,
                    isset($b['reorder_level']) ? (float)$b['reorder_level'] : null,
                    isset($b['cost_per_unit']) ? (float)$b['cost_per_unit'] : null,
                    $id,
                ]);
            }
            InventorySync::syncAfterInventoryChange($pdo, $id);
            $row = $pdo->prepare('SELECT *, (quantity <= reorder_level) AS is_low FROM inventory_items WHERE id = ?');
            $row->execute([$id]);
            Response::success($row->fetch(), 'Inventory updated — linked menu dishes synced');
        }

        if ($method === 'GET' && $sub === 'movements') {
            Auth::requireLogin(['admin', 'kitchen']);
            $rows = $pdo->query(
                'SELECT im.*, ii.name FROM inventory_movements im
                 JOIN inventory_items ii ON ii.id = im.inventory_item_id
                 ORDER BY im.created_at DESC LIMIT 100'
            )->fetchAll();
            Response::success($rows);
        }

        if ($method === 'GET' && $sub === 'waste') {
            Auth::requireLogin(['admin', 'kitchen']);
            $rows = $pdo->query('SELECT iw.*, ii.name, ii.unit FROM inventory_waste iw JOIN inventory_items ii ON ii.id=iw.inventory_item_id ORDER BY iw.created_at DESC LIMIT 100')->fetchAll();
            Response::success($rows);
        }

        if ($method === 'POST' && $sub === 'waste') {
            Auth::requireLogin(['admin', 'kitchen']);
            $b = $this->body();
            $qty = (float)($b['quantity'] ?? 0); $id = (int)($b['inventory_item_id'] ?? 0);
            if ($qty <= 0 || !$id || empty($b['reason'])) Response::error('inventory_item_id, positive quantity, and reason required');
            Security::requireManagerPin($b, 'record inventory waste', $id);
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE inventory_items SET quantity=GREATEST(0, quantity-?) WHERE id=?')->execute([$qty, $id]);
            $pdo->prepare('INSERT INTO inventory_waste (inventory_item_id,quantity,reason,expires_at) VALUES (?,?,?,?)')->execute([$id,$qty,substr($b['reason'],0,120),$b['expires_at']??null]);
            $pdo->prepare('INSERT INTO inventory_movements (inventory_item_id,change_qty,reason,reference_type) VALUES (?,?,?,?)')->execute([$id,-$qty,'Waste recorded','waste']);
            $pdo->commit();
            InventorySync::syncAfterInventoryChange($pdo, $id);
            Response::success(null, 'Waste recorded', 201);
        }

        if ($method === 'POST' && $sub === 'sync-menu') {
            Auth::requireLogin(['admin', 'kitchen']);
            InventorySync::syncMenuAvailability($pdo, null);
            Response::success(null, 'Menu availability synced from stock levels');
        }

        Response::error('Inventory route not found', 404);
    }
}

(new InventoryService())->handle();
