<?php
require_once __DIR__ . '/../../shared/Response.php';
require_once __DIR__ . '/../../shared/Database.php';
require_once __DIR__ . '/../../shared/Auth.php';
require_once __DIR__ . '/../../shared/ServiceHelpers.php';
require_once __DIR__ . '/../../shared/Security.php';

class ReservationService
{
    use ServiceHelpers;

    public function handle(): void
    {
        $sub = $_SERVER['RMS_SUBPATH'] ?? '';
        $method = $this->method();
        $pdo = Database::connection();

        if ($method === 'GET' && $sub === '') {
            $date = $this->query('date');
            $mine = $this->query('mine');
            $sql = 'SELECT r.*, t.table_number FROM reservations r LEFT JOIN dining_tables t ON t.id = r.table_id';
            $params = [];
            $where = [];
            if ($mine === '1') {
                $user = Auth::requireLogin(['customer', 'admin']);
                if ($user['role'] === 'customer') {
                    $where[] = '(r.customer_id = ? OR (r.customer_id IS NULL AND r.customer_email = ?))';
                    $params[] = (int)$user['id'];
                    $params[] = $user['email'] ?? '';
                }
            } elseif ($date) {
                $where[] = 'r.reservation_date = ?';
                $params[] = $date;
            } else {
                $where[] = 'r.reservation_date >= CURDATE()';
            }
            if ($where) {
                $sql .= ' WHERE ' . implode(' AND ', $where);
            }
            $sql .= ' ORDER BY r.reservation_date DESC, r.reservation_time DESC';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            Response::success($stmt->fetchAll());
        }

        if ($method === 'GET' && $sub === 'calendar') {
            $month = $this->query('month', date('Y-m'));
            $mine = $this->query('mine');
            $sql = "SELECT r.*, t.table_number, t.zone, t.seating_type, t.capacity
                    FROM reservations r
                    LEFT JOIN dining_tables t ON t.id = r.table_id
                    WHERE DATE_FORMAT(r.reservation_date, '%Y-%m') = ?";
            $params = [$month];
            if ($mine === '1') {
                $user = Auth::requireLogin(['customer', 'admin', 'cashier']);
                if (($user['role'] ?? '') === 'customer') {
                    $sql .= ' AND r.customer_id = ?';
                    $params[] = (int)$user['id'];
                }
            }
            $sql .= ' ORDER BY r.reservation_date, r.reservation_time';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            Response::success($stmt->fetchAll());
        }

        if ($method === 'GET' && $sub === 'available-tables') {
            $date = $this->query('date', date('Y-m-d'));
            $time = $this->query('time', '19:00:00');
            $party = max(1, (int)$this->query('party_size', 2));
            $pref = $this->query('seating_pref', 'any');
            $sql = "SELECT t.* FROM dining_tables t
                    WHERE t.capacity >= ? AND t.status <> 'cleaning'";
            $params = [$party];
            if (in_array($pref, ['indoor', 'outdoor', 'window'], true)) {
                $sql .= ' AND t.seating_type = ?';
                $params[] = $pref;
            }
            $sql .= ' ORDER BY t.capacity, t.table_number';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $tables = $stmt->fetchAll();
            $out = [];
            foreach ($tables as $t) {
                $conflict = $pdo->prepare(
                    "SELECT id FROM reservations
                     WHERE table_id=? AND reservation_date=?
                       AND ABS(TIME_TO_SEC(TIMEDIFF(reservation_time, ?))) < 7200
                       AND status IN ('pending','confirmed','seated') LIMIT 1"
                );
                $conflict->execute([(int)$t['id'], $date, $time]);
                $t['available_slot'] = $conflict->fetch() ? 0 : 1;
                $out[] = $t;
            }
            Response::success($out);
        }

        if ($method === 'PATCH' && preg_match('/^(\d+)\/table$/', $sub, $m)) {
            Auth::requireLogin(['admin', 'cashier']);
            $reservationId = (int)$m[1];
            $body = $this->body();
            $tableId = !empty($body['table_id']) ? (int)$body['table_id'] : null;
            $current = $pdo->prepare('SELECT * FROM reservations WHERE id = ?');
            $current->execute([$reservationId]);
            $reservation = $current->fetch();
            if (!$reservation) Response::error('Reservation not found', 404);
            if (!in_array($reservation['status'], ['pending', 'confirmed', 'seated'], true)) {
                Response::error('Only active reservations can be assigned', 409);
            }

            $newTable = null;
            if ($tableId) {
                $tableStmt = $pdo->prepare('SELECT * FROM dining_tables WHERE id = ?');
                $tableStmt->execute([$tableId]);
                $newTable = $tableStmt->fetch();
                if (!$newTable) Response::error('Table not found', 404);
                if ((int)$newTable['capacity'] < (int)$reservation['party_size']) {
                    Response::error('That table is too small for this party', 409);
                }
                if ($newTable['status'] === 'cleaning') Response::error('That table is being cleaned', 409);
                $conflict = $pdo->prepare(
                    "SELECT id FROM reservations
                     WHERE table_id = ? AND id <> ? AND reservation_date = ?
                       AND ABS(TIME_TO_SEC(TIMEDIFF(reservation_time, ?))) < 7200
                       AND status IN ('pending','confirmed','seated') LIMIT 1"
                );
                $conflict->execute([$tableId, $reservationId, $reservation['reservation_date'], $reservation['reservation_time']]);
                if ($conflict->fetch()) Response::error('That table is reserved near this time', 409);
            }

            $oldTableId = !empty($reservation['table_id']) ? (int)$reservation['table_id'] : null;
            $pdo->beginTransaction();
            try {
                $pdo->prepare('UPDATE reservations SET table_id = ? WHERE id = ?')->execute([$tableId, $reservationId]);
                if ($tableId) {
                    $tableStatus = $reservation['status'] === 'seated' ? 'occupied' : 'reserved';
                    $pdo->prepare("UPDATE dining_tables SET status = ? WHERE id = ? AND status <> 'cleaning'")
                        ->execute([$tableStatus, $tableId]);
                }
                if ($oldTableId && $oldTableId !== $tableId) {
                    $remaining = $pdo->prepare(
                        "SELECT COUNT(*) FROM reservations WHERE table_id = ? AND id <> ? AND status IN ('pending','confirmed','seated')"
                    );
                    $remaining->execute([$oldTableId, $reservationId]);
                    $orders = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE table_id = ? AND status NOT IN ('paid','cancelled')");
                    $orders->execute([$oldTableId]);
                    if ((int)$remaining->fetchColumn() === 0 && (int)$orders->fetchColumn() === 0) {
                        $pdo->prepare("UPDATE dining_tables SET status = 'available' WHERE id = ? AND status IN ('reserved','occupied')")
                            ->execute([$oldTableId]);
                    }
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                Response::error($e->getMessage(), 400);
            }
            Response::success(['reservation_id' => $reservationId, 'table_id' => $tableId], 'Reservation table updated');
        }

        if ($method === 'POST' && $sub === '') {
            $b = $this->body();
            if (empty($b['customer_name']) || empty($b['party_size']) || empty($b['reservation_date']) || empty($b['reservation_time'])) {
                Response::error('customer_name, party_size, reservation_date, reservation_time required');
            }
            $reservationDate = (string)$b['reservation_date'];
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reservationDate)) {
                Response::error('reservation_date must be YYYY-MM-DD');
            }
            $reservationTime = (string)$b['reservation_time'];
            if (!preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $reservationTime)) {
                Response::error('reservation_time must be HH:MM or HH:MM:SS');
            }
            if (substr_count($reservationTime, ':') === 1) {
                $reservationTime .= ':00';
            }
            $parts = array_map('intval', explode(':', $reservationTime));
            $reservationTime = sprintf('%02d:%02d:%02d', $parts[0], $parts[1], $parts[2] ?? 0);

            $today = date('Y-m-d');
            if ($reservationDate < $today) {
                Response::error('Cannot book a reservation for a past date');
            }
            if ($reservationDate === $today && $reservationTime <= date('H:i:s')) {
                Response::error('Cannot book a reservation for a time that has already passed');
            }
            $b['reservation_time'] = $reservationTime;
            $stmt = $pdo->prepare(
                'INSERT INTO reservations (customer_id, customer_name, customer_email, customer_phone, party_size, table_id, reservation_date, reservation_time, status, notes, seating_pref)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $status = $b['status'] ?? 'pending';
            $tableId = !empty($b['table_id']) ? (int)$b['table_id'] : null;
            $seatingPref = $b['seating_pref'] ?? 'any';
            if (!in_array($seatingPref, ['any', 'indoor', 'outdoor', 'window'], true)) {
                $seatingPref = 'any';
            }
            // Auto-pick a matching table when none selected, including the
            // default "any" preference so the booking reaches Tables & QR.
            if (!$tableId) {
                $pickSql = "SELECT id FROM dining_tables
                            WHERE capacity >= ? AND status IN ('available','reserved')";
                $pickParams = [(int)$b['party_size']];
                if ($seatingPref !== 'any') {
                    $pickSql .= ' AND seating_type = ?';
                    $pickParams[] = $seatingPref;
                }
                $pickSql .= ' ORDER BY capacity ASC, table_number LIMIT 1';
                $pick = $pdo->prepare($pickSql);
                $pick->execute($pickParams);
                $picked = $pick->fetch();
                if ($picked) {
                    $tableId = (int)$picked['id'];
                }
            }
            if ($tableId) {
                $conflict = $pdo->prepare("SELECT id FROM reservations WHERE table_id=? AND reservation_date=? AND ABS(TIME_TO_SEC(TIMEDIFF(reservation_time, ?))) < 7200 AND status IN ('pending','confirmed','seated') LIMIT 1");
                $conflict->execute([$tableId, $b['reservation_date'], $b['reservation_time']]);
                if ($conflict->fetch()) Response::error('That table is already reserved near the selected time', 409);
            }
            $customerId = !empty($b['customer_id']) ? (int)$b['customer_id'] : null;
            $user = Auth::user();
            if ($user && ($user['role'] ?? '') === 'customer') {
                $customerId = (int)$user['id'];
                if (empty($b['customer_name'])) {
                    $b['customer_name'] = $user['name'];
                }
                if (empty($b['customer_email'])) {
                    $b['customer_email'] = $user['email'] ?? null;
                }
                if (empty($b['customer_phone'])) {
                    $b['customer_phone'] = $user['phone'] ?? null;
                }
            }
            $stmt->execute([
                $customerId,
                $b['customer_name'],
                $b['customer_email'] ?? null,
                $b['customer_phone'] ?? null,
                (int)$b['party_size'],
                $tableId,
                $b['reservation_date'],
                $b['reservation_time'],
                $status,
                $b['notes'] ?? null,
                $seatingPref,
            ]);
            $id = (int)$pdo->lastInsertId();
            if ($tableId && in_array($status, ['pending', 'confirmed'], true)) {
                $pdo->prepare("UPDATE dining_tables SET status='reserved' WHERE id=? AND status='available'")->execute([$tableId]);
            }
            $pdo->prepare('INSERT INTO notifications (role_target, title, body, type) VALUES (?,?,?,?)')
                ->execute(['admin', 'New reservation', $b['customer_name'] . ' — party of ' . $b['party_size'], 'reservation']);
            $pdo->prepare('INSERT INTO notifications (role_target, title, body, type) VALUES (?,?,?,?)')
                ->execute(['cashier', 'New reservation', $b['customer_name'] . ' — party of ' . $b['party_size'], 'reservation']);
            Response::success(['id' => $id], 'Reservation created', 201);
        }

        if ($method === 'PATCH' && preg_match('/^(\d+)\/status$/', $sub, $m)) {
            $user = Auth::requireLogin(['admin', 'cashier', 'customer']);
            $b = $this->body();
            $status = $b['status'] ?? '';
            $allowed = ['pending','confirmed','seated','completed','cancelled','no_show'];
            if (!in_array($status, $allowed, true)) {
                Response::error('Invalid status');
            }
            $id = (int)$m[1];
            if (($user['role'] ?? '') === 'customer') {
                if ($status !== 'cancelled') {
                    Response::error('Guests may only cancel their reservations', 403);
                }
                $check = $pdo->prepare(
                    'SELECT id FROM reservations WHERE id = ? AND (customer_id = ? OR customer_email = ?)'
                );
                $check->execute([$id, (int)$user['id'], $user['email'] ?? '']);
                if (!$check->fetch()) {
                    Response::error('Reservation not found', 404);
                }
            } elseif ($status === 'cancelled') {
                Security::requireManagerPin($b, 'cancel reservation', $id);
            }
            $before = $pdo->prepare('SELECT table_id, customer_id, customer_name, reservation_date, reservation_time, status FROM reservations WHERE id = ?');
            $before->execute([$id]);
            $row = $before->fetch();
            if (!$row) {
                Response::error('Reservation not found', 404);
            }
            $pdo->prepare('UPDATE reservations SET status = ? WHERE id = ?')->execute([$status, $id]);
            $tableId = !empty($row['table_id']) ? (int)$row['table_id'] : null;
            if ($status === 'confirmed' && $tableId) {
                $pdo->prepare("UPDATE dining_tables SET status='reserved' WHERE id=? AND status IN ('available','reserved')")->execute([$tableId]);
            }
            if ($status === 'seated' && $tableId) {
                $pdo->prepare("UPDATE dining_tables SET status='occupied' WHERE id=?")->execute([$tableId]);
            }
            if (in_array($status, ['cancelled', 'completed', 'no_show'], true) && $tableId) {
                $openOrders = $pdo->prepare(
                    "SELECT COUNT(*) FROM orders WHERE table_id = ? AND status NOT IN ('paid','cancelled')"
                );
                $openOrders->execute([$tableId]);
                $otherRes = $pdo->prepare(
                    "SELECT COUNT(*) FROM reservations WHERE table_id = ? AND id <> ? AND status IN ('pending','confirmed','seated')"
                );
                $otherRes->execute([$tableId, $id]);
                if ((int)$openOrders->fetchColumn() === 0 && (int)$otherRes->fetchColumn() === 0) {
                    $pdo->prepare("UPDATE dining_tables SET status='available' WHERE id=? AND status IN ('reserved','occupied','cleaning')")
                        ->execute([$tableId]);
                }
            }
            if (in_array($status, ['confirmed', 'cancelled', 'completed'], true)) {
                if (!empty($row['customer_id'])) {
                    $title = match ($status) {
                        'confirmed' => 'Reservation confirmed',
                        'completed' => 'Thanks for dining with us',
                        default => 'Reservation cancelled',
                    };
                    $body = ($row['reservation_date'] ?? '') . ' · ' . substr((string)($row['reservation_time'] ?? ''), 0, 5);
                    $pdo->prepare('INSERT INTO notifications (user_id, role_target, title, body, type) VALUES (?,?,?,?,?)')
                        ->execute([(int)$row['customer_id'], 'customer', $title, $body, 'reservation']);
                }
            }
            Response::success(null, 'Reservation status updated');
        }

        Response::error('Reservation route not found', 404);
    }
}

(new ReservationService())->handle();
