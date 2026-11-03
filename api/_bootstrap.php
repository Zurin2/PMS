<?php
declare(strict_types=1);
// =============================================================
// PMS Inventory Tracking & Delivery Monitoring System
// File: api/_bootstrap.php
// Description: Shared bootstrap for all API endpoints
//              Starts session, sets JSON headers, loads DB
// =============================================================

session_start();
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/../config/db.php';

/**
 * Emit a JSON response and terminate.
 */
function respond(int $code, mixed $data): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Guard: abort with 401 if the user is not authenticated.
 */
function require_login(): void {
    if (empty($_SESSION['user_id'])) {
        respond(401, ['error' => 'Login required']);
    }
}

/**
 * Parse and return the JSON request body as an array.
 */
function input(): array {
    $d = json_decode(file_get_contents('php://input'), true);
    return is_array($d) ? $d : [];
}

/**
 * Validate that required keys exist in $body and are non-empty.
 * Responds 422 immediately if any key is missing.
 */
function need(array $body, array $keys): void {
    foreach ($keys as $k) {
        if (!isset($body[$k]) || $body[$k] === '') {
            respond(422, ['error' => "Missing field: $k"]);
        }
    }
}

/**
 * Translate PDOException to a safe HTTP error response.
 * Handles trigger violations (45000) and constraint violations (23000).
 */
function fail(PDOException $e): void {
    $state = $e->errorInfo[0] ?? '';
    if ($state === '45000') respond(409, ['error' => $e->errorInfo[2]]);   // custom trigger rule
    if ($state === '23000') respond(409, ['error' => 'Rule violated: duplicate or still referenced']);
    respond(500, ['error' => 'Database error']);
}
