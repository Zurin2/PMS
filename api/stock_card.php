<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_login();

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_err('Method not allowed', 405);
}

$item_id = int_param($_GET, 'item_id');

/* ---------- Item picker ---------- */
if ($item_id <= 0) {
    $rows = $pdo->query(
        'SELECT item_id, item_code, name, unit, stock_on_hand
           FROM v_item_stock
          WHERE status = \'ACTIVE\'
          ORDER BY name ASC'
    )->fetchAll();
    json_ok(['items' => array_map(fn($r) => [
        'item_id'       => (int)$r['item_id'],
        'item_code'     => $r['item_code'],
        'name'          => $r['name'],
        'unit'          => $r['unit'],
        'stock_on_hand' => (int)$r['stock_on_hand'],
    ], $rows)]);
}

/* ---------- Ledger for one item ---------- */
$stmt = $pdo->prepare('SELECT item_code, name, unit, COALESCE(stock_on_hand, 0) AS stock_on_hand FROM v_item_stock WHERE item_id = ?');
$stmt->execute([$item_id]);
$item = $stmt->fetch();
if (!$item) {
    json_err('Item not found', 404);
}

$stmt = $pdo->prepare(
    'SELECT move_date, move_type, qty
       FROM v_stock_movement
      WHERE item_id = ?
      ORDER BY move_date ASC, FIELD(move_type, \'IN\', \'OUT\', \'ADJ\')'
);
$stmt->execute([$item_id]);
$movements = $stmt->fetchAll();

$from = str_param($_GET, 'from');
$to = str_param($_GET, 'to');

// Running balance starts from movements before the period when `from` is set.
$balance = 0;
$entries = [];
if ($from !== '') {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        json_err('from must be a YYYY-MM-DD date', 400);
    }
    foreach ($movements as $m) {
        if ($m['move_date'] < $from) {
            $balance += (int)$m['qty'];
        }
    }
}

foreach ($movements as $m) {
    if ($from !== '' && $m['move_date'] < $from) {
        continue;
    }
    if ($to !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            json_err('to must be a YYYY-MM-DD date', 400);
        }
        if ($m['move_date'] > $to) {
            continue;
        }
    }
    $qty = (int)$m['qty'];
    $balance += $qty;
    $entries[] = [
        'move_date' => $m['move_date'],
        'move_type' => $m['move_type'],
        'qty'       => $qty,
        'balance'   => $balance,
    ];
}

json_ok([
    'item' => [
        'item_code'     => $item['item_code'],
        'name'          => $item['name'],
        'unit'          => $item['unit'],
        'stock_on_hand' => (int)$item['stock_on_hand'],
    ],
    'entries' => $entries,
]);
