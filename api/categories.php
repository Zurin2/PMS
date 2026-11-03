<?php
// =============================================================
// PMS Inventory Tracking & Delivery Monitoring System
// File: api/categories.php
// Description: CRUD endpoint for item categories
// =============================================================

require __DIR__ . '/_bootstrap.php';
require_login();

$pdo = db();
$id  = (int) ($_GET['id'] ?? 0);

try {
    switch ($_SERVER['REQUEST_METHOD']) {

        case 'GET':
            if ($id) {
                $st = $pdo->prepare('SELECT * FROM category WHERE category_id = ?');
                $st->execute([$id]);
                $row = $st->fetch();
                $row ? respond(200, $row) : respond(404, ['error' => 'Category not found']);
            }
            respond(200, $pdo->query('SELECT * FROM category ORDER BY name')->fetchAll());

        case 'POST':
            $b = input();
            need($b, ['name']);
            $st = $pdo->prepare('INSERT INTO category (name) VALUES (?)');
            $st->execute([$b['name']]);
            respond(201, ['category_id' => (int) $pdo->lastInsertId()]);

        case 'PUT':
            if (!$id) respond(400, ['error' => 'id required']);
            $b = input();
            need($b, ['name']);
            $pdo->prepare('UPDATE category SET name = ? WHERE category_id = ?')->execute([$b['name'], $id]);
            respond(200, ['ok' => true]);

        case 'DELETE':
            if (!$id) respond(400, ['error' => 'id required']);
            $pdo->prepare('DELETE FROM category WHERE category_id = ?')->execute([$id]);
            respond(200, ['deleted' => true]);

        default:
            respond(405, ['error' => 'Method not allowed']);
    }
} catch (PDOException $e) {
    fail($e);
}
