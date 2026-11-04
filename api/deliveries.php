<?php
declare(strict_types=1);
// =============================================================
// PMS Inventory Tracking & Delivery Monitoring System
// File: api/deliveries.php
// Description: Delivery records endpoint (DIRECT & BIDDING types).
// POST creates delivery + subtype + line items in ONE transaction;
// trg_di_stock_check SIGNAL 45000 rolls back on insufficient stock.
// =============================================================

require_once __DIR__ . '/_bootstrap.php';
require_login();

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];
$id = int_param($_GET, 'id');

/* ---------- helpers (used only by this file) ---------- */

function valid_date(string $d): bool
{
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
}

/** LEFT-JOINed delivery + balance + customer + both subtypes. */
function delivery_base_query(PDO $pdo, array $where, array $params, string $order): array
{
    $sql = 'SELECT v.delivery_id, v.receipt_no, v.customer_id, v.delivery_date, v.status,
                   v.total_amount, v.total_paid, v.balance,
                   c.name AS customer_name, c.customer_type, d.order_type,
                   dd.po_number, dd.po_date, bd.bid_ref_no, bd.delivery_deadline
            FROM v_delivery_balance v
            JOIN delivery d ON d.delivery_id = v.delivery_id
            JOIN customer c ON c.customer_id = v.customer_id
            LEFT JOIN direct_delivery dd ON dd.delivery_id = v.delivery_id
            LEFT JOIN bidding_delivery bd ON bd.delivery_id = v.delivery_id';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ' . $order;
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/* ---------- GET: detail or filtered list ---------- */

if ($method === 'GET') {
    if ($id) {
        $rows = delivery_base_query(
            $pdo,
            ['v.delivery_id = ?'],
            [$id],
            ''
        );
        if ($rows === []) {
            json_err('Delivery not found', 404);
        }
        $row = $rows[0];
        $order_type = (string)$row['order_type'];
        $row['subtype'] = $order_type === 'DIRECT'
            ? ['po_number' => $row['po_number'], 'po_date' => $row['po_date']]
            : ['bid_ref_no' => $row['bid_ref_no'], 'delivery_deadline' => $row['delivery_deadline']];

        $stItems = $pdo->prepare(
            'SELECT di.item_id, i.item_code, i.name, i.unit, di.quantity, di.unit_price,
                    di.quantity * di.unit_price AS line_total
             FROM delivery_item di
             JOIN item i ON i.item_id = di.item_id
             WHERE di.delivery_id = ?
             ORDER BY di.item_id'
        );
        $stItems->execute([$id]);
        $row['items'] = $stItems->fetchAll();

        $stPay = $pdo->prepare(
            'SELECT payment_id, delivery_id, payment_date, amount, method, reference_no, remarks
             FROM payment WHERE delivery_id = ?
             ORDER BY payment_date, payment_id'
        );
        $stPay->execute([$id]);
        $row['payments'] = $stPay->fetchAll();

        json_ok($row);
    }

    // ---- list with filters ----
    $where = [];
    $params = [];
    $status = strtoupper(str_param($_GET, 'status'));
    if ($status !== '') {
        if (!in_array($status, ['DELIVERED', 'CANCELLED'], true)) {
            json_err('Invalid status', 400);
        }
        $where[] = 'v.status = ?';
        $params[] = $status;
    }
    $order_type = strtoupper(str_param($_GET, 'order_type'));
    if ($order_type !== '') {
        if (!in_array($order_type, ['DIRECT', 'BIDDING'], true)) {
            json_err('Invalid order_type', 400);
        }
        $where[] = 'd.order_type = ?';
        $params[] = $order_type;
    }
    $cid = int_param($_GET, 'customer_id');
    if ($cid > 0) {
        $where[] = 'v.customer_id = ?';
        $params[] = $cid;
    }
    $from = str_param($_GET, 'from');
    if ($from !== '') {
        if (!valid_date($from)) {
            json_err('from must be YYYY-MM-DD', 400);
        }
        $where[] = 'v.delivery_date >= ?';
        $params[] = $from;
    }
    $to = str_param($_GET, 'to');
    if ($to !== '') {
        if (!valid_date($to)) {
            json_err('to must be YYYY-MM-DD', 400);
        }
        $where[] = 'v.delivery_date <= ?';
        $params[] = $to;
    }
    if (int_param($_GET, 'unpaid') === 1) {
        $where[] = 'v.balance > 0';
        $where[] = "v.status = 'DELIVERED'";
    }

    $rows = delivery_base_query(
        $pdo,
        $where,
        $params,
        'ORDER BY v.delivery_date DESC, v.delivery_id DESC'
    );
    json_ok($rows);
}

/* ---------- POST: create delivery (single transaction) ---------- */

if ($method === 'POST') {
    $b = body();

    $customer_id = (int)($b['customer_id'] ?? 0);
    $delivery_date = str_param($b, 'delivery_date');
    $order_type = strtoupper(str_param($b, 'order_type'));

    if ($customer_id <= 0) {
        json_err('customer_id is required', 400);
    }
    if (!valid_date($delivery_date)) {
        json_err('delivery_date must be YYYY-MM-DD', 400);
    }
    if (!in_array($order_type, ['DIRECT', 'BIDDING'], true)) {
        json_err('order_type must be DIRECT or BIDDING', 400);
    }

    $po_number = str_param($b, 'po_number');
    $po_date = str_param($b, 'po_date');
    $bid_ref_no = str_param($b, 'bid_ref_no');
    $delivery_deadline = str_param($b, 'delivery_deadline');

    if ($order_type === 'DIRECT') {
        if ($po_number === '') {
            json_err('po_number is required for DIRECT orders', 400);
        }
        if (!valid_date($po_date)) {
            json_err('po_date must be YYYY-MM-DD for DIRECT orders', 400);
        }
    } else {
        if ($bid_ref_no === '') {
            json_err('bid_ref_no is required for BIDDING orders', 400);
        }
        if (!valid_date($delivery_deadline)) {
            json_err('delivery_deadline must be YYYY-MM-DD for BIDDING orders', 400);
        }
    }

    $items = $b['items'] ?? null;
    if (!is_array($items) || count($items) === 0) {
        json_err('At least one item is required', 400);
    }

    $lines = [];
    $seen = [];
    foreach (array_values($items) as $r) {
        if (!is_array($r)) {
            json_err('items[] entries must be objects', 400);
        }
        $item_id = (int)($r['item_id'] ?? 0);
        $qtyRaw = $r['quantity'] ?? null;
        $priceRaw = $r['unit_price'] ?? null;
        if ($item_id <= 0) {
            json_err('items[].item_id is required', 400);
        }
        if (!is_numeric($qtyRaw) || preg_match('/^\d+$/', (string)$qtyRaw) !== 1 || (int)$qtyRaw < 1) {
            json_err('items[].quantity must be a positive integer', 400);
        }
        if (!is_numeric($priceRaw) || (float)$priceRaw < 0) {
            json_err('items[].unit_price must be a number >= 0', 400);
        }
        if (isset($seen[$item_id])) {
            json_err('Duplicate item_id in items[]: ' . $item_id, 400);
        }
        $seen[$item_id] = true;
        $lines[] = [$item_id, (int)$qtyRaw, (float)$priceRaw];
    }

    $stCust = $pdo->prepare('SELECT 1 FROM customer WHERE customer_id = ?');
    $stCust->execute([$customer_id]);
    if ($stCust->fetchColumn() === false) {
        json_err('Customer not found', 404);
    }

    $custom_receipt = str_param($b, 'receipt_no');
    $delivery_id = 0;

    try {
        $pdo->beginTransaction();

        $insDelivery = $pdo->prepare(
            "INSERT INTO delivery (receipt_no, customer_id, delivery_date, order_type, status)
             VALUES (?, ?, ?, ?, 'DELIVERED')"
        );

        if ($custom_receipt !== '') {
            // caller supplied: rely on UNIQUE constraint (23000 -> 409 via handler)
            $insDelivery->execute([$custom_receipt, $customer_id, $delivery_date, $order_type]);
            $receipt_no = $custom_receipt;
        } else {
            // DR-{Y-m}-{seq:4}, seq = deliveries in that year + 1
            $year = substr($delivery_date, 0, 4);
            $stSeq = $pdo->prepare("SELECT COUNT(*) FROM delivery WHERE receipt_no LIKE ?");
            $stSeq->execute(['DR-' . $year . '-%']);
            $seq = (int)$stSeq->fetchColumn() + 1;

            $receipt_no = '';
            $attempts = 0;
            while (true) {
                $receipt_no = sprintf('DR-%s-%04d', $year, $seq);
                try {
                    $insDelivery->execute([$receipt_no, $customer_id, $delivery_date, $order_type]);
                    break;
                } catch (PDOException $e) {
                    if ($e->getCode() === '23000' && $attempts < 5) {
                        $attempts++;
                        $seq++;
                        continue;
                    }
                    throw $e;
                }
            }
        }

        $delivery_id = (int)$pdo->lastInsertId();

        // subtype row (order_type consistency validated above)
        if ($order_type === 'DIRECT') {
            $pdo->prepare('INSERT INTO direct_delivery (delivery_id, po_number, po_date) VALUES (?, ?, ?)')
                ->execute([$delivery_id, $po_number, $po_date]);
        } else {
            $pdo->prepare('INSERT INTO bidding_delivery (delivery_id, bid_ref_no, delivery_deadline) VALUES (?, ?, ?)')
                ->execute([$delivery_id, $bid_ref_no, $delivery_deadline]);
        }

        // line items — trg_di_stock_check SIGNALs 45000 on insufficient stock
        $insItem = $pdo->prepare(
            'INSERT INTO delivery_item (delivery_id, item_id, quantity, unit_price) VALUES (?, ?, ?, ?)'
        );
        foreach ($lines as $line) {
            $insItem->execute([$delivery_id, $line[0], $line[1], $line[2]]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    json_ok(['delivery_id' => $delivery_id, 'receipt_no' => $receipt_no], 201);
}

/* ---------- PUT: cancel (only while unpaid) ---------- */

if ($method === 'PUT') {
    if (!$id) {
        json_err('id is required', 400);
    }
    $b = body();
    $status = strtoupper(str_param($b, 'status'));
    if ($status !== 'CANCELLED') {
        json_err('Only status CANCELLED is allowed', 400);
    }
    $stExists = $pdo->prepare('SELECT 1 FROM delivery WHERE delivery_id = ?');
    $stExists->execute([$id]);
    if ($stExists->fetchColumn() === false) {
        json_err('Delivery not found', 404);
    }
    $stPaid = $pdo->prepare('SELECT COUNT(*) FROM payment WHERE delivery_id = ?');
    $stPaid->execute([$id]);
    if ((int)$stPaid->fetchColumn() > 0) {
        json_err('Delivery has payments and cannot be cancelled', 409);
    }
    $pdo->prepare("UPDATE delivery SET status = 'CANCELLED' WHERE delivery_id = ?")->execute([$id]);
    json_ok(['delivery_id' => $id, 'status' => 'CANCELLED']);
}

/* ---------- DELETE (only while unpaid) ---------- */

if ($method === 'DELETE') {
    if (!$id) {
        json_err('id is required', 400);
    }
    $stExists = $pdo->prepare('SELECT 1 FROM delivery WHERE delivery_id = ?');
    $stExists->execute([$id]);
    if ($stExists->fetchColumn() === false) {
        json_err('Delivery not found', 404);
    }
    $stPaid = $pdo->prepare('SELECT COUNT(*) FROM payment WHERE delivery_id = ?');
    $stPaid->execute([$id]);
    if ((int)$stPaid->fetchColumn() > 0) {
        json_err('Delivery has payments and cannot be deleted', 409);
    }
    $pdo->prepare('DELETE FROM delivery WHERE delivery_id = ?')->execute([$id]);
    json_ok(['ok' => true]);
}

json_err('Method not allowed', 405);
