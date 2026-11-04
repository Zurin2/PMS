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
| Database | MariaDB/MySQL 8 (InnoDB, utf8mb4_unicode_ci, Views, Triggers) |
| Backend | PHP 8+ (PDO prepared statements, sessions, REST-style JSON API) |
| Frontend | Vanilla HTML5 / CSS3 / JavaScript (fetch API) |
| Server | XAMPP (Apache + MySQL), `C:\xampp\htdocs\PMS` |

## Project Structure
```
PMS/
├── sql/                      # Database (already imported: pms_inventory)
│   ├── 01_schema.sql         # 15-table schema (3NF/4NF, supertype/subtype)
│   ├── 02_views.sql          # v_stock_movement, v_item_stock, v_delivery_balance
│   ├── 03_triggers.sql       # 6 business-rule triggers (stock guard, overpay, office-only…)
│   ├── 04_seed.sql           # Default categories + owner account
│   └── 05_reports.sql        # Analytical queries
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
└── README.md
```

## Setup Instructions

### 1. XAMPP
Start **Apache** and **MySQL** from the XAMPP control panel.

### 2. Load the database (only if `pms_inventory` does not exist)
phpMyAdmin (`http://localhost/phpmyadmin`), run in order:
```
sql/01_schema.sql
sql/02_views.sql
sql/03_triggers.sql
sql/04_seed.sql
```

### 3. Owner password
The system is single-user (row `owner` in `user_account`, `CHECK (user_id = 1)`).
Generate a bcrypt hash and update the row:
```
php -r "require 'C:/xampp/htdocs/PMS/config/db.php'; db()->prepare('UPDATE user_account SET password_hash=? WHERE user_id=1')->execute([password_hash('YOUR_NEW_PASSWORD', PASSWORD_DEFAULT)]);"
```

### 4. Access
```
http://localhost/PMS/login.html
```

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
