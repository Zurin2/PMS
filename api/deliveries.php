<?php
// =============================================================
// PMS Inventory Tracking & Delivery Monitoring System
// File: api/deliveries.php
// Description: Delivery records endpoint (DIRECT & BIDDING types)
//              POST creates delivery + line items in one transaction
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
                $st = $pdo->prepare(
                    'SELECT d.*, vdb.total_amount, vdb.total_paid, vdb.balance,
                            c.name AS customer_name, c.customer_type
                     FROM delivery d
                     JOIN v_delivery_balance vdb ON vdb.delivery_id = d.delivery_id
                     JOIN customer c ON c.customer_id = d.customer_id
                     WHERE d.delivery_id = ?'
                );
                $st->execute([$id]);
                $row = $st->fetch();
                if (!$row) respond(404, ['error' => 'Delivery not found']);

                // Attach line items
                $items = $pdo->prepare(
                    'SELECT di.*, i.name AS item_name, i.unit
                     FROM delivery_item di JOIN item i ON i.item_id = di.item_id
                     WHERE di.delivery_id = ?'
                );
                $items->execute([$id]);
                $row['items'] = $items->fetchAll();
                respond(200, $row);
            }
            respond(200, $pdo->query(
                'SELECT d.*, vdb.total_amount, vdb.total_paid, vdb.balance,
                        c.name AS customer_name
                 FROM delivery d
                 JOIN v_delivery_balance vdb ON vdb.delivery_id = d.delivery_id
                 JOIN customer c ON c.customer_id = d.customer_id
                 ORDER BY d.delivery_date DESC'
            )->fetchAll());

        // ---- CREATE (transactional) -----------------------------
        case 'POST':
            $b = input();
            need($b, ['receipt_no', 'customer_id', 'delivery_date', 'order_type']);

            if (empty($b['items']) || !is_array($b['items'])) {
                respond(422, ['error' => 'At least one item is required']);
            }

            if ($b['order_type'] === 'DIRECT') {
                need($b, ['po_number', 'po_date']);
            } elseif ($b['order_type'] === 'BIDDING') {
                need($b, ['bid_ref_no', 'delivery_deadline']);
            } else {
                respond(422, ['error' => 'order_type must be DIRECT or BIDDING']);
            }

            $pdo->beginTransaction();
            try {
                $pdo->prepare(
                    'INSERT INTO delivery (receipt_no, customer_id, delivery_date, order_type)
                     VALUES (?, ?, ?, ?)'
                )->execute([$b['receipt_no'], $b['customer_id'], $b['delivery_date'], $b['order_type']]);
                $did = (int) $pdo->lastInsertId();

                if ($b['order_type'] === 'DIRECT') {
                    $pdo->prepare(
                        'INSERT INTO direct_delivery (delivery_id, po_number, po_date) VALUES (?, ?, ?)'
                    )->execute([$did, $b['po_number'], $b['po_date']]);
                } else {
                    $pdo->prepare(
                        'INSERT INTO bidding_delivery (delivery_id, bid_ref_no, delivery_deadline) VALUES (?, ?, ?)'
                    )->execute([$did, $b['bid_ref_no'], $b['delivery_deadline']]);
                }

                // Insert line items (trigger checks SOH per item)
                $line = $pdo->prepare(
                    'INSERT INTO delivery_item (delivery_id, item_id, quantity, unit_price)
                     SELECT ?, item_id, ?, COALESCE(?, unit_price) FROM item WHERE item_id = ?'
                );
                foreach ($b['items'] as $l) {
                    $line->execute([$did, (int)$l['quantity'], $l['unit_price'] ?? null, (int)$l['item_id']]);
                    if ($line->rowCount() !== 1) {
                        throw new RuntimeException('Unknown item id: ' . ($l['item_id'] ?? '?'));
                    }
                }

                $pdo->commit();
                respond(201, ['delivery_id' => $did]);
            } catch (RuntimeException $e) {
                $pdo->rollBack();
                respond(422, ['error' => $e->getMessage()]);
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                fail($e);
            }

        // ---- CANCEL (soft status update) ------------------------
        case 'PUT':
            if (!$id) respond(400, ['error' => 'id required']);
            $b = input();
            need($b, ['status']);
            if (!in_array($b['status'], ['DELIVERED', 'CANCELLED'], true)) {
                respond(422, ['error' => 'Invalid status value']);
            }
            $pdo->prepare('UPDATE delivery SET status = ? WHERE delivery_id = ?')->execute([$b['status'], $id]);
            respond(200, ['ok' => true]);

        default:
            respond(405, ['error' => 'Method not allowed']);
    }
} catch (PDOException $e) {
    fail($e);
}
