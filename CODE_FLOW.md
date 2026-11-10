# PMS Trading — The Flow in Code (Process 1 → 6)

This document supplements [`API_SPEC.md`](API_SPEC.md). Where the API guide explains each process in plain English (with the 🎤 presenter scripts), this walkthrough pairs **every numbered process 1 → 6** with the **actual code** that implements it — PHP files, SQL objects, and frontend JS you can open and point at during a defense. Every snippet is copied exactly from the repo, with a `path:lines` reference. Section numbers mirror the API_SPEC, so you can read a §1 here and a §1 there side by side.

---

## 1. Login & Session (the wristband)

When the user signs in, `api/auth.php` looks up the account by username, checks the typed password against the stored hash with PHP's `password_verify`, and — only if it matches — stores `user_id`, `username`, and `full_name` into the PHP session. That session is the wristband: it exists on the server, and the browser carries it automatically. Every protected endpoint starts with `require_login()`, which returns HTTP **401** if the wristband is missing. On the frontend, every page boots through `UI.guard()`, which asks `auth.php?action=me` who the user is; if the session is dead, the page is bounced to `login.html`. If the session expires mid-session, the shared fetch client (`wb`) intercepts any 401 response and redirects on the spot.

```php
    $stmt = db()->prepare(
        'SELECT user_id, username, password_hash, full_name
           FROM user_account
          WHERE username = ?'
    );
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        json_err('Invalid credentials', 401);
    }

    $_SESSION['user_id'] = (int)$user['user_id'];
    $_SESSION['username'] = (string)$user['username'];
    $_SESSION['full_name'] = (string)$user['full_name'];

    json_ok([
        'user_id'   => (int)$user['user_id'],
        'username'  => (string)$user['username'],
        'full_name' => (string)$user['full_name'],
    ]);
```
`api/auth.php:15-35` — the POST login branch

```php
function require_login(): void
{
    if (empty($_SESSION['user_id'])) {
        json_err('Authentication required', 401);
    }
}
```
`api/_bootstrap.php:31-36` — every protected endpoint calls this first

```js
  /* Session guard + owner name rendering */
  async function guard() {
    try {
      const me = await wb.get('auth.php?action=me');
      const who = document.getElementById('who');
      if (who) who.textContent = me.full_name || me.username;
      return me;
    } catch (e) {
      if (e.message !== 'Session expired') console.warn(e);
      location.href = 'login.html';
      throw e;
    }
  }
```
`js/ui.js:153-164` — each page starts with `await UI.guard()`

```js
  _check(res) {
    if (res.status === 401 && !location.pathname.includes('login.html')) {
      location.href = 'login.html';
      throw new Error('Session expired');
    }
    return res;
  },
```
`js/api.js:5-11` — the 401 interceptor inside the shared fetch client

**Proceeds next → 2.** Money in and stock in both start from this check.

---

## 2. Ground rules — shared toolkit

Every endpoint file begins with `require_once __DIR__ . '/_bootstrap.php'`, which loads the database, opens the session, and defines the shared contract: `json_ok()` and `json_err()` are the only two ways an endpoint answers, so every response is either a data payload or `{"error": ...}` with the right HTTP code. Incoming JSON is read with `body()`, the one helper that handles both `POST` JSON bodies and ordinary form posts. When the database complains, the central exception handler maps the error to an honest status: the business-rule SQLSTATE **45000** and integrity SQLSTATE **23000** both become **409** ("conflict"), everything else becomes **500**. And every connection to MariaDB is the same PDO object — a singleton in `config/db.php` that turns on exceptions, real prepared statements, and a pinned collation so joins and unions never crash on mixed collations.

```php
function json_ok($data = null, int $code = 200)
{
    http_response_code($code);
    echo json_encode($data ?? [], JSON_UNESCAPED_UNICODE);
    exit;
}

function json_err(string $message, int $code = 400, array $extra = [])
{
    http_response_code($code);
    echo json_encode(['error' => $message] + $extra, JSON_UNESCAPED_UNICODE);
    exit;
}
```
`api/_bootstrap.php:17-29` — the two-voice response contract

```php
function body(): array
{
    $raw = file_get_contents('php://input');
    $json = json_decode($raw ?: '', true);
    if (is_array($json)) {
        return $json;
    }
    return $_POST;
}
```
`api/_bootstrap.php:40-48` — one input reader for all endpoints

```php
set_exception_handler(function (Throwable $e): void {
    $msg = $e->getMessage();
    $sqlstate = ($e instanceof PDOException) ? substr($msg, 9, 5) : '';
    $code = match (true) {
        $sqlstate === '45000' => 409,
        $sqlstate === '23000' => 409,
        default               => 500,
    };
    json_err($msg, $code);
});
```
`api/_bootstrap.php:68-77` — SQLSTATE 45000/23000 → HTTP 409, else 500

```php
    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $dsn = 'mysql:host=127.0.0.1;port=3306;dbname=pms_inventory;charset=utf8mb4';
            self::$pdo = new PDO($dsn, 'root', '', [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                // MariaDB's default collation for utf8mb4 is *_general_ci, while the
                // schema/views use utf8mb4_unicode_ci. Pin the connection collation
                // to avoid "Illegal mix of collations" on UNION-view comparisons.
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
            ]);
        }
        return self::$pdo;
    }
```
`config/db.php:13-28` — the PDO singleton; `db()` at `config/db.php:31-34` hands it out

**Proceeds next → 3.** With a wristband and a toolkit in place, the system can track stock honestly.

---

## 3. Life of the stock — one ledger

Stock enters the system through `api/stock_in.php` POST, which validates the item exists and the quantity/date are real, then performs a plain `INSERT INTO stock_in`. But the magic is that the arrival log is never the "source of truth" by itself: the view `v_stock_movement` is. It is a single ledger that unions all three movement families — entries from `stock_in` (IN), delivered sales lines (OUT, negated), and counted-variance adjustments (ADJ) — and `v_item_stock` simply sums that ledger per item to get stock-on-hand. When a delivery line would oversell, the trigger `trg_di_stock_check` fires *before* the row lands, asks `v_item_stock` for the current balance, and `SIGNAL`s SQLSTATE 45000 if the shelf is empty — the global handler turns that into HTTP 409. Every arrival is also journaled, including adjustments, whose variance is journaled by `trg_sa_audit`; both audit inserts `CAST` to `SIGNED` defensively (the schema fix at `sql/01_schema.sql:68-69` made `counted_qty`/`system_qty` plain `INT NOT NULL`, so the type is safe either way).

```php
if ($method === 'POST') {
    $b = body();
    $item_id = int_param($b, 'item_id');
    $quantity = int_param($b, 'quantity');
    $date_received = str_param($b, 'date_received');
    $remarks = str_param($b, 'remarks') ?: null;

    if ($item_id <= 0) {
        json_err('item_id is required', 400);
    }
    if ($quantity <= 0) {
        json_err('quantity must be greater than 0', 400);
    }
    if ($date_received === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_received)) {
        json_err('date_received must be a YYYY-MM-DD date', 400);
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM item WHERE item_id = ?');
    $stmt->execute([$item_id]);
    if ((int)$stmt->fetchColumn() === 0) {
        json_err('Item not found', 400);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO stock_in (item_id, quantity, remarks, date_received) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$item_id, $quantity, $remarks, $date_received]);

    json_ok(['stock_in_id' => (int)$pdo->lastInsertId()], 201);
}
```
`api/stock_in.php:50-79` — the arrival log entry point

```sql
-- Unified stock movement ledger (IN, OUT, ADJ)
CREATE VIEW v_stock_movement AS
SELECT item_id, date_received AS move_date, 'IN' AS move_type, CAST(quantity AS SIGNED) AS qty 
FROM stock_in
UNION ALL
SELECT di.item_id, d.delivery_date, 'OUT', -CAST(di.quantity AS SIGNED)
FROM delivery_item di 
JOIN delivery d ON d.delivery_id = di.delivery_id 
WHERE d.status = 'DELIVERED'
UNION ALL
SELECT item_id, adjust_date, 'ADJ', CAST(counted_qty AS SIGNED) - system_qty 
FROM stock_adjustment;
```
`sql/02_views.sql:9-20` — the single ledger: IN + OUT + ADJ

```sql
CREATE VIEW v_item_stock AS
SELECT i.item_id, i.item_code, i.name, c.name AS category, i.unit, i.unit_price, i.low_stock_level, i.status,
       COALESCE(m.soh, 0) AS stock_on_hand
FROM item i 
JOIN category c ON c.category_id = i.category_id
LEFT JOIN (SELECT item_id, SUM(qty) AS soh FROM v_stock_movement GROUP BY item_id) m ON m.item_id = i.item_id;
```
`sql/02_views.sql:23-28` — stock-on-hand = SUM of the ledger

```sql
CREATE TRIGGER trg_di_stock_check BEFORE INSERT ON delivery_item FOR EACH ROW
BEGIN
    DECLARE soh INT;
    SELECT stock_on_hand INTO soh FROM v_item_stock WHERE item_id = NEW.item_id;
    IF soh < NEW.quantity THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Insufficient stock on hand';
    END IF;
END$$
```
`sql/03_triggers.sql:13-20` — the oversell guard (SIGNAL 45000 → 409)

```sql
-- Physical count variance -> ADJUSTMENT (delta = counted - system)
DROP TRIGGER IF EXISTS trg_sa_audit;
CREATE TRIGGER trg_sa_audit AFTER INSERT ON stock_adjustment FOR EACH ROW
BEGIN
    DECLARE v_bal INT;
    SELECT stock_on_hand INTO v_bal FROM v_item_stock WHERE item_id = NEW.item_id;
    IF v_bal IS NULL THEN SET v_bal = 0; END IF;
    INSERT INTO item_stock_audit (item_id, change_type, qty_delta, balance_after, source, created_at)
    VALUES (NEW.item_id, 'ADJUSTMENT', CAST(NEW.counted_qty AS SIGNED) - NEW.system_qty, v_bal,
            CONCAT('adjustment #', NEW.adjustment_id), NOW(3));
END$$
```
`sql/03_triggers.sql:128-137` — the audit trail write (SIGNED cast belt-and-braces; schema constant is `system_qty INT NOT NULL, counted_qty INT NOT NULL` — `sql/01_schema.sql:68-69`)

**Proceeds next → 4.** The two hero workflows lean on this ledger directly.

---

## 4. Hero workflows, step by step

The two workflows the business does hundreds of times a season — booking a delivery and taking a payment — plus the rule for deleting things that have history. These are the money flows, so the code is the strictest here.

### 4.1 Creating a delivery — saved all-or-nothing

`api/deliveries.php` POST never writes half a delivery. First it validates everything in cheap in-memory checks: the customer exists, the order type's paperwork is present (PO for DIRECT, bid reference for BIDDING), and every line item carries a positive integer quantity and a unit price of 0 or more with no duplicate items. Only then does it open a single transaction: generate-or-take the receipt number (custom ones rely on the UNIQUE constraint; generated ones follow `DR-{year}-{seq}` with up to 5 retries on collision), insert the `delivery` header, insert the matching subtype row (`direct_delivery` or `bidding_delivery`), then insert each delivery line — where the `trg_di_stock_check` trigger rules out overselling. If anything anywhere fails, the code rolls the whole transaction back and re-throws so the global handler answers with a clean 409 or 500; the database is left exactly as it was before the attempt.

Phase one — validation, all failures are plain 400s:

```php
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
```
`api/deliveries.php:181-208`; the customer existence check is at `api/deliveries.php:211-215`

Phase two — the all-or-nothing transaction:

```php
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
```
`api/deliveries.php:220-285` — beginTransaction → header + subtype + lines → commit / rollBack

**Proceeds → 4.2**, where the same discipline is applied to taking a payment.

### 4.2 Recording a payment — race-proof by design

Taking money is protected two layers deep. Pre-flight validation in `api/payments.php` catches silly mistakes with clean HTTP codes (required id, amount > 0, real date, allowed method), but the *authoritative* check lives inside the stored procedure `sp_record_delivery_payment`. The procedure locks the delivery's balance row (`FOR UPDATE`, so two collectors in two rooms cannot both read the same balance and both "win"), then validates status, amount, and balance headroom, `SIGNAL`ing 45000 with the rest already rolled back. While PHP pointed queries would leave a read-then-insert gap that two concurrent collectors could slip through, the `FOR UPDATE` lock closes that race in the database itself. The PHP side catches any 45000 and maps it to HTTP 409 with the exact message; `trg_payment_no_overpay` (`sql/03_triggers.sql:44`) remains as a backstop.

```sql
CREATE PROCEDURE sp_record_delivery_payment(
    IN p_delivery_id INT UNSIGNED,
    IN p_payment_date DATE,
    IN p_amount DECIMAL(12,2),
    IN p_method VARCHAR(50),
    IN p_reference_no VARCHAR(60),
    IN p_remarks VARCHAR(255)
)
BEGIN
    DECLARE v_status VARCHAR(20);
    DECLARE v_balance DECIMAL(12,2);

    START TRANSACTION;

    -- Lock the delivery row so concurrent payments serialize
    SELECT status, balance INTO v_status, v_balance
    FROM v_delivery_balance
    WHERE delivery_id = p_delivery_id
    FOR UPDATE;

    IF v_status IS NULL THEN
        ROLLBACK;
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Delivery not found';
    ELSEIF v_status != 'DELIVERED' THEN
        ROLLBACK;
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Delivery is not DELIVERED';
    ELSEIF p_amount <= 0 THEN
        ROLLBACK;
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment amount must be greater than zero';
    ELSEIF p_amount > v_balance THEN
        ROLLBACK;
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment exceeds the delivery balance';
    ELSE
        INSERT INTO payment (delivery_id, payment_date, amount, method, reference_no, remarks)
        VALUES (p_delivery_id, p_payment_date, p_amount, p_method, p_reference_no, p_remarks);
        COMMIT;
        SELECT LAST_INSERT_ID() AS payment_id,
               'Payment recorded successfully' AS message,
               (v_balance - p_amount) AS remaining_balance;
    END IF;
END$$
```
`sql/06_procedures.sql:15-54` — FOR UPDATE → SIGNAL on bad state → insert + COMMIT → result row

```php
    // Pre-flight validation (cheap checks, clean HTTP codes), then the
    // authoritative atomic path: sp_record_delivery_payment closes the
    // read-then-insert race that two plain queries (or concurrent POSTs)
    // could hit — the delivery row is locked FOR UPDATE inside the SP.
    try {
        $st = $pdo->prepare('CALL sp_record_delivery_payment(?, ?, ?, ?, ?, ?)');
        $st->execute([$delivery_id, $payment_date, $amount, $method_pay, $reference_no ?: null, $remarks ?: null]);
        $res = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $st->closeCursor();

        json_ok([
            'payment_id'        => (int)($res['payment_id'] ?? 0),
            'remaining_balance' => (float)($res['remaining_balance'] ?? 0.0),
        ], 201);
    } catch (PDOException $e) {
        if (($e->errorInfo[0] ?? '') === '45000') {
            // SIGNAL raised inside the procedure (and rolled back)
            json_err($e->getMessage(), 409);
        }
        throw $e;
    }
```
`api/payments.php:96-112` — CALL the procedure; SIGNAL 45000 → HTTP 409 with the exact message

**Proceeds → 4.3**, the polite way to remove things that have history.

### 4.3 Deleting things that have history

When a user presses Delete on an item, `api/items.php` first checks whether that item ever appears in `stock_in`, `delivery_item`, or `stock_adjustment`. If it does, the row is **not** deleted — it is flipped to `status = 'ARCHIVED'`, so the posting history (and the single stock ledger) stays intact while the item disappears from day-to-day dropdowns. If it has no history at all, a real `DELETE FROM item` runs. Deleting a *stock-in receipt* is guarded by the trigger `trg_si_delete_guard`, which refuses to let stock-on-hand go negative after the arrival is removed — notice the `CAST(OLD.quantity AS SIGNED)` so the subtraction can't be skewed by unsigned arithmetic — and the refused delete surfaces as HTTP 409.

```php
    $hasHistory =
        (function () use ($pdo, $id): bool {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM stock_in WHERE item_id = ?');
            $stmt->execute([$id]);
            return (int)$stmt->fetchColumn() > 0;
        })()
        || (function () use ($pdo, $id): bool {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM delivery_item WHERE item_id = ?');
            $stmt->execute([$id]);
            return (int)$stmt->fetchColumn() > 0;
        })()
        || (function () use ($pdo, $id): bool {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM stock_adjustment WHERE item_id = ?');
            $stmt->execute([$id]);
            return (int)$stmt->fetchColumn() > 0;
        })();

    if ($hasHistory) {
        $stmt = $pdo->prepare('UPDATE item SET status = \'ARCHIVED\' WHERE item_id = ?');
        $stmt->execute([$id]);
        json_ok(['archived' => true]);
    }

    $stmt = $pdo->prepare('DELETE FROM item WHERE item_id = ?');
    $stmt->execute([$id]);
    json_ok(['ok' => true]);
```
`api/items.php:228-253` — history-detect → archive instead of delete; `api/items.php:217-226` is the id/existence preamble

```sql
CREATE TRIGGER trg_si_delete_guard BEFORE DELETE ON stock_in FOR EACH ROW
BEGIN
    DECLARE soh INT;
    SELECT stock_on_hand INTO soh FROM v_item_stock WHERE item_id = OLD.item_id;
    IF soh - CAST(OLD.quantity AS SIGNED) < 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot delete: stock would go negative';
    END IF;
END$$
```
`sql/03_triggers.sql:24-31` — the receipt-delete guard (note the SIGNED cast)

**Proceeds → 5**, the complete code map of every endpoint.

---

## 5. Endpoint-by-endpoint code map

Twelve endpoint files live in `api/`. Each has one verb per row: what the code does, then which tables/views it touches. (All files route on `$method = $_SERVER['REQUEST_METHOD']` and end with `json_err('Method not allowed', 405)`.)

### 5.1 `api/auth.php`
| Verb | What the code does | Touches |
|---|---|---|
| POST | Validates username/password, `password_verify`, sets `$_SESSION['user_id'/'username'/'full_name']` | `user_account` |
| GET `?action=me` | Returns the session user (or 401) | `user_account` |
| GET `?action=logout` | `$_SESSION = []` + `session_destroy()` | session only |

### 5.2 `api/items.php`
| Verb | What the code does | Touches |
|---|---|---|
| GET | List/filter by `status` (ACTIVE/ARCHIVED), joined to category; `?id=` fetches one | `item`, `category` |
| POST | Validates code/name (not shown in snippet above), inserts the catalog row | `item` |
| PUT | Partial update; `status` may flip to ACTIVE/ARCHIVED (that's the manual archive path) | `item` |
| DELETE | History-detect floor (see §4.3): archive if used, else delete | `item`, `stock_in`, `delivery_item`, `stock_adjustment` |

### 5.3 `api/categories.php`
| Verb | What the code does | Touches |
|---|---|---|
| GET | Lists categories with item counts | `category`, `item` |
| POST | Inserts a new category (UNIQUE name protected) | `category` |
| PUT | Renames a category after existence check | `category` |
| DELETE | Deletes, but catches 23000 → "Category has items", 409 | `category` |

### 5.4 `api/customers.php`
| Verb | What the code does | Touches |
|---|---|---|
| GET `?id=` | One customer + contacts + outstanding balance (SUM of `v_delivery_balance.balance`) | `customer`, `customer_contact`, `v_delivery_balance` |
| GET (list) | All customers LEFT JOIN `school`/`office` subtype fields, contacts indexed in one pass | `customer`, `school`, `office`, `customer_contact` |
| POST | Transactional insert: customer + school or office subtype row + contacts | `customer`, `school`, `office`, `customer_contact` |
| PUT | Update + rewrite `customer_contact` set (`DELETE` then re-insert) | `customer`, subtype tables |
| DELETE | Delete, 23000 → "Customer has deliveries", 409 | `customer` |

### 5.5 `api/stock_in.php`
| Verb | What the code does | Touches |
|---|---|---|
| GET | Filtered arrival log (`?item_id=`, `?from=`, `?to=`), newest first | `stock_in`, `item` |
| POST | Validate → `INSERT INTO stock_in` (see §3) | `stock_in` |
| DELETE | Exists-check → delete; `trg_si_delete_guard` may refuse with 45000 → 409 | `stock_in` |

### 5.6 `api/stock_card.php`
| Verb | What the code does | Touches |
|---|---|---|
| GET (item picker) | ACTIVE items for the dropdown | `v_item_stock` |
| GET (ledger) | Item header + running balance from `v_stock_movement`, optional date range | `v_item_stock`, `v_stock_movement` |

### 5.7 `api/deliveries.php`
| Verb | What the code does | Touches |
|---|---|---|
| GET `?id=` | Header + subtype + lines + payments built up from `v_delivery_balance` | `v_delivery_balance`, `delivery_item`, `payment` |
| GET (list) | Date/order_type/receipt filters on the balance view | `v_delivery_balance` |
| POST | Full transaction (see §4.1) | `delivery`, `direct_delivery`/`bidding_delivery`, `delivery_item` |
| PUT (cancel) | Only while unpaid: sets `status='CANCELLED'`; cancelled deliveries drop out of `v_stock_movement` | `delivery`, `payment` |
| DELETE | Only while unpaid: hard delete of the delivery | `delivery` |

### 5.8 `api/payments.php`
| Verb | What the code does | Touches |
|---|---|---|
| GET | Payment history filtered by `delivery_id` / `customer_id` / date range | `payment`, `delivery`, `customer` |
| POST | Pre-flight validate → `CALL sp_record_delivery_payment(...)` → 45000 → 409 (see §4.2) | `payment` (via SP), `v_delivery_balance` |

### 5.9 `api/adjustments.php`
| Verb | What the code does | Touches |
|---|---|---|
| GET | Adjustment history (with item join, optional item/date filters) | `stock_adjustment`, `item` |
| POST | Reads current SOH from `v_item_stock` as `system_qty`, inserts the counted record (see §5 of code above: `api/adjustments.php:61-94`); `trg_sa_audit` journals the *variance* | `v_item_stock`, `stock_adjustment` (+ audit trigger) |

### 5.10 `api/dashboard.php`
| Verb | What the code does | Touches |
|---|---|---|
| GET | KPI cards (year billed, collected, receivables, low-stock, counts by type), upcoming deadlines (`v_delivery_balance`), 6-month movement over `v_stock_movement` | `v_delivery_balance`, `v_item_stock`, `v_stock_movement`, `payment`, `item`, `customer` |

### 5.11 `api/reports.php`
| Verb | What the code does | Touches |
|---|---|---|
| GET `?report=matrix` | Per-item movement matrix over the period from `v_stock_movement` | `v_stock_movement`, `item` |
| GET `?report=summary` | Period aggregates: billed, collected, #deliveries, top items | `v_delivery_balance`, `payment`, `delivery`, `delivery_item` |
| GET `?report=revenue` | Monthly billed vs collected vs deliveries, gaps included in the series | `v_delivery_balance`, `payment`, `delivery` |

### 5.12 `api/receipt.php`
| Verb | What the code does | Touches |
|---|---|---|
| GET | **Not JSON** — renders a printable delivery receipt: header/status/contact/lines/payments fetched *before* any HTML, 404 for unknown/invalid receipts | `delivery`, `customer`, `customer_contact`, `delivery_item`, `payment` |

**Proceeds → 6**, the frontend half of the contract.

---

## 6. Frontend contract in code

Every page talks to the API through one object, `wb` in `js/api.js`, so headers, JSON encoding, error messages, and the 401 redirect are written once and reused everywhere. Every page builds its chrome through `UI` in `js/ui.js`: `UI.shell(...)` renders the shared sidebar/topbar skeleton, `UI.guard()` stamps in the logged-in name, `UI.toast()` pops feedback, `UI.confirm()` and `UI.modal()` handle prompts and dialogs, `UI.badge()` renders status pills, `UI.setSelectValue()` pre-selects `<option>`s type-safely, and `UI.addSearch()` wires a filter box to a table body that re-applies itself after every re-render via a `MutationObserver`. Pages do no bespoke fetching or dialogs — e.g. `js/items.js` wires its search with a single line.

The four verbs of `wb`:

```js
  get(path) {
    return this._run(path, { headers: { 'Accept': 'application/json' } });
  },
  post(path, json) {
    return this._run(path, this._json(json, 'POST'));
  },
  put(path, json) {
    return this._run(path, this._json(json, 'PUT'));
  },
  del(path) {
    return this._run(path, { method: 'DELETE', headers: { 'Accept': 'application/json' } });
  },
```
`js/api.js:28-39` — `wb.get` / `wb.post` / `wb.put` / `wb.del` all funnel into `_run`

```js
  async _run(path, opts) {
    const res = await fetch(this.base + path, opts);
    this._check(res);
    const text = await res.text();
    let data = null;
    try { data = JSON.parse(text); } catch { data = null; }
    if (!res.ok) {
      const msg = (data && data.error) ? data.error : `Request failed (${res.status})`;
      const err = new Error(msg);
      err.status = res.status;
      throw err;
    }
    return data;
  },
```
`js/api.js:13-26` — fetch → 401 check → JSON parse → throw Error carrying `err.status` (so pages can toast the server's exact message)

The `UI` helpers (one-liners and tiny hooks):

```js
  const badge = (text, tone = 'mute') =>
    `<span class="badge ${esc(tone)}">${esc(text)}</span>`;
```
`js/ui.js:33-34` — status pills (tones come from `statusTone`/`typeTone`)

```js
  function toast(msg, type = 'ok') {
    let zone = document.getElementById('toast-zone');
    if (!zone) { zone = document.createElement('div'); zone.id = 'toast-zone'; document.body.appendChild(zone); }
    const el = document.createElement('div');
    el.className = 'toast' + (type === 'err' ? ' err' : type === 'warn' ? ' warn' : '');
    el.textContent = msg;
    zone.appendChild(el);
    setTimeout(() => { el.style.opacity = '0'; el.style.transition = 'opacity .25s'; setTimeout(() => el.remove(), 260); },
      type === 'err' ? 5200 : 3200);
  }
```
`js/ui.js:40-49` — transient feedback (`'ok' | 'err' | 'warn'`); pages call it in every catch

```js
  function confirm(msg, { danger = false } = {}) {
    return new Promise((resolve) => {
      const m = modal({
        title: 'Please confirm',
        width: 'narrow',
        bodyHTML: `<p style="margin:0;color:var(--ink-2)">${esc(msg)}</p>`,
        footerHTML: `<button class="btn" data-act="no">Cancel</button>
                     <button class="btn ${danger ? 'danger' : 'primary'}" data-act="yes">${danger ? 'Delete' : 'Confirm'}</button>`
      });
      m.el.querySelector('[data-act="no"]').onclick = () => { m.close(); resolve(false); };
      m.el.querySelector('[data-act="yes"]').onclick = () => { m.close(); resolve(true); };
      m.el.querySelector('.modal-head .x').addEventListener('click', () => resolve(false));
    });
  }
```
`js/ui.js:51-64` — awaitable confirmation built on `modal()` (`js/ui.js:67` takes `{title, bodyHTML, footerHTML, width}` and returns `{el, close}`)

```js
  function shell(activeKey, title, hint = '') {
    const shellEl = document.getElementById('shell');
    if (!shellEl) return;
    const page = shellEl.dataset.page || activeKey || '';
```
`js/ui.js:87-91` — `shell()` swaps the `<div id="shell">` placeholder for the full sidebar + topbar layout from the `NAV` list

```js
  function setSelectValue(el, val, fallbackText) {
    if (!el) return;
    const want = String(val ?? '');
    if (want !== '' && [...el.options].some(o => String(o.value) === want)) {
      el.value = want;
      return;
    }
    if (fallbackText) {
      const hit = [...el.options].find(o => o.text === fallbackText);
      if (hit) { el.value = hit.value; return; }
    }
    el.selectedIndex = el.selectedIndex >= 0 ? el.selectedIndex : 0;
  }
```
`js/ui.js:173-185` — type-safe `<select>` preselection (id comes back as a number; option values are strings, so it coerces and can fall back to matching the option's *text*, e.g. a category name)

```js
  function addSearch(input, tbody) {
    const box = typeof input === 'string' ? document.getElementById(input) : input;
    const body = typeof tbody === 'string' ? document.getElementById(tbody) : tbody;
    if (!box || !body) return;
    const apply = () => {
      const q = box.value.trim().toLowerCase();
      for (const tr of body.rows)
        tr.hidden = !!q && (tr.classList.contains('empty-row') ||
          !tr.textContent.toLowerCase().includes(q));
    };
    box.addEventListener('input', apply);
    new MutationObserver(apply).observe(body, { childList: true });
  }
```
`js/ui.js:192-204` — one binding survives every table re-render, no per-render calls

Where pages call them (wiring examples):

- `js/items.js:4` — `await UI.guard();` boots the page; `js/items.js:101` — `UI.addSearch($('f-search'), $('i-tbody'));`
- `js/stock_in.js:50` — `UI.addSearch('r-search', $('r-tbody'));` (string-element style)
- `js/stock_card.js:45`, `js/deliveries.js:55`, `js/payments.js:69`, `js/customers.js:80`, `js/count.js:62` — the same one-line pattern in every list page
- `js/deliveries.js:184`, `js/items.js:215`, `js/stock_in.js:113` — `await UI.confirm('...', { danger: true })` before destructive verbs
- `js/deliveries.js:124`, `js/items.js:122`, `js/customers.js:100` — `UI.modal({...})` for create/edit forms
- `js/delivery_new.js:195` — `UI.toast('Delivery created');` and `js/payments.js:125` — toasting the exact 409 overpay message from the server
