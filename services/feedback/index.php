<?php
require_once __DIR__ . '/../../shared/Response.php';
require_once __DIR__ . '/../../shared/Database.php';
require_once __DIR__ . '/../../shared/Auth.php';
require_once __DIR__ . '/../../shared/ServiceHelpers.php';

class FeedbackService
{
    use ServiceHelpers;

    public function handle(): void
    {
        $sub = $_SERVER['RMS_SUBPATH'] ?? '';
        $method = $this->method();
        $pdo = Database::connection();

        if ($method === 'GET' && $sub === 'summary') {
            Auth::requireLogin(['admin', 'cashier']);
            $summary = $pdo->query(
                'SELECT COUNT(*) AS responses,
                        ROUND(AVG(food_quality),2) AS food_quality,
                        ROUND(AVG(service),2) AS service,
                        ROUND(AVG(cleanliness),2) AS cleanliness,
                        ROUND(AVG(overall),2) AS overall
                 FROM feedback'
            )->fetch();
            $comments = $pdo->query(
                'SELECT f.*, o.order_number
                 FROM feedback f
                 JOIN orders o ON o.id = f.order_id
                 ORDER BY f.created_at DESC LIMIT 20'
            )->fetchAll();
            $praised = $pdo->query(
                "SELECT mi.name, COUNT(*) AS mentions, ROUND(AVG(f.food_quality),2) AS avg_food
                 FROM feedback f
                 JOIN order_items oi ON oi.order_id = f.order_id
                 JOIN menu_items mi ON mi.id = oi.menu_item_id
                 WHERE f.food_quality >= 4
                 GROUP BY mi.id ORDER BY mentions DESC LIMIT 8"
            )->fetchAll();
            $complaints = $pdo->query(
                "SELECT f.comment, f.overall, f.service, o.order_number, f.created_at
                 FROM feedback f JOIN orders o ON o.id = f.order_id
                 WHERE f.overall <= 3
                 ORDER BY f.created_at DESC LIMIT 10"
            )->fetchAll();
            Response::success([
                'summary' => $summary,
                'recent' => $comments,
                'praised_items' => $praised,
                'complaints' => $complaints,
            ]);
        }

        if ($method === 'GET' && $sub === '') {
            $user = Auth::requireLogin(['customer', 'admin']);
            $stmt = $pdo->prepare(
                'SELECT f.*, o.order_number FROM feedback f
                 JOIN orders o ON o.id = f.order_id
                 WHERE f.customer_id = ? ORDER BY f.created_at DESC'
            );
            $stmt->execute([(int)$user['id']]);
            Response::success($stmt->fetchAll());
        }

        if ($method === 'POST' && $sub === '') {
            $user = Auth::requireLogin(['customer']);
            $b = $this->body();
            $orderId = (int)($b['order_id'] ?? 0);
            $order = $pdo->prepare("SELECT id FROM orders WHERE id=? AND customer_id=? AND status='paid'");
            $order->execute([$orderId, (int)$user['id']]);
            if (!$order->fetch()) {
                Response::error('Only your paid orders can receive feedback', 422);
            }
            foreach (['food_quality', 'service', 'cleanliness', 'overall'] as $key) {
                $value = (int)($b[$key] ?? 0);
                if ($value < 1 || $value > 5) {
                    Response::error($key . ' must be between 1 and 5');
                }
            }
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO feedback (order_id, customer_id, food_quality, service, cleanliness, overall, comment)
                     VALUES (?,?,?,?,?,?,?)'
                );
                $stmt->execute([
                    $orderId,
                    (int)$user['id'],
                    (int)$b['food_quality'],
                    (int)$b['service'],
                    (int)$b['cleanliness'],
                    (int)$b['overall'],
                    substr(trim((string)($b['comment'] ?? '')), 0, 2000),
                ]);
            } catch (PDOException $e) {
                Response::error('Feedback has already been submitted for this order', 409);
            }
            Response::success(['id' => (int)$pdo->lastInsertId()], 'Thank you for your feedback', 201);
        }

        Response::error('Feedback route not found', 404);
    }
}
(new FeedbackService())->handle();
