<?php
// =============================================================
// PMS Inventory Tracking & Delivery Monitoring System
// File: api/items.php
// Description: CRUD endpoint for inventory items
//              GET    -> list or single item
//              POST   -> create item
//              PUT    -> update item
//              DELETE -> delete (or archive if referenced)
// =============================================================

require __DIR__ . '/_bootstrap.php';
require_login();

$pdo = db();
$id  = (int) ($_GET['id'] ?? 0);

try {
    switch ($_SERVER['REQUEST_METHOD']) {

        // ---- READ -----------------------------------------------
        case 'GET':
            if ($id) {
                $st = $pdo->prepare('SELECT * FROM v_item_stock WHERE item_id = ?');
                $st->execute([$id]);
                $row = $st->fetch();
                $row ? respond(200, $row) : respond(404, ['error' => 'Item not found']);
            }
            respond(200, $pdo->query('SELECT * FROM v_item_stock ORDER BY name')->fetchAll());

        // ---- CREATE ---------------------------------------------
        case 'POST':
            $b = input();
            need($b, ['category_id', 'item_code', 'name', 'unit', 'unit_price']);
            $st = $pdo->prepare(
                'INSERT INTO item (category_id, item_code, name, unit, unit_price, low_stock_level)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $st->execute([
                $b['category_id'],
                $b['item_code'],
                $b['name'],
                $b['unit'],
                $b['unit_price'],
                $b['low_stock_level'] ?? 0,
            ]);
            respond(201, ['item_id' => (int) $pdo->lastInsertId()]);

        // ---- UPDATE ---------------------------------------------
        case 'PUT':
            if (!$id) respond(400, ['error' => 'id required']);
            $b = input();
            need($b, ['category_id', 'name', 'unit', 'unit_price', 'low_stock_level']);
            $st = $pdo->prepare(
                'UPDATE item SET category_id = ?, name = ?, unit = ?, unit_price = ?, low_stock_level = ?
                 WHERE item_id = ?'
            );
            $st->execute([
                $b['category_id'],
                $b['name'],
                $b['unit'],
                $b['unit_price'],
                $b['low_stock_level'],
                $id,
            ]);
            respond(200, ['ok' => true]); // trigger logs price change automatically

        // ---- DELETE (soft-archive if referenced) ----------------
        case 'DELETE':
            if (!$id) respond(400, ['error' => 'id required']);
            try {
                $pdo->prepare('DELETE FROM item WHERE item_id = ?')->execute([$id]);
                respond(200, ['deleted' => true]);
            } catch (PDOException $e) {
                if (($e->errorInfo[0] ?? '') !== '23000') throw $e; // re-throw unexpected errors
                // RESTRICT constraint: item is referenced — archive instead
                $pdo->prepare("UPDATE item SET status = 'ARCHIVED' WHERE item_id = ?")->execute([$id]);
                respond(200, ['deleted' => false, 'archived' => true]);
            }

        default:
            respond(405, ['error' => 'Method not allowed']);
    }
} catch (PDOException $e) {
    fail($e);
}
