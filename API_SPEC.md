# PMS API Contract (single source of truth for all agents)

Shared foundation already written: `config/db.php` (PDO singleton `db()`), `api/_bootstrap.php`
(`json_ok()`, `json_err()`, `require_login()`, `body()`, `int_param()`, `str_param()`,
exception handler mapping SQLSTATE 45000/23000 -> HTTP 409).

## Conventions (mandatory)

- Every `api/*.php` file starts with: `require_once __DIR__ . '/_bootstrap.php';`
- Every endpoint except `auth.php` login/`me` calls `require_login()` first.
- All responses are JSON: success `json_ok($data, $code)`; errors `json_err($msg, $code)`.
- Status codes used: 200 OK, 201 Created (POST create), 400 validation, 401 unauth, 404 not found, 409 conflict (duplicate / business-rule SIGNAL / FK restrict), 500.
- Never throw raw SQLSTATE mess in 500; validate inputs first.
- Enum values are UPPERCASE, as stored in DB (e.g. `ACTIVE`, `SCHOOL`, `DIRECT`, `DELIVERED`, `CASH`).
- Dates are `YYYY-MM-DD` strings. Money is returned as string-friendly numbers via `(float)` or DB decimal.

## Endpoint spec

### api/auth.php
- `POST` (no action): body `{username, password}` -> verify against `user_account` (`password_verify`). Set `$_SESSION['user_id']`, `$_SESSION['username']`. Return `{user_id, username, full_name}`.
- `GET ?action=me` -> `{user_id, username, full_name}` or `json_err('Authentication required', 401)` if no session.
- `GET ?action=logout` -> `session_destroy()`, `{ok: true}`.

### api/items.php
- `GET` -> list from `v_item_stock` (has columns: `item_id, item_code, name, category, unit, unit_price, low_stock_level, status, stock_on_hand`). Optional filters: `?search=` (matches item_code/name/ LIKE), `?category=`, `?status=` (default all), `?low_stock=1` (stock_on_hand <= low_stock_level). Sorted by name.
- `GET ?id=N` -> single row from `v_item_stock` + `price_history` (array of {effective_at, price}) from `item_price_history`.
- `POST` body `{category_id, item_code, name, unit, unit_price, low_stock_level}` -> insert into `item`; passing unit_price also handled by trigger `trg_item_price_ins`; return `{item_id}` 201. Validate: all required present, unit_price >= 0.
- `PUT ?id=N` body accepts any of `{category_id, name, unit, unit_price, low_stock_level, status}` -> dynamic UPDATE. Trigger `trg_item_price_log` writes history automatically when price changes.
- `DELETE ?id=N` -> if item has movement history (rows in `stock_in`/`delivery_item`/`stock_adjustment`) then `UPDATE item SET status='ARCHIVED'`; else hard DELETE. Return `{archived: bool}` or `{ok: true}`.

### api/categories.php
- `GET` -> all categories with item counts. `POST {name}` -> 201 `{category_id}`. `PUT ?id=N {name}`. `DELETE ?id=N` -> 409 if items exist (map FK RESTRICT; catch a PDOException with SQLSTATE 23000 in a try/catch and `json_err('Category has items', 409)`).

### api/customers.php
- `GET` -> list merging supertype + subtype: `customer_id, name, address, customer_type, school_level?, head_name?, office_type?, contact_person?, contacts` (contacts = array of {contact_type, contact_value} from `customer_contact`). Optional `?type=SCHOOL|OFFICE`, `?search=`.
- `GET ?id=N` -> same shape, single, plus `outstanding` (sum of `balance` from `v_delivery_balance` where customer = N and status='DELIVERED').
- `POST` body `{name, address, customer_type, school_level?, head_name?, office_type?, contact_person?, contacts: [{contact_type, contact_value}]}` -> transaction: insert `customer`, insert into `school` or `office` table, replace contacts (delete existing + insert). 201 `{customer_id}`.
- `PUT ?id=N` -> same body handling, in transaction with subtype + contacts replace.
- `DELETE ?id=N` -> try/catch: RESTRICT when deliveries exist -> `json_err('Customer has deliveries', 409)`.

### api/stock_in.php
- `GET` -> receipts joined with item name/code: `?item_id=N` optional filter, `?from=`, `?to=` optional date range on `date_received`. Order date_received DESC, stock_in_id DESC.
- `POST` body `{item_id, quantity, date_received, remarks?}` -> validate quantity > 0 and item exists. Return `{stock_in_id}` 201.
- `DELETE ?id=N` -> try/catch: trigger `trg_si_delete_guard` SIGNAL 45000 when stock would go negative -> 409.

### api/stock_card.php
- `GET !id required` -> if no `?item_id=` first return `{items:[{item_id, item_code, name, unit, stock_on_hand}]}` for the picker (all items incl. ARCHIVED? only ACTIVE).
  With `?item_id=N&from=&to=` (dates optional) -> chronological ledger from `v_stock_movement` for that item: rows `{move_date, move_type (IN|OUT|ADJ), qty (signed), balance}` where `balance` = running total computed in PHP after ordering by move_date then move IN before OUT before ADJ within same date, oldest first. Also return `{item: {item_code, name, unit, stock_on_hand}, entries: [...]}`.

### api/deliveries.php
- `GET` -> list from `v_delivery_balance` joined `customer` (name, customer_type) and subtype fields (po_number/po_date or bid_ref_no/delivery_deadline). Filters: `?status=`, `?order_type=`, `?customer_id=`, `?from=`, `?to=`, `?unpaid=1` (balance > 0 and status='DELIVERED'). Order delivery_date DESC, delivery_id DESC.
- `GET ?id=N` -> header + `items` array `{item_id, item_code, name, unit, quantity, unit_price, line_total}` + `payments` array of all payment rows + computed total/paid/balance.
- `POST` body `{customer_id, delivery_date, order_type: DIRECT|BIDDING, po_number?, po_date?, bid_ref_no?, delivery_deadline?, items: [{item_id, quantity, unit_price}] (>=1)}` -> transaction:
  1. validate: customer exists, order_type matches subtype fields (DIRECT needs po_number+po_date; BIDDING needs bid_ref_no+delivery_deadline), items non-empty.
  2. `receipt_no`: optional in body; if empty generate `DR-{Y-m}-{seq:4}` where seq = count of deliveries this year + 1 (check uniqueness, increment seq on collision).
  3. insert `delivery` (status 'DELIVERED'), insert subtype row, insert `delivery_item` rows (trigger `trg_di_stock_check` SIGNAL 45000 if stock insufficient -> causes rollback -> 409).
  4. 201 `{delivery_id, receipt_no}`.
- `PUT ?id=N` body `{status: 'CANCELLED'}` only allowed while no payments exist. A CANCELLED delivery is excluded from stock movement by the view already.
- `DELETE ?id=N` -> only when no payments -> delete (cascade removes items/subtype). Else 409.

### api/payments.php
- `GET` -> list joined receipt_no + customer name. Filters: `?delivery_id=`, `?customer_id=`, `?from=`, `?to=`. Order payment_date DESC, payment_id DESC.
- `POST` body `{delivery_id, amount, payment_date, method: CASH|CHECK|BANK TRANSFER|OTHER, reference_no?, remarks?}` -> validate: delivery exists, delivery status='DELIVERED', amount > 0 and <= balance (from `v_delivery_balance`, else trigger `trg_payment_no_overpay` SIGNAL 45000 -> rollback -> 409). 201 `{payment_id}`.

### api/adjustments.php
- `GET` -> list joined item code/name: `{adjustment_id, item_id, item_code, name, adjust_date, system_qty, counted_qty, variance, reason}`. Order adjust_date DESC. Optional `?item_id=`, `?from=`, `?to=`.
- `POST` body `{item_id, counted_quantity, adjust_date, reason?}` -> look up current `stock_on_hand` from `v_item_stock`; insert `stock_adjustment` with `system_qty` = that value, `counted_qty` = counted_quantity. 201.

### api/dashboard.php
- `GET` -> `{revenue_total_ytd, collected_ytd, outstanding, item_count, active_items, low_stock_items, deliveries_this_year, schools, offices}` and `low_stock` array (from `v_item_stock` where status='ACTIVE' AND stock_on_hand <= low_stock_level, ORDER BY stock_on_hand ASC, limit 50).
  - revenue = total_amount on month/year of YEAR(CURDATE()) via `v_delivery_balance` where status='DELIVERED' and YEAR(delivery_date) = {year}.
  - collected = SUM(amount) via `payment` where YEAR(payment_date) = {year}.
  - outstanding = SUM(balance where balance > 0 and status='DELIVERED') from `v_delivery_balance`.
  - deliveries_ytd = count of deliveries with YEAR(delivery_date) = {year}.

### api/reports.php
- `GET ?report=matrix&from=YYYY-MM-DD&to=YYYY-MM-DD` (required) -> per-item periodic stock movement matrix: for the period, every item row `{item_code, name, unit, category, beginning, in_qty, out_qty, ending}` where:
  - beginning = SUM(qty) of `v_stock_movement` rows with move_date < from.
  - in_qty = SUM(qty WHERE move_type='IN' AND move_date BETWEEN from AND to).
  - out_qty = -SUM(qty WHERE move_type='OUT' AND move_date BETWEEN from AND to) (positive number).
  - ending = beginning + in_qty - out_qty + adj_in_period (SUM qty of ADJ rows in period). Return also `adj` per row; ending = beginning + in_qty - out_qty + adj.
  Include only items where any of beginning/in/out/adj != 0, sorted by category, name.
- `GET ?report=summary&from=&to=` -> `{revenue, collected, deliveries_count, outflow_value}` for the period.
- `GET ?report=revenue&from=&to=` -> monthly rows `{ym (YYYY-MM), revenue, collected, deliveries_count}` ordered by month (phase 1 of period to the end).

### api/receipt.php (PDF via print CSS)
- `GET ?id=N` (requires login session cookie; browser navigates here) -> renders a full standalone HTML page (NOT JSON): `Content-Type: text/html`, the printable delivery receipt using `receipt.html`-style markup + its own inline `<style>` + print CSS (`@media print` hides chrome, `@page { size: A4; margin: 15mm }`). Header shows company name "ADS PMS TRADING", receipt_no, customer block (name/address/contacts, subtype reviewer), item table (qty, unit, item, description, unit price, line total), totals, payments applied, balance, prepared-by footer with `window.addEventListener('load', () => window.print())` and a "Back" link button (hidden in print). 404 if missing; 401 if no session.

## Frontend contract (already written files you MUST reuse)

- `js/api.js`: exports global `wb` — `wb.get(path)`, `wb.post(path, json)`, `wb.put(path, json)`, `wb.del(path)`. Paths like `'items.php?id=3'` (base `api/` prepended). 401 interceptor redirects to `login.html`.
- `js/ui.js`: global `UI` with:
  - `UI.shell(activePageKey)` — renders sidebar + topbar into `<div id="shell" data-page="..."></div>`; call at top of every page script.
  - `UI.guard()` — calls auth me() and redirects to login if 401; await this first on every page.
  - `UI.esc(s)`, `UI.money(n)` (₱ formatted), `UI.date(s)` (short P.H. format), `UI.toast(msg, type)`, `UI.confirm(msg)` -> Promise<boolean>, `UI.modal({title, bodyHTML, footerHTML?})` -> {el, close}, `UI.badge(text, tone)`.
- `css/app.css`: complete design system (variables, layout, sidebar, topbar, cards/kpis, tables, buttons, forms, badges, toasts, modal, print). Use only these classes.
