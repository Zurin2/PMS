<?php
// =============================================================
// PMS Inventory Tracking & Delivery Monitoring System
// File: scripts/verify.php
// Description: Standalone system diagnostic / QA verification
// Access: http://localhost/PMS/scripts/verify.php
//
// ⚠ WARNING: REMOVE or PROTECT this file before any production
//   deployment. It exposes schema and seed data details.
// =============================================================

require __DIR__ . '/../config/db.php';

$results = [];
$pdo     = null;

// ─────────────────────────────────────────────────────────────
// Check 1: DB Connection
// ─────────────────────────────────────────────────────────────
try {
    $pdo = db();
    $results[] = [
        'check'  => 'DB Connection',
        'status' => 'PASS',
        'detail' => 'PDO connected to pms_inventory (127.0.0.1) — utf8mb4',
    ];
} catch (Exception $e) {
    $results[] = [
        'check'  => 'DB Connection',
        'status' => 'FAIL',
        'detail' => $e->getMessage(),
    ];
}

// All remaining checks depend on a live connection.
if ($pdo !== null) {

    // ─────────────────────────────────────────────────────────
    // Check 2: Tables exist (expect 15)
    // ─────────────────────────────────────────────────────────
    try {
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

        $expected = [
            'category', 'item', 'item_price_history', 'stock_in', 'stock_adjustment',
            'customer', 'school', 'office', 'customer_contact',
            'delivery', 'direct_delivery', 'bidding_delivery', 'delivery_item',
            'payment', 'user_account',
        ];

        $missing = array_diff($expected, $tables);
        $extra   = array_diff($tables, $expected);

        if (empty($missing)) {
            $detail = 'All 15 tables present: ' . implode(', ', $tables);
            if (!empty($extra)) {
                $detail .= ' | Extra (non-spec) tables: ' . implode(', ', $extra);
            }
            $results[] = ['check' => 'Tables (15)', 'status' => 'PASS', 'detail' => $detail];
        } else {
            $results[] = [
                'check'  => 'Tables (15)',
                'status' => 'FAIL',
                'detail' => 'Missing: ' . implode(', ', $missing)
                          . (count($tables) ? ' | Found: ' . implode(', ', $tables) : ' | No tables found'),
            ];
        }
    } catch (Exception $e) {
        $results[] = ['check' => 'Tables (15)', 'status' => 'FAIL', 'detail' => $e->getMessage()];
    }

    // ─────────────────────────────────────────────────────────
    // Check 3: Views exist
    // ─────────────────────────────────────────────────────────
    try {
        $views = $pdo->query(
            "SELECT TABLE_NAME FROM information_schema.VIEWS
             WHERE TABLE_SCHEMA = DATABASE()"
        )->fetchAll(PDO::FETCH_COLUMN);

        $expectedViews = ['v_stock_movement', 'v_item_stock', 'v_delivery_balance'];
        $missingViews  = array_diff($expectedViews, $views);

        if (empty($missingViews)) {
            $results[] = [
                'check'  => 'Views (3)',
                'status' => 'PASS',
                'detail' => 'Found: ' . implode(', ', $views),
            ];
        } else {
            $results[] = [
                'check'  => 'Views (3)',
                'status' => 'FAIL',
                'detail' => 'Missing views: ' . implode(', ', $missingViews)
                          . (count($views) ? ' | Found: ' . implode(', ', $views) : ' | No views found'),
            ];
        }
    } catch (Exception $e) {
        $results[] = ['check' => 'Views (3)', 'status' => 'FAIL', 'detail' => $e->getMessage()];
    }

    // ─────────────────────────────────────────────────────────
    // Check 4: Triggers exist
    // ─────────────────────────────────────────────────────────
    try {
        $triggers = $pdo->query(
            "SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
             WHERE TRIGGER_SCHEMA = DATABASE()"
        )->fetchAll(PDO::FETCH_COLUMN);

        if (count($triggers) > 0) {
            $results[] = [
                'check'  => 'Triggers',
                'status' => 'PASS',
                'detail' => count($triggers) . ' trigger(s): ' . implode(', ', $triggers),
            ];
        } else {
            $results[] = [
                'check'  => 'Triggers',
                'status' => 'WARN',
                'detail' => 'No triggers found — run sql/03_triggers.sql',
            ];
        }
    } catch (Exception $e) {
        $results[] = ['check' => 'Triggers', 'status' => 'FAIL', 'detail' => $e->getMessage()];
    }

    // ─────────────────────────────────────────────────────────
    // Check 5: User account seeded
    // ─────────────────────────────────────────────────────────
    try {
        $userCount = (int) $pdo->query("SELECT COUNT(*) FROM user_account")->fetchColumn();
        $ownerRow  = $pdo->query(
            "SELECT username, full_name, password_hash FROM user_account WHERE username = 'owner' LIMIT 1"
        )->fetch();

        if ($ownerRow) {
            $hashOk = ($ownerRow['password_hash'] !== 'REPLACE WITH THE HASHED PASSWORD');
            $results[] = [
                'check'  => 'User Account (owner)',
                'status' => $hashOk ? 'PASS' : 'WARN',
                'detail' => $hashOk
                    ? "Owner account seeded — full_name: \"{$ownerRow['full_name']}\" | Total accounts: {$userCount}"
                    : 'Owner account found but password_hash is still the placeholder — run: php -r "echo password_hash(\'changeme\', PASSWORD_DEFAULT);" and update sql/04_seed.sql',
            ];
        } else {
            $results[] = [
                'check'  => 'User Account (owner)',
                'status' => 'FAIL',
                'detail' => "No 'owner' account found — run sql/04_seed.sql (total rows: {$userCount})",
            ];
        }
    } catch (Exception $e) {
        $results[] = ['check' => 'User Account (owner)', 'status' => 'FAIL', 'detail' => $e->getMessage()];
    }

    // ─────────────────────────────────────────────────────────
    // Check 6: Categories seeded (expect 3)
    // ─────────────────────────────────────────────────────────
    try {
        $cats = $pdo->query("SELECT name FROM category ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        $expectedCats = ['Janitorial Supplies', 'Office Supplies', 'School Supplies'];
        $missingCats  = array_diff($expectedCats, $cats);

        if (empty($missingCats)) {
            $results[] = [
                'check'  => 'Categories (3)',
                'status' => 'PASS',
                'detail' => 'Found: ' . implode(', ', $cats),
            ];
        } else {
            $results[] = [
                'check'  => 'Categories (3)',
                'status' => 'FAIL',
                'detail' => 'Missing: ' . implode(', ', $missingCats)
                          . (count($cats) ? ' | Found: ' . implode(', ', $cats) : ' | category table is empty'),
            ];
        }
    } catch (Exception $e) {
        $results[] = ['check' => 'Categories (3)', 'status' => 'FAIL', 'detail' => $e->getMessage()];
    }

    // ─────────────────────────────────────────────────────────
    // Check 7: v_item_stock view is queryable
    // ─────────────────────────────────────────────────────────
    try {
        $stockRows = (int) $pdo->query("SELECT COUNT(*) FROM v_item_stock")->fetchColumn();
        $results[] = [
            'check'  => 'v_item_stock (queryable)',
            'status' => 'PASS',
            'detail' => "{$stockRows} item row(s) in v_item_stock",
        ];
    } catch (Exception $e) {
        $results[] = [
            'check'  => 'v_item_stock (queryable)',
            'status' => 'FAIL',
            'detail' => $e->getMessage(),
        ];
    }

    // ─────────────────────────────────────────────────────────
    // Check 8: v_delivery_balance view is queryable
    // ─────────────────────────────────────────────────────────
    try {
        $dbRows = (int) $pdo->query("SELECT COUNT(*) FROM v_delivery_balance")->fetchColumn();
        $results[] = [
            'check'  => 'v_delivery_balance (queryable)',
            'status' => 'PASS',
            'detail' => "{$dbRows} delivery row(s) in v_delivery_balance",
        ];
    } catch (Exception $e) {
        $results[] = [
            'check'  => 'v_delivery_balance (queryable)',
            'status' => 'FAIL',
            'detail' => $e->getMessage(),
        ];
    }

    // ─────────────────────────────────────────────────────────
    // Check 9: Auth endpoint reachable (file exists)
    // ─────────────────────────────────────────────────────────
    $apiFiles = [
        '_bootstrap.php', 'auth.php', 'items.php', 'categories.php',
        'stock_in.php', 'stock_card.php', 'deliveries.php', 'customers.php',
        'payments.php', 'adjustments.php', 'reports.php',
    ];
    $missingApi = [];
    foreach ($apiFiles as $f) {
        if (!file_exists(__DIR__ . '/../api/' . $f)) {
            $missingApi[] = $f;
        }
    }
    if (empty($missingApi)) {
        $results[] = [
            'check'  => 'API Files (' . count($apiFiles) . ')',
            'status' => 'PASS',
            'detail' => 'All API endpoint files present in api/',
        ];
    } else {
        $results[] = [
            'check'  => 'API Files (' . count($apiFiles) . ')',
            'status' => 'FAIL',
            'detail' => 'Missing: ' . implode(', ', $missingApi),
        ];
    }

    // ─────────────────────────────────────────────────────────
    // Check 10: HTML pages exist
    // ─────────────────────────────────────────────────────────
    $htmlFiles = [
        'index.html', 'login.html', 'dashboard.html', 'items.html',
        'stock_in.html', 'stock_card.html', 'customers.html',
        'deliveries.html', 'delivery_new.html', 'receipt.html',
        'payments.html', 'count.html', 'reports.html',
    ];
    $missingHtml = [];
    foreach ($htmlFiles as $f) {
        if (!file_exists(__DIR__ . '/../' . $f)) {
            $missingHtml[] = $f;
        }
    }
    if (empty($missingHtml)) {
        $results[] = [
            'check'  => 'HTML Pages (' . count($htmlFiles) . ')',
            'status' => 'PASS',
            'detail' => 'All HTML page files present',
        ];
    } else {
        $results[] = [
            'check'  => 'HTML Pages (' . count($htmlFiles) . ')',
            'status' => 'WARN',
            'detail' => 'Missing: ' . implode(', ', $missingHtml),
        ];
    }

    // ─────────────────────────────────────────────────────────
    // Check 11: SQL migration files exist
    // ─────────────────────────────────────────────────────────
    $sqlFiles = [
        'sql/01_schema.sql', 'sql/02_views.sql', 'sql/03_triggers.sql',
        'sql/04_seed.sql', 'sql/05_reports.sql',
    ];
    $missingSql = [];
    foreach ($sqlFiles as $f) {
        if (!file_exists(__DIR__ . '/../' . $f)) {
            $missingSql[] = $f;
        }
    }
    if (empty($missingSql)) {
        $results[] = [
            'check'  => 'SQL Files (5)',
            'status' => 'PASS',
            'detail' => 'All SQL migration files present in sql/',
        ];
    } else {
        $results[] = [
            'check'  => 'SQL Files (5)',
            'status' => 'FAIL',
            'detail' => 'Missing: ' . implode(', ', $missingSql),
        ];
    }

} // end if ($pdo !== null)

// ─────────────────────────────────────────────────────────────
// Summary counts
// ─────────────────────────────────────────────────────────────
$passCount = count(array_filter($results, fn($r) => $r['status'] === 'PASS'));
$failCount = count(array_filter($results, fn($r) => $r['status'] === 'FAIL'));
$warnCount = count(array_filter($results, fn($r) => $r['status'] === 'WARN'));
$total     = count($results);

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>PMS System Verification</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
            font-size: 14px;
            background: #f1f5f9;
            color: #1e293b;
            padding: 2rem;
            min-height: 100vh;
        }

        header { margin-bottom: 1.5rem; }
        header h1 { font-size: 1.4rem; font-weight: 700; color: #1e293b; margin-bottom: 0.25rem; }
        header p  { font-size: 0.85rem; color: #64748b; }

        .summary-bar {
            display: flex;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }
        .badge {
            padding: 0.4rem 0.9rem;
            border-radius: 20px;
            font-size: 0.82rem;
            font-weight: 700;
            letter-spacing: .03em;
        }
        .badge-pass  { background: #dcfce7; color: #15803d; }
        .badge-fail  { background: #fee2e2; color: #b91c1c; }
        .badge-warn  { background: #fef9c3; color: #92400e; }
        .badge-total { background: #e2e8f0; color: #334155; }

        table {
            border-collapse: collapse;
            width: 100%;
            background: #fff;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 1px 4px rgba(0,0,0,.08);
        }

        thead { background: #1e293b; }
        thead th {
            padding: 11px 16px;
            text-align: left;
            font-size: 0.78rem;
            font-weight: 600;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: .07em;
        }

        tbody td {
            padding: 10px 16px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: top;
            font-size: 0.875rem;
        }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover td { background: #f8fafc; }

        td.check-name { font-weight: 600; white-space: nowrap; color: #334155; width: 240px; }
        td.status     { white-space: nowrap; width: 80px; }
        td.detail     { color: #475569; word-break: break-word; }

        .pass { color: #16a34a; font-weight: 700; }
        .fail { color: #dc2626; font-weight: 700; }
        .warn { color: #d97706; font-weight: 700; }

        footer {
            margin-top: 1.5rem;
            font-size: 0.78rem;
            color: #94a3b8;
            padding: 0.75rem 1rem;
            background: #fff3cd;
            border: 1px solid #fde68a;
            border-radius: 8px;
        }
        footer strong { color: #92400e; }
    </style>
</head>
<body>

<header>
    <h1>🔍 PMS System Verification</h1>
    <p>Run this after loading all SQL files in phpMyAdmin. Access: <code>http://localhost/PMS/scripts/verify.php</code></p>
</header>

<div class="summary-bar">
    <span class="badge badge-total">Total: <?= $total ?></span>
    <span class="badge badge-pass">✓ Pass: <?= $passCount ?></span>
    <?php if ($failCount > 0): ?>
    <span class="badge badge-fail">✗ Fail: <?= $failCount ?></span>
    <?php endif; ?>
    <?php if ($warnCount > 0): ?>
    <span class="badge badge-warn">⚠ Warn: <?= $warnCount ?></span>
    <?php endif; ?>
</div>

<table>
    <thead>
        <tr>
            <th>Check</th>
            <th>Status</th>
            <th>Detail</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($results as $r): ?>
        <tr>
            <td class="check-name"><?= htmlspecialchars($r['check']) ?></td>
            <td class="status <?= strtolower($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></td>
            <td class="detail"><?= htmlspecialchars($r['detail']) ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<footer>
    <strong>⚠ Security Warning:</strong> Remove or restrict access to this file before deploying to production.
    It exposes database schema details and seed data status.
</footer>

</body>
</html>
