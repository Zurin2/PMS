<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_login();

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

/* ---------- GET ---------- */
if ($method === 'GET') {
    $id = int_param($_GET, 'id');

    if ($id > 0) {
        $stmt = $pdo->prepare('SELECT * FROM v_item_stock WHERE item_id = ?');
        $stmt->execute([$id]);
        $item = $stmt->fetch();
        if (!$item) {
            json_err('Item not found', 404);
        }
        $stmt = $pdo->prepare(
            'SELECT effective_at, price
               FROM item_price_history
              WHERE item_id = ?
              ORDER BY effective_at DESC'
        );
        $stmt->execute([$id]);
        $item['price_history'] = array_map(fn($r) => [
            'effective_at' => $r['effective_at'],
            'price'        => (float)$r['price'],
        ], $stmt->fetchAll());
        $item['item_id'] = (int)$item['item_id'];
        $item['unit_price'] = (float)$item['unit_price'];
        $item['low_stock_level'] = (int)$item['low_stock_level'];
        $item['stock_on_hand'] = (int)$item['stock_on_hand'];
        json_ok($item);
    }

    $sql = 'SELECT * FROM v_item_stock WHERE 1=1';
    $args = [];

    $search = str_param($_GET, 'search');
    if ($search !== '') {
        $sql .= ' AND (item_code LIKE ? OR name LIKE ?)';
        $like = '%' . $search . '%';
        $args[] = $like;
        $args[] = $like;
    }

    $category = str_param($_GET, 'category');
    if ($category !== '') {
        if (ctype_digit($category)) {
            $sql .= ' AND category_id = ?';
            $args[] = (int)$category;
        } else {
            $sql .= ' AND category = ?';
            $args[] = $category;
        }
    }

    $status = strtoupper(str_param($_GET, 'status'));
    if ($status !== '') {
        if ($status !== 'ACTIVE' && $status !== 'ARCHIVED') {
            json_err('Invalid status', 400);
        }
        $sql .= ' AND status = ?';
        $args[] = $status;
    }

    if (int_param($_GET, 'low_stock') === 1) {
        $sql .= ' AND stock_on_hand <= low_stock_level';
    }

    $sql .= ' ORDER BY name ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);

    $items = [];
    foreach ($stmt->fetchAll() as $r) {
        $r['item_id'] = (int)$r['item_id'];
        $r['unit_price'] = (float)$r['unit_price'];
        $r['low_stock_level'] = (int)$r['low_stock_level'];
        $r['stock_on_hand'] = (int)$r['stock_on_hand'];
        $items[] = $r;
    }
    json_ok($items);
}

/* ---------- POST ---------- */
if ($method === 'POST') {
    $b = body();
    $category_id = int_param($b, 'category_id');
    $item_code = str_param($b, 'item_code');
    $name = str_param($b, 'name');
    $unit = str_param($b, 'unit');
    $unit_price = $b['unit_price'] ?? null;
    $low_stock_level = int_param($b, 'low_stock_level', 0);

    if ($category_id <= 0 || $item_code === '' || $name === '' || $unit === '') {
        json_err('category_id, item_code, name and unit are required', 400);
    }
    if ($unit_price === null || $unit_price === '' || !is_numeric($unit_price) || (float)$unit_price < 0) {
        json_err('unit_price must be a number >= 0', 400);
    }
    if ($low_stock_level < 0) {
        json_err('low_stock_level must be >= 0', 400);
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM category WHERE category_id = ?');
    $stmt->execute([$category_id]);
    if ((int)$stmt->fetchColumn() === 0) {
        json_err('Category not found', 400);
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO item (category_id, item_code, name, unit, unit_price, low_stock_level, status)
             VALUES (?, ?, ?, ?, ?, ?, \'ACTIVE\')'
        );
        $stmt->execute([$category_id, $item_code, $name, $unit, (string)(float)$unit_price, $low_stock_level]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            json_err('Item code or name/unit already exists', 409);
        }
        throw $e;
    }

    json_ok(['item_id' => (int)$pdo->lastInsertId()], 201);
}

/* ---------- PUT ---------- */
if ($method === 'PUT') {
    $id = int_param($_GET, 'id');
    if ($id <= 0) {
        json_err('id is required', 400);
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM item WHERE item_id = ?');
    $stmt->execute([$id]);
    if ((int)$stmt->fetchColumn() === 0) {
        json_err('Item not found', 404);
    }

    $b = body();
    $sets = [];
    $args = [];

    if (array_key_exists('category_id', $b)) {
        $category_id = int_param($b, 'category_id');
        if ($category_id <= 0) {
            json_err('category_id must be a positive integer', 400);
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM category WHERE category_id = ?');
        $stmt->execute([$category_id]);
        if ((int)$stmt->fetchColumn() === 0) {
            json_err('Category not found', 400);
        }
        $sets[] = 'category_id = ?';
        $args[] = $category_id;
    }
    if (array_key_exists('name', $b)) {
        if (str_param($b, 'name') === '') {
            json_err('name cannot be empty', 400);
        }
        $sets[] = 'name = ?';
        $args[] = str_param($b, 'name');
    }
    if (array_key_exists('unit', $b)) {
        if (str_param($b, 'unit') === '') {
            json_err('unit cannot be empty', 400);
        }
        $sets[] = 'unit = ?';
        $args[] = str_param($b, 'unit');
    }
    if (array_key_exists('unit_price', $b)) {
        $p = $b['unit_price'];
        if ($p === null || $p === '' || !is_numeric($p) || (float)$p < 0) {
            json_err('unit_price must be a number >= 0', 400);
        }
        $sets[] = 'unit_price = ?';
        $args[] = (string)(float)$p;
    }
    if (array_key_exists('low_stock_level', $b)) {
        $lvl = int_param($b, 'low_stock_level', 0);
        if ($lvl < 0) {
            json_err('low_stock_level must be >= 0', 400);
        }
        $sets[] = 'low_stock_level = ?';
        $args[] = $lvl;
    }
    if (array_key_exists('status', $b)) {
        $status = strtoupper(str_param($b, 'status'));
        if ($status !== 'ACTIVE' && $status !== 'ARCHIVED') {
            json_err('status must be ACTIVE or ARCHIVED', 400);
        }
        $sets[] = 'status = ?';
        $args[] = $status;
    }

    if ($sets === []) {
        json_err('No valid fields to update', 400);
    }

    try {
        $args[] = $id;
        $stmt = $pdo->prepare('UPDATE item SET ' . implode(', ', $sets) . ' WHERE item_id = ?');
        $stmt->execute($args);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            json_err('Item code or name/unit already exists', 409);
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
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM item WHERE item_id = ?');
    $stmt->execute([$id]);
    if ((int)$stmt->fetchColumn() === 0) {
        json_err('Item not found', 404);
    }

    $hasHistory =
        (function () use ($pdo, $id): bool {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM stock_in WHERE item_id = ?');
            $stmt->execute([$id]);
            return (int)$stmt->fetchColumn() > 0;
        })()
        || (function () use ($pdo, $id): bool {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM delivery_item WHERE item_id = ?');
            $stmt->execute([$id]);
            return (int)$stmt->fetchColumn() > 0;
        })()
        || (function () use ($pdo, $id): bool {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM stock_adjustment WHERE item_id = ?');
            $stmt->execute([$id]);
            return (int)$stmt->fetchColumn() > 0;
        })();

    if ($hasHistory) {
        $stmt = $pdo->prepare('UPDATE item SET status = \'ARCHIVED\' WHERE item_id = ?');
        $stmt->execute([$id]);
        json_ok(['archived' => true]);
    }

    $stmt = $pdo->prepare('DELETE FROM item WHERE item_id = ?');
    $stmt->execute([$id]);
    json_ok(['ok' => true]);
}

json_err('Method not allowed', 405);
