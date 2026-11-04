<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_login();

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

/* ---------- GET ---------- */
if ($method === 'GET') {
    $sql = 'SELECT si.stock_in_id, si.item_id, i.item_code, i.name, si.quantity,
                   si.date_received, si.remarks
              FROM stock_in si
              JOIN item i ON i.item_id = si.item_id
             WHERE 1=1';
    $args = [];

    $item_id = int_param($_GET, 'item_id');
    if ($item_id > 0) {
        $sql .= ' AND si.item_id = ?';
        $args[] = $item_id;
    }

    $from = str_param($_GET, 'from');
    if ($from !== '') {
        $sql .= ' AND si.date_received >= ?';
        $args[] = $from;
    }
    $to = str_param($_GET, 'to');
    if ($to !== '') {
        $sql .= ' AND si.date_received <= ?';
        $args[] = $to;
    }

    $sql .= ' ORDER BY si.date_received DESC, si.stock_in_id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);

    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $r['stock_in_id'] = (int)$r['stock_in_id'];
        $r['item_id'] = (int)$r['item_id'];
        $r['quantity'] = (int)$r['quantity'];
        $out[] = $r;
    }
    json_ok($out);
}

/* ---------- POST ---------- */
if ($method === 'POST') {
    $b = body();
    $item_id = int_param($b, 'item_id');
    $quantity = int_param($b, 'quantity');
    $date_received = str_param($b, 'date_received');
    $remarks = str_param($b, 'remarks') ?: null;

    if ($item_id <= 0) {
        json_err('item_id is required', 400);
    }
    if ($quantity <= 0) {
        json_err('quantity must be greater than 0', 400);
    }
    if ($date_received === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_received)) {
        json_err('date_received must be a YYYY-MM-DD date', 400);
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM item WHERE item_id = ?');
    $stmt->execute([$item_id]);
    if ((int)$stmt->fetchColumn() === 0) {
        json_err('Item not found', 400);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO stock_in (item_id, quantity, remarks, date_received) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$item_id, $quantity, $remarks, $date_received]);

    json_ok(['stock_in_id' => (int)$pdo->lastInsertId()], 201);
}

/* ---------- DELETE ---------- */
if ($method === 'DELETE') {
    $id = int_param($_GET, 'id');
    if ($id <= 0) {
        json_err('id is required', 400);
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM stock_in WHERE stock_in_id = ?');
    $stmt->execute([$id]);
    if ((int)$stmt->fetchColumn() === 0) {
        json_err('Stock-in receipt not found', 404);
    }

    // trg_si_delete_guard SIGNALs 45000 when stock would go negative -> global handler maps to 409.
    $stmt = $pdo->prepare('DELETE FROM stock_in WHERE stock_in_id = ?');
    $stmt->execute([$id]);

    json_ok(['ok' => true]);
}

json_err('Method not allowed', 405);
