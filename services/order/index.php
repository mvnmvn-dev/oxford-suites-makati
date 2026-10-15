<?php
require_once __DIR__ . '/../../shared/Response.php';
require_once __DIR__ . '/../../shared/Database.php';
require_once __DIR__ . '/../../shared/Auth.php';
require_once __DIR__ . '/../../shared/EventBus.php';
require_once __DIR__ . '/../../shared/ServiceHelpers.php';
require_once __DIR__ . '/../../shared/InventorySync.php';
require_once __DIR__ . '/../../shared/bootstrap_events.php';
require_once __DIR__ . '/../../shared/Security.php';

class OrderService
{
    use ServiceHelpers;

    public function handle(): void
    {
        $sub = $_SERVER['RMS_SUBPATH'] ?? '';
        $method = $this->method();
        $pdo = Database::connection();
        Security::ensureSchema();

        if ($method === 'GET' && $sub === '') {
            $status = $this->query('status');
            $mine = $this->query('mine');
            $sql = 'SELECT o.*, t.table_number,
                           COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.order_id=o.id AND p.status="completed"),0) AS paid_total,
                           GREATEST(0, o.total - COALESCE((SELECT SUM(p2.amount) FROM payments p2 WHERE p2.order_id=o.id AND p2.status="completed"),0)) AS payment_remaining
                    FROM orders o LEFT JOIN dining_tables t ON t.id = o.table_id';
            $params = [];
            $where = [];
            if ($mine === '1') {
                $user = Auth::requireLogin(['customer', 'admin']);
                if ($user['role'] === 'customer') {
                    $where[] = 'o.customer_id = ?';
                    $params[] = (int)$user['id'];
                }
            }
            if ($status) {
                $where[] = 'o.status = ?';
                $params[] = $status;
            }
            if ($where) {
                $sql .= ' WHERE ' . implode(' AND ', $where);
            }
            $sql .= ' ORDER BY o.created_at DESC LIMIT 100';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            if ($mine === '1') {
                foreach ($rows as &$row) {
                    $items = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ?');
                    $items->execute([(int)$row['id']]);
                    $row['items'] = $items->fetchAll();
                }
                unset($row);
            }
            Response::success($rows);
        }

        if ($method === 'GET' && preg_match('/^(\d+)$/', $sub, $m)) {
            $order = $this->fetchOrder($pdo, (int)$m[1]);
            if (!$order) {
                Response::error('Order not found', 404);
            }
            $user = Auth::user();
            if (($user['role'] ?? '') === 'customer' && (int)$order['customer_id'] !== (int)$user['id']) {
                Response::error('Order not found', 404);
            }
            Response::success($order);
        }

        if ($method === 'POST' && $sub === '') {
            $b = $this->body();
            $items = $b['items'] ?? [];
            if (!$items || !is_array($items)) {
                Response::error('Order items required');
            }
            $config = require __DIR__ . '/../../config/app.php';
            $hours = $config['order_hours'] ?? ['open' => '10:00', 'close' => '22:00'];
            $now = new DateTimeImmutable('now');
            $scheduledFor = null;
            $orderWindow = 'standard';
            if (!empty($b['scheduled_for'])) {
                try {
                    $scheduledFor = new DateTimeImmutable((string)$b['scheduled_for']);
                } catch (Throwable $e) {
                    Response::error('scheduled_for must be a valid datetime');
                }
            }
            $target = $scheduledFor ?: $now;
            $targetTod = $target->format('H:i');
            $openTod = ($hours['open'] ?? '10:00');
            $closeTod = ($hours['close'] ?? '22:00');
            if ($targetTod < $openTod || $targetTod > $closeTod) {
                Response::error('Orders are accepted between ' . $openTod . ' and ' . $closeTod);
            }
            $advanceMin = max(30, (int)($config['order_advance_minutes'] ?? 120));
            $rushMin = max(15, (int)($config['order_rush_minutes'] ?? 45));
            $diffMin = (int)round(($target->getTimestamp() - $now->getTimestamp()) / 60);
            if ($scheduledFor && $diffMin >= $advanceMin) {
                $orderWindow = 'advance';
            } elseif ($diffMin <= $rushMin) {
                $orderWindow = 'rush';
            }
            if (!empty($b['order_window']) && in_array($b['order_window'], ['standard', 'advance', 'rush'], true)) {
                // Allow explicit override from POS when classifying a ticket.
                $orderWindow = $b['order_window'];
            }

            $tableId = !empty($b['table_id']) ? (int)$b['table_id'] : null;
            if ($tableId && ($b['source'] ?? 'qr') === 'qr') {
                $session = $pdo->prepare("SELECT ts.id FROM table_sessions ts JOIN dining_tables dt ON dt.table_number=ts.table_number WHERE ts.session_token=? AND ts.status='ACTIVE' AND ts.expires_at > NOW() AND dt.id=? LIMIT 1");
                $session->execute([(string)($b['session_token'] ?? ''), $tableId]);
                if (!$session->fetch()) Response::error('This QR table session has expired. Scan the table QR again.', 403);
            }

            $pdo->beginTransaction();
            try {
                $subtotal = 0;
                $lineRows = [];
                foreach ($items as $line) {
                    $menuId = (int)($line['menu_item_id'] ?? 0);
                    $qty = max(1, (int)($line['quantity'] ?? 1));
                    $stmt = $pdo->prepare('SELECT * FROM menu_items WHERE id = ? AND is_available = 1');
                    $stmt->execute([$menuId]);
                    $menu = $stmt->fetch();
                    if (!$menu) {
                        throw new RuntimeException('Unavailable menu item: ' . $menuId);
                    }
                    InventorySync::assertCanFulfill($pdo, $menuId, $qty, $menu['name']);
                    $unitPrice = (float)$menu['price'];
                    $customization = $line['customization'] ?? null;
                    $notes = $line['notes'] ?? null;
                    if (is_array($customization) && $customization) {
                        $extra = 0.0;
                        $display = null;
                        if (isset($customization['_display']) && is_array($customization['_display'])) {
                            $display = $customization['_display'];
                            unset($customization['_display']);
                        }
                        foreach ($customization as $group => $value) {
                            if ($group === '_display') {
                                continue;
                            }
                            $keys = is_array($value) ? $value : [$value];
                            foreach ($keys as $key) {
                                $opt = $pdo->prepare(
                                    'SELECT price_delta FROM menu_item_options
                                     WHERE is_active=1 AND option_group=? AND option_key=?
                                       AND (menu_item_id IS NULL OR menu_item_id=?) LIMIT 1'
                                );
                                $opt->execute([(string)$group, (string)$key, $menuId]);
                                $row = $opt->fetch();
                                if ($row) {
                                    $extra += (float)$row['price_delta'];
                                }
                            }
                        }
                        $unitPrice += $extra;
                        $notes = json_encode($display ?: $customization, JSON_UNESCAPED_UNICODE);
                    } elseif (!empty($line['customization']) && !is_array($line['customization'])) {
                        $notes = is_string($line['customization']) ? $line['customization'] : $notes;
                    }
                    $lineTotal = $unitPrice * $qty;
                    $subtotal += $lineTotal;
                    $lineRows[] = [
                        'menu_item_id' => $menuId,
                        'item_name' => $menu['name'],
                        'unit_price' => $unitPrice,
                        'quantity' => $qty,
                        'notes' => $notes,
                    ];
                }

                $tax = round($subtotal * 0.12, 2);
                $total = $subtotal + $tax;
                $orderNumber = 'ORD-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));

                $customerId = !empty($b['customer_id']) ? (int)$b['customer_id'] : null;
                $authUser = Auth::user();
                if (!$customerId && $authUser && ($authUser['role'] ?? '') === 'customer') {
                    $customerId = (int)$authUser['id'];
                    if (empty($b['customer_name'])) {
                        $b['customer_name'] = $authUser['name'] ?? 'Guest';
                    }
                }
                $ins = $pdo->prepare(
                    'INSERT INTO orders (order_number, table_id, customer_id, customer_name, status, source, order_window, scheduled_for, subtotal, tax, total, notes)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $ins->execute([
                    $orderNumber,
                    $tableId,
                    $customerId,
                    $b['customer_name'] ?? 'Guest',
                    'pending',
                    $b['source'] ?? 'qr',
                    $orderWindow,
                    $scheduledFor ? $scheduledFor->format('Y-m-d H:i:s') : null,
                    $subtotal,
                    $tax,
                    $total,
                    $b['notes'] ?? null,
                ]);
                $orderId = (int)$pdo->lastInsertId();

                $oi = $pdo->prepare(
                    'INSERT INTO order_items (order_id, menu_item_id, item_name, unit_price, quantity, notes) VALUES (?, ?, ?, ?, ?, ?)'
                );
                foreach ($lineRows as $lr) {
                    $oi->execute([$orderId, $lr['menu_item_id'], $lr['item_name'], $lr['unit_price'], $lr['quantity'], $lr['notes']]);
                }

                InventorySync::deductForOrder($pdo, $orderId);

                if ($tableId) {
                    $pdo->prepare("UPDATE dining_tables SET status = 'occupied' WHERE id = ? AND status = 'available'")
                        ->execute([$tableId]);
                }

                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                Response::error($e->getMessage(), 400);
            }

            EventBus::publish('OrderCreated', [
                'order_id' => $orderId,
                'order_number' => $orderNumber,
                'total' => $total,
                'order_window' => $orderWindow,
            ]);

            $windowLabel = match ($orderWindow) {
                'advance' => 'Advance order',
                'rush' => 'Rush order',
                default => 'Order',
            };
            $pdo->prepare('INSERT INTO notifications (role_target, title, body, type) VALUES (?,?,?,?)')
                ->execute([
                    'kitchen',
                    $windowLabel . ' ' . $orderNumber,
                    'Table order ready for kitchen · ₱' . number_format($total, 2),
                    'order',
                ]);
            $pdo->prepare('INSERT INTO notifications (role_target, title, body, type) VALUES (?,?,?,?)')
                ->execute([
                    'cashier',
                    $windowLabel . ' ' . $orderNumber,
                    'Guest order received · ₱' . number_format($total, 2),
                    'order',
                ]);
            $customerId = !empty($b['customer_id']) ? (int)$b['customer_id'] : null;
            if (!$customerId) {
                $authUser = Auth::user();
                if ($authUser && ($authUser['role'] ?? '') === 'customer') {
                    $customerId = (int)$authUser['id'];
                }
            }
            if ($customerId) {
                $pdo->prepare('INSERT INTO notifications (user_id, role_target, title, body, type) VALUES (?,?,?,?,?)')
                    ->execute([
                        $customerId,
                        'customer',
                        'Order placed',
                        $orderNumber . ' · ₱' . number_format($total, 2) . ' is with the kitchen.',
                        'order',
                    ]);
            }

            Response::success($this->fetchOrder($pdo, $orderId), 'Order created', 201);
        }

        if ($method === 'PATCH' && preg_match('/^(\d+)\/status$/', $sub, $m)) {
            Auth::requireLogin(['admin', 'kitchen', 'cashier']);
            $b = $this->body();
            $status = $b['status'] ?? '';
            $allowed = ['pending','confirmed','preparing','ready','served','paid','cancelled'];
            if (!in_array($status, $allowed, true)) {
                Response::error('Invalid status');
            }
            $orderId = (int)$m[1];
            $prev = $pdo->prepare('SELECT status, customer_id, order_number FROM orders WHERE id = ?');
            $prev->execute([$orderId]);
            $prevRow = $prev->fetch();
            if (!$prevRow) {
                Response::error('Order not found', 404);
            }
            if ($status === 'cancelled' && $prevRow['status'] !== 'cancelled') {
                Security::requireManagerPin($b, 'void order', $orderId);
                if (trim((string)($b['cancel_reason'] ?? '')) === '') {
                    Response::error('Cancellation reason is required');
                }
            }

            $pdo->beginTransaction();
            try {
                if ($status === 'cancelled' && $prevRow['status'] !== 'cancelled') {
                    $pdo->prepare('UPDATE orders SET status = ?, cancel_reason = ? WHERE id = ?')
                        ->execute([$status, trim((string)$b['cancel_reason']), $orderId]);
                } else {
                    $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([$status, $orderId]);
                }
                if ($status === 'preparing') {
                    $pdo->prepare("UPDATE kitchen_tickets SET status='in_progress', started_at=NOW() WHERE order_id=?")->execute([$orderId]);
                }
                if ($status === 'ready') {
                    $pdo->prepare("UPDATE kitchen_tickets SET status='ready', ready_at=NOW() WHERE order_id=?")->execute([$orderId]);
                }
                if ($status === 'cancelled' && $prevRow['status'] !== 'cancelled') {
                    InventorySync::restockForOrder($pdo, $orderId);
                    $pdo->prepare("UPDATE kitchen_tickets SET status='bumped' WHERE order_id=? AND status IN ('new','in_progress','ready')")
                        ->execute([$orderId]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                Response::error($e->getMessage(), 400);
            }
            $updatedOrder = $this->fetchOrder($pdo, $orderId);
            if ($updatedOrder && !empty($prevRow['customer_id']) && $prevRow['status'] !== $status) {
                $statusLabel = match ($status) {
                    'pending', 'confirmed' => 'Order received',
                    'preparing' => 'Preparing your order',
                    'ready' => 'Ready to serve',
                    'served', 'paid' => 'Served',
                    'cancelled' => 'Order cancelled',
                    default => ucfirst((string)$status),
                };
                $pdo->prepare('INSERT INTO notifications (user_id, role_target, title, body, type) VALUES (?,?,?,?,?)')
                    ->execute([
                        (int)$prevRow['customer_id'],
                        'customer',
                        'Order progress update',
                        $prevRow['order_number'] . ' · ' . $statusLabel,
                        'order_progress',
                    ]);
            }
            Response::success($updatedOrder, 'Status updated');
        }

        Response::error('Order route not found', 404);
    }

    private function fetchOrder(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT o.*, t.table_number,
                                      COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.order_id=o.id AND p.status="completed"),0) AS paid_total,
                                      GREATEST(0, o.total - COALESCE((SELECT SUM(p2.amount) FROM payments p2 WHERE p2.order_id=o.id AND p2.status="completed"),0)) AS payment_remaining
                               FROM orders o LEFT JOIN dining_tables t ON t.id = o.table_id WHERE o.id = ?');
        $stmt->execute([$id]);
        $order = $stmt->fetch();
        if (!$order) {
            return null;
        }
        $items = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ?');
        $items->execute([$id]);
        $order['items'] = $items->fetchAll();
        $timeline = [
            ['key' => 'pending', 'label' => 'Order received', 'complete' => true],
            ['key' => 'preparing', 'label' => 'Preparing', 'complete' => in_array($order['status'], ['preparing', 'ready', 'served', 'paid'], true)],
            ['key' => 'ready', 'label' => 'Ready to serve', 'complete' => in_array($order['status'], ['ready', 'served', 'paid'], true)],
            ['key' => 'served', 'label' => 'Served', 'complete' => in_array($order['status'], ['served', 'paid'], true)],
        ];
        $order['timeline'] = $timeline;
        $order['status_label'] = match ($order['status']) {
            'pending', 'confirmed' => 'Order received',
            'preparing' => 'Preparing your order',
            'ready' => 'Ready to serve',
            'served', 'paid' => 'Served',
            'cancelled' => 'Order cancelled',
            default => ucfirst((string)$order['status']),
        };

        // Estimated prep from menu items + kitchen ticket progress
        $prepStmt = $pdo->prepare(
            'SELECT COALESCE(MAX(mi.prep_minutes), 15) AS est_prep_minutes
             FROM order_items oi
             LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id
             WHERE oi.order_id = ?'
        );
        $prepStmt->execute([$id]);
        $estPrep = (int)($prepStmt->fetchColumn() ?: 15);
        $order['est_prep_minutes'] = $estPrep;

        $kt = $pdo->prepare(
            "SELECT status, started_at, ready_at, created_at,
                    TIMESTAMPDIFF(SECOND, COALESCE(started_at, created_at), NOW()) AS elapsed_seconds
             FROM kitchen_tickets WHERE order_id = ? ORDER BY id DESC LIMIT 1"
        );
        $kt->execute([$id]);
        $ticket = $kt->fetch();
        $elapsed = $ticket ? max(0, (int)$ticket['elapsed_seconds']) : 0;
        if ($ticket && in_array($ticket['status'], ['ready', 'bumped'], true)) {
            $order['eta_minutes'] = 0;
            $order['eta_label'] = 'Ready now';
            $order['eta_progress'] = 100;
        } elseif (in_array($order['status'], ['served', 'paid'], true)) {
            $order['eta_minutes'] = 0;
            $order['eta_label'] = 'Served';
            $order['eta_progress'] = 100;
        } elseif (in_array($order['status'], ['cancelled'], true)) {
            $order['eta_minutes'] = null;
            $order['eta_label'] = 'Cancelled';
            $order['eta_progress'] = 0;
        } else {
            $remaining = max(0, ($estPrep * 60) - $elapsed);
            $order['eta_minutes'] = (int)ceil($remaining / 60);
            $order['eta_label'] = $order['eta_minutes'] <= 1
                ? 'About 1 minute'
                : 'About ' . $order['eta_minutes'] . ' minutes';
            $order['eta_progress'] = min(100, (int)round(($elapsed / max(1, $estPrep * 60)) * 100));
        }
        $order['elapsed_seconds'] = $elapsed;
        if ($ticket) {
            $order['kitchen_status'] = $ticket['status'];
        }

        // Queue position among open kitchen tickets
        $queuePos = null;
        if (!in_array($order['status'], ['served', 'paid', 'cancelled', 'ready'], true)) {
            $pos = $pdo->prepare(
                "SELECT COUNT(*) FROM kitchen_tickets
                 WHERE status IN ('new','in_progress')
                   AND created_at <= COALESCE(
                     (SELECT created_at FROM kitchen_tickets WHERE order_id=? LIMIT 1), NOW())"
            );
            $pos->execute([$id]);
            $queuePos = max(1, (int)$pos->fetchColumn());
        } elseif ($order['status'] === 'ready') {
            $queuePos = 0;
        }
        $order['queue_position'] = $queuePos;
        $order['last_updated'] = $order['updated_at'] ?? $order['created_at'];

        return $order;
    }
}

(new OrderService())->handle();
