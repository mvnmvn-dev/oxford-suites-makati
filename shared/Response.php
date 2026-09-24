<?php
class Response
{
    public static function json($data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Role, X-User-Id');
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function error(string $message, int $status = 400, array $extra = []): void
    {
        self::json(array_merge(['success' => false, 'error' => $message], $extra), $status);
    }

    public static function success($data = null, string $message = 'OK', int $status = 200): void
    {
        $payload = ['success' => true, 'message' => $message];
        if ($data !== null) {
            $payload['data'] = $data;
        }
        self::json($payload, $status);
    }
}
