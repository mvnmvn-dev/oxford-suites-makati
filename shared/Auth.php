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
            $forwardedProto = strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')[0] ?? ''));
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $forwardedProto === 'https';
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => $base === '/' ? '/' : $base . '/',
                'httponly' => true,
                'secure' => $isHttps,
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
        unset($user['password_hash'], $user['manager_pin_hash'], $user['totp_secret']);
        return $user;
    }

    public static function login(array $user): void
    {
        self::startSession();
        unset($user['password_hash'], $user['manager_pin_hash'], $user['totp_secret']);
        $_SESSION['user'] = $user;
        $_SESSION['last_activity'] = time();
        unset($_SESSION['mfa_pending_user_id'], $_SESSION['mfa_pending_at']);
        Security::csrfToken();
    }

    /** Password OK but TOTP still required — do not grant a full session yet. */
    public static function beginMfaChallenge(int $userId): string
    {
        self::startSession();
        session_regenerate_id(true);
        $_SESSION['mfa_pending_user_id'] = $userId;
        $_SESSION['mfa_pending_at'] = time();
        unset($_SESSION['user']);
        $token = bin2hex(random_bytes(16));
        $_SESSION['mfa_challenge_token'] = $token;
        Security::csrfToken();
        return $token;
    }

    public static function pendingMfaUserId(?string $challengeToken = null): ?int
    {
        self::startSession();
        $uid = (int)($_SESSION['mfa_pending_user_id'] ?? 0);
        if ($uid < 1) {
            return null;
        }
        $started = (int)($_SESSION['mfa_pending_at'] ?? 0);
        if ($started < 1 || time() - $started > 300) {
            unset($_SESSION['mfa_pending_user_id'], $_SESSION['mfa_pending_at'], $_SESSION['mfa_challenge_token']);
            return null;
        }
        if ($challengeToken !== null && $challengeToken !== '') {
            $expected = (string)($_SESSION['mfa_challenge_token'] ?? '');
            if (!$expected || !hash_equals($expected, $challengeToken)) {
                return null;
            }
        }
        return $uid;
    }

    public static function publicUser(array $user): array
    {
        unset($user['password_hash'], $user['manager_pin_hash'], $user['totp_secret']);
        $user['totp_enabled'] = !empty($user['totp_enabled']) ? 1 : 0;
        return $user;
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
