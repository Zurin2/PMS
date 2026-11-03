<?php
// =============================================================
// PMS Inventory Tracking & Delivery Monitoring System
// File: api/auth.php
// Description: Authentication endpoint (login / logout / me)
// =============================================================

require __DIR__ . '/_bootstrap.php';

$action = $_GET['action'] ?? '';

// POST api/auth.php?action=login
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = input();
    need($b, ['username', 'password']);

    $st = db()->prepare('SELECT user_id, password_hash FROM user_account WHERE username = ?');
    $st->execute([$b['username']]);
    $u = $st->fetch();

    if (!$u || !password_verify($b['password'], $u['password_hash'])) {
        respond(401, ['error' => 'Wrong username or password']);
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $u['user_id'];
    respond(200, ['ok' => true]);
}

// GET/POST api/auth.php?action=logout
if ($action === 'logout') {
    session_destroy();
    respond(200, ['ok' => true]);
}

// GET api/auth.php?action=me
if ($action === 'me') {
    require_login();
    respond(200, ['user_id' => $_SESSION['user_id']]);
}

respond(400, ['error' => 'Unknown action']);
