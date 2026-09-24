<?php
require_once __DIR__ . '/../../shared/Response.php';
require_once __DIR__ . '/../../shared/Database.php';
require_once __DIR__ . '/../../shared/Auth.php';
require_once __DIR__ . '/../../shared/ServiceHelpers.php';
require_once __DIR__ . '/../../shared/Security.php';

class TableService
{
    use ServiceHelpers;

    public function handle(): void
    {
        $sub = $_SERVER['RMS_SUBPATH'] ?? '';
        $method = $this->method();
        $pdo = Database::connection();
        Security::ensureSchema();
        $config = require __DIR__ . '/../../config/app.php';
        $base = rtrim($config['base_url'], '/');

        if ($method === 'GET' && $sub === '') {
            $rows = $pdo->query(
                "SELECT dt.*,
                        COALESCE((SELECT COUNT(*) FROM orders o WHERE o.table_id = dt.id AND o.status NOT IN ('paid','cancelled')), 0) AS open_orders,
                        COALESCE((SELECT SUM(o.total) FROM orders o WHERE o.table_id = dt.id AND o.status NOT IN ('paid','cancelled')), 0) AS current_bill,
                        (SELECT MAX(o.created_at) FROM orders o WHERE o.table_id = dt.id AND o.status NOT IN ('paid','cancelled')) AS seated_at,
                        (SELECT MAX(o.customer_name) FROM orders o WHERE o.table_id = dt.id AND o.status NOT IN ('paid','cancelled')) AS guest_name
                 FROM dining_tables dt ORDER BY dt.table_number"
            )->fetchAll();
            foreach ($rows as &$r) {
                $r['qr_url'] = $base . '/public/customer/menu.html?token=' . urlencode($r['qr_token']);
                $r['display_status'] = $r['status'];
                // Reserved within next 2 hours → "reserved_soon" for floor UI
                if ($r['status'] === 'available' || $r['status'] === 'reserved') {
                    $soon = $pdo->prepare(
                        "SELECT reservation_time, customer_name FROM reservations
                         WHERE table_id = ? AND reservation_date = CURDATE()
                           AND status IN ('pending','confirmed')
                           AND reservation_time BETWEEN CURTIME() AND ADDTIME(CURTIME(), '02:00:00')
                         ORDER BY reservation_time ASC
                         LIMIT 1"
                    );
                    $soon->execute([(int)$r['id']]);
                    $next = $soon->fetch();
                    if ($next) {
                        $r['display_status'] = 'reserved_soon';
                        $r['next_reservation'] = substr((string)$next['reservation_time'], 0, 5)
                            . (!empty($next['customer_name']) ? ' · ' . $next['customer_name'] : '');
                    }
                }
            }
            Response::success($rows);
        }

        if ($method === 'GET' && preg_match('/^token\/(.+)$/', $sub, $m)) {
            $stmt = $pdo->prepare('SELECT * FROM dining_tables WHERE qr_token = ?');
            $stmt->execute([urldecode($m[1])]);
            $row = $stmt->fetch();
            if (!$row) {
                Response::error('Table not found', 404);
            }
            $row['qr_url'] = $base . '/public/customer/menu.html?token=' . urlencode($row['qr_token']);
            $sessionToken = bin2hex(random_bytes(32));
            $pdo->prepare("UPDATE table_sessions SET status='EXPIRED' WHERE table_number=? AND status='ACTIVE'")->execute([$row['table_number']]);
            $pdo->prepare("INSERT INTO table_sessions (session_token, table_number, expires_at, status) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 2 HOUR), 'ACTIVE')")->execute([$sessionToken, $row['table_number']]);
            $row['session_token'] = $sessionToken;
            Response::success($row);
        }

        if ($method === 'PATCH' && preg_match('/^(\d+)\/status$/', $sub, $m)) {
            Auth::requireLogin(['admin', 'cashier']);
            $b = $this->body();
            $status = $b['status'] ?? '';
            $allowed = ['available', 'occupied', 'reserved', 'cleaning'];
            if (!in_array($status, $allowed, true)) {
                Response::error('Invalid table status');
            }
            $pdo->prepare('UPDATE dining_tables SET status = ? WHERE id = ?')->execute([$status, (int)$m[1]]);
            Response::success(null, 'Table status updated');
        }

        if ($method === 'POST' && $sub === '') {
            Auth::requireLogin(['admin']);
            $b = $this->body();
            $token = 'qr_' . strtolower(preg_replace('/\s+/', '', $b['table_number'] ?? 't')) . '_' . bin2hex(random_bytes(4));
            $stmt = $pdo->prepare(
                'INSERT INTO dining_tables (table_number, capacity, zone, status, qr_token) VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $b['table_number'],
                (int)($b['capacity'] ?? 4),
                $b['zone'] ?? 'Main Dining',
                'available',
                $token,
            ]);
            Response::success(['id' => (int)$pdo->lastInsertId(), 'qr_token' => $token], 'Table created', 201);
        }

        Response::error('Table route not found', 404);
    }
}

(new TableService())->handle();
