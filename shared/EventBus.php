<?php
require_once __DIR__ . '/Database.php';

/**
 * Lightweight event bus: persist event then dispatch to in-process handlers.
 * Mirrors the Order Created → Kitchen + Inventory flow from the guide.
 */
class EventBus
{
    /** @var array<string, callable[]> */
    private static array $handlers = [];

    public static function subscribe(string $event, callable $handler): void
    {
        self::$handlers[$event][] = $handler;
    }

    public static function publish(string $event, array $payload): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('INSERT INTO event_log (event_name, payload) VALUES (?, ?)');
        $stmt->execute([$event, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
        $id = (int)$pdo->lastInsertId();

        foreach (self::$handlers[$event] ?? [] as $handler) {
            try {
                $handler($payload);
            } catch (Throwable $e) {
                error_log("EventBus handler error [{$event}]: " . $e->getMessage());
            }
        }

        $pdo->prepare('UPDATE event_log SET processed = 1 WHERE id = ?')->execute([$id]);
        return $id;
    }
}
