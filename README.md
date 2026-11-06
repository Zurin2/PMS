# PMS Inventory Tracking & Delivery Monitoring System
# ADS PMS Trading — WMSU Project

---

## Overview
Zero-framework PHP/MySQL web application for inventory tracking and delivery monitoring
for PMS School and Office Supplies Trading. Single-owner, single-branch, local deployment
on XAMPP. Receipt export: printable PDF via browser print CSS (DOCX/PPTX deferred).

## Stack
| Layer | Technology |
| :--- | :--- |
| Database | MariaDB/MySQL 8 (InnoDB, utf8mb4_unicode_ci, Views, Triggers, Stored Procedures) |
| Backend | PHP 8+ (PDO prepared statements, sessions, REST-style JSON API) |
| Frontend | Vanilla HTML5 / CSS3 / JavaScript (fetch API) |
| Server | XAMPP (Apache + MySQL), `C:\xampp\htdocs\PMS` |

## Project Structure
```
PMS/
├── sql/                      # Load into the "pms_inventory" DB in numbered order
│   ├── 01_schema.sql         # 15-table schema (3NF/4NF, supertype/subtype)
│   ├── 02_views.sql          # v_stock_movement, v_item_stock, v_delivery_balance
│   ├── 03_triggers.sql       # 10 triggers: 6 business rules + 4 stock-audit (idempotent)
│   ├── 04_seed.sql           # Default categories + owner account
│   ├── 05_reports.sql        # Analytical queries
│   ├── 06_procedures.sql     # sp_record_delivery_payment (atomic, row-locked payments)
│   └── 07_sample_data.sql    # Optional sample/demo dataset (items, stock, deliveries, payments) — safe to re-run
├── config/db.php             # PDO singleton collation-pinned (utf8mb4_unicode_ci)
├── api/                      # 13 JSON endpoints (see API_SPEC.md for the full contract)
│   ├── _bootstrap.php        # Session, JSON responders, SQLSTATE->409 mapper
│   ├── auth.php              # login / me / logout
│   ├── items.php             # CRUD + archive + price history (triggers)
│   ├── categories.php        # Category CRUD
│   ├── customers.php         # customer + school/office subtype + contacts (transactional)
│   ├── stock_in.php          # Stock receipts (+ delete guard via trigger)
│   ├── stock_card.php        # Movement ledger with running balance
│   ├── deliveries.php        # Atomic delivery transactions (DIRECT/BIDDING subtypes)
│   ├── payments.php          # Payment recording (overpay guard)
│   ├── adjustments.php       # Physical count variance records
│   ├── dashboard.php         # KPI aggregates
│   ├── reports.php           # matrix / summary / revenue periods
│   └── receipt.php           # Printable HTML delivery receipt (auto window.print())
├── css/app.css               # Full design system (single source of UI classes)
├── js/                       # api.js (wb client), ui.js (shell/toasts/modals), per-page JS
├── scripts/
│   ├── smoke.ps1             # 24-test live HTTP smoke suite (PowerShell + curl.exe)
│   ├── _pw.php               # Temp owner-password helper used by smoke.ps1
│   ├── api_tests.sh          # Legacy cURL tests (bash)
│   └── verify.php            # Legacy verifier
├── login.html                # Auth page
├── dashboard.html            # Live KPIs + low stock
├── items.html                # Inventory catalog & low-stock tags
├── stock_in.html             # Restock logger
├── stock_card.html           # Digital stock card ledger
├── customers.html            # Schools/Offices directory (subtypes + contacts)
├── deliveries.html           # Delivery log, balances, receipts
├── delivery_new.html         # Order fulfilment (Direct/Bidding)
├── payments.html             # Collection entry (unpaid delivery focus)
├── count.html                # Physical count / variance adjustments
├── reports.html              # Matrix & revenue analytics
├── index.html                # Entry redirect -> dashboard
├── CHANGES.md                # Change log (latest: 2026-11-06 views/triggers/procedures pass)
├── STUDY_GUIDE.md            # Presentation prep: flows, trigger/procedure walkthrough, Q&A
└── README.md
```

## Setup Instructions (New User / Groupmate Guide)

### 0. Requirements
- Windows laptop with [XAMPP](https://www.apachefriends.org/) (**8.0 or newer** — includes PHP 8+, Apache, and MariaDB 10.4+).
- Any modern browser (Chrome/Edge/Firefox).
- Nothing else needs installing — the app is zero-framework.

### 1. Copy the project to your laptop
Copy the whole `PMS` folder (zip it, share it, USB, however) and place it **exactly** here:
```
C:\xampp\htdocs\PMS
```
`htdocs` is XAMPP's web root, so the folder name must be `PMS` right under it (no nested
`PMS/PMS`). If XAMPP lives on another drive, use `D:\xampp\htdocs\PMS` and keep everything
relative — no config changes are needed.

### 2. Check `config/db.php`
This file holds the database connection and is the **only file not in Git** (it is in `.gitignore`).
- If you received a zip/folder copy, it is already included — do nothing.
- If you cloned from Git and it is missing, create `config\db.php` with:
```php
<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Manila');
final class Database
{
    private static ?PDO $pdo = null;
    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $dsn = 'mysql:host=127.0.0.1;port=3306;dbname=pms_inventory;charset=utf8mb4';
            self::$pdo = new PDO($dsn, 'root', '', [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
            ]);
        }
        return self::$pdo;
    }
}
function db(): PDO
{
    return Database::pdo();
}
```

### 3. Start XAMPP
Open the **XAMPP Control Panel** and press **Start** on both:
- **Apache**
- **MySQL**

Both should turn green. If MySQL refuses to start, see Troubleshooting below.

### 4. Import the database (once, on a fresh laptop)
The app expects a database named `pms_inventory`. Load the 6 SQL files **in numbered order**.

**Option A — phpMyAdmin (easiest):** open `http://localhost/phpmyadmin` → *New* → create
database name `pms_inventory`, collation `utf8mb4_unicode_ci` → select it → *Import* tab →
upload each file in order: `01_schema.sql`, `02_views.sql`, `03_triggers.sql`, `04_seed.sql`,
`05_reports.sql`, `06_procedures.sql`.

**Option B — command line (paste all in one go):**
```
"C:\xampp\mysql\bin\mysql.exe" -u root -e "source C:/xampp/htdocs/PMS/sql/01_schema.sql; source C:/xampp/htdocs/PMS/sql/02_views.sql; source C:/xampp/htdocs/PMS/sql/03_triggers.sql; source C:/xampp/htdocs/PMS/sql/04_seed.sql; source C:/xampp/htdocs/PMS/sql/05_reports.sql; source C:/xampp/htdocs/PMS/sql/06_procedures.sql;"
```
The trigger script is idempotent (`DROP TRIGGER IF EXISTS` guards), so re-running it is safe.

### Quick sanity check in phpMyAdmin: 15 tables + `item_stock_audit`, 3 views, 10 triggers, 1 procedure.

**Optional — sample demo data:** to see populated screens right away (instead of starting
empty), also run `07_sample_data.sql` (same Import tab / append its `source` to the CLI
command). It adds 14 real-product items, 7 Zamboanga-area customers (3 schools, 4 offices),
opening stock, 9 deliveries (2 fully paid, 3 partial, 2 unpaid, 1 cancelled), collections,
and 3 physical-count adjustments — all guarded, so re-running never duplicates or clobbers
data you entered yourself. To undo the demo data: delete the deliveries whose
`receipt_no` is `DR-2026-0101` … `DR-2026-0109`, and stock-in / payment / adjustment rows
marked `sample data` / `sample collection` / `PERIODIC COUNT (sample data)`.

### 5. Owner password
The system is single-user (row `owner` in `user_account`, `CHECK (user_id = 1)`). The seeded
login comes from `04_seed.sql` — to set your own password, run (XAMPP shell or terminal):
```
php -r "require 'C:/xampp/htdocs/PMS/config/db.php'; db()->prepare('UPDATE user_account SET password_hash=? WHERE user_id=1')->execute([password_hash('YOUR_NEW_PASSWORD', PASSWORD_DEFAULT)]);"
```

### 6. Access
```
http://localhost/PMS/login.html
```

### Troubleshooting
| Symptom | Fix |
| :--- | :--- |
| MySQL turns yellow / "Error 10061" when importing | Port 3306 already used (often by Skype or another MySQL). In XAMPP Config → `my.ini`, change port or stop the conflicting service, then Start MySQL again. |
| `Access denied for user 'root'@'localhost'` | XAMPP's default MySQL root has an empty password — confirm the copy is XAMPP's MySQL, not an independent installation. |
| Blank page / `CALL sp_record_delivery_payment` fails | `06_procedures.sql` was not imported — repeat Step 4 (all 6 files, in order). |
| "Illegal mix of collations" | You skipped `01_schema.sql` — the schema pins `utf8mb4_unicode_ci`; re-import from scratch (drop the DB first). |
| Login loop / generic 500 | `config/db.php` missing or wrong path — see Step 2. |

## Verification
Run the live smoke suite (expects Apache + MySQL running):
```
powershell -ExecutionPolicy Bypass -File C:\xampp\htdocs\PMS\scripts\smoke.ps1
```
24 checks: auth (401/200/me), item CRUD + duplicate 409 + price history trigger,
stock-in, stock card running balances, insufficient stock 409, delivery transaction,
overpay 409, payment, cancel guard 409, physical count adjustment, dashboard,
reports (matrix/summary), receipt HTML, category delete restriction 409.
The suite temporarily swaps the owner password and restores the original hash afterwards.

Stock invariant enforced end-to-end: `Beginning + In − Out + Adj == Ending`.

## Security Notes
- `config/db.php` is excluded from Git (`.gitignore`).
- Replace `root` with a limited DB user before any non-local deployment.
- Passwords are bcrypt via `password_hash()` / `password_verify()`.
