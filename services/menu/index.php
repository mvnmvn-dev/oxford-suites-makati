<?php
require_once __DIR__ . '/../../shared/Response.php';
require_once __DIR__ . '/../../shared/Database.php';
require_once __DIR__ . '/../../shared/Auth.php';
require_once __DIR__ . '/../../shared/ServiceHelpers.php';
require_once __DIR__ . '/../../shared/InventorySync.php';
require_once __DIR__ . '/../../shared/Security.php';

class MenuService
{
    use ServiceHelpers;

    public function handle(): void
    {
        $sub = $_SERVER['RMS_SUBPATH'] ?? '';
        $method = $this->method();
        $pdo = Database::connection();

        if ($method === 'GET' && ($sub === '' || $sub === 'items')) {
            $available = $this->query('available');
            $sql = 'SELECT mi.*, mc.name AS category_name, mc.slug AS category_slug
                    FROM menu_items mi JOIN menu_categories mc ON mc.id = mi.category_id';
            if ($available === '1') {
                $sql .= ' WHERE mi.is_available = 1 AND mc.is_active = 1';
            }
            $sql .= ' ORDER BY mc.sort_order, mi.name';
            $rows = $pdo->query($sql)->fetchAll();
            foreach ($rows as &$row) {
                $level = InventorySync::stockLevel($pdo, (int)$row['id']);
                $row['stock_level'] = $level;
                $row['stock_ok'] = $level === 'sold_out' ? 0 : 1;
                $row['availability_label'] = $level === 'sold_out' ? 'Sold out' : ($level === 'low_stock' ? 'Low stock' : 'Available');
            }
            unset($row);
            Response::success($rows);
        }

        if ($method === 'GET' && $sub === 'categories') {
            $cats = $pdo->query('SELECT * FROM menu_categories WHERE is_active = 1 ORDER BY sort_order')->fetchAll();
            Response::success($cats);
        }

        if ($method === 'GET' && $sub === 'options') {
            $menuId = (int)$this->query('menu_item_id', 0);
            $sql = 'SELECT * FROM menu_item_options WHERE is_active = 1 AND (menu_item_id IS NULL';
            $params = [];
            if ($menuId > 0) {
                $sql .= ' OR menu_item_id = ?';
                $params[] = $menuId;
            }
            $sql .= ') ORDER BY option_group, sort_order, label';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            $grouped = [];
            foreach ($rows as $opt) {
                $grouped[$opt['option_group']][] = $opt;
            }
            Response::success(['options' => $rows, 'groups' => $grouped]);
        }

        if ($method === 'GET' && $sub === 'favorites') {
            $user = Auth::requireLogin(['customer']);
            $stmt = $pdo->prepare(
                'SELECT mi.*, cf.created_at AS favorited_at
                 FROM customer_favorites cf
                 JOIN menu_items mi ON mi.id = cf.menu_item_id
                 WHERE cf.customer_id = ?
                 ORDER BY cf.created_at DESC'
            );
            $stmt->execute([(int)$user['id']]);
            $rows = $stmt->fetchAll();
            foreach ($rows as &$row) {
                $level = InventorySync::stockLevel($pdo, (int)$row['id']);
                $row['stock_level'] = $level;
                $row['orderable'] = ((int)$row['is_available'] === 1 && $level !== 'sold_out') ? 1 : 0;
            }
            unset($row);
            Response::success($rows);
        }

        if ($method === 'POST' && $sub === 'favorites') {
            $user = Auth::requireLogin(['customer']);
            $b = $this->body();
            $menuId = (int)($b['menu_item_id'] ?? 0);
            if ($menuId < 1) {
                Response::error('menu_item_id required');
            }
            $check = $pdo->prepare('SELECT id FROM menu_items WHERE id = ?');
            $check->execute([$menuId]);
            if (!$check->fetch()) {
                Response::error('Menu item not found', 404);
            }
            $pdo->prepare(
                'INSERT IGNORE INTO customer_favorites (customer_id, menu_item_id) VALUES (?, ?)'
            )->execute([(int)$user['id'], $menuId]);
            Response::success(['menu_item_id' => $menuId], 'Added to favorites', 201);
        }

        if ($method === 'DELETE' && preg_match('/^favorites\/(\d+)$/', $sub, $m)) {
            $user = Auth::requireLogin(['customer']);
            $pdo->prepare('DELETE FROM customer_favorites WHERE customer_id = ? AND menu_item_id = ?')
                ->execute([(int)$user['id'], (int)$m[1]]);
            Response::success(null, 'Removed from favorites');
        }

        if ($method === 'GET' && $sub === 'full') {
            $cats = $pdo->query('SELECT * FROM menu_categories WHERE is_active = 1 ORDER BY sort_order')->fetchAll();
            $items = $pdo->query('SELECT * FROM menu_items ORDER BY name')->fetchAll();
            $bestSellers = [];
            try {
                $bs = $pdo->query(
                    "SELECT menu_item_id FROM sales_daily
                     WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                     GROUP BY menu_item_id ORDER BY SUM(quantity_sold) DESC LIMIT 5"
                )->fetchAll(PDO::FETCH_COLUMN);
                $bestSellers = array_map('intval', $bs ?: []);
            } catch (Throwable $e) {
                $bestSellers = [];
            }
            foreach ($items as &$item) {
                $level = InventorySync::stockLevel($pdo, (int)$item['id']);
                $item['stock_level'] = $level;
                $item['stock_ok'] = $level === 'sold_out' ? 0 : 1;
                $item['orderable'] = ((int)$item['is_available'] === 1 && $level !== 'sold_out') ? 1 : 0;
                $item['availability_label'] = $level === 'sold_out' ? 'Sold out' : ($level === 'low_stock' ? 'Low stock' : 'Available');
                $tags = array_filter(array_map('trim', explode(',', (string)($item['tags'] ?? ''))));
                $item['is_best_seller'] = in_array((int)$item['id'], $bestSellers, true) ? 1 : 0;
                $item['is_chef_pick'] = (int)$item['is_featured'] === 1 ? 1 : 0;
                $item['is_spicy'] = in_array('spicy', $tags, true) || in_array('hot', $tags, true) ? 1 : 0;
                $item['is_vegetarian'] = in_array('vegetarian', $tags, true) ? 1 : 0;
            }
            unset($item);
            foreach ($cats as &$c) {
                $c['items'] = array_values(array_filter($items, fn($i) => (int)$i['category_id'] === (int)$c['id']));
            }
            Response::success($cats);
        }

        if ($method === 'GET' && preg_match('/^items\/(\d+)$/', $sub, $m)) {
            $stmt = $pdo->prepare('SELECT * FROM menu_items WHERE id = ?');
            $stmt->execute([(int)$m[1]]);
            $row = $stmt->fetch();
            if (!$row) {
                Response::error('Item not found', 404);
            }
            Response::success($row);
        }

        if ($method === 'POST' && $sub === 'upload') {
            Auth::requireLogin(['admin']);
            if (empty($_FILES['image']) || !is_uploaded_file($_FILES['image']['tmp_name'])) {
                Response::error('Image file required');
            }
            $file = $_FILES['image'];
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                Response::error('Upload failed');
            }
            if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
                Response::error('Image must be under 5MB');
            }
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);
            $allowed = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
            ];
            if (!isset($allowed[$mime])) {
                Response::error('Only JPG, PNG, WEBP, or GIF allowed');
            }
            $dir = __DIR__ . '/../../public/assets/img/menu';
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                Response::error('Could not create upload directory', 500);
            }
            $name = 'dish_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
            $dest = $dir . '/' . $name;
            if (!move_uploaded_file($file['tmp_name'], $dest)) {
                Response::error('Failed to save image', 500);
            }
            $config = require __DIR__ . '/../../config/app.php';
            $url = rtrim($config['base_url'], '/') . '/public/assets/img/menu/' . $name;
            Response::success(['url' => $url, 'filename' => $name], 'Image uploaded', 201);
        }

        if ($method === 'POST' && ($sub === '' || $sub === 'items')) {
            Auth::requireLogin(['admin']);
            $b = $this->body();
            $stmt = $pdo->prepare(
                'INSERT INTO menu_items (category_id, name, description, price, image_url, is_available, is_featured, prep_minutes, tags)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                (int)$b['category_id'],
                $b['name'],
                $b['description'] ?? null,
                (float)$b['price'],
                $b['image_url'] ?? null,
                isset($b['is_available']) ? (int)$b['is_available'] : 1,
                isset($b['is_featured']) ? (int)$b['is_featured'] : 0,
                (int)($b['prep_minutes'] ?? 15),
                $b['tags'] ?? null,
            ]);
            Response::success(['id' => (int)$pdo->lastInsertId()], 'Menu item created', 201);
        }

        if ($method === 'PUT' && preg_match('/^items\/(\d+)$/', $sub, $m)) {
            Auth::requireLogin(['admin']);
            $b = $this->body();
            $id = (int)$m[1];
            $current = $pdo->prepare('SELECT price FROM menu_items WHERE id=?');
            $current->execute([$id]);
            $currentPrice = $current->fetchColumn();
            if ($currentPrice === false) Response::error('Menu item not found', 404);
            if (abs((float)$currentPrice - (float)($b['price'] ?? 0)) > 0.0001) {
                Security::requireManagerPin($b, 'change menu price', $id);
            }
            $stmt = $pdo->prepare(
                'UPDATE menu_items SET category_id=?, name=?, description=?, price=?, image_url=?, is_available=?, is_featured=?, prep_minutes=?, tags=? WHERE id=?'
            );
            $stmt->execute([
                (int)$b['category_id'],
                $b['name'],
                $b['description'] ?? null,
                (float)$b['price'],
                $b['image_url'] ?? null,
                (int)($b['is_available'] ?? 1),
                (int)($b['is_featured'] ?? 0),
                (int)($b['prep_minutes'] ?? 15),
                $b['tags'] ?? null,
                $id,
            ]);
            Response::success(['id' => $id], 'Menu item updated');
        }

        if ($method === 'PATCH' && preg_match('/^items\/(\d+)\/availability$/', $sub, $m)) {
            Auth::requireLogin(['admin', 'kitchen']);
            $b = $this->body();
            $id = (int)$m[1];
            $next = (int)($b['is_available'] ?? 0);
            if ($next === 1 && !InventorySync::canServe($pdo, $id, 1)) {
                Response::error('Cannot restore — inventory is low or insufficient for this dish. Restock first.', 400);
            }
            $pdo->prepare('UPDATE menu_items SET is_available = ? WHERE id = ?')
                ->execute([$next, $id]);
            Response::success(null, 'Availability updated');
        }

        if ($method === 'DELETE' && preg_match('/^items\/(\d+)$/', $sub, $m)) {
            Auth::requireLogin(['admin']);
            $pdo->prepare('UPDATE menu_items SET is_available = 0 WHERE id = ?')->execute([(int)$m[1]]);
            Response::success(null, 'Menu item disabled');
        }

        Response::error('Menu route not found', 404);
    }
}

(new MenuService())->handle();
