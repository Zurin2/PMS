<?php
declare(strict_types=1);
// =============================================================
// PMS Inventory Tracking & Delivery Monitoring System
// File: api/dashboard.php
// Description: YTD/month KPIs, inventory stats, and analytics widgets.
// =============================================================

require_once __DIR__ . '/_bootstrap.php';
require_login();

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_err('Method not allowed', 405);
}

$year = (int)date('Y');

$revenue = $pdo->prepare(
    "SELECT COALESCE(SUM(total_amount), 0) FROM v_delivery_balance
     WHERE status = 'DELIVERED' AND YEAR(delivery_date) = ?"
);
$revenue->execute([$year]);
$revenue_ytd = (float)$revenue->fetchColumn();

$collected = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM payment WHERE YEAR(payment_date) = ?');
$collected->execute([$year]);
$collected_ytd = (float)$collected->fetchColumn();

$outstanding_row = $pdo->query(
    "SELECT COALESCE(SUM(balance), 0) FROM v_delivery_balance
     WHERE balance > 0 AND status = 'DELIVERED'"
)->fetchColumn();
$outstanding = (float)$outstanding_row;

// Inventory stats (valuation = stock on hand x unit price, active items only)
$inv = $pdo->query(
    "SELECT COALESCE(SUM(stock_on_hand * unit_price), 0), COALESCE(SUM(stock_on_hand), 0)
     FROM v_item_stock WHERE status = 'ACTIVE'"
)->fetch(PDO::FETCH_NUM);
$inventory_valuation = (float)$inv[0];
$stock_on_hand_units = (int)$inv[1];

$item_count = (int)$pdo->query('SELECT COUNT(*) FROM item')->fetchColumn();
$active_items = (int)$pdo->query("SELECT COUNT(*) FROM item WHERE status = 'ACTIVE'")->fetchColumn();

$low_stock_count = (int)$pdo->query(
    "SELECT COUNT(*) FROM v_item_stock WHERE status = 'ACTIVE' AND stock_on_hand <= low_stock_level"
)->fetchColumn();

$dq = $pdo->prepare('SELECT COUNT(*) FROM delivery WHERE YEAR(delivery_date) = ?');
$dq->execute([$year]);
$deliveries_this_year = (int)$dq->fetchColumn();

$schools = (int)$pdo->query("SELECT COUNT(*) FROM customer WHERE customer_type = 'SCHOOL'")->fetchColumn();
$offices = (int)$pdo->query("SELECT COUNT(*) FROM customer WHERE customer_type = 'OFFICE'")->fetchColumn();

// Revenue this calendar month
$mrev = $pdo->prepare(
    "SELECT COALESCE(SUM(total_amount), 0) FROM v_delivery_balance
     WHERE status = 'DELIVERED' AND YEAR(delivery_date) = ? AND MONTH(delivery_date) = ?"
);
$mrev->execute([(int)date('Y'), (int)date('n')]);
$revenue_this_month = (float)$mrev->fetchColumn();

// Revenue trend: last 6 calendar months (missing months stay at 0)
$months = [];
for ($i = 5; $i >= 0; $i--) {
    $ts = strtotime("first day of -$i month");
    $key = date('Y-m', $ts);
    $months[$key] = ['month' => $key, 'label' => date('M', $ts), 'total' => 0.0, 'deliveries' => 0];
}
$trend = $pdo->prepare(
    "SELECT DATE_FORMAT(delivery_date, '%Y-%m') AS ym,
            SUM(total_amount) AS total, COUNT(*) AS deliveries
     FROM v_delivery_balance
     WHERE status = 'DELIVERED' AND delivery_date >= ?
     GROUP BY ym"
);
$trend->execute([date('Y-m-01', strtotime('-5 month'))]);
foreach ($trend->fetchAll() as $r) {
    $k = $r['ym'];
    if (isset($months[$k])) {
        $months[$k]['total'] = (float)$r['total'];
        $months[$k]['deliveries'] = (int)$r['deliveries'];
    }
}

// Top items by outgoing quantity, last 90 days
$top = $pdo->query(
    "SELECT i.item_code, i.name, c.name AS category, i.unit, CAST(SUM(-m.qty) AS UNSIGNED) AS out_qty
     FROM v_stock_movement m
     JOIN item i ON i.item_id = m.item_id
     JOIN category c ON c.category_id = i.category_id
     WHERE m.move_type = 'OUT' AND m.move_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
     GROUP BY i.item_id
     ORDER BY out_qty DESC
     LIMIT 5"
)->fetchAll();

$low = $pdo->query(
    "SELECT item_id, item_code, name, category, unit, unit_price, low_stock_level, stock_on_hand
     FROM v_item_stock
     WHERE status = 'ACTIVE' AND stock_on_hand <= low_stock_level
     ORDER BY stock_on_hand ASC
     LIMIT 50"
)->fetchAll();

json_ok([
    'revenue_total_ytd'   => $revenue_ytd,
    'revenue_this_month'  => $revenue_this_month,
    'collected_ytd'       => $collected_ytd,
    'outstanding'         => $outstanding,
    'inventory_valuation' => $inventory_valuation,
    'stock_on_hand_units' => $stock_on_hand_units,
    'item_count'          => $item_count,
    'active_items'        => $active_items,
    'low_stock_items'     => $low_stock_count,
    'deliveries_this_year' => $deliveries_this_year,
    'schools'             => $schools,
    'offices'             => $offices,
    'revenue_trend'       => array_values($months),
    'top_items'           => $top,
    'low_stock'           => $low,
]);
