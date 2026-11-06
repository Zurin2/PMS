<?php
declare(strict_types=1);
// =============================================================
// PMS Inventory Tracking & Delivery Monitoring System
// File: api/receipt.php
// Description: Printable A4 delivery receipt (HTML, NOT JSON).
// Session required first (401 JSON). Then all DB reads happen
// BEFORE HTML output; 404 is JSON. Rendered page auto-prints.
// =============================================================

require_once __DIR__ . '/_bootstrap.php';

/* Auth check FIRST — 401 JSON if no session (bootstrap already sent JSON CT). */
require_login();

$pdo = db();
$id = int_param($_GET, 'id');
if ($id <= 0) {
    json_err('id is required', 400);
}

/* ---------- All data collection + 404 handling BEFORE any HTML ---------- */

$st = $pdo->prepare(
    'SELECT d.delivery_id, d.receipt_no, d.delivery_date, d.order_type, d.status,
            v.total_amount, v.total_paid, v.balance,
            c.customer_id, c.name AS customer_name, c.address, c.customer_type,
            s.school_level, s.head_name, o.office_type, o.contact_person
     FROM delivery d
     JOIN v_delivery_balance v ON v.delivery_id = d.delivery_id
     JOIN customer c ON c.customer_id = d.customer_id
     LEFT JOIN school s ON s.customer_id = d.customer_id
     LEFT JOIN office o ON o.customer_id = d.customer_id
     WHERE d.delivery_id = ?'
);
$st->execute([$id]);
$d = $st->fetch();
if ($d === false) {
    json_err('Delivery not found', 404);
}

$stCont = $pdo->prepare(
    'SELECT contact_type, contact_value FROM customer_contact WHERE customer_id = ?
     ORDER BY contact_type, contact_value'
);
$stCont->execute([(int)$d['customer_id']]);
$contacts = $stCont->fetchAll();

$stItems = $pdo->prepare(
    'SELECT di.quantity, i.unit, i.name, i.item_code, di.unit_price,
            di.quantity * di.unit_price AS line_total
     FROM delivery_item di
     JOIN item i ON i.item_id = di.item_id
     WHERE di.delivery_id = ?
     ORDER BY i.name'
);
$stItems->execute([$id]);
$items = $stItems->fetchAll();

$stPay = $pdo->prepare(
    'SELECT payment_date, method, reference_no, amount
     FROM payment WHERE delivery_id = ?
     ORDER BY payment_date, payment_id'
);
$stPay->execute([$id]);
$payments = $stPay->fetchAll();

/* ---------- Rendering helpers ---------- */

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function money(float $n): string
{
    return number_format($n, 2);
}

$headerLabel = $d['order_type'] === 'DIRECT'
    ? 'PO No. ' . (string)$d['po_number'] . '  |  PO Date ' . (string)$d['po_date']
    : 'Bid Ref. No. ' . (string)$d['bid_ref_no'] . '  |  Delivery Deadline ' . (string)$d['delivery_deadline'];

$customerSub = $d['customer_type'] === 'SCHOOL'
    ? 'School Level: ' . str_replace('_', ' ', (string)($d['school_level'] ?? ''))
        . (($d['head_name'] ?? '') !== '' ? '  |  Head: ' . (string)$d['head_name'] : '')
    : 'Office Type: ' . (string)($d['office_type'] ?? '')
        . (($d['contact_person'] ?? '') !== '' ? '  |  Contact Person: ' . (string)$d['contact_person'] : '');

$total = (float)$d['total_amount'];
$paid = (float)$d['total_paid'];
$balance = (float)$d['balance'];
$cancelled = $d['status'] !== 'DELIVERED';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Delivery Receipt <?= e((string)$d['receipt_no']) ?></title>
<style>
  @page { size: A4; margin: 15mm; }
  * { box-sizing: border-box; }
  body {
    font-family: Arial, Helvetica, sans-serif;
    color: #1a1a1a;
    margin: 0;
    background: #f2f2f2;
    font-size: 12px;
  }
  .sheet {
    width: 180mm;
    margin: 10mm auto;
    background: #ffffff;
    padding: 10mm 12mm;
    box-shadow: 0 1px 6px rgba(0,0,0,.25);
  }
  .r-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    border-bottom: 3px double #1a1a1a;
    padding-bottom: 8px;
  }
  .r-company { font-size: 22px; font-weight: bold; letter-spacing: 1px; }
  .r-tagline { font-size: 11px; color: #444; margin-top: 2px; }
  .r-meta { text-align: right; font-size: 12px; line-height: 1.6; }
  .r-meta .r-no { font-size: 15px; font-weight: bold; }
  .cancelled-banner {
    margin-top: 8px;
    border: 2px solid #b00020;
    color: #b00020;
    font-weight: bold;
    text-align: center;
    padding: 6px;
    letter-spacing: 2px;
  }
  .r-customer { margin-top: 14px; line-height: 1.6; }
  .r-customer .c-name { font-size: 14px; font-weight: bold; }
  .r-sub { color: #444; margin-top: 2px; }
  table.items {
    width: 100%;
    border-collapse: collapse;
    margin-top: 14px;
  }
  table.items th, table.items td {
    border: 1px solid #999;
    padding: 5px 7px;
  }
  table.items th {
    background: #eaeaea;
    text-align: left;
    font-size: 11px;
  }
  table.items td.num, table.items th.num { text-align: right; }
  table.items td.center, table.items th.center { text-align: center; }
  table.items .i-code { color: #666; font-size: 10px; display: block; }
  .totals { margin-top: 12px; margin-left: auto; width: 70mm; }
  .totals .t-row {
    display: flex;
    justify-content: space-between;
    padding: 3px 7px;
  }
  .totals .t-row.grand {
    border-top: 2px solid #1a1a1a;
    font-weight: bold;
    margin-top: 3px;
  }
  .totals .t-row.balance { font-weight: bold; color: #b00020; }
  .r-payments { margin-top: 16px; }
  .r-payments h4 { margin: 0 0 5px; font-size: 12px; }
  table.pays { width: 100%; border-collapse: collapse; font-size: 11px; }
  table.pays th, table.pays td { border: 1px solid #bbb; padding: 4px 6px; }
  table.pays th { background: #f4f4f4; text-align: left; }
  table.pays td.num { text-align: right; }
  .muted { color: #666; font-style: italic; }
  .r-footer {
    margin-top: 26px;
    display: flex;
    justify-content: space-between;
    font-size: 11px;
  }
  .sig-line { border-top: 1px solid #555; width: 55mm; text-align: center; padding-top: 2px; }
  .no-print { text-align: center; margin: 20px 0; }
  .btn-back {
    display: inline-block;
    padding: 9px 22px;
    background: #16355c;
    color: #fff;
    text-decoration: none;
    border-radius: 4px;
    font-size: 14px;
  }
  .btn-back:hover { background: #0f2540; }
  @media print {
    body { background: #fff; }
    .sheet { box-shadow: none; margin: 0; width: auto; }
    .no-print { display: none; }
  }
</style>
</head>
<body>
<div class="sheet">
  <div class="r-head">
    <div>
      <div class="r-company">PMS TRADING</div>
      <div class="r-tagline">School &amp; Office Supplies Trading</div>
    </div>
    <div class="r-meta">
      <div class="r-no">DELIVERY RECEIPT</div>
      <div><?= e((string)$d['receipt_no']) ?></div>
      <div>Date: <?= e((string)$d['delivery_date']) ?></div>
      <div><?= e($headerLabel) ?></div>
    </div>
  </div>

<?php if ($cancelled): ?>
  <div class="cancelled-banner">STATUS: <?= e((string)$d['status']) ?> &mdash; NOT FOR COLLECTION</div>
<?php endif; ?>

  <div class="r-customer">
    <div class="c-name"><?= e((string)$d['customer_name']) ?></div>
    <div><?= e((string)$d['address']) ?></div>
    <div class="r-sub"><?= e($customerSub) ?></div>
<?php foreach ($contacts as $ct): ?>
    <div class="r-sub"><?= e((string)$ct['contact_type']) ?>: <?= e((string)$ct['contact_value']) ?></div>
<?php endforeach; ?>
  </div>

  <table class="items">
    <thead>
      <tr>
        <th class="center">Qty</th>
        <th class="center">Unit</th>
        <th>Description</th>
        <th class="num">Unit Price</th>
        <th class="num">Line Total</th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($items as $it): ?>
      <tr>
        <td class="center"><?= e((string)$it['quantity']) ?></td>
        <td><?= e((string)$it['unit']) ?></td>
        <td><?= e((string)$it['name']) ?><span class="i-code"><?= e((string)$it['item_code']) ?></span></td>
        <td class="num"><?= money((float)$it['unit_price']) ?></td>
        <td class="num"><?= money((float)$it['line_total']) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>

  <div class="totals">
    <div class="t-row grand"><span>TOTAL</span><span>&#8369; <?= money($total) ?></span></div>
    <div class="t-row"><span>Total Paid</span><span>&#8369; <?= money($paid) ?></span></div>
    <div class="t-row balance"><span>Balance Due</span><span>&#8369; <?= money($balance) ?></span></div>
  </div>

  <div class="r-payments">
    <h4>Payments Applied</h4>
<?php if (count($payments) === 0): ?>
    <div class="muted">No payments applied yet.</div>
<?php else: ?>
    <table class="pays">
      <thead>
        <tr><th>Date</th><th>Method</th><th>Reference No.</th><th class="num">Amount (&#8369;)</th></tr>
      </thead>
      <tbody>
<?php foreach ($payments as $p): ?>
        <tr>
          <td><?= e((string)$p['payment_date']) ?></td>
          <td><?= e((string)$p['method']) ?></td>
          <td><?= e((string)($p['reference_no'] ?? '')) ?></td>
          <td class="num"><?= money((float)$p['amount']) ?></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
<?php endif; ?>
  </div>

  <div class="r-footer">
    <div>
      <div class="sig-line">Prepared by</div>
      <div><?= e((string)($_SESSION['username'] ?? '')) ?></div>
    </div>
    <div>
      <div class="sig-line">Received by (signature over printed name)</div>
    </div>
  </div>
</div>

<div class="no-print">
  <a class="btn-back" href="#" onclick="history.back(); return false;">&larr; Back / Done</a>
</div>

<script>
window.addEventListener('load', function () { window.print(); });
</script>
</body>
</html>
