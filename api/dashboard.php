<?php
declare(strict_types=1);
// =============================================================
// PMS Inventory Tracking & Delivery Monitoring System
// File: api/dashboard.php
// Description: Year-to-date KPIs + low stock list.
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

$low = $pdo->query(
    "SELECT item_id, item_code, name, category, unit, unit_price, low_stock_level, stock_on_hand
     FROM v_item_stock
     WHERE status = 'ACTIVE' AND stock_on_hand <= low_stock_level
     ORDER BY stock_on_hand ASC
     LIMIT 50"
)->fetchAll();

json_ok([
    'revenue_total_ytd'   => $revenue_ytd,
    'collected_ytd'       => $collected_ytd,
    'outstanding'         => $outstanding,
    'item_count'          => $item_count,
    'active_items'        => $active_items,
    'low_stock_items'     => $low_stock_count,
    'deliveries_this_year' => $deliveries_this_year,
    'schools'             => $schools,
    'offices'             => $offices,
    'low_stock'           => $low,
]);
