<?php
/**
 * Demo mode — one-click seed scenarios for capstone defense.
 */
require_once __DIR__ . '/../shared/Response.php';
require_once __DIR__ . '/../shared/Database.php';
require_once __DIR__ . '/../shared/Auth.php';
require_once __DIR__ . '/../shared/ServiceHelpers.php';
require_once __DIR__ . '/../shared/Audit.php';

class DemoService
{
    use ServiceHelpers;

    public function handle(): void
    {
        Auth::requireLogin(['admin']);
        $sub = $_SERVER['RMS_SUBPATH'] ?? '';
        $method = $this->method();
        $pdo = Database::connection();

        if ($method === 'GET' && ($sub === '' || $sub === 'scenarios')) {
            Response::success([
                'scenarios' => [
                    ['id' => 'lunch_rush', 'name' => 'Lunch Rush', 'description' => 'Open tickets, occupied tables, pending service requests'],
                    ['id' => 'dinner_rush', 'name' => 'Dinner Rush', 'description' => 'Heavy evening bookings + kitchen queue'],
                    ['id' => 'fully_booked', 'name' => 'Fully Booked', 'description' => 'Tonight filled with reservations'],
                    ['id' => 'inventory_shortage', 'name' => 'Inventory Shortage', 'description' => 'Low stock flags + waste alerts'],
                ],
            ]);
        }

        if ($method === 'POST' && ($sub === 'seed' || $sub === '')) {
            $scenario = (string)($this->body()['scenario'] ?? 'lunch_rush');
            $created = ['orders' => 0, 'reservations' => 0, 'requests' => 0, 'notifications' => 0];

            $guestId = (int)$pdo->query("SELECT id FROM users WHERE role='customer' LIMIT 1")->fetchColumn();
            $tables = $pdo->query('SELECT id, table_number, capacity FROM dining_tables ORDER BY id')->fetchAll();
            $menuIds = $pdo->query('SELECT id, name, price FROM menu_items WHERE is_available=1 LIMIT 12')->fetchAll();

            if ($scenario === 'inventory_shortage') {
                $pdo->exec('UPDATE inventory_items SET quantity = GREATEST(0, reorder_level - 2) WHERE id IN (SELECT id FROM (SELECT id FROM inventory_items ORDER BY id LIMIT 5) t)');
                require_once __DIR__ . '/../shared/InventorySync.php';
                InventorySync::syncMenuAvailability($pdo);
                $ids = $pdo->query('SELECT id FROM inventory_items ORDER BY id LIMIT 5')->fetchAll(PDO::FETCH_COLUMN);
                InventorySync::notifyLowStock($pdo, array_map('intval', $ids ?: []));
                $created['notifications']++;
                Audit::log('demo_seed', 'demo', 'scenario=inventory_shortage');
                Response::success($created, 'Inventory shortage scenario ready');
            }

            if ($scenario === 'fully_booked') {
                foreach (array_slice($tables, 0, 8) as $i => $t) {
                    $time = sprintf('%02d:00:00', 18 + ($i % 3));
                    $pdo->prepare(
                        'INSERT INTO reservations (customer_id, customer_name, customer_email, party_size, table_id, reservation_date, reservation_time, status, seating_pref, notes)
                         VALUES (?,?,?,?,?,CURDATE(),?,?,?,?)'
                    )->execute([
                        $guestId ?: null,
                        'Demo Guest ' . ($i + 1),
                        'demo' . ($i + 1) . '@example.com',
                        min(4, (int)$t['capacity']),
                        (int)$t['id'],
                        $time,
                        'confirmed',
                        'any',
                        'Demo fully booked',
                    ]);
                    $pdo->prepare("UPDATE dining_tables SET status='reserved' WHERE id=? AND status='available'")->execute([(int)$t['id']]);
                    $created['reservations']++;
                }
                Audit::log('demo_seed', 'demo', 'scenario=fully_booked');
                Response::success($created, 'Fully booked scenario ready');
            }

            // lunch_rush / dinner_rush
            $count = $scenario === 'dinner_rush' ? 6 : 4;
            for ($i = 0; $i < $count; $i++) {
                $t = $tables[$i % max(1, count($tables))];
                $item = $menuIds[$i % max(1, count($menuIds))];
                $num = 'ORD-DEMO-' . strtoupper(bin2hex(random_bytes(2)));
                $subtotal = (float)$item['price'];
                $tax = round($subtotal * 0.12, 2);
                $total = $subtotal + $tax;
                $pdo->prepare(
                    "INSERT INTO orders (order_number, table_id, customer_id, customer_name, status, source, subtotal, tax, total)
                     VALUES (?,?,?,?, 'preparing', 'qr', ?, ?, ?)"
                )->execute([$num, (int)$t['id'], $guestId ?: null, 'Demo Diner ' . ($i + 1), $subtotal, $tax, $total]);
                $oid = (int)$pdo->lastInsertId();
                $pdo->prepare(
                    'INSERT INTO order_items (order_id, menu_item_id, item_name, unit_price, quantity, notes, status)
                     VALUES (?,?,?,?,?,?,?)'
                )->execute([$oid, (int)$item['id'], $item['name'], $subtotal, 1, '{"Spice level":"Medium"}', 'preparing']);
                $pdo->prepare(
                    "INSERT INTO kitchen_tickets (order_id, order_number, table_label, status, started_at)
                     VALUES (?,?,?, 'in_progress', DATE_SUB(NOW(), INTERVAL ? MINUTE))"
                )->execute([$oid, $num, $t['table_number'], 5 + $i * 3]);
                $pdo->prepare("UPDATE dining_tables SET status='occupied' WHERE id=?")->execute([(int)$t['id']]);
                $created['orders']++;
            }

            $pdo->prepare(
                "INSERT INTO service_requests (table_id, customer_id, request_type, note, status)
                 VALUES (?, ?, 'bill', 'Demo bill request', 'open')"
            )->execute([(int)$tables[0]['id'], $guestId ?: null]);
            $pdo->prepare(
                "INSERT INTO service_requests (table_id, request_type, note, status)
                 VALUES (?, 'water', 'Demo water refill', 'open')"
            )->execute([(int)$tables[1]['id'] ?? (int)$tables[0]['id']]);
            $created['requests'] += 2;

            $pdo->prepare('INSERT INTO notifications (role_target, title, body, type) VALUES (?,?,?,?)')
                ->execute(['kitchen', 'Demo kitchen queue', ucfirst(str_replace('_', ' ', $scenario)) . ' orders loaded.', 'order']);
            $pdo->prepare('INSERT INTO notifications (role_target, title, body, type) VALUES (?,?,?,?)')
                ->execute(['cashier', 'Demo service requests', 'Open bill and water requests on the floor.', 'service_request']);
            $created['notifications'] += 2;

            Audit::log('demo_seed', 'demo', 'scenario=' . $scenario);
            Response::success($created, 'Demo scenario seeded: ' . $scenario, 201);
        }

        Response::error('Demo route not found', 404);
    }
}

(new DemoService())->handle();
