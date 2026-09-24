<?php
/**
 * Audit logs + activity timeline for managers.
 */
require_once __DIR__ . '/../shared/Response.php';
require_once __DIR__ . '/../shared/Database.php';
require_once __DIR__ . '/../shared/Auth.php';
require_once __DIR__ . '/../shared/ServiceHelpers.php';
require_once __DIR__ . '/../shared/Security.php';

class AuditService
{
    use ServiceHelpers;

    public function handle(): void
    {
        Auth::requireLogin(['admin']);
        $sub = $_SERVER['RMS_SUBPATH'] ?? '';
        $method = $this->method();
        $pdo = Database::connection();
        Security::ensureSchema();

        // Ensure table exists on older DBs
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS audit_logs (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              user_id INT UNSIGNED DEFAULT NULL,
              user_name VARCHAR(120) DEFAULT NULL,
              role VARCHAR(40) DEFAULT NULL,
              action VARCHAR(80) NOT NULL,
              module VARCHAR(60) NOT NULL DEFAULT 'system',
              details TEXT,
              ip_address VARCHAR(45) DEFAULT NULL,
              created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              INDEX idx_audit_created (created_at),
              INDEX idx_audit_module (module),
              INDEX idx_audit_user (user_id)
            ) ENGINE=InnoDB"
        );

        if ($method === 'GET' && ($sub === '' || $sub === 'logs')) {
            $module = $this->query('module', '');
            $user = $this->query('user', '');
            $date = $this->query('date', '');
            $sql = 'SELECT * FROM audit_logs WHERE 1=1';
            $params = [];
            if ($module !== '') {
                $sql .= ' AND module = ?';
                $params[] = $module;
            }
            if ($user !== '') {
                $sql .= ' AND (user_name LIKE ? OR user_id = ?)';
                $params[] = '%' . $user . '%';
                $params[] = (int)$user;
            }
            if ($date !== '') {
                $sql .= ' AND DATE(created_at) = ?';
                $params[] = $date;
            }
            $sql .= ' ORDER BY created_at DESC LIMIT 200';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            Response::success($stmt->fetchAll());
        }

        if ($method === 'GET' && $sub === 'security') {
            $widgets = [
                'successful_logins_today' => (int)$pdo->query("SELECT COUNT(*) FROM security_events WHERE event_type='successful_login' AND DATE(created_at)=CURDATE()")->fetchColumn(),
                'failed_login_attempts' => (int)$pdo->query("SELECT COUNT(*) FROM security_events WHERE event_type IN ('failed_login','account_locked') AND DATE(created_at)=CURDATE()")->fetchColumn(),
                'locked_accounts' => (int)$pdo->query("SELECT COUNT(*) FROM login_attempts WHERE locked_until IS NOT NULL AND locked_until > NOW()")->fetchColumn(),
                'trusted_devices' => (int)$pdo->query("SELECT COUNT(*) FROM trusted_devices WHERE expires_at IS NULL OR expires_at > NOW()")->fetchColumn(),
                'manager_pin_approvals' => (int)$pdo->query("SELECT COUNT(*) FROM manager_approvals WHERE DATE(created_at)=CURDATE()")->fetchColumn(),
                'suspicious_activities' => (int)$pdo->query("SELECT COUNT(*) FROM security_events WHERE severity IN ('warning','critical') AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn(),
                'manager_pin_configured' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin' AND is_active=1 AND manager_pin_hash IS NOT NULL")->fetchColumn(),
            ];
            $feed = $pdo->query("SELECT se.*, u.name AS user_name, u.role FROM security_events se LEFT JOIN users u ON u.id=se.user_id ORDER BY se.created_at DESC LIMIT 12")->fetchAll();
            Response::success(['widgets' => $widgets, 'feed' => $feed, 'refreshed_at' => date('c')]);
        }

        if ($method === 'GET' && $sub === 'activity') {
            $feed = [];
            $orders = $pdo->query(
                "SELECT id, order_number, status, customer_name, created_at, updated_at
                 FROM orders ORDER BY updated_at DESC LIMIT 15"
            )->fetchAll();
            foreach ($orders as $o) {
                $feed[] = [
                    'at' => $o['updated_at'] ?: $o['created_at'],
                    'type' => 'order',
                    'text' => 'Order ' . $o['order_number'] . ' is ' . $o['status'] . ($o['customer_name'] ? ' · ' . $o['customer_name'] : ''),
                ];
            }
            $pays = $pdo->query(
                "SELECT receipt_no, amount, method, created_at FROM payments WHERE status='completed' ORDER BY created_at DESC LIMIT 10"
            )->fetchAll();
            foreach ($pays as $p) {
                $feed[] = [
                    'at' => $p['created_at'],
                    'type' => 'payment',
                    'text' => 'Payment ' . ($p['receipt_no'] ?: '') . ' · ₱' . number_format((float)$p['amount'], 2) . ' via ' . $p['method'],
                ];
            }
            $fb = $pdo->query(
                "SELECT overall, created_at FROM feedback ORDER BY created_at DESC LIMIT 8"
            )->fetchAll();
            foreach ($fb as $f) {
                $feed[] = [
                    'at' => $f['created_at'],
                    'type' => 'feedback',
                    'text' => 'Customer rated ' . $f['overall'] . '/5',
                ];
            }
            $res = $pdo->query(
                "SELECT customer_name, status, reservation_date, updated_at, created_at FROM reservations ORDER BY updated_at DESC LIMIT 10"
            )->fetchAll();
            foreach ($res as $r) {
                $feed[] = [
                    'at' => $r['updated_at'] ?: $r['created_at'],
                    'type' => 'reservation',
                    'text' => 'Reservation ' . $r['status'] . ' · ' . $r['customer_name'] . ' · ' . $r['reservation_date'],
                ];
            }
            $kt = $pdo->query(
                "SELECT order_number, status, ready_at, created_at FROM kitchen_tickets ORDER BY id DESC LIMIT 10"
            )->fetchAll();
            foreach ($kt as $k) {
                $feed[] = [
                    'at' => $k['ready_at'] ?: $k['created_at'],
                    'type' => 'kitchen',
                    'text' => 'Kitchen ticket ' . $k['order_number'] . ' → ' . $k['status'],
                ];
            }
            usort($feed, fn($a, $b) => strcmp((string)$b['at'], (string)$a['at']));
            Response::success(array_slice($feed, 0, 40));
        }

        Response::error('Audit route not found', 404);
    }
}

(new AuditService())->handle();
