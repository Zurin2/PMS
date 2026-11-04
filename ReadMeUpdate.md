# ReadMeUpdate.md — Latest System Changes
**Project:** ADS PMS Trading — Inventory & Delivery Monitoring System
**Location:** `C:\xampp\htdocs\PMS`
**Updated:** November 4, 2026
**Status:** Fully rebuilt and verified (24/24 live smoke tests passing)

Read this after `README.md` — it covers everything that changed in the latest build,
on top of the original scaffold described in `README.md`.

---

## 1. Full system rebuild (latest major change)

The old scaffold (flat schema, 3 pages, partial APIs) was replaced with the complete
system per the approved spec
(`PMS Inventory System — Database Design & Implementation Plan`):

### New / rebuilt pages (12)
| Page | Purpose |
| :--- | :--- |
| `login.html` | Owner login with **show/hide password toggle** |
| `dashboard.html` | Live KPIs (Revenue YTD, Collected, Outstanding, Active items, Low stock, Deliveries YTD) + low-stock table + quick links |
| `items.html` | Inventory catalog, add/edit modal, low-stock tags, archive-or-delete |
| `stock_in.html` | Restock receipt logger with history |
| `stock_card.html` | Digital stock card — IN/OUT/ADJ ledger with running balance |
| `customers.html` | Schools/Offices directory (subtype fields + multi-contact storage) |
| `deliveries.html` | Delivery log with balance tracking, receipt viewer, cancel |
| `delivery_new.html` | Order fulfilment form (DIRECT with PO / BIDDING with bid ref & deadline) |
| `payments.html` | Collection entry fed by unpaid deliveries; payment history |
| `count.html` | Physical count audit with system-vs-count variance |
| `reports.html` | Movement matrix (Beginning/In/Out/Adj/Ending), summary, revenue by month; printable |
| `index.html` | Entry redirect to dashboard |

### New / rebuilt APIs (13 endpoints, contract in `API_SPEC.md`)
`auth` · `items` · `categories` · `customers` · `stock_in` · `stock_card` ·
`deliveries` · `payments` · `adjustments` · `dashboard` · `reports` · `receipt` · `_bootstrap`

Key behaviors now enforced by the server:
- **Atomic delivery transactions** — header + DIRECT/BIDDING subtype + line items inserted
  in one transaction; insufficient stock aborts with HTTP 409 (trigger SIGNAL).
- **Receipt numbering** — auto `DR-{Y-m}-{seq}` with uniqueness retry.
- **Payment overpay guard** — server-side balance check + database trigger backstop.
- **Price history** — item price changes are logged automatically by triggers.
- **Archive-on-delete** — items with movement history are archived, not deleted.
- **Guaranteed deletes** — categories/customers/stock-ins with dependencies are refused (409).
- **Single owner account** — `user_account` singleton (`CHECK (user_id = 1)`).

### Shared foundations (new)
- `API_SPEC.md` — the authoritative contract for every endpoint (status codes, JSON shapes).
- `css/app.css` — complete design system (no frameworks; all UI classes live here).
- `js/api.js` — unified fetch client (`wb`) with 401-interceptor.
- `js/ui.js` — shell/sidebar renderer, session guard, toasts, modals, formatters.

---

## 2. Hotfixes applied after the rebuild

| # | Fix | Details |
| :--- | :--- | :--- |
| 1 | **Login crash** — “res.text is not a function” | `js/api.js::_run()` wasn't invoking `fetch()`; fixed — the response object is now fetched first, then parsed. **Verify: login works.** |
| 2 | **Show-password toggle not responding** | Caused by the browser serving a **cached older `auth.js`**. `login.html` script tags now carry `?v=2` cache-busters. If behavior ever seems stale: `Ctrl+F5`. |
| 3 | **MariaDB collation crash** — “Illegal mix of collations” on stock card/reports | PDO's `charset=utf8mb4` defaults to `utf8mb4_general_ci`, but the schema/views use `utf8mb4_unicode_ci`. `config/db.php` now pins `SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci` on connect. **Verify: Stock Card and Reports load.** |
| 4 | **Owner credentials** | Reset to **username `owner`** (password set privately by the owner via reset command — see `README.md` §3 to change it again). Original hash preserved until the owner asked for the reset. |

---

## 3. Documents generated for the receipt engine (current scope)

- **PDF (print CSS)** — implemented now: `deliveries.html` → “Receipt” opens
  `api/receipt.php?id=N`, a standalone A4 print sheet that auto-opens the print dialog.
- **DOCX / PPTX** — deferred by decision (was spec'd as ZIPArchive generators). Not built yet.

---

## 4. How to verify everything works (fast path)

1. XAMPP → start **Apache** + **MySQL**.
2. Browser → `http://localhost/PMS/login.html` → sign in as `owner`.
3. Automated check (expects both services running):
   ```powershell
   powershell -ExecutionPolicy Bypass -File C:\xampp\htdocs\PMS\scripts\smoke.ps1
   ```
   Runs **24 live HTTP checks** (auth, CRUD, stock math, delivery transaction,
   overpay/cancel guards, dashboard, reports, receipt). It temporarily swaps the owner
   password and **restores the original hash afterwards**. Last run: **24 passed / 0 failed**.
4. Manual sanity: create an item → stock in → create a delivery → open receipt →
   record payment → check dashboard KPIs move.

---

## 5. Known limitations (intentional, per spec)

- Single-user / single-branch only (no roles, no multi-tenancy).
- Receipt export limited to PDF printing; DOCX/PPTX pending decision.
- `delivery.status` enum is `DELIVERED | CANCELLED` (matches the live DB schema,
  superset “pending” state from the ERD doc was not implemented).
- Reports `deliveries_count` counts all deliveries in period (including cancelled);
  `revenue` counts only `DELIVERED`. If strict bookkeeping needs the count to exclude
  cancelled, one WHERE clause in `api/reports.php` changes it.

---

## 6. Test scripts alive in `scripts/`

| File | Role |
| :--- | :--- |
| `smoke.ps1` | **Primary verification suite** (24 checks, see §4.3) |
| `_pw.php` | Helper used by smoke.ps1 for the temporary password swap |
| `api_tests.sh` / `verify.php` | Legacy scaffold scripts, kept for reference |
