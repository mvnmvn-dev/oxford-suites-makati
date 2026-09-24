<?php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';

class Audit
{
    public static function log(string $action, string $module = 'system', ?string $details = null, ?array $user = null): void
    {
        try {
            $pdo = Database::connection();
            $user = $user ?? Auth::user();
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $pdo->prepare(
                'INSERT INTO audit_logs (user_id, user_name, role, action, module, details, ip_address)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $user['id'] ?? null,
                $user['name'] ?? null,
                $user['role'] ?? null,
                $action,
                $module,
                $details,
                $ip,
            ]);
        } catch (Throwable $e) {
            // never break primary flows
        }
    }
}
