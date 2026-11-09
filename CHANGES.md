# PMS Trading Inventory System — Change Log

## 2026-11-07 — Dashboard KPI/analytics pass, physical-count fix, UX entry-point fixes

Four fixes applied via parallel subagent tasks. Full smoke suite re-verified **24/24** after the batch.

### Changed files

| File | Change |
| :--- | :--- |
| `api/dashboard.php` | Added metrics: `inventory_valuation` / `stock_on_hand_units` (from `v_item_stock`), `revenue_this_month` (monthly slice of the existing YTD definition), plus two analytics queries: `revenue_trend` (last 6 calendar months from `v_delivery_balance`, gaps zero-filled) and `top_items` (top 5 by outgoing qty, last 90 days, from `v_stock_movement`). |
| `js/dashboard.js` | KPI strip expanded to 9 cards (Valuation, Total Items, Stock On Hand, Low Stock, Rev YTD, Rev This Month, Collected YTD, Outstanding, Deliveries YTD); added pure-CSS 6-month revenue bar chart and Top-Items table widget. Existing cards untouched. |
| `css/app.css` | Small additions: `.kpi.warn`, `.bars/.bar/*` chart classes using existing tokens only. `@media print` untouched. |
| `dashboard.html` | CSS cache-bust `?v=4`. |
| `js/ui.js` | New `UI.setSelectValue(el, val, fallbackText)` — type-safe select preselect helper (`String()` comparison + option-text fallback). New `UI.addSearch(input, tbody)` — generic client-side table search box helper: case-insensitive row filter on cell text, re-applied automatically after re-renders via a MutationObserver. |
| `js/items.js` | Edit-item path resolves `category_id` from `item.category` via `state.cats` + `UI.setSelectValue` — fixes "category left blank when editing". Search box wired to the items table. |
| `js/customers.js`, `js/deliveries.js`, `js/payments.js`, `js/stock_in.js`, `js/stock_card.js`, `js/count.js` | Search box added to each list section's toolbar (pure client-side filtering; no API changes). |
| `sql/01_schema.sql` | `stock_adjustment.counted_qty` → `INT NOT NULL` (was `INT UNSIGNED`). Unsigned blocked legitimate negative variance during audits. Live DB altered (`ALTER TABLE`, 8 rows preserved). |
| `sql/03_triggers.sql` | `trg_si_delete_guard` now uses `CAST(OLD.quantity AS SIGNED)` so the intended 45000 signal fires instead of a masked 1690 unsigned overflow. (Re-applied idempotently; 10 triggers live.) |
| `js/deliveries.js` | Added prominent `+ New Delivery` primary button (→ `delivery_new.html`) to the deliveries log toolbar — creation was previously reachable only via a quick-link on the Dashboard. |

### Verification

| Check | Result |
| :--- | :--- |
| `php -l` on all changed PHP files | Clean || Authenticated curl to `api/dashboard.php` | New keys live on seeded data (`inventory_valuation=235905`, `stock_on_hand_units=5534`, 6-month trend, top-5 items) |
| Authenticated curl to `api/items.php` / `api/categories.php` | Confirmed list payload has no `category_id` (only `category` name) — root cause of the blank select |
| Negative variance adjustment (counted < system) | PASS — no more `SQLSTATE[22003] / 1690`; audit row records negative `qty_delta` with correct `balance_after`; test rows rolled back |
| Positive variance adjustment | PASS |
| `php scripts/verify.php` | 10 PASS / 1 WARN (pre-existing `receipt.html` spec mismatch only) |
| `scripts/smoke.ps1` | **24 passed / 0 failed** (re-run after the search-box pass: still 24/24) |
| All 7 searchable pages via `php -S` | `items`, `customers`, `deliveries`, `payments`, `stock_in`, `stock_card`, `count` → HTTP 200; JS parse checks clean |

### Notes
- Audit-trigger hardening and the `sp_record_delivery_payment` changes from 2026-11-06 remain in force; `trg_sa_audit` already used `CAST(... AS SIGNED)` on disk and live.
- Docs updated in the same pass: `API_SPEC.md` (dashboard response keys) and the vault testing playbook (Feature 02 KPI list).
- 2026-11-09: `API_SPEC.md` fully rewritten for readability — plain-English explanations, mermaid flow diagrams (login, stock life-cycle, delivery all-or-nothing, race-proof payments, delete-vs-archive), human-word status-code table, and a per-endpoint ask→get reference. No behavioral facts changed (24/24 smoke baseline unaffected).

## 2026-11-06 — SQL Lab optimization pass (Views, Triggers, Stored Procedures)


Applied the learnings from the Advanced Database Systems lesson note
**"SQL Lab: Views, Triggers, and Transactions (PMS Integration)"** (vault, dated 2026-11-05)
to the live codebase.

---

### Changed files

| File | Change |
| :--- | :--- |
| `sql/03_triggers.sql` | Added `item_stock_audit` table + 4 audit triggers (`trg_si_audit`, `trg_di_audit`, `trg_delivery_cancel_audit`, `trg_sa_audit`). Added `DROP TRIGGER IF EXISTS` guards before every trigger so the script is re-runnable. |
| `sql/06_procedures.sql` | **New file.** First stored procedure: `sp_record_delivery_payment` (ACID transaction + `SELECT ... FOR UPDATE` row locking + `SIGNAL` validation). |
| `api/payments.php` | POST handler now delegates the transaction to `CALL sp_record_delivery_payment(...)` and maps SQLSTATE `45000` signals to HTTP 409. |

---

### Why each change is an optimization

#### 1. Stock audit ledger (`item_stock_audit` + 4 triggers) — via `sql/03_triggers.sql`

**Lesson pattern:** `trg_after_inventory_update` (lesson: audit triggers capture state changes
automatically, `FOR EACH ROW`, `OLD` vs `NEW`).

**Optimization:** Previously the system could only compute *current* stock on hand
(`v_item_stock`); there was no point-in-time history. Auditing had to be done by hand from the
three separate ledger tables, and any future write path (new endpoint, manual SQL, another app)
would silently bypass any PHP-side logging.

**Key adaptation:** the lesson audited a physical `products.stock_quantity` column, but in PMS
stock is a *computed* ledger (`v_item_stock`). So the triggers were placed on the stock tables
themselves and snapshot `balance_after` from `v_item_stock` at trigger time — same capture
philosophy, correct for this schema:

- `trg_si_audit` (AFTER INSERT ON `stock_in`) → `STOCK_IN`
- `trg_di_audit` (AFTER INSERT ON `delivery_item`, only when the delivery is `DELIVERED`) → `STOCK_OUT`
- `trg_delivery_cancel_audit` (AFTER UPDATE ON `delivery`, only on `DELIVERED → CANCELLED`) → `CANCELLATION` (restores stock, one row per item)
- `trg_sa_audit` (AFTER INSERT ON `stock_adjustment`) → `ADJUSTMENT` (`counted − system`)

Each row stores `qty_delta`, `balance_after`, and a `source` reference (`stock_in #4`,
`delivery #6`, …) — an append-only trail that the stock-card/report screens can build on later.

**Bonus (lesson troubleshooting table applied):** hit the exact `ERROR 1359` (trigger already
exists) during deployment; fixed by putting `DROP TRIGGER IF EXISTS` before every
`CREATE TRIGGER`, making `03_triggers.sql` idempotent instead of one-shot.

---

#### 2. Transactional stored procedure (`sp_record_delivery_payment`) — via `sql/06_procedures.sql`

**Lesson pattern:** `sp_transfer_funds` — `START TRANSACTION` / `SELECT ... FOR UPDATE` /
`SIGNAL SQLSTATE '45000'` / `COMMIT` / `ROLLBACK`.

**Optimization:** the old POST flow in `api/payments.php` was a non-atomic
read-then-insert in PHP:

```sql
-- query 1 (no lock):  SELECT status, balance FROM v_delivery_balance WHERE delivery_id = ?
-- query 2:            INSERT INTO payment ...
```

Two concurrent POSTs (double-click, slow connection retry) could both pass the balance check
before either insert landed — a lost-update race. The overpay *trigger* only rejects the second
insert, with no guarantee on ordering, and the endpoint produced an ugly generic 500 for the
slow loser instead of a clean 409.

The stored procedure makes the whole unit atomic and serialized:

- `SELECT ... FOR UPDATE` takes a pessimistic row-level lock on the delivery row, so competing
  payments queue up instead of interleaving (lesson's concurrency-control concept);
- all validation (exists → `DELIVERED` → `amount > 0` → `amount ≤ balance`) is enforced
  server-side inside the transaction and cannot be bypassed by any client or future code path;
- `SIGNAL SQLSTATE '45000'` rolls the transaction back and gives the API the exact reason;
- `LAST_INSERT_ID()` + `remaining_balance` are returned in a single round trip.

Nothing is placed on the wire between validated read and insert — it is one `CALL` on the
network instead of two queries, and the business rule now lives and dies with the database.

**Adaptation notes:**
- The lesson's `status = 'PAID'` update branch was dropped because `delivery.status` in PMS is
  `ENUM('DELIVERED','CANCELLED')` — paid-vs-unpaid is already derived by `v_delivery_balance`.
- `p_payment_date` was added as an `IN` parameter (PMS accepts bill-date entries), and
  `p_remarks` was added since the `payment` table has the column.
- `trg_payment_no_overpay` was intentionally kept as a backstop (defense in depth).

---

#### 3. API delegation — via `api/payments.php`

The POST branch now: keeps its cheap pre-flight parameter validation (clean 400s), then issues
one `CALL`, maps `errorInfo[0] === '45000'` to the same 409 responses as before (`Delivery not
found`, `Delivery is not DELIVERED`, `Payment exceeds the delivery balance`), and degrades all
other database errors to the bootstrap's generic handling. `closeCursor()` is called before
reusing the connection because `CALL` can return multiple result sets in PDO/MySQL.

#### 4. Branding + responsive layer — via `js/ui.js`, `login.html`, `api/receipt.php`, `css/app.css` *(same day)*

Full app identity renamed from "ADS PMS Trading" to **PMS Trading** (sidebar shell, login screen/title, printed receipt header). Added a three-tier responsive system replacing the single 900px block:

- **Tablet ≤900px** — **off-canvas drawer**: burger button in the topbar slides the full-height sidebar in from the left (group labels restored, 44px targets); backdrop tap, Escape, nav-link tap, or rotating back to desktop closes it. Replaced a first attempt at a one-rail top strip that truncated labels and misoriented wide report tables — the drawer keeps the main content full-width so tables keep their natural orientation.
- **Mobile ≤640px** — 44px touch targets, stacked forms and fluid toolbar columns, modals re-flavored as bottom sheets with sticky footers and full-width buttons, 16px inputs (prevents iOS focus auto-zoom), width-spanning toasts.
- **Small-phone ≤420px** — single-column meta grids, stacked modal actions.

**Why more optimized:** pure CSS on the existing token system (zero JS, zero dependencies); all bounded scroll lives inside components (nav rail, `.tablewrap` trays) so the page itself never scrolls horizontally; `@media print` untouched (printed receipts byte-identical); HTML pages cache-busted (`ui.js?v=2`, `app.css?v=2`) so devices render the new shell immediately.

#### 5. Sample/demo dataset — via `sql/07_sample_data.sql` *(added earlier the same day)*

Optional one-file demo data so fresh installs show populated screens: 14 real-product items
across the three seeded categories, 7 local customers (3 schools / 4 offices with subtypes +
contacts), opening stock per item, 9 deliveries spanning Oct–Nov 2026 (2 paid, 3 partial,
2 unpaid, 1 cancelled to demo stock restoration), 6 collections, and 3 physical-count
adjustments.

**Why it is optimized:** every insert is guarded (`INSERT IGNORE` or `NOT EXISTS` keyed on
remark markers), so re-running the file can never duplicate rows or overwrite data the owner
entered afterward. Learning applied from this revision: `INSERT IGNORE` does **not** suppress
`BEFORE INSERT` triggers — duplicate-key rows still hit `trg_di_stock_check`, which rejected
re-runs as "insufficient stock". Fixed by guarding `delivery_item` inserts with
`NOT EXISTS` so already-present sample lines never reach the trigger at all.

---

| Check | Result |
| :--- | :--- |
| `php -l api/payments.php` | No syntax errors |
| `source sql/03_triggers.sql` + `sql/06_procedures.sql` (MariaDB 10.4.32) | Applied clean (idempotent re-run) |
| `SHOW TRIGGERS` / `SHOW PROCEDURE STATUS` / `SHOW TABLES` | 10 triggers, 1 procedure, `item_stock_audit` present |
| Smoke suite `scripts/smoke.ps1` | **24 passed / 0 failed** |
| Audit trail end-to-end | `STOCK_IN +20 → 20`, `STOCK_OUT −3 → 17`, `ADJUSTMENT −2 → 15` rows written automatically by triggers |
| Overpay probe `CALL sp_record_delivery_payment(2, …, 99.00, …)` | `ERROR 1644 (45000): Payment exceeds the delivery balance`; no row inserted (rollback confirmed), balance untouched |

### Load order note

`README.md` setup sequence is unchanged for fresh installs — after loading `sql/01–05`, also
load `sql/06_procedures.sql`.
