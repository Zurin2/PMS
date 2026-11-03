<?php
// =============================================================
// PMS Inventory Tracking & Delivery Monitoring System
// File: api/payments.php
// Description: Payment endpoint — record payments against deliveries
//              Trigger trg_payment_no_overpay prevents overpayment
// =============================================================

require __DIR__ . '/_bootstrap.php';
require_login();

$pdo = db();
$id  = (int) ($_GET['id'] ?? 0);

try {
    switch ($_SERVER['REQUEST_METHOD']) {

        case 'GET':
            if ($id) {
                $st = $pdo->prepare('SELECT * FROM payment WHERE payment_id = ?');
                $st->execute([$id]);
                $row = $st->fetch();
                $row ? respond(200, $row) : respond(404, ['error' => 'Payment not found']);
            }
            $did = (int) ($_GET['delivery_id'] ?? 0);
            if ($did) {
                respond(200, $pdo->prepare('SELECT * FROM payment WHERE delivery_id = ? ORDER BY payment_date')
                    ->execute([$did]) ? $pdo->query("SELECT * FROM payment WHERE delivery_id = $did ORDER BY payment_date")->fetchAll() : []);
            }
            respond(200, $pdo->query('SELECT * FROM payment ORDER BY payment_date DESC')->fetchAll());

        case 'POST':
            $b = input();
            need($b, ['delivery_id', 'payment_date', 'amount', 'method']);
            $st = $pdo->prepare(
                'INSERT INTO payment (delivery_id, payment_date, amount, method, reference_no, remarks)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $st->execute([
                (int) $b['delivery_id'],
                $b['payment_date'],
                $b['amount'],
                $b['method'],
                $b['reference_no'] ?? null,
                $b['remarks'] ?? null,
            ]);
            respond(201, ['payment_id' => (int) $pdo->lastInsertId()]);

        default:
            respond(405, ['error' => 'Method not allowed']);
    }
} catch (PDOException $e) {
    fail($e);
}
