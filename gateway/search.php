<?php
/**
 * Global staff search across orders, reservations, customers, menu, inventory.
 */
require_once __DIR__ . '/../shared/Response.php';
require_once __DIR__ . '/../shared/Database.php';
require_once __DIR__ . '/../shared/Auth.php';
require_once __DIR__ . '/../shared/ServiceHelpers.php';

class SearchService
{
    use ServiceHelpers;

    public function handle(): void
    {
        Auth::requireLogin(['admin', 'cashier', 'kitchen']);
        $q = trim((string)$this->query('q', ''));
        if (strlen($q) < 2) {
            Response::success(['query' => $q, 'results' => []]);
        }
        $pdo = Database::connection();
        $like = '%' . $q . '%';
        $results = [];

        $orders = $pdo->prepare(
            "SELECT id, order_number, customer_name, status, total FROM orders
             WHERE order_number LIKE ? OR customer_name LIKE ? ORDER BY id DESC LIMIT 8"
        );
        $orders->execute([$like, $like]);
        foreach ($orders->fetchAll() as $r) {
            $results[] = [
                'type' => 'order',
                'id' => (int)$r['id'],
                'title' => $r['order_number'],
                'subtitle' => ($r['customer_name'] ?: 'Guest') . ' · ' . $r['status'] . ' · ₱' . number_format((float)$r['total'], 2),
                'href' => 'orders.html',
            ];
        }

        $res = $pdo->prepare(
            "SELECT id, customer_name, reservation_date, reservation_time, status, party_size FROM reservations
             WHERE customer_name LIKE ? OR customer_email LIKE ? OR customer_phone LIKE ?
             ORDER BY reservation_date DESC LIMIT 8"
        );
        $res->execute([$like, $like, $like]);
        foreach ($res->fetchAll() as $r) {
            $results[] = [
                'type' => 'reservation',
                'id' => (int)$r['id'],
                'title' => $r['customer_name'],
                'subtitle' => $r['reservation_date'] . ' ' . substr((string)$r['reservation_time'], 0, 5) . ' · party ' . $r['party_size'] . ' · ' . $r['status'],
                'href' => 'reservations.html',
            ];
        }

        $users = $pdo->prepare(
            "SELECT id, name, email, role, phone FROM users
             WHERE (name LIKE ? OR email LIKE ? OR phone LIKE ?) AND is_active = 1
             ORDER BY role, name LIMIT 8"
        );
        $users->execute([$like, $like, $like]);
        foreach ($users->fetchAll() as $r) {
            $results[] = [
                'type' => 'person',
                'id' => (int)$r['id'],
                'title' => $r['name'],
                'subtitle' => $r['role'] . ' · ' . $r['email'],
                'href' => $r['role'] === 'customer' ? 'analytics.html' : 'dashboard.html',
            ];
        }

        $menu = $pdo->prepare(
            "SELECT id, name, price, is_available FROM menu_items WHERE name LIKE ? OR description LIKE ? ORDER BY name LIMIT 8"
        );
        $menu->execute([$like, $like]);
        foreach ($menu->fetchAll() as $r) {
            $results[] = [
                'type' => 'menu',
                'id' => (int)$r['id'],
                'title' => $r['name'],
                'subtitle' => '₱' . number_format((float)$r['price'], 2) . ($r['is_available'] ? '' : ' · unavailable'),
                'href' => 'menu.html',
            ];
        }

        $inv = $pdo->prepare(
            "SELECT id, name, quantity, unit, reorder_level FROM inventory_items WHERE name LIKE ? OR sku LIKE ? ORDER BY name LIMIT 8"
        );
        $inv->execute([$like, $like]);
        foreach ($inv->fetchAll() as $r) {
            $results[] = [
                'type' => 'inventory',
                'id' => (int)$r['id'],
                'title' => $r['name'],
                'subtitle' => $r['quantity'] . ' ' . $r['unit'] . ( (float)$r['quantity'] <= (float)$r['reorder_level'] ? ' · LOW' : ''),
                'href' => 'inventory.html',
            ];
        }

        Response::success(['query' => $q, 'results' => $results, 'count' => count($results)]);
    }
}

(new SearchService())->handle();
