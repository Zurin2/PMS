<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_login();

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

/* ---------- GET ---------- */
if ($method === 'GET') {
    $rows = $pdo->query(
        'SELECT c.category_id, c.name, COUNT(i.item_id) AS item_count
           FROM category c
           LEFT JOIN item i ON i.category_id = c.category_id
          GROUP BY c.category_id, c.name
          ORDER BY c.name ASC'
    )->fetchAll();
    json_ok(array_map(fn($r) => [
        'category_id' => (int)$r['category_id'],
        'name'        => $r['name'],
        'item_count'  => (int)$r['item_count'],
    ], $rows));
}

/* ---------- POST ---------- */
if ($method === 'POST') {
    $name = str_param(body(), 'name');
    if ($name === '') {
        json_err('name is required', 400);
    }
    try {
        $stmt = $pdo->prepare('INSERT INTO category (name) VALUES (?)');
        $stmt->execute([$name]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            json_err('Category name already exists', 409);
        }
        throw $e;
    }
    json_ok(['category_id' => (int)$pdo->lastInsertId()], 201);
}

/* ---------- PUT ---------- */
if ($method === 'PUT') {
    $id = int_param($_GET, 'id');
    if ($id <= 0) {
        json_err('id is required', 400);
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM category WHERE category_id = ?');
    $stmt->execute([$id]);
    if ((int)$stmt->fetchColumn() === 0) {
        json_err('Category not found', 404);
    }
    $name = str_param(body(), 'name');
    if ($name === '') {
        json_err('name is required', 400);
    }
    try {
        $stmt = $pdo->prepare('UPDATE category SET name = ? WHERE category_id = ?');
        $stmt->execute([$name, $id]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            json_err('Category name already exists', 409);
        }
        throw $e;
    }
    json_ok(['ok' => true]);
}

/* ---------- DELETE ---------- */
if ($method === 'DELETE') {
    $id = int_param($_GET, 'id');
    if ($id <= 0) {
        json_err('id is required', 400);
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM category WHERE category_id = ?');
    $stmt->execute([$id]);
    if ((int)$stmt->fetchColumn() === 0) {
        json_err('Category not found', 404);
    }
    try {
        $stmt = $pdo->prepare('DELETE FROM category WHERE category_id = ?');
        $stmt->execute([$id]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            json_err('Category has items', 409);
        }
        throw $e;
    }
    json_ok(['ok' => true]);
}

json_err('Method not allowed', 405);
