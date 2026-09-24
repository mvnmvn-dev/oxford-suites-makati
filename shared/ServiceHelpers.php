<?php
/**
 * Base helpers for microservice route handlers.
 */
trait ServiceHelpers
{
    protected function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    protected function path(): string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        return rtrim($uri, '/') ?: '/';
    }

    protected function body(): array
    {
        $raw = file_get_contents('php://input');
        if (!$raw) {
            return $_POST ?: [];
        }
        $json = json_decode($raw, true);
        return is_array($json) ? $json : [];
    }

    protected function query(string $key, $default = null)
    {
        return $_GET[$key] ?? $default;
    }

    protected function segmentAfter(string $prefix): string
    {
        $path = $this->path();
        $pos = strpos($path, $prefix);
        if ($pos === false) {
            return '';
        }
        return trim(substr($path, $pos + strlen($prefix)), '/');
    }
}
