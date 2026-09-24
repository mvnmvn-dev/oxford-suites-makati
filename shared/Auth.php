<?php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Response.php';
require_once __DIR__ . '/Security.php';

class Auth
{
    public static function startSession(): void
    {
        $config = require __DIR__ . '/../config/app.php';
        if (session_status() === PHP_SESSION_NONE) {
            session_name($config['session_name']);
            $base = rtrim($config['base_url'] ?: '/', '/') ?: '/';
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => $base === '/' ? '/' : $base . '/',
                'httponly' => true,
                'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
                'samesite' => 'Lax',
            ]);
            session_start();
            Security::bootSession();
        }
    }

    public static function attempt(string $email, string $password): ?array
    {
        Security::checkLogin($email, $_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            Security::failedLogin($email, $_SERVER['REMOTE_ADDR'] ?? 'unknown');
            return null;
        }
        Security::successfulLogin((int)$user['id'], $email, $_SERVER['REMOTE_ADDR'] ?? 'unknown');
        unset($user['password_hash']);
        return $user;
    }

    public static function login(array $user): void
    {
        self::startSession();
        $_SESSION['user'] = $user;
        $_SESSION['last_activity'] = time();
        Security::csrfToken();
    }

    public static function logout(): void
    {
        self::startSession();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public static function user(): ?array
    {
        self::startSession();
        return $_SESSION['user'] ?? null;
    }

    public static function requireLogin(array $roles = []): array
    {
        $user = self::user();
        if (!$user) {
            // Allow header-based auth for API service-to-service / SPA
            $role = $_SERVER['HTTP_X_ROLE'] ?? null;
            $userId = $_SERVER['HTTP_X_USER_ID'] ?? null;
            if ($role && $userId) {
                $user = ['id' => (int)$userId, 'role' => $role, 'name' => 'API User'];
            } else {
                Response::error('Unauthorized', 401);
            }
        }
        if ($roles && !in_array($user['role'], $roles, true)) {
            Response::error('Forbidden for role: ' . $user['role'], 403);
        }
        return $user;
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }
}
