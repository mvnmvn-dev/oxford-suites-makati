<?php
require_once __DIR__ . '/Database.php';

class Security
{
    public static function ensureSchema(): void
    {
        static $ready = false;
        if ($ready) return;
        $pdo = Database::connection();
        try {
            // Normal requests only perform metadata-free existence checks. Runtime
            // ALTER/CREATE statements can wait on MySQL metadata locks and make
            // protected actions appear to hang under concurrent restaurant use.
            $pdo->query('SELECT manager_pin_hash, totp_secret, totp_enabled FROM users LIMIT 0');
            $pdo->query('SELECT serve_time, cancel_reason FROM reservations LIMIT 0');
            $pdo->query('SELECT order_window, scheduled_for, cancel_reason, discount_id_number FROM orders LIMIT 0');
            $pdo->query('SELECT gateway_ref FROM payments LIMIT 0');
            $pdo->query('SELECT is_enabled FROM menu_items LIMIT 0');
            foreach (['trusted_devices', 'table_sessions', 'login_attempts', 'manager_approvals', 'security_events'] as $table) {
                $pdo->query("SELECT 1 FROM {$table} LIMIT 0");
            }
            $ready = true;
            return;
        } catch (Throwable $e) {
            // Fall through to the compatibility migration only for older installs.
        }
        $statements = [
            "ALTER TABLE users ADD COLUMN manager_pin_hash VARCHAR(255) NULL",
            "ALTER TABLE users ADD COLUMN totp_secret VARCHAR(64) DEFAULT NULL",
            "ALTER TABLE users ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0",
            "ALTER TABLE reservations ADD COLUMN serve_time TIME DEFAULT NULL",
            "ALTER TABLE reservations ADD COLUMN cancel_reason VARCHAR(255) DEFAULT NULL",
            "ALTER TABLE orders ADD COLUMN order_window ENUM('standard','advance','rush') NOT NULL DEFAULT 'standard'",
            "ALTER TABLE orders ADD COLUMN scheduled_for DATETIME DEFAULT NULL",
            "ALTER TABLE orders ADD COLUMN cancel_reason VARCHAR(255) DEFAULT NULL",
            "ALTER TABLE orders ADD COLUMN discount_id_number VARCHAR(64) DEFAULT NULL",
            "ALTER TABLE payments ADD COLUMN gateway_ref VARCHAR(64) DEFAULT NULL",
            "ALTER TABLE menu_items ADD COLUMN is_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER is_available",
            "UPDATE menu_items SET is_enabled = is_available WHERE is_available = 0",
            "CREATE TABLE IF NOT EXISTS trusted_devices (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, device_id VARCHAR(255) NOT NULL, device_name VARCHAR(100) DEFAULT NULL, browser VARCHAR(100) DEFAULT NULL, ip_address VARCHAR(45) DEFAULT NULL, last_login TIMESTAMP NULL DEFAULT NULL, expires_at TIMESTAMP NULL DEFAULT NULL, UNIQUE KEY uq_trusted_device (user_id, device_id), INDEX idx_trusted_expiry (expires_at)) ENGINE=InnoDB",
            "CREATE TABLE IF NOT EXISTS table_sessions (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, session_token CHAR(64) NOT NULL UNIQUE, table_number VARCHAR(20) NOT NULL, reservation_id INT UNSIGNED DEFAULT NULL, customer_name VARCHAR(100) DEFAULT NULL, expires_at TIMESTAMP NOT NULL, status ENUM('ACTIVE','EXPIRED','CLOSED') NOT NULL DEFAULT 'ACTIVE', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_table_session_status (status, expires_at)) ENGINE=InnoDB",
            "CREATE TABLE IF NOT EXISTS login_attempts (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED DEFAULT NULL, email VARCHAR(160) DEFAULT NULL, ip_address VARCHAR(45) NOT NULL, attempts INT NOT NULL DEFAULT 0, last_attempt TIMESTAMP DEFAULT CURRENT_TIMESTAMP, locked_until TIMESTAMP NULL DEFAULT NULL, UNIQUE KEY uq_login_identity (email, ip_address)) ENGINE=InnoDB",
            "CREATE TABLE IF NOT EXISTS manager_approvals (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, manager_id INT UNSIGNED NOT NULL, requester_id INT UNSIGNED DEFAULT NULL, action VARCHAR(80) NOT NULL, reference_id INT UNSIGNED DEFAULT NULL, ip_address VARCHAR(45) DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_manager_approval_created (created_at)) ENGINE=InnoDB",
            "CREATE TABLE IF NOT EXISTS security_events (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED DEFAULT NULL, event_type VARCHAR(80) NOT NULL, severity ENUM('info','warning','critical') NOT NULL DEFAULT 'info', details TEXT, ip_address VARCHAR(45) DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_security_event_created (created_at), INDEX idx_security_event_type (event_type)) ENGINE=InnoDB",
        ];
        foreach ($statements as $sql) {
            try { $pdo->exec($sql); } catch (Throwable $e) { /* ALTER is idempotent across old installs */ }
        }
        $ready = true;
    }

    public static function bootSession(): void
    {
        self::ensureSchema();
        if (session_status() !== PHP_SESSION_ACTIVE) return;
        $user = $_SESSION['user'] ?? null;
        if (!$user) return;
        // Staff pages can remain open throughout a service shift. Keep the
        // session alive while the user is active instead of expiring an admin
        // workspace after 15 minutes and leaving its cached UI looking usable.
        $timeout = match ($user['role'] ?? '') { 'cashier' => 28800, 'admin' => 28800, 'kitchen' => 28800, default => 7200 };
        if (!empty($_SESSION['last_activity']) && time() - (int)$_SESSION['last_activity'] > $timeout) {
            $_SESSION = [];
            session_destroy();
            return;
        }
        $_SESSION['last_activity'] = time();
    }

    public static function csrfToken(): string
    {
        self::ensureSchema();
        if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf_token'];
    }

    public static function requireCsrf(): void
    {
        if (empty($_SESSION['user'])) return; // service-to-service headers have no browser session
        $expected = $_SESSION['csrf_token'] ?? '';
        $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!$expected || !$provided || !hash_equals($expected, $provided)) {
            Response::error('CSRF validation failed. Refresh the page and try again.', 419);
        }
    }

    public static function checkLogin(string $email, string $ip): void
    {
        self::ensureSchema();
        $stmt = Database::connection()->prepare('SELECT locked_until FROM login_attempts WHERE email = ? AND ip_address = ? LIMIT 1');
        $stmt->execute([$email, $ip]);
        $until = $stmt->fetchColumn();
        if ($until && strtotime($until) > time()) Response::error('Account temporarily locked after repeated failed sign-in attempts.', 423, ['locked_until' => $until]);
    }

    public static function failedLogin(string $email, string $ip): void
    {
        self::ensureSchema();
        $pdo = Database::connection();
        $stmt = $pdo->prepare("INSERT INTO login_attempts (email, ip_address, attempts, last_attempt, locked_until) VALUES (?, ?, 1, NOW(), NULL) ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_attempt = NOW(), locked_until = CASE WHEN attempts + 1 >= 5 THEN DATE_ADD(NOW(), INTERVAL 15 MINUTE) ELSE locked_until END");
        $stmt->execute([$email, $ip]);
        $count = (int)$pdo->query('SELECT attempts FROM login_attempts WHERE email=' . $pdo->quote($email) . ' AND ip_address=' . $pdo->quote($ip))->fetchColumn();
        self::event(null, $count >= 5 ? 'account_locked' : 'failed_login', $count >= 5 ? 'warning' : 'info', 'Failed sign-in for ' . $email . ' (' . $count . ')');
    }

    public static function successfulLogin(int $userId, string $email, string $ip): void
    {
        self::ensureSchema();
        Database::connection()->prepare('DELETE FROM login_attempts WHERE email = ? AND ip_address = ?')->execute([$email, $ip]);
        self::event($userId, 'successful_login', 'info', 'Successful sign-in');
    }

    public static function event(?int $userId, string $type, string $severity = 'info', string $details = ''): void
    {
        try {
            self::ensureSchema();
            $stmt = Database::connection()->prepare('INSERT INTO security_events (user_id, event_type, severity, details, ip_address) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$userId, $type, $severity, $details, $_SERVER['REMOTE_ADDR'] ?? null]);
        } catch (Throwable $e) {}
    }

    public static function verifyManagerPin(string $pin, string $action, ?int $referenceId = null): bool
    {
        self::ensureSchema();
        $user = $_SESSION['user'] ?? null;
        if (!$user || !in_array($user['role'] ?? '', ['admin', 'cashier', 'kitchen'], true)) return false;
        $stmt = Database::connection()->query("SELECT id, manager_pin_hash FROM users WHERE role='admin' AND is_active=1 AND manager_pin_hash IS NOT NULL ORDER BY id LIMIT 1");
        $manager = $stmt->fetch();
        $hash = $manager['manager_pin_hash'] ?? null;
        if (!$hash || !preg_match('/^\d{4,6}$/', $pin) || !password_verify($pin, $hash)) {
            self::event((int)$user['id'], 'failed_manager_pin', 'warning', $action);
            return false;
        }
        Database::connection()->prepare('INSERT INTO manager_approvals (manager_id, requester_id, action, reference_id, ip_address) VALUES (?, ?, ?, ?, ?)')->execute([(int)$manager['id'], (int)$user['id'], $action, $referenceId, $_SERVER['REMOTE_ADDR'] ?? null]);
        self::event((int)$user['id'], 'manager_pin_approved', 'info', $action);
        return true;
    }

    public static function requireManagerPin(array $body, string $action, ?int $referenceId = null): void
    {
        $user = Auth::requireLogin(['admin', 'cashier', 'kitchen']);
        if (!self::verifyManagerPin((string)($body['manager_pin'] ?? ''), $action, $referenceId)) {
            Response::error('Manager PIN approval is required for this action.', 403);
        }
    }
}
?>
