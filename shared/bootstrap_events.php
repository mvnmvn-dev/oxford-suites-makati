<?php
/**
 * Event subscribers — Order Created → Kitchen + sales ledger
 * Stock deduction runs inside OrderService (same DB transaction).
 */
require_once __DIR__ . '/EventBus.php';
require_once __DIR__ . '/Database.php';

function register_rms_event_handlers(): void
{
    static $registered = false;
    if ($registered) {
        return;
    }
    $registered = true;

    EventBus::subscribe('OrderCreated', function (array $payload) {
        $pdo = Database::connection();
        $orderId = (int)($payload['order_id'] ?? 0);
        if (!$orderId) {
            return;
        }

        $order = $pdo->prepare('SELECT o.*, t.table_number FROM orders o LEFT JOIN dining_tables t ON t.id = o.table_id WHERE o.id = ?');
        $order->execute([$orderId]);
        $row = $order->fetch();
        if (!$row) {
            return;
        }

        $exists = $pdo->prepare('SELECT id FROM kitchen_tickets WHERE order_id = ?');
        $exists->execute([$orderId]);
        if (!$exists->fetch()) {
            $ins = $pdo->prepare('INSERT INTO kitchen_tickets (order_id, order_number, table_label, status, priority) VALUES (?, ?, ?, ?, ?)');
            $ins->execute([
                $orderId,
                $row['order_number'],
                $row['table_number'] ?: strtoupper($row['source']),
                'new',
                0,
            ]);
        }

        $pdo->prepare("UPDATE orders SET status = 'confirmed' WHERE id = ? AND status = 'pending'")->execute([$orderId]);
        // Kitchen/cashier notifications are created by OrderService to avoid duplicates.
    });

    EventBus::subscribe('OrderCreated', function (array $payload) {
        $pdo = Database::connection();
        $orderId = (int)($payload['order_id'] ?? 0);
        if (!$orderId) {
            return;
        }

        $items = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ?');
        $items->execute([$orderId]);
        foreach ($items->fetchAll() as $item) {
            $pdo->prepare(
                'INSERT INTO sales_daily (sale_date, menu_item_id, quantity_sold, revenue)
                 VALUES (CURDATE(), ?, ?, ?)
                 ON DUPLICATE KEY UPDATE quantity_sold = quantity_sold + VALUES(quantity_sold), revenue = revenue + VALUES(revenue)'
            )->execute([
                (int)$item['menu_item_id'],
                (int)$item['quantity'],
                (float)$item['unit_price'] * (int)$item['quantity'],
            ]);
        }
    });
}

register_rms_event_handlers();
