<?php
require_once __DIR__ . '/../../shared/Response.php';
require_once __DIR__ . '/../../shared/Database.php';
require_once __DIR__ . '/../../shared/Auth.php';
require_once __DIR__ . '/../../shared/ServiceHelpers.php';

class LoyaltyService
{
    use ServiceHelpers;

    /** Points → peso voucher: 100 points = ₱50 */
    private const POINTS_PER_VOUCHER = 100;
    private const VOUCHER_VALUE = 50.0;

    public function handle(): void
    {
        $sub = $_SERVER['RMS_SUBPATH'] ?? '';
        $method = $this->method();
        $pdo = Database::connection();
        $user = Auth::requireLogin(['customer', 'admin', 'cashier']);
        $customerId = ($user['role'] === 'customer')
            ? (int)$user['id']
            : (int)($this->query('customer_id') ?: ($this->body()['customer_id'] ?? $user['id']));

        if ($method === 'GET' && $sub === '') {
            $pdo->prepare('INSERT IGNORE INTO loyalty_accounts (customer_id,points) VALUES (?,0)')->execute([$customerId]);
            $stmt = $pdo->prepare('SELECT * FROM loyalty_accounts WHERE customer_id=?');
            $stmt->execute([$customerId]);
            $account = $stmt->fetch();
            $tx = $pdo->prepare('SELECT * FROM loyalty_transactions WHERE customer_id=? ORDER BY created_at DESC LIMIT 30');
            $tx->execute([$customerId]);
            $vouchers = $pdo->prepare("SELECT * FROM loyalty_vouchers WHERE customer_id=? ORDER BY created_at DESC LIMIT 20");
            $vouchers->execute([$customerId]);
            Response::success([
                'account' => $account,
                'transactions' => $tx->fetchAll(),
                'vouchers' => $vouchers->fetchAll(),
                'redeem_rate' => [
                    'points' => self::POINTS_PER_VOUCHER,
                    'value' => self::VOUCHER_VALUE,
                    'rewards' => [
                        ['name' => '₱50 dining credit', 'points' => 100],
                        ['name' => 'Free soft drink', 'points' => 80],
                        ['name' => 'Free dessert voucher', 'points' => 150],
                    ],
                ],
            ]);
        }

        if ($method === 'POST' && $sub === 'redeem') {
            if ($user['role'] !== 'customer' && $user['role'] !== 'admin' && $user['role'] !== 'cashier') {
                Response::error('Not allowed', 403);
            }
            $b = $this->body();
            $reward = strtolower(trim((string)($b['reward'] ?? 'credit')));
            $pointsMap = ['credit' => 100, 'drink' => 80, 'dessert' => 150];
            $points = (int)($b['points'] ?? ($pointsMap[$reward] ?? self::POINTS_PER_VOUCHER));
            $points = max(self::POINTS_PER_VOUCHER > 0 ? 80 : 1, $points);
            // Normalize to known tiers
            if (!in_array($points, [80, 100, 150], true)) {
                $points = self::POINTS_PER_VOUCHER;
                $reward = 'credit';
            }
            $valueMap = [80 => 40.0, 100 => 50.0, 150 => 80.0];
            $value = $valueMap[$points] ?? self::VOUCHER_VALUE;

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('UPDATE loyalty_accounts SET points=points-? WHERE customer_id=? AND points>=?');
                $stmt->execute([$points, $customerId, $points]);
                if (!$stmt->rowCount()) {
                    $pdo->rollBack();
                    Response::error('Insufficient loyalty points', 422);
                }
                $code = 'LOY-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
                $reason = match ($reward) {
                    'drink' => 'Redeemed free drink voucher',
                    'dessert' => 'Redeemed free dessert voucher',
                    default => 'Redeemed ₱' . number_format($value, 0) . ' dining credit',
                };
                $pdo->prepare('INSERT INTO loyalty_transactions (customer_id, points, reason) VALUES (?,?,?)')
                    ->execute([$customerId, -$points, $reason]);
                $pdo->prepare(
                    'INSERT INTO loyalty_vouchers (customer_id, code, points_spent, discount_value, status, expires_at)
                     VALUES (?,?,?,?,\'open\', DATE_ADD(NOW(), INTERVAL 30 DAY))'
                )->execute([$customerId, $code, $points, $value]);
                $pdo->commit();
                Response::success([
                    'points_redeemed' => $points,
                    'voucher_code' => $code,
                    'discount_value' => $value,
                    'reward' => $reward,
                ], 'Voucher created — give code to cashier at payment');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                Response::error('Redeem failed: ' . $e->getMessage(), 500);
            }
        }

        Response::error('Loyalty route not found', 404);
    }
}
(new LoyaltyService())->handle();
