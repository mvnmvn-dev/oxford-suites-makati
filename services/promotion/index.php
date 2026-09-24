<?php
require_once __DIR__ . '/../../shared/Response.php';
require_once __DIR__ . '/../../shared/Database.php';
require_once __DIR__ . '/../../shared/Auth.php';
require_once __DIR__ . '/../../shared/ServiceHelpers.php';
require_once __DIR__ . '/../../shared/Security.php';

class PromotionService
{
    use ServiceHelpers;

    public function handle(): void
    {
        $sub = $_SERVER['RMS_SUBPATH'] ?? '';
        $method = $this->method();
        $pdo = Database::connection();

        if ($method === 'GET' && $sub === 'active') {
            $rows = $pdo->query(
                "SELECT code, name, type, value, starts_at, ends_at
                 FROM promotions
                 WHERE is_active = 1 AND NOW() BETWEEN starts_at AND ends_at
                 ORDER BY starts_at DESC"
            )->fetchAll();
            Response::success($rows);
        }

        if ($method === 'GET' && $sub === '') {
            Auth::requireLogin(['admin', 'cashier']);
            Response::success($pdo->query('SELECT * FROM promotions ORDER BY starts_at DESC')->fetchAll());
        }

        if ($method === 'POST' && $sub === '') {
            Auth::requireLogin(['admin']);
            $b = $this->body();
            Security::requireManagerPin($b, 'create promotion');
            if (empty($b['code']) || empty($b['name']) || empty($b['starts_at']) || empty($b['ends_at'])) {
                Response::error('code, name, starts_at, and ends_at are required');
            }
            $type = $b['type'] ?? 'percentage';
            if (!in_array($type, ['percentage', 'fixed', 'buy_one_take_one', 'birthday'], true)) {
                Response::error('Invalid promotion type');
            }
            $stmt = $pdo->prepare('INSERT INTO promotions (code,name,type,value,starts_at,ends_at,is_active) VALUES (?,?,?,?,?,?,?)');
            $stmt->execute([
                strtoupper(trim($b['code'])),
                $b['name'],
                $type,
                (float)($b['value'] ?? 0),
                $b['starts_at'],
                $b['ends_at'],
                (int)($b['is_active'] ?? 1),
            ]);
            Response::success(['id' => (int)$pdo->lastInsertId()], 'Promotion created', 201);
        }

        if ($method === 'PATCH' && preg_match('/^(\d+)$/', $sub, $m)) {
            Auth::requireLogin(['admin']);
            $b = $this->body();
            Security::requireManagerPin($b, 'change promotion', (int)$m[1]);
            $pdo->prepare(
                'UPDATE promotions SET
                    is_active = COALESCE(?, is_active),
                    value = COALESCE(?, value),
                    ends_at = COALESCE(?, ends_at),
                    name = COALESCE(?, name),
                    type = COALESCE(?, type)
                 WHERE id = ?'
            )->execute([
                isset($b['is_active']) ? (int)$b['is_active'] : null,
                isset($b['value']) ? (float)$b['value'] : null,
                $b['ends_at'] ?? null,
                $b['name'] ?? null,
                $b['type'] ?? null,
                (int)$m[1],
            ]);
            Response::success(null, 'Promotion updated');
        }

        Response::error('Promotion route not found', 404);
    }
}
(new PromotionService())->handle();
