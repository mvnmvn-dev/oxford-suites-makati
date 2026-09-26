<?php
require_once __DIR__ . '/../../shared/Response.php';
require_once __DIR__ . '/../../shared/Database.php';
require_once __DIR__ . '/../../shared/Auth.php';
require_once __DIR__ . '/../../shared/CircuitBreaker.php';
require_once __DIR__ . '/../../shared/ServiceHelpers.php';
require_once __DIR__ . '/../../shared/Security.php';

class PaymentService
{
    use ServiceHelpers;

    private const METHODS = ['cash', 'card', 'gcash', 'maya', 'debit', 'credit', 'other'];

    private function normalizeMethod(string $method): string
    {
        $m = strtolower(trim($method));
        if ($m === 'debit' || $m === 'credit') {
            return 'card';
        }
        return in_array($m, ['cash', 'card', 'gcash', 'maya', 'other'], true) ? $m : 'other';
    }

    public function handle(): void
    {
        $sub = $_SERVER['RMS_SUBPATH'] ?? '';
        $method = $this->method();
        $pdo = Database::connection();
        $breaker = new CircuitBreaker('payment');

        if ($method === 'POST' && $sub === 'circuit/reset') {
            Auth::requireLogin(['admin']);
            $breaker->recordSuccess();
            Response::success($breaker->status(), 'Payment circuit reset');
        }

        if ($method === 'GET' && $sub === 'circuit') {
            Response::success($breaker->status());
        }

        if ($method === 'GET' && preg_match('/^bill\/(\d+)$/', $sub, $m)) {
            Auth::requireLogin(['admin', 'cashier']);
            Response::success($this->bill($pdo, (int)$m[1]));
        }

        if ($method === 'GET' && $sub === '') {
            Auth::requireLogin(['admin', 'cashier']);
            $rows = $pdo->query('SELECT p.*, o.order_number, o.table_id FROM payments p JOIN orders o ON o.id = p.order_id ORDER BY p.created_at DESC LIMIT 50')->fetchAll();
            Response::success($rows);
        }

        if ($method === 'POST' && $sub === 'preview-split') {
            Auth::requireLogin(['admin', 'cashier']);
            $b = $this->body();
            Response::success($this->previewSplit($pdo, $b));
        }

        if ($method === 'POST' && preg_match('/^refund\/(\d+)$/', $sub, $m)) {
            $user = Auth::requireLogin(['admin', 'cashier']);
            $b = $this->body();
            $paymentId = (int)$m[1];
            Security::requireManagerPin($b, 'refund payment', $paymentId);
            $payment = $pdo->prepare("SELECT * FROM payments WHERE id=? AND status='completed' LIMIT 1");
            $payment->execute([$paymentId]);
            $row = $payment->fetch();
            if (!$row) Response::error('Completed payment not found or already refunded', 404);
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE payments SET status='refunded' WHERE id=? AND status='completed'")->execute([$paymentId]);
            $pdo->prepare("UPDATE orders SET status='cancelled' WHERE id=? AND status='paid'")->execute([(int)$row['order_id']]);
            $pdo->commit();
            Security::event((int)$user['id'], 'payment_refunded', 'warning', 'Payment #' . $paymentId . ' · ₱' . number_format((float)$row['amount'], 2));
            Response::success(['payment_id' => $paymentId, 'amount' => (float)$row['amount']], 'Payment refunded');
        }

        if ($method === 'POST' && $sub === '') {
            $user = Auth::requireLogin(['admin', 'cashier']);
            if (!$breaker->allowRequest()) {
                Response::error('Payment service temporarily unavailable (circuit open). Try cash settlement later.', 503, ['circuit' => $breaker->status()]);
            }
            $b = $this->body();
            if (!empty($b['discount_type']) || !empty($b['promo_code']) || !empty($b['loyalty_voucher']) || !empty($b['vat_exempt'])) {
                Security::requireManagerPin($b, 'override discount', (int)($b['order_id'] ?? 0));
            }
            $orderId = (int)($b['order_id'] ?? 0);
            if (!$orderId) {
                Response::error('order_id required');
            }
            if (!empty($b['force_fail'])) {
                $breaker->recordFailure();
                Response::error('Simulated payment gateway failure', 502);
            }

            try {
                $pdo->beginTransaction();
                $orderStmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? FOR UPDATE');
                $orderStmt->execute([$orderId]);
                $order = $orderStmt->fetch();
                if (!$order) {
                    throw new RuntimeException('Order not found');
                }
                if ($order['status'] === 'paid') {
                    throw new RuntimeException('Order is already paid');
                }
                $this->applyDiscount($pdo, $order, $b);
                $order = $this->reloadOrder($pdo, $orderId);
                $sumStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE order_id = ? AND status = 'completed'");
                $sumStmt->execute([$orderId]);
                $alreadyPaid = (float)$sumStmt->fetchColumn();
                $remaining = max(0, round((float)$order['total'] - $alreadyPaid, 2));

                $splits = $b['splits'] ?? null;
                if (!is_array($splits) || !$splits) {
                    $splits = [['method' => $b['method'] ?? 'cash', 'amount' => $b['amount'] ?? $remaining, 'reference_no' => $b['reference_no'] ?? null, 'label' => $b['split_label'] ?? 'Full', 'terminal_confirmed' => !empty($b['terminal_confirmed'])]];
                }

                // Auto equal / by-item split helpers
                if (!empty($b['split_mode'])) {
                    $splits = $this->buildSplitPayments($pdo, $order, $b, $remaining);
                }

                $valid = [];
                $requested = 0.0;
                foreach ($splits as $split) {
                    $payMethod = $this->normalizeMethod((string)($split['method'] ?? 'cash'));
                    $amount = round((float)($split['amount'] ?? 0), 2);
                    if ($amount <= 0) {
                        throw new RuntimeException('Each payment split needs a valid method and positive amount');
                    }
                    $reference = trim((string)($split['reference_no'] ?? $b['reference_no'] ?? ''));
                    $gatewayRef = null;
                    if (in_array($payMethod, ['gcash', 'maya'], true)) {
                        if ($reference === '' || strlen($reference) < 4) {
                            throw new RuntimeException(strtoupper($payMethod) . ' requires a valid reference / confirmation number');
                        }
                        $gatewayRef = strtoupper($payMethod) . '-' . preg_replace('/[^A-Z0-9]/i', '', $reference);
                    }
                    if ($payMethod === 'card') {
                        $confirmed = !empty($split['terminal_confirmed']) || !empty($b['terminal_confirmed']);
                        if (!$confirmed) {
                            throw new RuntimeException('Card payment requires confirmation on the card terminal');
                        }
                        if ($reference === '') {
                            $reference = 'TERM-' . strtoupper(substr(uniqid(), -8));
                        }
                        $gatewayRef = 'CARD-' . preg_replace('/[^A-Z0-9\-]/i', '', $reference);
                    }
                    $requested += $amount;
                    $valid[] = [$payMethod, $amount, $reference !== '' ? $reference : null, (string)($split['label'] ?? 'Share'), $gatewayRef];
                }
                if ($requested > $remaining + 0.01) {
                    throw new RuntimeException('Payment exceeds the remaining balance of ₱' . number_format($remaining, 2));
                }
                $user = Auth::user();
                $receipts = [];
                foreach ($valid as [$payMethod, $amount, $reference, $label, $gatewayRef]) {
                    $receipt = 'RCP-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
                    $stmt = $pdo->prepare('INSERT INTO payments (order_id, amount, method, status, reference_no, gateway_ref, cashier_id, receipt_no) VALUES (?, ?, ?, "completed", ?, ?, ?, ?)');
                    $stmt->execute([$orderId, $amount, $payMethod, $reference, $gatewayRef, $user['id'] ?? null, $receipt]);
                    $paymentId = (int)$pdo->lastInsertId();
                    $pdo->prepare('INSERT INTO bill_splits (order_id, split_label, amount, method, payment_id) VALUES (?,?,?,?,?)')
                        ->execute([$orderId, $label, $amount, $payMethod, $paymentId]);
                    $receipts[] = $receipt;
                }
                $newPaid = $alreadyPaid + $requested;
                $settled = $newPaid >= (float)$order['total'] - 0.01;
                if ($settled) {
                    $pdo->prepare("UPDATE orders SET status='paid' WHERE id=?")->execute([$orderId]);
                    if (!empty($order['table_id'])) {
                        $pdo->prepare("UPDATE dining_tables SET status='cleaning' WHERE id=?")->execute([(int)$order['table_id']]);
                    }
                    $this->awardLoyalty($pdo, $order);
                    $receiptLabel = $receipts[0] ?? 'paid';
                    if (!empty($order['customer_id'])) {
                        $pdo->prepare('INSERT INTO notifications (user_id, role_target, title, body, type) VALUES (?,?,?,?,?)')
                            ->execute([
                                (int)$order['customer_id'],
                                'customer',
                                'Payment received',
                                ($order['order_number'] ?? 'Your check') . ' is settled · ' . $receiptLabel,
                                'payment',
                            ]);
                    }
                    $pdo->prepare('INSERT INTO notifications (role_target, title, body, type) VALUES (?,?,?,?)')
                        ->execute([
                            'cashier',
                            'Check settled',
                            ($order['order_number'] ?? ('Order #' . $orderId)) . ' · ₱' . number_format((float)$order['total'], 2) . ' paid',
                            'payment',
                        ]);
                }
                $pdo->commit();
                $breaker->recordSuccess();
                Response::success([
                    'order_id' => $orderId,
                    'paid_now' => round($requested, 2),
                    'paid_total' => round($newPaid, 2),
                    'remaining' => max(0, round((float)$order['total'] - $newPaid, 2)),
                    'settled' => $settled,
                    'receipt_no' => $receipts[0] ?? null,
                    'receipts' => $receipts,
                    'amount' => round($requested, 2),
                ], $settled ? 'Payment completed' : 'Payment recorded', 201);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $msg = $e->getMessage();
                $isValidation = preg_match('/required|exceeds|already paid|not found|Invalid|invalid|terminal|GCash|Maya|reference/i', $msg);
                if (!$isValidation) {
                    $breaker->recordFailure();
                }
                $code = str_contains(strtolower($msg), 'not found') ? 404 : (str_contains(strtolower($msg), 'already') || str_contains(strtolower($msg), 'exceeds') ? 409 : 422);
                Response::error('Payment failed: ' . $msg, $code === 422 && !$isValidation ? 500 : $code);
            }
        }
        Response::error('Payment route not found', 404);
    }

    private function previewSplit(PDO $pdo, array $b): array
    {
        $orderId = (int)($b['order_id'] ?? 0);
        $order = $this->reloadOrder($pdo, $orderId);
        if (!$order) {
            Response::error('Order not found', 404);
        }
        $sumStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE order_id=? AND status='completed'");
        $sumStmt->execute([$orderId]);
        $paid = (float)$sumStmt->fetchColumn();
        $remaining = max(0, round((float)$order['total'] - $paid, 2));
        $b['method'] = $b['method'] ?? 'cash';
        return [
            'order_id' => $orderId,
            'remaining' => $remaining,
            'splits' => $this->buildSplitPayments($pdo, $order, $b, $remaining),
        ];
    }

    private function buildSplitPayments(PDO $pdo, array $order, array $b, float $remaining): array
    {
        $mode = strtolower((string)($b['split_mode'] ?? 'equal'));
        $method = $this->normalizeMethod((string)($b['method'] ?? 'cash'));
        $parts = max(1, min(12, (int)($b['split_count'] ?? 2)));

        if ($mode === 'by_item') {
            $items = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ?');
            $items->execute([(int)$order['id']]);
            $rows = $items->fetchAll();
            if (!$rows) {
                return [['method' => $method, 'amount' => $remaining, 'label' => 'Full']];
            }
            $lineTotals = [];
            $sum = 0.0;
            foreach ($rows as $row) {
                $lt = round((float)$row['unit_price'] * (int)$row['quantity'], 2);
                $lineTotals[] = ['label' => $row['item_name'], 'line' => $lt];
                $sum += $lt;
            }
            $out = [];
            $allocated = 0.0;
            foreach ($lineTotals as $i => $lt) {
                $share = $sum > 0 ? round($remaining * ($lt['line'] / $sum), 2) : 0;
                if ($i === count($lineTotals) - 1) {
                    $share = round($remaining - $allocated, 2);
                }
                $allocated += $share;
                if ($share > 0) {
                    $out[] = ['method' => $method, 'amount' => $share, 'label' => $lt['label']];
                }
            }
            return $out ?: [['method' => $method, 'amount' => $remaining, 'label' => 'Full']];
        }

        // equal / by_customer (same math — labeled Guest 1..N)
        $base = floor(($remaining / $parts) * 100) / 100;
        $out = [];
        $allocated = 0.0;
        for ($i = 1; $i <= $parts; $i++) {
            $amount = ($i === $parts) ? round($remaining - $allocated, 2) : $base;
            $allocated += $amount;
            $out[] = [
                'method' => $method,
                'amount' => $amount,
                'label' => ($mode === 'by_customer' ? 'Guest ' : 'Share ') . $i,
            ];
        }
        return $out;
    }

    private function bill(PDO $pdo, int $orderId): array
    {
        $order = $this->reloadOrder($pdo, $orderId);
        if (!$order) {
            Response::error('Order not found', 404);
        }
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE order_id=? AND status='completed'");
        $stmt->execute([$orderId]);
        $paid = (float)$stmt->fetchColumn();
        $order['payments_total'] = $paid;
        $order['remaining'] = max(0, round((float)$order['total'] - $paid, 2));
        $items = $pdo->prepare('SELECT * FROM order_items WHERE order_id=?');
        $items->execute([$orderId]);
        $order['items'] = $items->fetchAll();
        $splits = $pdo->prepare('SELECT * FROM bill_splits WHERE order_id=? ORDER BY id');
        $splits->execute([$orderId]);
        $order['bill_splits'] = $splits->fetchAll();
        return $order;
    }

    private function reloadOrder(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id=?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    private function applyDiscount(PDO $pdo, array $order, array $body): void
    {
        $type = strtolower(trim((string)($body['discount_type'] ?? '')));
        $code = strtoupper(trim((string)($body['promo_code'] ?? '')));
        $loyaltyCode = strtoupper(trim((string)($body['loyalty_voucher'] ?? '')));
        if ($type === '' && $code === '' && $loyaltyCode === '') {
            return;
        }

        $discount = 0.0;
        $vatExempt = 0;
        $label = $code ?: ($loyaltyCode ?: $type);

        if (in_array($type, ['senior', 'pwd'], true)) {
            $idNumber = trim((string)($body['discount_id_number'] ?? ''));
            if ($idNumber === '' || strlen($idNumber) < 4) {
                Response::error(($type === 'pwd' ? 'PWD' : 'Senior Citizen') . ' ID / OSCA number is required', 422);
            }
            $discount = round((float)$order['subtotal'] * 0.20, 2);
            $vatExempt = 1;
            $label = strtoupper($type) . ':' . $idNumber;
        } elseif ($type === 'employee') {
            $discount = round((float)$order['subtotal'] * 0.10, 2);
        }

        if ($loyaltyCode !== '') {
            $v = $pdo->prepare("SELECT * FROM loyalty_vouchers WHERE code=? AND status='open' AND expires_at >= NOW() FOR UPDATE");
            $v->execute([$loyaltyCode]);
            $voucher = $v->fetch();
            if (!$voucher) {
                Response::error('Loyalty voucher is invalid or expired', 422);
            }
            $discount += (float)$voucher['discount_value'];
            $label = $voucher['code'];
            $pdo->prepare("UPDATE loyalty_vouchers SET status='redeemed', order_id=? WHERE id=?")
                ->execute([(int)$order['id'], (int)$voucher['id']]);
        }

        if ($code !== '') {
            $p = $pdo->prepare('SELECT * FROM promotions WHERE code=? AND is_active=1 AND NOW() BETWEEN starts_at AND ends_at');
            $p->execute([$code]);
            $promo = $p->fetch();
            if (!$promo) {
                Response::error('Promo code is invalid or expired', 422);
            }
            $discount += $this->promoDiscount($pdo, $order, $promo);
            $label = $promo['code'];
        }

        $discount = min((float)$order['subtotal'], max(0, $discount));
        $taxable = max(0, (float)$order['subtotal'] - $discount);
        $tax = $vatExempt ? 0 : round($taxable * 0.12, 2);
        $total = round($taxable + $tax, 2);
        $idCol = trim((string)($body['discount_id_number'] ?? ''));
        $pdo->prepare('UPDATE orders SET discount=?, discount_code=?, discount_id_number=?, vat_exempt=?, tax=?, total=? WHERE id=?')
            ->execute([$discount, $label, $idCol !== '' ? $idCol : null, $vatExempt, $tax, $total, (int)$order['id']]);
    }

    private function promoDiscount(PDO $pdo, array $order, array $promo): float
    {
        $type = $promo['type'];
        $subtotal = (float)$order['subtotal'];
        if ($type === 'percentage') {
            return round($subtotal * ((float)$promo['value'] / 100), 2);
        }
        if ($type === 'fixed' || $type === 'birthday') {
            return min($subtotal, (float)$promo['value']);
        }
        if ($type === 'buy_one_take_one') {
            $items = $pdo->prepare('SELECT unit_price, quantity FROM order_items WHERE order_id=? ORDER BY unit_price ASC');
            $items->execute([(int)$order['id']]);
            $prices = [];
            foreach ($items->fetchAll() as $row) {
                for ($i = 0; $i < (int)$row['quantity']; $i++) {
                    $prices[] = (float)$row['unit_price'];
                }
            }
            sort($prices);
            $pairs = intdiv(count($prices), 2);
            $free = 0.0;
            for ($i = 0; $i < $pairs; $i++) {
                $free += $prices[$i];
            }
            return round($free, 2);
        }
        return 0.0;
    }

    private function awardLoyalty(PDO $pdo, array $order): void
    {
        if (empty($order['customer_id'])) {
            return;
        }
        $customerId = (int)$order['customer_id'];
        $points = max(1, (int)floor((float)$order['total'] / 100));
        $pdo->prepare('INSERT INTO loyalty_accounts (customer_id, points) VALUES (?,?) ON DUPLICATE KEY UPDATE points=points+VALUES(points)')
            ->execute([$customerId, $points]);
        $pdo->prepare('INSERT INTO loyalty_transactions (customer_id, order_id, points, reason) VALUES (?,?,?,?)')
            ->execute([$customerId, (int)$order['id'], $points, 'Paid order']);
    }
}
(new PaymentService())->handle();
