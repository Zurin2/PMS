<?php
declare(strict_types=1);
// =============================================================
// PMS Inventory Tracking & Delivery Monitoring System
// File: api/reports.php
// Description: Periodic reports.
//   ?report=matrix&from=&to=  per-item movement matrix (v_stock_movement)
//   ?report=summary&from=&to= period aggregates
//   ?report=revenue&from=&to= monthly revenue/collected/deliveries
// =============================================================

require_once __DIR__ . '/_bootstrap.php';
require_login();

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_err('Method not allowed', 405);
}

function valid_rdate(string $d): bool
{
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
}

$report = strtolower(str_param($_GET, 'report'));
$from = str_param($_GET, 'from');
$to = str_param($_GET, 'to');

if (!in_array($report, ['matrix', 'summary', 'revenue'], true)) {
    json_err('report must be matrix, summary or revenue', 400);
}
if ($from === '' || $to === '') {
    json_err('from and to are required (YYYY-MM-DD)', 400);
}
if (!valid_rdate($from) || !valid_rdate($to)) {
    json_err('from and to must be YYYY-MM-DD', 400);
}
if ($from > $to) {
    json_err('from must not be after to', 400);
}

/* ---------- ?report=matrix ---------- */

if ($report === 'matrix') {
    $st = $pdo->prepare(
        "SELECT i.item_id, i.item_code, i.name, c.name AS category, i.unit,
                COALESCE(SUM(CASE WHEN m.move_date < ? THEN m.qty END), 0) AS beginning,
                COALESCE(SUM(CASE WHEN m.move_date BETWEEN ? AND ? AND m.move_type = 'IN' THEN m.qty END), 0) AS in_qty,
                COALESCE(SUM(CASE WHEN m.move_date BETWEEN ? AND ? AND m.move_type = 'OUT' THEN -m.qty END), 0) AS out_qty,
                COALESCE(SUM(CASE WHEN m.move_date BETWEEN ? AND ? AND m.move_type = 'ADJ' THEN m.qty END), 0) AS adj
         FROM item i
         JOIN category c ON c.category_id = i.category_id
         LEFT JOIN v_stock_movement m ON m.item_id = i.item_id
         GROUP BY i.item_id, i.item_code, i.name, c.name, i.unit
         HAVING beginning <> 0 OR in_qty <> 0 OR out_qty <> 0 OR adj <> 0
         ORDER BY category ASC, name ASC"
    );
    $st->execute([$from, $from, $to, $from, $to, $from, $to]);

    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $beginning = (int)$r['beginning'];
        $in_qty = (int)$r['in_qty'];
        $out_qty = (int)$r['out_qty'];
        $adj = (int)$r['adj'];
        $rows[] = [
            'item_id'    => (int)$r['item_id'],
            'item_code'  => $r['item_code'],
            'name'       => $r['name'],
            'category'   => $r['category'],
            'unit'       => $r['unit'],
            'beginning'  => $beginning,
            'in_qty'     => $in_qty,
            'out_qty'    => $out_qty,
            'adj'        => $adj,
            'ending'     => $beginning + $in_qty - $out_qty + $adj,
        ];
    }
    json_ok($rows);
}

/* ---------- ?report=summary ---------- */

if ($report === 'summary') {
    $rev = $pdo->prepare(
        "SELECT COALESCE(SUM(total_amount), 0) FROM v_delivery_balance
         WHERE status = 'DELIVERED' AND delivery_date BETWEEN ? AND ?"
    );
    $rev->execute([$from, $to]);
    $revenue = (float)$rev->fetchColumn();

    $col = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM payment WHERE payment_date BETWEEN ? AND ?');
    $col->execute([$from, $to]);
    $collected = (float)$col->fetchColumn();

    $cnt = $pdo->prepare('SELECT COUNT(*) FROM delivery WHERE delivery_date BETWEEN ? AND ?');
    $cnt->execute([$from, $to]);
    $deliveries_count = (int)$cnt->fetchColumn();

    $out = $pdo->prepare(
        "SELECT COALESCE(SUM(di.quantity * di.unit_price), 0)
         FROM delivery_item di
         JOIN delivery d ON d.delivery_id = di.delivery_id
         WHERE d.status = 'DELIVERED' AND d.delivery_date BETWEEN ? AND ?"
    );
    $out->execute([$from, $to]);
    $outflow_value = (float)$out->fetchColumn();

    json_ok([
        'revenue'          => $revenue,
        'collected'        => $collected,
        'deliveries_count' => $deliveries_count,
        'outflow_value'    => $outflow_value,
    ]);
}

/* ---------- ?report=revenue (monthly series, gaps included) ---------- */

$yms = [];
$ym = substr($from, 0, 7);
$lastYm = substr($to, 0, 7);
while ($ym <= $lastYm) {
    $yms[] = $ym;
    $ym = date('Y-m', (int)strtotime($ym . '-01 +1 month'));
}

$revRows = $pdo->prepare(
    "SELECT DATE_FORMAT(delivery_date, '%Y-%m') AS ym, COALESCE(SUM(total_amount), 0) AS revenue
     FROM v_delivery_balance
     WHERE status = 'DELIVERED' AND delivery_date BETWEEN ? AND ?
     GROUP BY ym"
);
$revRows->execute([$from, $to]);
$revMap = [];
foreach ($revRows->fetchAll() as $r) {
    $revMap[$r['ym']] = (float)$r['revenue'];
}

$colRows = $pdo->prepare(
    "SELECT DATE_FORMAT(payment_date, '%Y-%m') AS ym, COALESCE(SUM(amount), 0) AS collected
     FROM payment WHERE payment_date BETWEEN ? AND ? GROUP BY ym"
);
$colRows->execute([$from, $to]);
$colMap = [];
foreach ($colRows->fetchAll() as $r) {
    $colMap[$r['ym']] = (float)$r['collected'];
}

$cntRows = $pdo->prepare(
    "SELECT DATE_FORMAT(delivery_date, '%Y-%m') AS ym, COUNT(*) AS n
     FROM delivery WHERE delivery_date BETWEEN ? AND ? GROUP BY ym"
);
$cntRows->execute([$from, $to]);
$cntMap = [];
foreach ($cntRows->fetchAll() as $r) {
    $cntMap[$r['ym']] = (int)$r['n'];
}

$rows = [];
foreach ($yms as $ymKey) {
    $rows[] = [
        'ym'               => $ymKey,
        'revenue'          => $revMap[$ymKey] ?? 0.0,
        'collected'        => $colMap[$ymKey] ?? 0.0,
        'deliveries_count' => $cntMap[$ymKey] ?? 0,
    ];
}
json_ok($rows);
