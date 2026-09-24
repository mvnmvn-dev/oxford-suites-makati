<?php
require_once __DIR__ . '/../../shared/Response.php';
require_once __DIR__ . '/../../shared/Database.php';
require_once __DIR__ . '/../../shared/Auth.php';
require_once __DIR__ . '/../../shared/ServiceHelpers.php';

class KitchenService
{
    use ServiceHelpers;

    public function handle(): void
    {
        $sub = $_SERVER['RMS_SUBPATH'] ?? '';
        $method = $this->method();
        $pdo = Database::connection();

        if ($method === 'GET' && ($sub === '' || $sub === 'tickets')) {
            $station = strtolower(trim((string)$this->query('station', '')));
            $tickets = $pdo->query(
                "SELECT kt.*, o.notes AS order_notes, o.status AS order_status, o.total, o.order_number,
                        COALESCE(dt.table_number, 'Walk-in') AS table_label,
                        TIMESTAMPDIFF(SECOND, COALESCE(kt.started_at, kt.created_at), NOW()) AS elapsed_seconds,
                        COALESCE(MAX(mi.prep_minutes), 15) AS prep_minutes
                 FROM kitchen_tickets kt
                 JOIN orders o ON o.id = kt.order_id
                 LEFT JOIN dining_tables dt ON dt.id = o.table_id
                 LEFT JOIN order_items oi ON oi.order_id = o.id
                 LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id
                 WHERE kt.status IN ('new','in_progress','ready')
                 GROUP BY kt.id
                 ORDER BY FIELD(kt.status,'new','in_progress','ready'),
                          CASE WHEN TIMESTAMPDIFF(SECOND, COALESCE(kt.started_at, kt.created_at), NOW()) > COALESCE(MAX(mi.prep_minutes),15)*60 THEN 0 ELSE 1 END,
                          kt.created_at ASC"
            )->fetchAll();

            $filtered = [];
            foreach ($tickets as $t) {
                $t['elapsed_seconds'] = (int)$t['elapsed_seconds'];
                $t['prep_minutes'] = (int)$t['prep_minutes'];
                $target = max(1, $t['prep_minutes'] * 60);
                $t['remaining_seconds'] = max(0, $target - $t['elapsed_seconds']);
                $t['is_warning'] = $t['status'] !== 'ready' && $t['elapsed_seconds'] >= (int)($target * 0.75) && $t['elapsed_seconds'] <= $target ? 1 : 0;
                $t['is_overdue'] = $t['status'] !== 'ready' && $t['elapsed_seconds'] > $target ? 1 : 0;
                $t['sla_state'] = $t['is_overdue'] ? 'overdue' : ($t['is_warning'] ? 'warning' : 'ok');
                $items = $pdo->prepare(
                    'SELECT oi.*, mi.station, mi.allergens FROM order_items oi
                     LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id
                     WHERE oi.order_id = ?'
                );
                $items->execute([(int)$t['order_id']]);
                $allItems = $items->fetchAll();
                $stations = array_unique(array_map(fn($i) => $i['station'] ?? 'general', $allItems));
                $t['stations'] = array_values($stations);
                if ($station && $station !== 'all') {
                    $allItems = array_values(array_filter($allItems, fn($i) => ($i['station'] ?? 'general') === $station));
                    if (!$allItems) {
                        continue;
                    }
                }
                $t['items'] = $allItems;
                $filtered[] = $t;
            }
            Response::success($filtered);
        }

        if ($method === 'PATCH' && preg_match('/^tickets\/(\d+)\/status$/', $sub, $m)) {
            Auth::requireLogin(['admin', 'kitchen']);
            $b = $this->body();
            $status = $b['status'] ?? '';
            $allowed = ['new', 'in_progress', 'ready', 'bumped'];
            if (!in_array($status, $allowed, true)) {
                Response::error('Invalid ticket status');
            }
            $id = (int)$m[1];
            if ($status === 'in_progress') {
                $pdo->prepare("UPDATE kitchen_tickets SET status=?, started_at=NOW() WHERE id=?")->execute([$status, $id]);
                $pdo->prepare("UPDATE orders o JOIN kitchen_tickets kt ON kt.order_id=o.id SET o.status='preparing' WHERE kt.id=?")->execute([$id]);
            } elseif ($status === 'ready') {
                $pdo->prepare("UPDATE kitchen_tickets SET status=?, ready_at=NOW() WHERE id=?")->execute([$status, $id]);
                $pdo->prepare("UPDATE orders o JOIN kitchen_tickets kt ON kt.order_id=o.id SET o.status='ready' WHERE kt.id=?")->execute([$id]);
                $pdo->prepare('INSERT INTO notifications (role_target, title, body, type) VALUES (?,?,?,?)')
                    ->execute(['cashier', 'Order ready', 'Kitchen ticket #' . $id . ' is ready for service', 'order']);
                $cust = $pdo->prepare(
                    'SELECT o.id, o.order_number, o.customer_id FROM orders o
                     JOIN kitchen_tickets kt ON kt.order_id = o.id WHERE kt.id = ?'
                );
                $cust->execute([$id]);
                $crow = $cust->fetch();
                if ($crow && !empty($crow['customer_id'])) {
                    $pdo->prepare('INSERT INTO notifications (user_id, role_target, title, body, type) VALUES (?,?,?,?,?)')
                        ->execute([
                            (int)$crow['customer_id'],
                            'customer',
                            'Your order is ready',
                            $crow['order_number'] . ' is ready to serve.',
                            'order_ready',
                        ]);
                }
            } elseif ($status === 'bumped') {
                $pdo->prepare("UPDATE kitchen_tickets SET status=? WHERE id=?")->execute([$status, $id]);
                $pdo->prepare("UPDATE orders o JOIN kitchen_tickets kt ON kt.order_id=o.id SET o.status='served' WHERE kt.id=?")->execute([$id]);
                $cust = $pdo->prepare(
                    'SELECT o.id, o.order_number, o.customer_id FROM orders o
                     JOIN kitchen_tickets kt ON kt.order_id = o.id WHERE kt.id = ?'
                );
                $cust->execute([$id]);
                $crow = $cust->fetch();
                if ($crow && !empty($crow['customer_id'])) {
                    $pdo->prepare('INSERT INTO notifications (user_id, role_target, title, body, type) VALUES (?,?,?,?,?)')
                        ->execute([
                            (int)$crow['customer_id'],
                            'customer',
                            'Order served',
                            $crow['order_number'] . ' has been served. Enjoy.',
                            'order_served',
                        ]);
                }
                if ($crow) {
                    $pdo->prepare('INSERT INTO notifications (role_target, title, body, type) VALUES (?,?,?,?)')
                        ->execute([
                            'cashier',
                            'Order served',
                            ($crow['order_number'] ?? ('Ticket #' . $id)) . ' is ready for the floor / check.',
                            'order',
                        ]);
                }
            } else {
                $pdo->prepare('UPDATE kitchen_tickets SET status=? WHERE id=?')->execute([$status, $id]);
            }
            Response::success(null, 'Ticket updated');
        }

        Response::error('Kitchen route not found', 404);
    }
}

(new KitchenService())->handle();
