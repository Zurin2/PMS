---
title: STUDY GUIDE — PMS Inventory Tracking & Delivery Monitoring System
tags:
  - project
  - study-guide
  - presentation
  - ads
  - php
  - mysql
---
# 📚 STUDY GUIDE — PMS Inventory Tracking & Delivery Monitoring System

> A complete, plain-English reference to study before a system presentation or defense. 
> Written clearly with plain explanations alongside any technical terms, so anyone can understand how the software works.

---

## 1. Quick Summary (Memorize This)

PMS is a web-based tracking system built for a school and office supplies trading business. Its main job is two-fold:
1. **Track physical goods**: Recording when stock arrives from suppliers, when it leaves during deliveries, and correcting stock when manual warehouse counts differ from system counts.
2. **Track money and customer debt**: Monitoring customer invoices, tracking partial or full payments, and flagging outstanding balances.

**How it works under the hood:**
The application uses pure PHP on the backend, interactive web pages in the browser without bulky external frameworks, and a MariaDB/MySQL database. Instead of trusting user input or letting calculation errors creep in, the database itself strictly enforces the math and business rules.

**The Golden Formula of Inventory:**
$$\text{Beginning Stock} + \text{Stock In} - \text{Stock Out (Deliveries)} \pm \text{Adjustments} = \text{Ending Stock}$$

---

## 2. Architecture at a Glance (Plain English)

```text
┌─ User's Web Browser ────────────────────────────────────┐
│  Web pages (.html) + Frontend script (js/api.js)        │
│  - Displays screens, buttons, tables, and forms.        │
│  - Sends and receives plain data (JSON) to the backend. │
└──────────────────────────┬──────────────────────────────┘
                           │ Network requests (Fetch)
┌──────────────────────────▼──────────────────────────────┐
│  Backend Server (api/*.php)                             │
│  - Checks if user is securely logged in.                │
│  - Validates user input (e.g., checks valid numbers).   │
│  - Talks safely to the database.                        │
└──────────────────────────┬──────────────────────────────┘
                           │ Safe Database Queries (PDO)
┌──────────────────────────▼──────────────────────────────┐
│  Database (MariaDB - pms_inventory)                     │
│  - Automatic Calculation Views: Computes stock & debt.  │
│  - Safety Triggers: Prevents negative stock & overpay.  │
│  - Stored Procedure: Locks rows to record payments.     │
└─────────────────────────────────────────────────────────┘
```

**Key sentence for your defense:**
*"The system never relies solely on the user's browser. Every action is checked twice: first by the backend web code for immediate user feedback, and second by the database itself so no invalid record can ever be saved."*

---

## 3. Directory Tour (What File to Open While Presenting)

| File or Folder | What It Does (Plain English) |
| :--- | :--- |
| `sql/01_schema.sql` | Blueprint of the 15 database tables organizing items, customers, deliveries, and payments. |
| `sql/02_views.sql` | Automated calculation rules that compute live stock levels and unpaid customer balances. |
| `sql/03_triggers.sql` | Automatic safety rules that instantly reject invalid actions (like delivering items not in stock) and log history. |
| `sql/06_procedures.sql` | A secure, all-or-nothing database script that safely records payments and prevents double-entry conflicts. |
| `sql/07_sample_data.sql` | Clean demo records for testing and presentation without corrupting real data. |
| `config/db.php` | The database connection file that links PHP to MariaDB securely. |
| `api/_bootstrap.php` | The core gatekeeper: checks login sessions and formats friendly error messages. |
| `api/*.php` | Backend endpoints (13 files): each handles actions for one part of the business (items, deliveries, payments, etc.). |
| `js/api.js` | Helper script in the browser to send requests and kick unauthorized users back to login. |
| `js/ui.js`, `css/app.css` | Styling and interactive controls (modals, mobile navigation drawer, notifications). |
| `*.html` | The user screens: clean, responsive HTML pages with no heavy third-party framework overhead. |
| `scripts/smoke.ps1` | An automated test script running 24 automated checks to prove the entire system works without bugs. |
| `API_SPEC.md` | Reference guide detailing what data each backend endpoint expects and returns. |
| `README.md` | Setup steps to install and run the project on any computer using XAMPP. |
| `CHANGES.md` | Log explaining past bug fixes and code improvements. |

---

## 4. Database Design (Open `sql/01_schema.sql`)

### 4.1 Parent and Specific Child Tables (Supertype / Subtype Pattern)
Instead of forcing different types of customers or orders into one messy table with lots of empty blank cells, the system cleanly separates general information from specific information:

- **Customers:**
  - `customer` (General Table): Holds shared details like customer name, address, and classification.
  - `school` (Specific Table): Holds school-only details (school grade level, principal/head name).
  - `office` (Specific Table): Holds office-only details (office type, company contact person).
- **Deliveries (Orders):**
  - `delivery` (General Table): Holds general delivery information (receipt number, delivery date, status).
  - `direct_delivery` (Specific Table): Used when a customer orders directly with a Purchase Order (holds PO number and date).
  - `bidding_delivery` (Specific Table): Used when won through a formal government/commercial bid (holds bid reference number and deadline).

### 4.2 Clean Structure (Normalization)
1. **No Duplicated Data:** Customer details are stored once in the customer table. Deliveries simply link to the customer ID rather than re-typing names and addresses.
2. **Multiple Contact Numbers:** Stored in a dedicated contact table (`customer_contact`) so each client can have multiple phone numbers or emails without wasting columns.
3. **Price History Log:** When an item's selling price changes, the old price is never overwritten. Instead, the date and new price are appended into `item_price_history`.

### 4.3 Built-in Database Guardrails
- **Positive Number Checks:** Quantities and payment amounts must be greater than zero.
- **Strict Allowed Options:** Fields like delivery status can only be set to predefined choices (`PENDING`, `DELIVERED`, `CANCELLED`).
- **Deletion Safeguards:** You cannot accidentally delete a customer or an item if there are existing delivery or payment records linked to it.

---

## 5. Live Calculations: The Stock Ledger (Open `sql/02_views.sql`)

A key strength of this system is: **Stock is calculated on the fly, never typed in or manually overwritten.** 

Three automated database queries (Views) maintain this:
1. **`v_stock_movement` (The Master Transaction Stream):** Combines incoming stock (+), outgoing completed deliveries (-), and warehouse recount adjustments (+ or -) into a single chronological timeline.
2. **`v_item_stock` (Current Stock On Hand):** Adds up all transactions from the stream for each item. If an item was never stocked, it cleanly shows `0`. Every screen in the app reads its inventory level from here.
3. **`v_delivery_balance` (Unpaid Balance Tracker):** Calculates `Total Delivery Cost − Total Payments Made = Remaining Balance`. This powers debt badges, payment forms, and dashboard financial counters automatically.

---

## 6. Safety Triggers: Rules That Cannot Be Bypassed (Open `sql/03_triggers.sql`)

Triggers are automated database rules that run immediately whenever data is inserted, updated, or deleted.

### Business Rules (Runs BEFORE the action saves; cancels immediately if invalid)
| Trigger Name | When It Runs | What Rule It Enforces |
| :--- | :--- | :--- |
| `trg_di_stock_check` | Adding an item to a delivery | **Prevents negative inventory**: Blocks the delivery if requested quantity exceeds current stock. |
| `trg_si_delete_guard` | Deleting a stock-in record | Blocks deletion if removing that historical batch would drop current stock below zero. |
| `trg_bd_office_only` | Creating a bidding delivery | Ensures bidding contracts can only be assigned to registered commercial/government offices. |
| `trg_payment_no_overpay` | Recording a payment | **Prevents overpayment**: Blocks any payment amount larger than the remaining unpaid balance. |
| `trg_item_price_ins` / `trg_item_price_log` | Creating or updating an item | Automatically saves a timestamped record in the price history table whenever prices change. |

### Audit Trail (Runs AFTER the action saves; records historical evidence)
| Trigger Name | What It Records |
| :--- | :--- |
| `trg_si_audit` | Logs a `STOCK_IN` entry with date, quantity, and balance snapshot. |
| `trg_di_audit` | Logs a `STOCK_OUT` entry when items leave for delivery. |
| `trg_delivery_cancel_audit` | If a delivered order is cancelled, automatically restores stock and logs a `CANCELLATION` entry. |
| `trg_sa_audit` | Logs manual warehouse recount differences (`ADJUSTMENT`) to keep audit records transparent. |

---

## 7. Secure Payments: Stored Procedure (Open `sql/06_procedures.sql`)

When money is involved, two people clicking "Pay" at the same moment must never corrupt the balance. The system uses a dedicated database script called `sp_record_delivery_payment`.

**Step-by-step payment flow:**
1. **Starts an All-or-Nothing Transaction:** Either every step succeeds, or nothing is saved.
2. **Locks the Row (Row Lock):** Freezes the target delivery record for a fraction of a second so other requests must wait their turn. This stops double-clicks from double-charging.
3. **Validates the Numbers:** Checks that the delivery exists, is already marked as `DELIVERED`, and that the payment does not exceed the remaining debt.
4. **Saves Payment & Calculates Balance:** Records the payment receipt and returns the updated remaining balance immediately.
5. **Finishes (Commit):** Saves the changes permanently and unlocks the row.

---

## 8. Backend API Standards (Open `api/_bootstrap.php` and `api/payments.php`)

All 13 backend endpoints follow a clean, consistent pattern:
1. **Security First:** Checks that the user is logged in before reading or writing any data. Unauthenticated requests are rejected immediately.
2. **Safe Database Access (Prepared Statements):** Uses parameterized database queries. User input is never glued directly into SQL commands, providing total protection against SQL Injection hacking attacks.
3. **Clean Feedback:** If the database rejects an action (such as trying to deliver more stock than available), the backend catches the error and sends back a helpful, friendly message rather than a confusing crash screen.

---

## 9. Frontend Experience (Open `js/api.js`, `login.html`, `dashboard.html`)

- **Fast and Lightweight:** Built with standard HTML5, CSS3, and JavaScript. Loads instantly with no heavy frameworks or downloads required.
- **Mobile and Tablet Friendly:** On smartphones or smaller screens, the sidebar neatly hides into a slide-out drawer menu, and large data tables adapt smoothly.
- **Session Protection:** If the user's login expires while they are working, the app automatically redirects them to the login screen instead of showing broken buttons.

---

## 10. Common Business Flows (Rehearse Aloud)

1. **Receiving New Stock from Suppliers:**
   - Go to Stock In $\rightarrow$ pick item and enter quantity received $\rightarrow$ stock updates automatically.
2. **Creating a Customer Delivery:**
   - Go to Deliveries $\rightarrow$ choose customer (School or Office) and select items.
   - If someone tries to order 50 boxes when only 20 are in stock, the system blocks the order with a clear alert.
3. **Collecting Customer Payment:**
   - Go to Payments $\rightarrow$ select the unpaid delivery $\rightarrow$ enter payment amount.
   - The remaining balance drops instantly, and the delivery balance badge changes to "PAID" once settled.
4. **Periodic Physical Inventory Count:**
   - Staff count boxes on warehouse shelves $\rightarrow$ enter counts on the Count screen.
   - If physical count differs from system count, an adjustment record logs the difference, bringing the system into exact agreement with reality.
5. **Cancelling a Delivery:**
   - Changing status to Cancelled automatically returns the delivered goods back to available stock.

---

## 11. Panel Defense Q&A (Common Questions & Clear Answers)

| # | Question | Clear, Plain-English Answer |
| :- | :--- | :--- |
| 1 | **Why build this with pure PHP and JavaScript instead of frameworks like Laravel or React?** | To demonstrate pure, foundational programming competence. Every security check, database query, and interface interaction is written clearly without hiding behind black-box third-party code. |
| 2 | **How is SQL Injection prevented?** | By strictly using PDO Prepared Statements. User input is treated strictly as data, never as executable SQL code. |
| 3 | **How do you guarantee inventory will never show negative numbers?** | A database trigger (`trg_di_stock_check`) validates stock levels before any delivery item is saved. Even if someone tries to insert invalid data directly into the database, it will be rejected. |
| 4 | **Where is total stock-on-hand stored?** | It is not stored in a static column. It is dynamically calculated using a database View (`v_item_stock`) from historical stock-in, deliveries, and adjustments. This eliminates calculation mismatches. |
| 5 | **What stops two users from overpaying a delivery at the same time?** | The stored procedure (`sp_record_delivery_payment`) locks the delivery row while processing the payment, ensuring transactions process one by one safely. |
| 6 | **What happens if a delivery is cancelled?** | The cancellation trigger restores the items back into available stock and writes a clear audit entry documenting why stock returned. |
| 7 | **How do you verify the system works before presenting?** | By running the automated test script (`scripts/smoke.ps1`), which executes 24 end-to-end tests across all features and confirms all tests pass. |

---

## 12. 5-Minute Live Demonstration Checklist

- [ ] **Step 1: Sign In** — Log in with the owner account and highlight the live KPI summary cards on the Dashboard.
- [ ] **Step 2: Items & Low Stock** — View the catalog, show stock-on-hand, and open an item's Stock Card to view its transaction history.
- [ ] **Step 3: Trigger Demo (Negative Stock Block)** — Attempt to create a delivery with a quantity higher than available stock. Point out the friendly error message blocked by the database.
- [ ] **Step 4: Valid Delivery** — Deliver a valid quantity and show stock decreasing in real-time.
- [ ] **Step 5: Payment Demo (Overpayment Block)** — Attempt to pay more than the outstanding debt to show the rejection, then record a valid partial payment.
- [ ] **Step 6: Reports** — Show the automated inventory movement report matching the formula: `Beginning + In - Out ± Adj = Ending`.
- [ ] **Step 7: Automated Test Proof** — Run `powershell -ExecutionPolicy Bypass -File scripts\smoke.ps1` in the terminal to display 24/24 passing tests.
