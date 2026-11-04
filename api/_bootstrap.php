<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/db.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('PMS_SESSION');
    session_start();
}

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
}

/* ---------- JSON responders (shared contract) ---------- */

function json_ok($data = null, int $code = 200)
{
    http_response_code($code);
    echo json_encode($data ?? [], JSON_UNESCAPED_UNICODE);
    exit;
}

function json_err(string $message, int $code = 400, array $extra = [])
{
    http_response_code($code);
    echo json_encode(['error' => $message] + $extra, JSON_UNESCAPED_UNICODE);
    exit;
}

function require_login(): void
{
    if (empty($_SESSION['user_id'])) {
        json_err('Authentication required', 401);
    }
}

/* ---------- Input helpers ---------- */

function body(): array
{
    $raw = file_get_contents('php://input');
    $json = json_decode($raw ?: '', true);
    if (is_array($json)) {
        return $json;
    }
    return $_POST;
}

function int_param(array $src, string $key, int $default = 0): int
{
    $v = $src[$key] ?? $default;
    return ($v === '' || $v === null) ? $default : (int)$v;
}

function str_param(array $src, string $key, string $default = ''): string
{
    $v = $src[$key] ?? $default;
    return trim((string)$v);
}

/**
 * Convert a thrown PDOException into the right HTTP status:
 *  - SQLSTATE 45000 = SIGNAL from business-rule triggers  -> 409
 *  - SQLSTATE 23000 = integrity constraint (UNIQUE/FK)    -> 409
 *  - everything else                                       -> 500
 */
set_exception_handler(function (Throwable $e): void {
    $msg = $e->getMessage();
    $sqlstate = ($e instanceof PDOException) ? substr($msg, 9, 5) : '';
    $code = match (true) {
        $sqlstate === '45000' => 409,
        $sqlstate === '23000' => 409,
        default               => 500,
    };
    json_err($msg, $code);
});
