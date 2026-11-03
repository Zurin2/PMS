<?php
// =============================================================
// PMS Inventory Tracking & Delivery Monitoring System
// File: api/reports.php
// Description: Analytical reporting endpoint
//              ?type=inventory_movement&start=YYYY-MM-DD&end=YYYY-MM-DD
//              ?type=delivery_balance
//              ?type=low_stock
// =============================================================

require __DIR__ . '/_bootstrap.php';
require_login();

$pdo  = db();
$type = $_GET['type'] ?? '';

try {
    switch ($type) {

        case 'inventory_movement':
            $start = $_GET['start'] ?? date('Y-m-01');
            $end   = $_GET['end']   ?? date('Y-m-d');
            $st = $pdo->prepare(
                'SELECT i.item_id, i.name,
                        COALESCE(SUM(CASE WHEN m.move_date < ? THEN m.qty END), 0) AS beginning,
                        COALESCE(SUM(CASE WHEN m.move_date BETWEEN ? AND ? AND m.move_type = "IN" THEN m.qty END), 0) AS incoming,
                        COALESCE(SUM(CASE WHEN m.move_date BETWEEN ? AND ? AND m.move_type = "OUT" THEN -m.qty END), 0) AS outgoing,
                        COALESCE(SUM(CASE WHEN m.move_date BETWEEN ? AND ? AND m.move_type = "ADJ" THEN m.qty END), 0) AS adjustments,
                        COALESCE(SUM(CASE WHEN m.move_date <= ? THEN m.qty END), 0) AS ending
                 FROM item i
                 LEFT JOIN v_stock_movement m ON m.item_id = i.item_id
                 GROUP BY i.item_id, i.name
                 ORDER BY i.name'
            );
            $st->execute([$start, $start, $end, $start, $end, $start, $end, $end]);
            respond(200, $st->fetchAll());

        case 'delivery_balance':
            respond(200, $pdo->query(
                'SELECT vdb.*, c.name AS customer_name
                 FROM v_delivery_balance vdb
                 JOIN customer c ON c.customer_id = vdb.customer_id
                 ORDER BY vdb.delivery_date DESC'
            )->fetchAll());

        case 'low_stock':
            respond(200, $pdo->query(
                'SELECT * FROM v_item_stock WHERE stock_on_hand <= low_stock_level AND status = "ACTIVE" ORDER BY name'
            )->fetchAll());

        default:
            respond(400, ['error' => 'Unknown report type. Use: inventory_movement, delivery_balance, low_stock']);
    }
} catch (PDOException $e) {
    fail($e);
}
