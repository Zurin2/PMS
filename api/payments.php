<?php
declare(strict_types=1);
// =============================================================
// PMS Inventory Tracking & Delivery Monitoring System
// File: api/payments.php
// Description: Payment records endpoint. POST validates amount
// against v_delivery_balance (trg_payment_no_overpay = backstop).
// =============================================================

require_once __DIR__ . '/_bootstrap.php';
require_login();

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

function valid_pdate(string $d): bool
{
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
}

if ($method === 'GET') {
    $where = [];
    $params = [];

    $did = int_param($_GET, 'delivery_id');
    if ($did > 0) {
        $where[] = 'p.delivery_id = ?';
        $params[] = $did;
    }
    $cid = int_param($_GET, 'customer_id');
    if ($cid > 0) {
        $where[] = 'd.customer_id = ?';
        $params[] = $cid;
    }
    $from = str_param($_GET, 'from');
    if ($from !== '') {
        if (!valid_pdate($from)) {
            json_err('from must be YYYY-MM-DD', 400);
        }
        $where[] = 'p.payment_date >= ?';
        $params[] = $from;
    }
    $to = str_param($_GET, 'to');
    if ($to !== '') {
        if (!valid_pdate($to)) {
            json_err('to must be YYYY-MM-DD', 400);
        }
        $where[] = 'p.payment_date <= ?';
        $params[] = $to;
    }

    $sql = 'SELECT p.payment_id, p.delivery_id, d.receipt_no, d.customer_id, c.name AS customer_name,
                   p.payment_date, p.amount, p.method, p.reference_no, p.remarks
            FROM payment p
            JOIN delivery d ON d.delivery_id = p.delivery_id
            JOIN customer c ON c.customer_id = d.customer_id';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY p.payment_date DESC, p.payment_id DESC';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    json_ok($st->fetchAll());
}

if ($method === 'POST') {
    $b = body();

    $delivery_id = (int)($b['delivery_id'] ?? 0);
    $amountRaw = $b['amount'] ?? null;
    $payment_date = str_param($b, 'payment_date');
    $method_pay = strtoupper(str_param($b, 'method', 'CASH'));
    $reference_no = str_param($b, 'reference_no');
    $remarks = str_param($b, 'remarks');

    if ($delivery_id <= 0) {
        json_err('delivery_id is required', 400);
    }
    if (!is_numeric($amountRaw) || (float)$amountRaw <= 0) {
        json_err('amount must be a number > 0', 400);
    }
    if (!valid_pdate($payment_date)) {
        json_err('payment_date must be YYYY-MM-DD', 400);
    }
    if (!in_array($method_pay, ['CASH', 'CHECK', 'BANK TRANSFER', 'OTHER'], true)) {
        json_err('method must be CASH, CHECK, BANK TRANSFER or OTHER', 400);
    }
    $amount = (float)$amountRaw;

    $st = $pdo->prepare(
        'SELECT status, balance FROM v_delivery_balance WHERE delivery_id = ?'
    );
    $st->execute([$delivery_id]);
    $del = $st->fetch();
    if ($del === false) {
        json_err('Delivery not found', 404);
    }
    if ($del['status'] !== 'DELIVERED') {
        json_err('Delivery is not DELIVERED', 409);
    }
    if ($amount > (float)$del['balance']) {
        json_err('Payment exceeds balance', 409);
    }

    $ins = $pdo->prepare(
        'INSERT INTO payment (delivery_id, payment_date, amount, method, reference_no, remarks)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $ins->execute([$delivery_id, $payment_date, $amount, $method_pay, $reference_no ?: null, $remarks ?: null]);

    json_ok(['payment_id' => (int)$pdo->lastInsertId()], 201);
}

json_err('Method not allowed', 405);
