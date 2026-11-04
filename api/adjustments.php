<?php
declare(strict_types=1);
// =============================================================
// PMS Inventory Tracking & Delivery Monitoring System
// File: api/adjustments.php
// Description: Stock adjustment endpoint. POST snapshots
// system_qty from v_item_stock before writing stock_adjustment.
// =============================================================

require_once __DIR__ . '/_bootstrap.php';
require_login();

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

function valid_adate(string $d): bool
{
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
}

if ($method === 'GET') {
    $where = [];
    $params = [];

    $iid = int_param($_GET, 'item_id');
    if ($iid > 0) {
        $where[] = 'sa.item_id = ?';
        $params[] = $iid;
    }
    $from = str_param($_GET, 'from');
    if ($from !== '') {
        if (!valid_adate($from)) {
            json_err('from must be YYYY-MM-DD', 400);
        }
        $where[] = 'sa.adjust_date >= ?';
        $params[] = $from;
    }
    $to = str_param($_GET, 'to');
    if ($to !== '') {
        if (!valid_adate($to)) {
            json_err('to must be YYYY-MM-DD', 400);
        }
        $where[] = 'sa.adjust_date <= ?';
        $params[] = $to;
    }

    $sql = 'SELECT sa.adjustment_id, sa.item_id, i.item_code, i.name,
                   sa.adjust_date, sa.system_qty, sa.counted_qty,
                   (sa.counted_qty - sa.system_qty) AS variance, sa.reason
            FROM stock_adjustment sa
            JOIN item i ON i.item_id = sa.item_id';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY sa.adjust_date DESC, sa.adjustment_id DESC';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    json_ok($st->fetchAll());
}

if ($method === 'POST') {
    $b = body();

    $item_id = (int)($b['item_id'] ?? 0);
    $countedRaw = $b['counted_quantity'] ?? null;
    $adjust_date = str_param($b, 'adjust_date');
    $reason = str_param($b, 'reason');

    if ($item_id <= 0) {
        json_err('item_id is required', 400);
    }
    if (!is_numeric($countedRaw) || preg_match('/^\d+$/', (string)$countedRaw) !== 1) {
        json_err('counted_quantity must be a non-negative integer', 400);
    }
    if (!valid_adate($adjust_date)) {
        json_err('adjust_date must be YYYY-MM-DD', 400);
    }
    $counted_quantity = (int)$countedRaw;

    $stItem = $pdo->prepare('SELECT stock_on_hand FROM v_item_stock WHERE item_id = ?');
    $stItem->execute([$item_id]);
    $soh = $stItem->fetchColumn();
    if ($soh === false) {
        json_err('Item not found', 404);
    }
    $system_qty = (int)$soh;

    $ins = $pdo->prepare(
        'INSERT INTO stock_adjustment (item_id, adjust_date, system_qty, counted_qty, reason)
         VALUES (?, ?, ?, ?, ?)'
    );
    $ins->execute([$item_id, $adjust_date, $system_qty, $counted_quantity, $reason ?: null]);

    json_ok(['adjustment_id' => (int)$pdo->lastInsertId()], 201);
}

json_err('Method not allowed', 405);
