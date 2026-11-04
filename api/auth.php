<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $b = body();
    $username = str_param($b, 'username');
    $password = str_param($b, 'password');
    if ($username === '' || $password === '') {
        json_err('Username and password are required', 400);
    }

    $stmt = db()->prepare(
        'SELECT user_id, username, password_hash, full_name
           FROM user_account
          WHERE username = ?'
    );
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        json_err('Invalid credentials', 401);
    }

    $_SESSION['user_id'] = (int)$user['user_id'];
    $_SESSION['username'] = (string)$user['username'];
    $_SESSION['full_name'] = (string)$user['full_name'];

    json_ok([
        'user_id'   => (int)$user['user_id'],
        'username'  => (string)$user['username'],
        'full_name' => (string)$user['full_name'],
    ]);
}

if ($method === 'GET') {
    $action = str_param($_GET, 'action');

    if ($action === 'me') {
        if (empty($_SESSION['user_id'])) {
            json_err('Authentication required', 401);
        }
        $stmt = db()->prepare('SELECT user_id, username, full_name FROM user_account WHERE user_id = ?');
        $stmt->execute([(int)$_SESSION['user_id']]);
        $user = $stmt->fetch();
        if (!$user) {
            json_err('Authentication required', 401);
        }
        json_ok([
            'user_id'   => (int)$user['user_id'],
            'username'  => (string)$user['username'],
            'full_name' => (string)$user['full_name'],
        ]);
    }

    if ($action === 'logout') {
        $_SESSION = [];
        session_destroy();
        json_ok(['ok' => true]);
    }

    json_err('Unknown action', 400);
}

json_err('Method not allowed', 405);
