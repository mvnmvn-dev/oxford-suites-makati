<?php
require_once __DIR__ . '/../../shared/Response.php';
require_once __DIR__ . '/../../shared/Database.php';
require_once __DIR__ . '/../../shared/Auth.php';
require_once __DIR__ . '/../../shared/ServiceHelpers.php';
require_once __DIR__ . '/../../shared/Security.php';

class UserService
{
    use ServiceHelpers;

    public function handle(): void
    {
        $service = $_SERVER['RMS_SERVICE'] ?? 'users';
        $sub = $_SERVER['RMS_SUBPATH'] ?? '';
        $method = $this->method();

        if ($service === 'auth') {
            $this->handleAuth($sub, $method);
            return;
        }

        if ($method === 'GET' && $sub === '') {
            Auth::requireLogin(['admin']);
            $rows = Database::connection()->query('SELECT id, name, email, role, phone, is_active, created_at FROM users ORDER BY id')->fetchAll();
            Response::success($rows);
        }

        if ($method === 'POST' && $sub === '') {
            Auth::requireLogin(['admin']);
            $b = $this->body();
            if (empty($b['name']) || empty($b['email']) || empty($b['password']) || empty($b['role'])) {
                Response::error('name, email, password, role required');
            }
            $hash = Auth::hashPassword($b['password']);
            $stmt = Database::connection()->prepare(
                'INSERT INTO users (name, email, password_hash, role, phone) VALUES (?, ?, ?, ?, ?)'
            );
            try {
                $stmt->execute([$b['name'], $b['email'], $hash, $b['role'], $b['phone'] ?? null]);
            } catch (PDOException $e) {
                Response::error('Could not create user (email may exist)', 409);
            }
            Response::success(['id' => (int)Database::connection()->lastInsertId()], 'User created', 201);
        }

        Response::error('Users route not found', 404);
    }

    private function handleAuth(string $sub, string $method): void
    {
        if ($method === 'GET' && $sub === 'csrf') {
            Auth::startSession();
            Response::success(['token' => Security::csrfToken()]);
        }

        if ($method === 'POST' && ($sub === 'login' || $sub === '')) {
            $b = $this->body();
            $email = trim($b['email'] ?? '');
            $password = $b['password'] ?? '';
            if (!$email || !$password) {
                Response::error('Email and password required');
            }
            $user = Auth::attempt($email, $password);
            if (!$user) {
                Response::error('Invalid credentials', 401);
            }
            Auth::login($user);
            try {
                require_once __DIR__ . '/../../shared/Audit.php';
                Audit::log('login', 'auth', 'Signed in as ' . ($user['role'] ?? ''));
            } catch (Throwable $e) {}
            Response::success(['user' => $user], 'Logged in');
        }

        if ($method === 'POST' && $sub === 'manager-pin') {
            $user = Auth::requireLogin(['admin']);
            $b = $this->body();
            $pin = (string)($b['pin'] ?? '');
            if (!preg_match('/^\d{4,6}$/', $pin)) Response::error('Manager PIN must be 4–6 digits');
            Database::connection()->prepare('UPDATE users SET manager_pin_hash = ? WHERE id = ?')->execute([Auth::hashPassword($pin), (int)$user['id']]);
            Security::event((int)$user['id'], 'manager_pin_updated', 'info', 'Manager PIN changed');
            Response::success(null, 'Manager PIN saved');
        }

        if ($method === 'POST' && $sub === 'manager-pin/verify') {
            $user = Auth::requireLogin(['admin']);
            $b = $this->body();
            if (!Security::verifyManagerPin((string)($b['pin'] ?? ''), (string)($b['action'] ?? 'sensitive_action'), !empty($b['reference_id']) ? (int)$b['reference_id'] : null)) {
                Response::error('Manager PIN is invalid or not configured.', 403);
            }
            Response::success(['approved' => true], 'Manager approval recorded');
        }

        if ($method === 'POST' && $sub === 'register') {
            $b = $this->body();
            $name = trim($b['name'] ?? '');
            $email = trim($b['email'] ?? '');
            $password = $b['password'] ?? '';
            $phone = trim($b['phone'] ?? '');
            if (!$name || !$email || !$password) {
                Response::error('Name, email, and password are required');
            }
            if (strlen($password) < 6) {
                Response::error('Password must be at least 6 characters');
            }
            $pdo = Database::connection();
            $hash = Auth::hashPassword($password);
            try {
                $stmt = $pdo->prepare(
                    "INSERT INTO users (name, email, password_hash, role, phone) VALUES (?, ?, ?, 'customer', ?)"
                );
                $stmt->execute([$name, $email, $hash, $phone ?: null]);
            } catch (PDOException $e) {
                Response::error('An account with this email already exists', 409);
            }
            $id = (int)$pdo->lastInsertId();
            $user = [
                'id' => $id,
                'name' => $name,
                'email' => $email,
                'role' => 'customer',
                'phone' => $phone ?: null,
                'is_active' => 1,
            ];
            Auth::login($user);
            Response::success(['user' => $user], 'Account created', 201);
        }

        if ($method === 'POST' && $sub === 'logout') {
            Auth::logout();
            Response::success(null, 'Logged out');
        }

        if ($method === 'GET' && $sub === 'me') {
            $user = Auth::user();
            if (!$user) {
                Response::error('Not authenticated', 401);
            }
            Response::success(['user' => $user]);
        }

        if ($method === 'PATCH' && $sub === 'me') {
            $user = Auth::requireLogin(['customer', 'admin', 'cashier', 'kitchen']);
            $b = $this->body();
            $name = trim($b['name'] ?? '');
            $email = trim($b['email'] ?? '');
            $phone = trim($b['phone'] ?? '');
            if (!$name || !$email) {
                Response::error('Name and email are required');
            }
            $pdo = Database::connection();
            try {
                $stmt = $pdo->prepare('UPDATE users SET name = ?, email = ?, phone = ? WHERE id = ?');
                $stmt->execute([$name, $email, $phone !== '' ? $phone : null, (int)$user['id']]);
            } catch (PDOException $e) {
                Response::error('Email may already be in use', 409);
            }
            $fresh = $pdo->prepare('SELECT id, name, email, role, phone, avatar_url, is_active, created_at, updated_at FROM users WHERE id = ?');
            $fresh->execute([(int)$user['id']]);
            $row = $fresh->fetch();
            Auth::login($row);
            Response::success(['user' => $row], 'Profile updated');
        }

        if ($method === 'PATCH' && $sub === 'password') {
            $user = Auth::requireLogin(['customer', 'admin', 'cashier', 'kitchen']);
            $b = $this->body();
            $current = $b['current_password'] ?? '';
            $next = $b['new_password'] ?? '';
            if (!$current || !$next) {
                Response::error('Current and new password required');
            }
            if (strlen($next) < 6) {
                Response::error('New password must be at least 6 characters');
            }
            $pdo = Database::connection();
            $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([(int)$user['id']]);
            $row = $stmt->fetch();
            if (!$row || !password_verify($current, $row['password_hash'])) {
                Response::error('Current password is incorrect', 400);
            }
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([Auth::hashPassword($next), (int)$user['id']]);
            Response::success(null, 'Password updated');
        }

        if ($method === 'DELETE' && $sub === 'me') {
            $user = Auth::requireLogin(['customer']);
            $b = $this->body();
            $password = $b['password'] ?? '';
            $pdo = Database::connection();
            $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([(int)$user['id']]);
            $row = $stmt->fetch();
            if (!$row || !password_verify($password, $row['password_hash'])) {
                Response::error('Password is incorrect', 400);
            }
            $pdo->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([(int)$user['id']]);
            Auth::logout();
            Response::success(null, 'Account deactivated');
        }

        Response::error('Auth route not found', 404);
    }
}

(new UserService())->handle();
