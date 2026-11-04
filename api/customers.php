<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_login();

const CUSTOMER_SCHOOL_LEVELS = ['ELEMENTARY', 'JUNIOR_HIGH', 'SENIOR_HIGH', 'COLLEGE', 'OTHER'];
const CUSTOMER_OFFICE_TYPES  = ['GOVERNMENT', 'PRIVATE'];
const CUSTOMER_CONTACT_TYPES = ['MOBILE', 'LANDLINE', 'EMAIL'];

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

/**
 * Validate subtype-specific body fields.
 * Returns [school_level, head_name, office_type, contact_person]; exits with json_err on failure.
 */
function customer_subtype_fields(array $b): array
{
    $type = strtoupper(str_param($b, 'customer_type'));
    if ($type !== 'SCHOOL' && $type !== 'OFFICE') {
        json_err('customer_type must be SCHOOL or OFFICE', 400);
    }

    if ($type === 'SCHOOL') {
        $level = strtoupper(str_param($b, 'school_level'));
        if ($level === '' || !in_array($level, CUSTOMER_SCHOOL_LEVELS, true)) {
            json_err('school_level is required for a SCHOOL customer (' . implode('/', CUSTOMER_SCHOOL_LEVELS) . ')', 400);
        }
        return ['school_level' => $level, 'head_name' => str_param($b, 'head_name') ?: null, 'office_type' => null, 'contact_person' => null];
    }

    $otype = strtoupper(str_param($b, 'office_type'));
    if ($otype === '' || !in_array($otype, CUSTOMER_OFFICE_TYPES, true)) {
        json_err('office_type is required for an OFFICE customer (' . implode('/', CUSTOMER_OFFICE_TYPES) . ')', 400);
    }
    return ['school_level' => null, 'head_name' => null, 'office_type' => $otype, 'contact_person' => str_param($b, 'contact_person') ?: null];
}

/** Validate and normalize contacts array; exits on failure. */
function customer_contacts(array $b): array
{
    $contacts = $b['contacts'] ?? [];
    if (!is_array($contacts)) {
        json_err('contacts must be an array of {contact_type, contact_value}', 400);
    }
    $out = [];
    foreach ($contacts as $c) {
        if (!is_array($c)) {
            json_err('contacts must be an array of {contact_type, contact_value}', 400);
        }
        $ctype = strtoupper(trim((string)($c['contact_type'] ?? '')));
        $cval = trim((string)($c['contact_value'] ?? ''));
        if ($ctype === '' || !in_array($ctype, CUSTOMER_CONTACT_TYPES, true)) {
            json_err('contact_type must be one of ' . implode('/', CUSTOMER_CONTACT_TYPES), 400);
        }
        if ($cval === '') {
            json_err('contact_value is required', 400);
        }
        $out[] = ['contact_type' => $ctype, 'contact_value' => $cval];
    }
    return $out;
}

/** Fetch one customer merged supertype + subtype + contacts, or null. */
function customer_row(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT c.customer_id, c.name, c.address, c.customer_type,
                s.school_level, s.head_name, o.office_type, o.contact_person
           FROM customer c
           LEFT JOIN school s ON s.customer_id = c.customer_id
           LEFT JOIN office o ON o.customer_id = c.customer_id
          WHERE c.customer_id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT contact_type, contact_value FROM customer_contact WHERE customer_id = ? ORDER BY contact_type, contact_value'
    );
    $stmt->execute([$id]);
    $row['contacts'] = array_map(fn($c) => [
        'contact_type'  => $c['contact_type'],
        'contact_value' => $c['contact_value'],
    ], $stmt->fetchAll());

    return $row;
}

/* ---------- GET ---------- */
if ($method === 'GET') {
    $id = int_param($_GET, 'id');

    if ($id > 0) {
        $row = customer_row($pdo, $id);
        if (!$row) {
            json_err('Customer not found', 404);
        }
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(balance), 0) AS outstanding
               FROM v_delivery_balance
              WHERE customer_id = ? AND status = \'DELIVERED\''
        );
        $stmt->execute([$id]);
        $row['outstanding'] = (float)$stmt->fetchColumn();
        json_ok($row);
    }

    $sql = 'SELECT c.customer_id, c.name, c.address, c.customer_type,
                   s.school_level, s.head_name, o.office_type, o.contact_person
              FROM customer c
              LEFT JOIN school s ON s.customer_id = c.customer_id
              LEFT JOIN office o ON o.customer_id = c.customer_id
             WHERE 1=1';
    $args = [];

    $type = strtoupper(str_param($_GET, 'type'));
    if ($type !== '') {
        if ($type !== 'SCHOOL' && $type !== 'OFFICE') {
            json_err('Invalid type', 400);
        }
        $sql .= ' AND c.customer_type = ?';
        $args[] = $type;
    }

    $search = str_param($_GET, 'search');
    if ($search !== '') {
        $sql .= ' AND (c.name LIKE ? OR c.address LIKE ?)';
        $like = '%' . $search . '%';
        $args[] = $like;
        $args[] = $like;
    }

    $sql .= ' ORDER BY c.name ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    $rows = $stmt->fetchAll();

    $allContacts = [];
    foreach ($pdo->query('SELECT customer_id, contact_type, contact_value FROM customer_contact ORDER BY contact_type, contact_value')->fetchAll() as $c) {
        $allContacts[(int)$c['customer_id']][] = [
            'contact_type'  => $c['contact_type'],
            'contact_value' => $c['contact_value'],
        ];
    }

    $out = [];
    foreach ($rows as $r) {
        $cid = (int)$r['customer_id'];
        $r['customer_id'] = $cid;
        $r['contacts'] = $allContacts[$cid] ?? [];
        if ($r['customer_type'] !== 'SCHOOL') {
            unset($r['school_level'], $r['head_name']);
        }
        if ($r['customer_type'] !== 'OFFICE') {
            unset($r['office_type'], $r['contact_person']);
        }
        $out[] = $r;
    }
    json_ok($out);
}

/* ---------- POST ---------- */
if ($method === 'POST') {
    $b = body();
    $name = str_param($b, 'name');
    $address = str_param($b, 'address');
    $type = strtoupper(str_param($b, 'customer_type'));
    if ($name === '' || $address === '') {
        json_err('name and address are required', 400);
    }
    if ($type !== 'SCHOOL' && $type !== 'OFFICE') {
        json_err('customer_type must be SCHOOL or OFFICE', 400);
    }
    $sub = customer_subtype_fields($b);
    $contacts = customer_contacts($b);

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('INSERT INTO customer (name, address, customer_type) VALUES (?, ?, ?)');
        $stmt->execute([$name, $address, $type]);
        $cid = (int)$pdo->lastInsertId();

        if ($type === 'SCHOOL') {
            $stmt = $pdo->prepare('INSERT INTO school (customer_id, school_level, head_name) VALUES (?, ?, ?)');
            $stmt->execute([$cid, $sub['school_level'], $sub['head_name']]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO office (customer_id, office_type, contact_person) VALUES (?, ?, ?)');
            $stmt->execute([$cid, $sub['office_type'], $sub['contact_person']]);
        }

        $stmt = $pdo->prepare('INSERT INTO customer_contact (customer_id, contact_type, contact_value) VALUES (?, ?, ?)');
        foreach ($contacts as $c) {
            $stmt->execute([$cid, $c['contact_type'], $c['contact_value']]);
        }

        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e->getCode() === '23000') {
            json_err('A customer with the same name and address already exists', 409);
        }
        throw $e;
    }

    json_ok(['customer_id' => $cid], 201);
}

/* ---------- PUT ---------- */
if ($method === 'PUT') {
    $id = int_param($_GET, 'id');
    if ($id <= 0) {
        json_err('id is required', 400);
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM customer WHERE customer_id = ?');
    $stmt->execute([$id]);
    if ((int)$stmt->fetchColumn() === 0) {
        json_err('Customer not found', 404);
    }

    $b = body();
    $name = str_param($b, 'name');
    $address = str_param($b, 'address');
    $type = strtoupper(str_param($b, 'customer_type'));
    if ($name === '' || $address === '') {
        json_err('name and address are required', 400);
    }
    if ($type !== 'SCHOOL' && $type !== 'OFFICE') {
        json_err('customer_type must be SCHOOL or OFFICE', 400);
    }
    $sub = customer_subtype_fields($b);
    $contacts = customer_contacts($b);

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('UPDATE customer SET name = ?, address = ?, customer_type = ? WHERE customer_id = ?');
        $stmt->execute([$name, $address, $type, $id]);
        $cid = $id;

        // Replace subtype: clear both, insert the matching one.
        $pdo->prepare('DELETE FROM school WHERE customer_id = ?')->execute([$cid]);
        $pdo->prepare('DELETE FROM office WHERE customer_id = ?')->execute([$cid]);
        if ($type === 'SCHOOL') {
            $stmt = $pdo->prepare('INSERT INTO school (customer_id, school_level, head_name) VALUES (?, ?, ?)');
            $stmt->execute([$cid, $sub['school_level'], $sub['head_name']]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO office (customer_id, office_type, contact_person) VALUES (?, ?, ?)');
            $stmt->execute([$cid, $sub['office_type'], $sub['contact_person']]);
        }

        // Replace contacts.
        $pdo->prepare('DELETE FROM customer_contact WHERE customer_id = ?')->execute([$cid]);
        $stmt = $pdo->prepare('INSERT INTO customer_contact (customer_id, contact_type, contact_value) VALUES (?, ?, ?)');
        foreach ($contacts as $c) {
            $stmt->execute([$cid, $c['contact_type'], $c['contact_value']]);
        }

        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e->getCode() === '23000') {
            json_err('A customer with the same name and address already exists', 409);
        }
        throw $e;
    }

    json_ok(['ok' => true]);
}

/* ---------- DELETE ---------- */
if ($method === 'DELETE') {
    $id = int_param($_GET, 'id');
    if ($id <= 0) {
        json_err('id is required', 400);
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM customer WHERE customer_id = ?');
    $stmt->execute([$id]);
    if ((int)$stmt->fetchColumn() === 0) {
        json_err('Customer not found', 404);
    }
    try {
        $stmt = $pdo->prepare('DELETE FROM customer WHERE customer_id = ?');
        $stmt->execute([$id]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            json_err('Customer has deliveries', 409);
        }
        throw $e;
    }
    json_ok(['ok' => true]);
}

json_err('Method not allowed', 405);
