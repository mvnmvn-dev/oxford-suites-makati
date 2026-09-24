<?php
/**
 * Proxy / fallback for TensorFlow ML Inference Service.
 */
require_once __DIR__ . '/../shared/Response.php';
require_once __DIR__ . '/../shared/Database.php';
require_once __DIR__ . '/../shared/ServiceHelpers.php';

class MlProxy
{
    use ServiceHelpers;

    public function handle(): void
    {
        $config = require __DIR__ . '/../config/app.php';
        $sub = $_SERVER['RMS_SUBPATH'] ?? '';
        $method = $this->method();

        if ($method === 'GET' && ($sub === 'health' || $sub === '')) {
            $remote = $this->callMl($config, '/health');
            Response::success([
                'gateway' => 'ok',
                'ml_service' => $remote ?: ['status' => 'fallback', 'note' => 'Using PHP heuristic fallback'],
            ]);
        }

        if ($method === 'GET' && str_starts_with($sub, 'recommend')) {
            $customerId = (int)$this->query('customer_id', 0);
            $limit = (int)$this->query('limit', 5);
            $remote = $this->callMl($config, '/recommend?customer_id=' . $customerId . '&limit=' . $limit);
            if ($remote && isset($remote['recommendations'])) {
                Response::success($remote);
            }
            Response::success(['recommendations' => $this->fallbackRecommend($limit), 'source' => 'php-fallback']);
        }

        if ($method === 'GET' && str_starts_with($sub, 'forecast')) {
            $days = (int)$this->query('days', 7);
            $remote = $this->callMl($config, '/forecast?days=' . $days);
            if ($remote && isset($remote['forecast'])) {
                Response::success($remote);
            }
            Response::success(['forecast' => $this->fallbackForecast($days), 'source' => 'php-fallback']);
        }

        Response::error('ML route not found', 404);
    }

    private function callMl(array $config, string $path): ?array
    {
        if (empty($config['ml_service']['enabled'])) {
            return null;
        }
        $url = rtrim($config['ml_service']['url'], '/') . $path;
        $ctx = stream_context_create(['http' => ['timeout' => 0.4, 'ignore_errors' => true]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            return null;
        }
        $json = json_decode($raw, true);
        return is_array($json) ? $json : null;
    }

    private function fallbackRecommend(int $limit): array
    {
        $pdo = Database::connection();
        $limit = max(1, min(20, $limit));

        // Co-occurrence: items frequently ordered together with recent bestsellers
        $pairs = $pdo->query(
            "SELECT a.menu_item_id AS id_a, b.menu_item_id AS id_b, COUNT(*) AS together
             FROM order_items a
             JOIN order_items b ON a.order_id = b.order_id AND a.menu_item_id < b.menu_item_id
             JOIN orders o ON o.id = a.order_id
             WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)
             GROUP BY a.menu_item_id, b.menu_item_id
             ORDER BY together DESC
             LIMIT 40"
        )->fetchAll();

        $scores = [];
        foreach ($pairs as $p) {
            $scores[(int)$p['id_a']] = ($scores[(int)$p['id_a']] ?? 0) + (int)$p['together'];
            $scores[(int)$p['id_b']] = ($scores[(int)$p['id_b']] ?? 0) + (int)$p['together'];
        }

        $stmt = $pdo->query(
            'SELECT mi.id, mi.name, mi.price, mi.description, mi.image_url, mi.is_featured, mi.tags, mc.slug AS category_slug,
                    COALESCE(SUM(sd.quantity_sold), 0) AS popularity
             FROM menu_items mi
             JOIN menu_categories mc ON mc.id = mi.category_id
             LEFT JOIN sales_daily sd ON sd.menu_item_id = mi.id
             WHERE mi.is_available = 1
             GROUP BY mi.id
             ORDER BY mi.is_featured DESC, popularity DESC'
        );
        $items = $stmt->fetchAll();
        foreach ($items as &$item) {
            $id = (int)$item['id'];
            $item['pair_score'] = $scores[$id] ?? 0;
            $item['reason'] = ($scores[$id] ?? 0) > 0
                ? 'Frequently ordered together'
                : ((int)$item['is_featured'] ? "Chef's recommendation" : 'Popular this month');
            // Pairing hints
            $slug = $item['category_slug'] ?? '';
            if ($slug === 'mains' || $slug === 'grill') {
                $item['pairing_hint'] = 'Pairs well with a dessert or bar selection';
            } elseif ($slug === 'desserts') {
                $item['pairing_hint'] = 'Dessert suggestion';
            } elseif ($slug === 'bar') {
                $item['pairing_hint'] = 'Drink recommendation';
            } else {
                $item['pairing_hint'] = 'Starter pairing';
            }
        }
        unset($item);
        usort($items, function ($a, $b) {
            $sa = ((int)$a['is_featured'] * 1000) + ((int)$a['pair_score'] * 10) + (float)$a['popularity'];
            $sb = ((int)$b['is_featured'] * 1000) + ((int)$b['pair_score'] * 10) + (float)$b['popularity'];
            return $sb <=> $sa;
        });
        return array_slice($items, 0, $limit);
    }

    private function fallbackForecast(int $days): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->query(
            'SELECT ii.id, ii.name, ii.unit, ii.quantity, ii.reorder_level,
                    ROUND(ii.quantity / GREATEST(ii.reorder_level, 1), 2) AS stock_ratio
             FROM inventory_items ii
             ORDER BY stock_ratio ASC'
        );
        $items = $stmt->fetchAll();
        $out = [];
        foreach ($items as $item) {
            $avgDaily = max(0.5, (float)$item['reorder_level'] / 7);
            $projected = [];
            $qty = (float)$item['quantity'];
            for ($i = 1; $i <= $days; $i++) {
                $qty = max(0, $qty - $avgDaily);
                $projected[] = ['day' => $i, 'projected_qty' => round($qty, 2)];
            }
            $out[] = [
                'inventory_item_id' => (int)$item['id'],
                'name' => $item['name'],
                'unit' => $item['unit'],
                'current_qty' => (float)$item['quantity'],
                'reorder_level' => (float)$item['reorder_level'],
                'suggested_reorder' => $qty < (float)$item['reorder_level'],
                'avg_daily_use' => round($avgDaily, 2),
                'projection' => $projected,
            ];
        }
        return $out;
    }
}

(new MlProxy())->handle();
