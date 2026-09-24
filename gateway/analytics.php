<?php
require_once __DIR__ . '/../shared/Response.php';
require_once __DIR__ . '/../shared/Database.php';
require_once __DIR__ . '/../shared/Auth.php';
require_once __DIR__ . '/../shared/ServiceHelpers.php';

class AnalyticsService
{
    use ServiceHelpers;

    public function handle(): void
    {
        $viewer = Auth::requireLogin(['admin', 'cashier', 'kitchen']);
        $pdo = Database::connection();
        $systemWide = ($viewer['role'] ?? '') === 'admin';
        $viewerId = (int)($viewer['id'] ?? 0);
        $orderScope = $systemWide ? '' : ' AND customer_id = ' . $viewerId;
        $feedbackScope = $systemWide ? '' : ' AND f.customer_id = ' . $viewerId;

        $todayOrders = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()$orderScope")->fetchColumn();
        $todayRevenue = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE DATE(created_at)=CURDATE() AND status IN ('paid','served','ready','preparing','confirmed')$orderScope")->fetchColumn();
        $openTables = (int)$pdo->query("SELECT COUNT(*) FROM dining_tables WHERE status='occupied'")->fetchColumn();
        $pendingRes = (int)$pdo->query("SELECT COUNT(*) FROM reservations WHERE status='pending' AND reservation_date >= CURDATE()")->fetchColumn();
        $lowStock = (int)$pdo->query("SELECT COUNT(*) FROM inventory_items WHERE quantity <= reorder_level")->fetchColumn();
        $kitchenQueue = (int)$pdo->query("SELECT COUNT(*) FROM kitchen_tickets WHERE status IN ('new','in_progress')")->fetchColumn();
        $avgPrep = (float)$pdo->query(
            "SELECT COALESCE(AVG(TIMESTAMPDIFF(MINUTE, started_at, ready_at)),0)
             FROM kitchen_tickets WHERE started_at IS NOT NULL AND ready_at IS NOT NULL
             AND ready_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        )->fetchColumn();
        $avgRating = (float)$pdo->query('SELECT COALESCE(AVG(overall),0) FROM feedback')->fetchColumn();

        $salesTrend = $pdo->query(
            "SELECT sale_date AS day, SUM(revenue) AS revenue, SUM(quantity_sold) AS items
             FROM sales_daily
             WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
             GROUP BY sale_date ORDER BY sale_date"
        )->fetchAll();

        $topItems = $pdo->query(
            "SELECT mi.name, SUM(sd.quantity_sold) AS sold, SUM(sd.revenue) AS revenue
             FROM sales_daily sd
             JOIN menu_items mi ON mi.id = sd.menu_item_id
             WHERE sd.sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
             GROUP BY mi.id ORDER BY sold DESC LIMIT 8"
        )->fetchAll();

        $orderStatus = $pdo->query(
            "SELECT status, COUNT(*) AS count FROM orders WHERE 1=1$orderScope GROUP BY status"
        )->fetchAll();

        $satisfaction = $pdo->query(
            "SELECT COUNT(*) AS responses,
                    ROUND(AVG(food_quality),2) AS food_quality,
                    ROUND(AVG(service),2) AS service,
                    ROUND(AVG(cleanliness),2) AS cleanliness,
                    ROUND(AVG(overall),2) AS overall
             FROM feedback f WHERE 1=1$feedbackScope"
        )->fetch();

        $lowRated = $pdo->query(
            "SELECT f.comment, f.overall, f.food_quality, f.service, o.order_number
             FROM feedback f JOIN orders o ON o.id = f.order_id
             WHERE f.overall <= 3 OR f.comment IS NOT NULL AND f.comment <> ''
             ORDER BY f.overall ASC, f.created_at DESC LIMIT 10"
        )->fetchAll();

        $praisedItems = $pdo->query(
            "SELECT mi.name, COUNT(*) AS mentions, ROUND(AVG(f.food_quality),2) AS avg_food
             FROM feedback f
             JOIN order_items oi ON oi.order_id = f.order_id
             JOIN menu_items mi ON mi.id = oi.menu_item_id
             WHERE f.food_quality >= 4
             GROUP BY mi.id ORDER BY mentions DESC, avg_food DESC LIMIT 8"
        )->fetchAll();

        $demandForecast = $pdo->query(
            "SELECT mi.name,
                    ROUND(AVG(sd.quantity_sold),1) AS avg_daily_demand,
                    ROUND(AVG(sd.quantity_sold)*7,1) AS next_7_days
             FROM sales_daily sd
             JOIN menu_items mi ON mi.id=sd.menu_item_id
             WHERE sd.sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
             GROUP BY mi.id ORDER BY avg_daily_demand DESC LIMIT 10"
        )->fetchAll();

        // Rush-hour / weekend demand from order timestamps
        $peakHours = $pdo->query(
            "SELECT HOUR(created_at) AS hour, COUNT(*) AS orders
             FROM orders
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY HOUR(created_at)
             ORDER BY orders DESC"
        )->fetchAll();

        $weekendVsWeekday = $pdo->query(
            "SELECT
               CASE WHEN DAYOFWEEK(created_at) IN (1,7) THEN 'weekend' ELSE 'weekday' END AS period,
               COUNT(*) AS orders,
               ROUND(AVG(total),2) AS avg_ticket
             FROM orders
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY period"
        )->fetchAll();

        $lunchRush = 0;
        $dinnerRush = 0;
        foreach ($peakHours as $ph) {
            $h = (int)$ph['hour'];
            if ($h >= 11 && $h <= 14) {
                $lunchRush += (int)$ph['orders'];
            }
            if ($h >= 18 && $h <= 21) {
                $dinnerRush += (int)$ph['orders'];
            }
        }

        $wasteInsights = $pdo->query(
            "SELECT ii.name, SUM(iw.quantity) AS quantity_wasted, COUNT(*) AS incidents, MAX(iw.expires_at) AS last_expiry
             FROM inventory_waste iw
             JOIN inventory_items ii ON ii.id=iw.inventory_item_id
             WHERE iw.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
             GROUP BY ii.id ORDER BY quantity_wasted DESC LIMIT 10"
        )->fetchAll();

        // Predictive: items expiring soon + suggested promo dishes using those ingredients
        $expiringSoon = $pdo->query(
            "SELECT ii.*, DATEDIFF(ii.expires_at, CURDATE()) AS days_left
             FROM inventory_items ii
             WHERE ii.expires_at IS NOT NULL AND ii.expires_at <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
             ORDER BY ii.expires_at ASC"
        )->fetchAll();

        $suggestedPromos = [];
        foreach ($expiringSoon as $inv) {
            $menus = $pdo->prepare(
                'SELECT mi.id, mi.name, mi.price FROM recipe_ingredients ri
                 JOIN menu_items mi ON mi.id = ri.menu_item_id
                 WHERE ri.inventory_item_id = ? AND mi.is_available = 1 LIMIT 3'
            );
            $menus->execute([(int)$inv['id']]);
            $dishes = $menus->fetchAll();
            if ($dishes) {
                $suggestedPromos[] = [
                    'ingredient' => $inv['name'],
                    'days_left' => (int)$inv['days_left'],
                    'quantity' => (float)$inv['quantity'],
                    'suggested_dishes' => $dishes,
                    'promo_idea' => 'Feature ' . $dishes[0]['name'] . ' this week to use ' . $inv['name'],
                ];
            }
        }

        $avgWaiterAck = (float)$pdo->query(
            "SELECT COALESCE(AVG(TIMESTAMPDIFF(SECOND, created_at, acknowledged_at)),0)
             FROM service_requests WHERE acknowledged_at IS NOT NULL
             AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        )->fetchColumn();

        $ratingDist = $pdo->query(
            "SELECT overall AS stars, COUNT(*) AS count FROM feedback GROUP BY overall ORDER BY overall"
        )->fetchAll();

        $satTrend = $pdo->query(
            "SELECT DATE(created_at) AS day, ROUND(AVG(overall),2) AS avg_rating, COUNT(*) AS responses
             FROM feedback
             WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
             GROUP BY DATE(created_at) ORDER BY day"
        )->fetchAll();

        $wasteCost = (float)$pdo->query(
            "SELECT COALESCE(SUM(iw.quantity * COALESCE(ii.cost_per_unit,0)),0)
             FROM inventory_waste iw JOIN inventory_items ii ON ii.id=iw.inventory_item_id
             WHERE iw.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        )->fetchColumn();

        $reservationsToday = (int)$pdo->query(
            "SELECT COUNT(*) FROM reservations WHERE reservation_date=CURDATE() AND status IN ('pending','confirmed','seated')" . ($systemWide ? '' : ' AND customer_id = ' . $viewerId)
        )->fetchColumn();

        $moduleOverview = [
            'menu' => [
                'total' => (int)$pdo->query('SELECT COUNT(*) FROM menu_items')->fetchColumn(),
                'available' => (int)$pdo->query('SELECT COUNT(*) FROM menu_items WHERE is_available=1')->fetchColumn(),
                'featured' => (int)$pdo->query('SELECT COUNT(*) FROM menu_items WHERE is_featured=1 AND is_available=1')->fetchColumn(),
            ],
            'tables' => $pdo->query("SELECT status, COUNT(*) AS count FROM dining_tables GROUP BY status ORDER BY status")->fetchAll(),
            'orders_by_source' => $pdo->query("SELECT source, COUNT(*) AS count, COALESCE(SUM(total),0) AS revenue FROM orders WHERE 1=1$orderScope GROUP BY source ORDER BY count DESC")->fetchAll(),
            'reservations_by_status' => $pdo->query("SELECT status, COUNT(*) AS count FROM reservations WHERE reservation_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)" . ($systemWide ? '' : ' AND customer_id = ' . $viewerId) . " GROUP BY status ORDER BY count DESC")->fetchAll(),
            'payments_by_method' => $pdo->query("SELECT p.method, COUNT(*) AS count, COALESCE(SUM(p.amount),0) AS amount FROM payments p JOIN orders o ON o.id=p.order_id WHERE p.status='completed'" . ($systemWide ? '' : ' AND o.customer_id = ' . $viewerId) . " GROUP BY p.method ORDER BY amount DESC")->fetchAll(),
            'kitchen_by_status' => $pdo->query("SELECT status, COUNT(*) AS count FROM kitchen_tickets WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY status ORDER BY status")->fetchAll(),
            'inventory_health' => [
                'total' => (int)$pdo->query('SELECT COUNT(*) FROM inventory_items')->fetchColumn(),
                'low' => (int)$pdo->query('SELECT COUNT(*) FROM inventory_items WHERE quantity <= reorder_level')->fetchColumn(),
                'expiring_7d' => (int)$pdo->query('SELECT COUNT(*) FROM inventory_items WHERE expires_at IS NOT NULL AND expires_at BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)')->fetchColumn(),
                'stock_value' => (float)$pdo->query('SELECT COALESCE(SUM(quantity * cost_per_unit),0) FROM inventory_items')->fetchColumn(),
            ],
            'promotions' => [
                'active' => (int)$pdo->query('SELECT COUNT(*) FROM promotions WHERE is_active=1 AND starts_at <= NOW() AND ends_at >= NOW()')->fetchColumn(),
                'scheduled' => (int)$pdo->query('SELECT COUNT(*) FROM promotions WHERE is_active=1 AND starts_at > NOW()')->fetchColumn(),
            ],
        ];
        $moduleInsights = [];
        $availability = (int)$moduleOverview['menu']['total'] > 0 ? round(($moduleOverview['menu']['available'] / $moduleOverview['menu']['total']) * 100) : 0;
        $moduleInsights[] = $availability . '% of menu items are currently available.';
        $moduleInsights[] = $moduleOverview['inventory_health']['low'] . ' inventory items are at or below reorder level.';
        $moduleInsights[] = $moduleOverview['tables'] ? 'Dining floor utilization is led by ' . $moduleOverview['tables'][0]['status'] . ' tables.' : 'No table activity has been recorded yet.';
        $moduleInsights[] = $lunchRush >= $dinnerRush ? 'Lunch is the stronger demand window over the last 30 days.' : 'Dinner is the stronger demand window over the last 30 days.';
        if ($moduleOverview['promotions']['active'] > 0) $moduleInsights[] = $moduleOverview['promotions']['active'] . ' promotion(s) are active now.';

        Response::success([
            'kpis' => [
                'orders_today' => $todayOrders,
                'revenue_today' => $todayRevenue,
                'occupied_tables' => $openTables,
                'pending_reservations' => $pendingRes,
                'reservations_today' => $reservationsToday,
                'low_stock_items' => $lowStock,
                'kitchen_queue' => $kitchenQueue,
                'avg_prep_minutes' => round($avgPrep, 1),
                'avg_waiter_response_minutes' => round($avgWaiterAck / 60, 1),
                'customer_rating' => round($avgRating, 2),
                'waste_cost_30d' => round($wasteCost, 2),
                'scope' => $systemWide ? 'system-wide' : 'user-based',
                'viewer_role' => $viewer['role'] ?? null,
            ],
            'sales_trend' => $salesTrend,
            'top_items' => $topItems,
            'order_status' => $orderStatus,
            'satisfaction' => $satisfaction,
            'satisfaction_trend' => $satTrend,
            'rating_distribution' => $ratingDist,
            'complaints' => $lowRated,
            'praised_items' => $praisedItems,
            'demand_forecast' => $demandForecast,
            'demand_rushes' => [
                'lunch_orders_30d' => $lunchRush,
                'dinner_orders_30d' => $dinnerRush,
                'peak_hours' => array_slice($peakHours, 0, 6),
                'weekend_vs_weekday' => $weekendVsWeekday,
            ],
            'waste_insights' => $wasteInsights,
            'waste_prediction' => [
                'expiring_soon' => $expiringSoon,
                'suggested_promos' => $suggestedPromos,
                'waste_cost_30d' => round($wasteCost, 2),
            ],
            'module_overview' => $moduleOverview,
            'insights' => $moduleInsights,
        ]);
    }
}

(new AnalyticsService())->handle();
