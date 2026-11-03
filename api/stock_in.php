<?php
// =============================================================
// PMS Inventory Tracking & Delivery Monitoring System
// File: api/stock_in.php
// Description: Manage stock-in records (receiving inventory)
// =============================================================

require __DIR__ . '/_bootstrap.php';
require_login();

$pdo = db();
$id  = (int) ($_GET['id'] ?? 0);

try {
    switch ($_SERVER['REQUEST_METHOD']) {

        case 'GET':
            if ($id) {
                $st = $pdo->prepare(
                    'SELECT si.*, i.name AS item_name, i.unit
                     FROM stock_in si JOIN item i ON i.item_id = si.item_id
                     WHERE si.stock_in_id = ?'
                );
                $st->execute([$id]);
                $row = $st->fetch();
                $row ? respond(200, $row) : respond(404, ['error' => 'Record not found']);
            }
            respond(200, $pdo->query(
                'SELECT si.*, i.name AS item_name, i.unit
                 FROM stock_in si JOIN item i ON i.item_id = si.item_id
                 ORDER BY si.date_received DESC'
            )->fetchAll());

        case 'POST':
            $b = input();
            need($b, ['item_id', 'quantity', 'date_received']);
            $st = $pdo->prepare(
                'INSERT INTO stock_in (item_id, quantity, remarks, date_received)
                 VALUES (?, ?, ?, ?)'
            );
            $st->execute([
                (int) $b['item_id'],
                (int) $b['quantity'],
                $b['remarks'] ?? null,
                $b['date_received'],
            ]);
            respond(201, ['stock_in_id' => (int) $pdo->lastInsertId()]);

        case 'DELETE':
            if (!$id) respond(400, ['error' => 'id required']);
            // Trigger trg_si_delete_guard prevents negative SOH
            $pdo->prepare('DELETE FROM stock_in WHERE stock_in_id = ?')->execute([$id]);
            respond(200, ['deleted' => true]);

        default:
            respond(405, ['error' => 'Method not allowed']);
    }
} catch (PDOException $e) {
    fail($e);
}
