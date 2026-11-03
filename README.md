# PMS Inventory Tracking & Delivery Monitoring System
# ADS PMS Trading – WMSU Project

---

## Overview
PHP/MySQL web application for tracking inventory and delivery monitoring for PMS Trading.
Built for local deployment on XAMPP (PHP + MySQL).

## Stack
| Layer | Technology |
| :--- | :--- |
| Database | MySQL 8 (InnoDB, Views, Triggers) |
| Backend | PHP 8+ (PDO, sessions, REST-style JSON API) |
| Frontend | Vanilla HTML5 / CSS3 / JavaScript (fetch API) |
| Server | XAMPP (Apache + MySQL) |

## Project Structure
```
ADS PMS TRADING INVENTORY SYSTEM/
├── sql/
│   ├── 01_schema.sql       # Full database schema
│   ├── 02_views.sql        # v_item_stock, v_stock_movement, v_delivery_balance
│   ├── 03_triggers.sql     # Business rule triggers
│   ├── 04_seed.sql         # Default categories + owner account
│   └── 05_reports.sql      # Analytical queries
├── config/
│   └── db.php              # PDO singleton (keep out of Git)
├── api/
│   ├── _bootstrap.php      # Session, headers, helpers
│   ├── auth.php            # Login / logout / me
│   ├── items.php           # Inventory items CRUD
│   ├── categories.php      # Category CRUD
│   ├── stock_in.php        # Receive stock
│   ├── deliveries.php      # Create & view deliveries
│   ├── customers.php       # Customer (School/Office) CRUD
│   ├── payments.php        # Record delivery payments
│   └── reports.php         # Analytical reports endpoint
├── js/
│   ├── api.js              # Fetch wrapper
│   ├── auth.js             # Login form handler
│   └── items.js            # Items table renderer
├── public/
│   └── css/style.css       # Base stylesheet
├── scripts/
│   └── api_tests.sh        # cURL smoke tests
├── login.html              # Login page
├── index.html              # Dashboard
├── items.html              # Items management page
└── README.md
```

## Setup Instructions

### 1. Install XAMPP
Download from https://www.apachefriends.org and start **Apache** and **MySQL**.

### 2. Deploy files
Copy this folder to: `C:\xampp\htdocs\pms\`

### 3. Load the database
Open phpMyAdmin (`http://localhost/phpmyadmin`) and run SQL files in order:
```
sql/01_schema.sql
sql/02_views.sql
sql/03_triggers.sql
sql/04_seed.sql
```

### 4. Set the owner password
Run in phpMyAdmin or terminal:
```php
php -r "echo password_hash('your_password_here', PASSWORD_DEFAULT);"
```
Then update `sql/04_seed.sql` with the generated hash before importing.

### 5. Access the system
Open your browser: `http://localhost/pms/login.html`

---

## API Reference

| Endpoint | Methods | Description |
| :--- | :--- | :--- |
| `api/auth.php` | POST (login), GET (logout, me) | Authentication |
| `api/items.php` | GET, POST, PUT, DELETE | Inventory items |
| `api/categories.php` | GET, POST, PUT, DELETE | Item categories |
| `api/stock_in.php` | GET, POST, DELETE | Stock receipts |
| `api/deliveries.php` | GET, POST, PUT | Deliveries |
| `api/customers.php` | GET, POST, DELETE | Customers |
| `api/payments.php` | GET, POST | Delivery payments |
| `api/reports.php` | GET | Inventory movement, balance, low stock |

## Security Notes
- `config/db.php` must be excluded from any public repository (`.gitignore`).
- Replace the default `root` user with a limited DB user before any non-local deployment.
- Passwords are stored as bcrypt hashes via PHP `password_hash()`.
