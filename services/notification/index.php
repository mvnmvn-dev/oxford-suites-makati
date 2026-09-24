<?php
require_once __DIR__ . '/../../shared/Response.php';
require_once __DIR__ . '/../../shared/Database.php';
require_once __DIR__ . '/../../shared/Auth.php';
require_once __DIR__ . '/../../shared/ServiceHelpers.php';

class NotificationService
{
    use ServiceHelpers;

    private const REQUEST_TYPES = ['water', 'utensils', 'follow_up_order', 'bill', 'assistance'];

    public function handle(): void
    {
        $sub = $_SERVER['RMS_SUBPATH'] ?? '';
        $method = $this->method();
        $pdo = Database::connection();

        if ($method === 'GET' && $sub === '') {
            $user = Auth::requireLogin(['admin', 'cashier', 'kitchen', 'customer']);
            $role = $user['role'];
            $uid = (int)$user['id'];
            $stmt = $pdo->prepare(
                'SELECT * FROM notifications
                 WHERE (user_id = ? OR role_target = ? OR role_target IS NULL OR role_target = \'all\')
                 ORDER BY created_at DESC LIMIT 40'
            );
            $stmt->execute([$uid, $role]);
            $rows = $stmt->fetchAll();
            $unread = 0;
            foreach ($rows as $r) {
                if ((int)($r['is_read'] ?? 0) === 0) {
                    $unread++;
                }
            }
            $openRequests = 0;
            if (in_array($role, ['admin', 'cashier', 'kitchen'], true)) {
                $openRequests = (int)$pdo->query(
                    "SELECT COUNT(*) FROM service_requests WHERE status IN ('open','acknowledged')"
                )->fetchColumn();
            }
            Response::success([
                'items' => $rows,
                'unread' => $unread,
                'open_requests' => $openRequests,
                'badge' => $unread + $openRequests,
                'server_time' => date('c'),
            ]);
        }

        // Server-Sent Events stream for live badges (long-poll friendly for XAMPP)
        if ($method === 'GET' && $sub === 'stream') {
            $user = Auth::requireLogin(['admin', 'cashier', 'kitchen', 'customer']);
            // Critical: release session lock so other API calls (module loads) are not blocked
            // while this long-lived stream sleeps between polls.
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            @ini_set('zlib.output_compression', '0');
            @ini_set('implicit_flush', '1');
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no');
            $role = $user['role'];
            $uid = (int)$user['id'];
            $lastId = (int)($_GET['last_id'] ?? 0);
            $loops = 0;
            while ($loops < 25 && !connection_aborted()) {
                $stmt = $pdo->prepare(
                    'SELECT * FROM notifications
                     WHERE id > ? AND (user_id = ? OR role_target = ? OR role_target IS NULL OR role_target = \'all\')
                     ORDER BY id ASC LIMIT 20'
                );
                $stmt->execute([$lastId, $uid, $role]);
                $rows = $stmt->fetchAll();
                foreach ($rows as $r) {
                    $lastId = max($lastId, (int)$r['id']);
                    echo 'event: notification' . "\n";
                    echo 'data: ' . json_encode($r) . "\n\n";
                }
                $c = $pdo->prepare(
                    'SELECT COUNT(*) FROM notifications
                     WHERE is_read=0 AND (user_id=? OR role_target=? OR role_target IS NULL OR role_target=\'all\')'
                );
                $c->execute([$uid, $role]);
                $unread = (int)$c->fetchColumn();
                $openReq = 0;
                if (in_array($role, ['admin', 'cashier', 'kitchen'], true)) {
                    $openReq = (int)$pdo->query(
                        "SELECT COUNT(*) FROM service_requests WHERE status IN ('open','acknowledged')"
                    )->fetchColumn();
                }
                echo 'event: badge' . "\n";
                echo 'data: ' . json_encode(['badge' => $unread + $openReq, 'unread' => $unread, 'open_requests' => $openReq, 'last_id' => $lastId]) . "\n\n";
                flush();
                $loops++;
                sleep(2);
            }
            echo 'event: ping' . "\n";
            echo 'data: {"ok":true}' . "\n\n";
            flush();
            exit;
        }

        if ($method === 'GET' && $sub === 'requests') {
            Auth::requireLogin(['admin', 'cashier', 'kitchen']);
            $status = $this->query('status', 'open');
            $statuses = $status === 'active' ? "sr.status IN ('open','acknowledged')" : 'sr.status = ?';
            $sql = "SELECT sr.*, dt.table_number, u.name AS customer_name,
                           TIMESTAMPDIFF(SECOND, sr.created_at, NOW()) AS wait_seconds,
                           TIMESTAMPDIFF(SECOND, sr.created_at, COALESCE(sr.acknowledged_at, NOW())) AS ack_seconds,
                           CASE sr.request_type
                             WHEN 'bill' THEN 'high'
                             WHEN 'assistance' THEN 'high'
                             WHEN 'water' THEN 'medium'
                             WHEN 'follow_up_order' THEN 'medium'
                             ELSE 'low'
                           END AS priority
                    FROM service_requests sr
                    LEFT JOIN dining_tables dt ON dt.id = sr.table_id
                    LEFT JOIN users u ON u.id = sr.customer_id
                    WHERE " . ($status === 'active' ? $statuses : 'sr.status = ?') . "
                    ORDER BY FIELD(priority,'high','medium','low'), sr.created_at ASC";
            $stmt = $pdo->prepare($sql);
            if ($status === 'active') {
                $stmt->execute();
            } else {
                $stmt->execute([$status]);
            }
            Response::success($stmt->fetchAll());
        }

        if ($method === 'GET' && $sub === 'request-stats') {
            Auth::requireLogin(['admin', 'cashier']);
            $avgAck = (float)$pdo->query(
                "SELECT COALESCE(AVG(TIMESTAMPDIFF(SECOND, created_at, acknowledged_at)),0)
                 FROM service_requests WHERE acknowledged_at IS NOT NULL
                 AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
            )->fetchColumn();
            $avgComplete = (float)$pdo->query(
                "SELECT COALESCE(AVG(TIMESTAMPDIFF(SECOND, created_at, completed_at)),0)
                 FROM service_requests WHERE completed_at IS NOT NULL
                 AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
            )->fetchColumn();
            $pending = (int)$pdo->query("SELECT COUNT(*) FROM service_requests WHERE status IN ('open','acknowledged')")->fetchColumn();
            $completed = (int)$pdo->query(
                "SELECT COUNT(*) FROM service_requests WHERE status='completed' AND DATE(completed_at)=CURDATE()"
            )->fetchColumn();
            Response::success([
                'avg_ack_seconds' => round($avgAck),
                'avg_complete_seconds' => round($avgComplete),
                'avg_response_minutes' => round($avgAck / 60, 1),
                'pending' => $pending,
                'completed_today' => $completed,
            ]);
        }

        if ($method === 'POST' && $sub === 'requests') {
            $user = Auth::requireLogin(['customer']);
            $body = $this->body();
            $type = (string)($body['request_type'] ?? '');
            if (!in_array($type, self::REQUEST_TYPES, true)) {
                Response::error('Invalid service request type');
            }
            $tableId = !empty($body['table_id']) ? (int)$body['table_id'] : null;
            if (!$tableId) {
                Response::error('Table is required for a service request');
            }
            $table = $pdo->prepare('SELECT id, table_number FROM dining_tables WHERE id = ?');
            $table->execute([$tableId]);
            $trow = $table->fetch();
            if (!$trow) {
                Response::error('Table not found', 404);
            }
            $customerId = (int)$user['id'];
            $note = trim((string)($body['note'] ?? ''));
            $stmt = $pdo->prepare(
                'INSERT INTO service_requests (table_id, customer_id, request_type, note) VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$tableId, $customerId, $type, $note !== '' ? substr($note, 0, 255) : null]);
            $id = (int)$pdo->lastInsertId();
            $labels = [
                'water' => 'Water refill', 'utensils' => 'Extra utensils',
                'follow_up_order' => 'Follow-up order', 'bill' => 'Request bill',
                'assistance' => 'Guest assistance',
            ];
            $guestName = trim((string)($user['name'] ?? 'Guest'));
            $title = ($labels[$type] ?? 'Service request') . ' · Table ' . $trow['table_number'];
            $bodyText = $guestName . ' requested service' . ($note ? ': ' . $note : '');
            $pdo->prepare('INSERT INTO notifications (role_target, title, body, type) VALUES (?,?,?,?)')
                ->execute(['cashier', $title, $bodyText, 'service_request']);
            $pdo->prepare('INSERT INTO notifications (role_target, title, body, type) VALUES (?,?,?,?)')
                ->execute(['admin', $title, $bodyText, 'service_request']);
            Response::success(['id' => $id, 'status' => 'open'], 'Request sent', 201);
        }

        if ($method === 'PATCH' && preg_match('/^requests\/(\d+)\/status$/', $sub, $m)) {
            Auth::requireLogin(['admin', 'cashier', 'kitchen']);
            $status = (string)($this->body()['status'] ?? '');
            if (!in_array($status, ['open', 'acknowledged', 'completed', 'cancelled'], true)) {
                Response::error('Invalid request status');
            }
            $id = (int)$m[1];
            $prev = $pdo->prepare('SELECT customer_id, request_type, table_id FROM service_requests WHERE id = ?');
            $prev->execute([$id]);
            $req = $prev->fetch();
            $sql = "UPDATE service_requests SET status = ?, acknowledged_at = CASE WHEN ? = 'acknowledged' AND acknowledged_at IS NULL THEN NOW() ELSE acknowledged_at END, completed_at = CASE WHEN ? IN ('completed', 'cancelled') THEN NOW() ELSE completed_at END WHERE id = ?";
            $pdo->prepare($sql)->execute([$status, $status, $status, $id]);
            if ($status === 'completed' && $req && !empty($req['customer_id'])) {
                $pdo->prepare('INSERT INTO notifications (user_id, role_target, title, body, type) VALUES (?,?,?,?,?)')
                    ->execute([
                        (int)$req['customer_id'],
                        'customer',
                        'Service request completed',
                        'Your ' . str_replace('_', ' ', (string)$req['request_type']) . ' request has been handled.',
                        'service_request',
                    ]);
            }
            Response::success(null, 'Service request updated');
        }

        if ($method === 'PATCH' && $sub === 'read-all') {
            $user = Auth::requireLogin(['admin', 'cashier', 'kitchen', 'customer']);
            $pdo->prepare(
                'UPDATE notifications SET is_read = 1
                 WHERE is_read = 0 AND (user_id = ? OR role_target = ? OR role_target IS NULL OR role_target = \'all\')'
            )->execute([(int)$user['id'], $user['role']]);
            Response::success(null, 'All marked read');
        }

        if ($method === 'PATCH' && preg_match('/^(\d+)\/read$/', $sub, $m)) {
            Auth::requireLogin(['admin', 'cashier', 'kitchen', 'customer']);
            $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = ?')->execute([(int)$m[1]]);
            Response::success(null, 'Marked read');
        }

        Response::error('Notification route not found', 404);
    }
}

(new NotificationService())->handle();
