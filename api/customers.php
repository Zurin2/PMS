<?php
// =============================================================
// PMS Inventory Tracking & Delivery Monitoring System
// File: api/customers.php
// Description: CRUD endpoint for customers (SCHOOL and OFFICE subtypes)
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
                    'SELECT c.*,
                            s.school_level, s.head_name,
                            o.office_type, o.contact_person
                     FROM customer c
                     LEFT JOIN school s ON s.customer_id = c.customer_id
                     LEFT JOIN office o ON o.customer_id = c.customer_id
                     WHERE c.customer_id = ?'
                );
                $st->execute([$id]);
                $row = $st->fetch();
                if (!$row) respond(404, ['error' => 'Customer not found']);

                // Attach contacts
                $cc = $pdo->prepare('SELECT contact_type, contact_value FROM customer_contact WHERE customer_id = ?');
                $cc->execute([$id]);
                $row['contacts'] = $cc->fetchAll();
                respond(200, $row);
            }
            respond(200, $pdo->query(
                'SELECT c.*, s.school_level, o.office_type
                 FROM customer c
                 LEFT JOIN school s ON s.customer_id = c.customer_id
                 LEFT JOIN office o ON o.customer_id = c.customer_id
                 ORDER BY c.name'
            )->fetchAll());

        case 'POST':
            $b = input();
            need($b, ['name', 'address', 'customer_type']);

            $pdo->beginTransaction();
            try {
                $pdo->prepare(
                    'INSERT INTO customer (name, address, customer_type) VALUES (?, ?, ?)'
                )->execute([$b['name'], $b['address'], $b['customer_type']]);
                $cid = (int) $pdo->lastInsertId();

                if ($b['customer_type'] === 'SCHOOL') {
                    need($b, ['school_level']);
                    $pdo->prepare(
                        'INSERT INTO school (customer_id, school_level, head_name) VALUES (?, ?, ?)'
                    )->execute([$cid, $b['school_level'], $b['head_name'] ?? null]);
                } elseif ($b['customer_type'] === 'OFFICE') {
                    need($b, ['office_type']);
                    $pdo->prepare(
                        'INSERT INTO office (customer_id, office_type, contact_person) VALUES (?, ?, ?)'
                    )->execute([$cid, $b['office_type'], $b['contact_person'] ?? null]);
                }

                $pdo->commit();
                respond(201, ['customer_id' => $cid]);
            } catch (PDOException $e) {
                $pdo->rollBack();
                fail($e);
            }

        case 'DELETE':
            if (!$id) respond(400, ['error' => 'id required']);
            $pdo->prepare('DELETE FROM customer WHERE customer_id = ?')->execute([$id]);
            respond(200, ['deleted' => true]);

        default:
            respond(405, ['error' => 'Method not allowed']);
    }
} catch (PDOException $e) {
    fail($e);
}
