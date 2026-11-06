---
title: PMS Trading — Business Guide & Code Architecture Blueprint
tags:
  - pms
  - business-guide
  - user-stories
  - architecture
  - documentation
---

# 🏢 PMS Trading — Business Guide & Code Architecture Blueprint

> A plain-language guide for understanding how PMS Trading operates in the real world (Part 1), 
> and how each business activity is powered by the software without technical jargon (Part 2).

---

# PART 1: The Business, Processes, and User Stories

## 1. How the Business Works (Executive Summary)

**PMS Trading** is a wholesale distributor that supplies schools and corporate/government offices with educational and office materials (e.g., paper, pens, notebooks, toner, cleaning supplies).

The business operates on three essential real-world cycles:
1. **Supply Cycle (Stock-In):** Buying goods in bulk from manufacturers and storing them in the warehouse.
2. **Sales & Distribution Cycle (Deliveries):** Delivering ordered goods to schools or offices via official receipts and purchase agreements.
3. **Credit & Collection Cycle (Payments):** Because institutions rarely pay with physical cash upfront, PMS delivers goods on credit (terms). PMS monitors what each client owes and collects checks, bank transfers, or cash over time until the balance is zero.

---

## 2. Who the Customers Are

PMS categorizes all customers into two distinct groups because they purchase differently:

| Customer Group | Who They Are | How They Buy |
| :--- | :--- | :--- |
| **Schools** | Public and private elementary, high schools, and colleges. | Handled by school heads or department heads. They typically purchase regular term supplies via standard direct orders. |
| **Offices** | Commercial companies, banks, or local government units (LGUs). | Handled by purchasing officers. They buy either through regular **Purchase Orders (POs)** or through competitive public/private **Bidding Contracts** with strict reference numbers and delivery deadlines. |

---

## 3. Core Business Processes (Step-by-Step)

```text
  [Supplier Delivers Goods]
             │
             ▼
  Step 1: Receiving Stock  ──────► (Warehouse inventory increases)
             │
             ▼
  Step 2: Customer Orders  ──────► (Check available warehouse stock)
             │
             ▼
  Step 3: Delivering Order ──────► (Warehouse inventory decreases; Customer owes money)
             │
             ▼
  Step 4: Collecting Debt  ──────► (Customer pays cash/check; Customer debt decreases)
             │
             ▼
  Step 5: Physical Count   ──────► (Compare physical boxes on shelves with records)
```

### Process 1: Receiving New Stock (Restocking)
- When a bulk shipment arrives from suppliers, the warehouse staff verifies the items and quantities.
- Staff records the incoming batch into the system with supplier reference and unit cost.
- The warehouse inventory increases immediately.

### Process 2: Creating and Fulfilling a Delivery (Sales)
- A customer orders supplies either by issuing a Purchase Order or via an approved Bidding Contract.
- Staff selects the customer and chooses items to deliver.
- **Strict Business Rule:** Staff cannot fulfill or promise items that are not physically sitting in the warehouse.
- Once marked as delivered, the stock is deducted, and a new accounts receivable (customer debt) is created.

### Process 3: Collecting Customer Payments
- Most schools and corporate clients pay on 30-day or 60-day terms. Some pay in installments.
- When a payment arrives (cash, bank transfer, check), staff issues an official payment receipt and credits the specific delivery invoice.
- **Strict Business Rule:** A customer cannot pay more than the outstanding invoice balance.
- Once the balance reaches 0.00, the delivery is marked as fully paid.

### Process 4: Physical Warehouse Recount (Inventory Audit)
- Over time, physical boxes can get damaged, miscounted, or lost.
- Periodically, warehouse staff physically walk the aisles and count every box on the shelf.
- If the physical count matches the system, nothing changes.
- If there is a discrepancy, an adjustment is logged stating the reason (e.g., damaged stock, count correction), resetting the recorded stock to match reality with full accountability.

---

## 4. User Stories (Everyday Scenarios from Real Life)

### Story 1: Receiving Bulk Inventory
> **As the** Business Owner / Warehouse Clerk,  
> **I want to** quickly record a new shipment of 200 reams of copy paper from my supplier,  
> **So that** my inventory is up to date and I know exactly how much stock I have available to sell.

### Story 2: Preventing Accidental Overselling
> **As the** Sales Clerk,  
> **I want the** system to immediately block me if I try to send 50 boxes of ballpens to a school when we only have 20 in the warehouse,  
> **So that** we never make false promises to clients or create shipping mistakes.

### Story 3: Handling Formal Bidding Contracts
> **As an** Account Manager,  
> **I want to** record a delivery under a government office's Bidding Reference Number and track its delivery deadline,  
> **So that** our company stays compliant with formal government procurement rules.

### Story 4: Tracking Customer Debt and Installment Payments
> **As the** Billing Officer,  
> **I want to** look at a delivery invoice worth ₱100,000, see that the customer paid ₱40,000 last week, and accept another ₱60,000 today,  
> **So that** the debt drops to zero and I cannot accidentally overcharge the customer.

### Story 5: Cancelling an Incorrect Delivery
> **As the** Business Owner,  
> **I want to** cancel an order if a school suddenly changes their order before the truck leaves,  
> **So that** all the items automatically return to our warehouse inventory without me having to manually recalculate every item.

### Story 6: Periodic Shelf Recounting
> **As the** Warehouse Auditor,  
> **I want to** type in the actual count of notebooks found on shelf B3 during our monthly audit,  
> **So that** any difference is recorded with a clear reason and our system matches what is physically on the shelves.

---

# PART 2: How the Business Maps to the Software Code

This section explains how each real-world activity described above is handled by the software files, written in clear, plain language.

```text
  Real-World Business Activity           Web Screen (User View)     Backend API Guard          Database Engine
  ────────────────────────────           ──────────────────────     ─────────────────          ───────────────
  1. Receiving Supplier Stock    ───►    stock_in.html       ───►   api/stock_in.php    ───►   v_item_stock (increases)
  2. Delivering to Customer      ───►    delivery_new.html   ───►   api/deliveries.php  ───►   trg_di_stock_check (blocks overselling)
  3. Customer Pays Invoice       ───►    payments.html       ───►   api/payments.php    ───►   sp_record_delivery_payment (locks & records)
  4. Recounting Warehouse Shelves───►    count.html          ───►   api/adjustments.php ───►   v_stock_movement (logs discrepancy)
  5. Executive Business Overview ───►    dashboard.html      ───►   api/dashboard.php   ───►   v_delivery_balance (unpaid debt summary)
```

---

## 1. Managing Catalog & Supplier Shipments (Stock-In)

- **What Happens in Business:** The business adds products to its catalog and records goods received from suppliers.
- **The Screen the User Sees:**
  - `items.html`: Shows product names, categories, and current warehouse stock.
  - `stock_in.html`: Form to record arrival of supplier shipments.
- **The Backend Code (`api/items.php`, `api/stock_in.php`):**
  - Verifies that quantities and prices are positive numbers.
  - Safely saves the entry into the database.
- **The Database Magic (`sql/01_schema.sql`, `sql/02_views.sql`):**
  - The tables `item` and `stock_in` store the product details and shipment receipts.
  - The live calculation view (`v_item_stock`) immediately reflects the higher stock count across all screens.

---

## 2. Delivering Orders to Schools and Offices

- **What Happens in Business:** Fulfilling customer orders either via standard Purchase Orders (Schools/Offices) or formal Bidding Contracts (Offices only).
- **The Screen the User Sees:**
  - `deliveries.html`: List of all customer deliveries, order types, and delivery statuses.
  - `delivery_new.html`: Order entry screen where staff pick items and enter quantities.
  - `receipt.php`: Clean printable invoice and delivery receipt for the truck driver and customer.
- **The Backend Code (`api/deliveries.php`):**
  - Checks if the user is authorized and wraps the entire order in a safe transaction (so if one item fails, the whole delivery is safely rolled back).
- **The Database Magic (`sql/01_schema.sql`, `sql/03_triggers.sql`):**
  - **Supertype/Subtype Tables:** General details go to `delivery`, PO details go to `direct_delivery`, and bidding contract details go to `bidding_delivery`.
  - **The Safety Trigger (`trg_di_stock_check`):** If someone tries to order 100 items when only 40 exist, the database itself rejects the save with a clear error: `"Insufficient stock"`.
  - **The Audit Trigger (`trg_di_audit`):** Logs a historical record noting exactly who, when, and how many items left the building.

---

## 3. Debt Collection and Payment Processing

- **What Happens in Business:** Customers pay down their invoices over time. Cash flow must be accurate to the exact cent, and customers must never be overcharged.
- **The Screen the User Sees:**
  - `payments.html`: Shows every delivery that has an unpaid balance, along with an instant payment entry modal.
- **The Backend Code (`api/payments.php`):**
  - Validates that the payment amount is positive and calls the database payment procedure.
- **The Database Magic (`sql/06_procedures.sql`, `sql/02_views.sql`):**
  - **The Calculation View (`v_delivery_balance`):** Automatically computes `Total Cost − Payments Made = Unpaid Balance`.
  - **The Stored Procedure (`sp_record_delivery_payment`):** Locks the delivery record for a millisecond while calculating, records the payment, and returns the new remaining balance. This guarantees that double-clicking or two clerks taking payments at the same second cannot corrupt the balance.
  - **The Overpay Guard (`trg_payment_no_overpay`):** If a customer owes ₱5,000 and the clerk accidentally types ₱50,000, the database immediately stops the payment and says: `"Payment exceeds remaining balance"`.

---

## 4. Cancelling an Order & Restoring Stock

- **What Happens in Business:** A client calls to cancel a delivery before or upon dispatch.
- **The Screen the User Sees:** The user clicks "Cancel Delivery" on `deliveries.html`.
- **The Backend Code (`api/deliveries.php`):** Changes the delivery status from `DELIVERED` to `CANCELLED`.
- **The Database Magic (`sql/03_triggers.sql`, `sql/02_views.sql`):**
  - The live stock view (`v_stock_movement`) automatically ignores cancelled orders, returning the items to available inventory.
  - The trigger (`trg_delivery_cancel_audit`) writes a timestamped cancellation note in the history log explaining that stock was restored.

---

## 5. Warehouse Audits & Physical Recounts

- **What Happens in Business:** Warehouse staff count physical items on shelves to verify system accuracy.
- **The Screen the User Sees:** `count.html` (Physical Count / Inventory Audit screen).
- **The Backend Code (`api/adjustments.php`):** Accepts the counted quantity and the reason for any discrepancy.
- **The Database Magic (`sql/02_views.sql`, `sql/03_triggers.sql`):**
  - Computes the discrepancy: $\text{Counted} - \text{System Stock} = \text{Adjustment Quantity}$.
  - Inserts the difference into `stock_adjustment`, which flows into `v_stock_movement`.
  - The new stock-on-hand immediately matches what was physically counted.

---

## 6. Executive Dashboard & Real-Time Reports

- **What Happens in Business:** The business owner wants to know total stock value, low-stock alerts, and total money owed by customers.
- **The Screen the User Sees:**
  - `dashboard.html`: High-level summary cards (Total Inventory Value, Low Stock Alert Count, Total Uncollected Receivables).
  - `reports.html`: Detailed period-by-period inventory movement breakdown.
- **The Backend Code (`api/dashboard.php`, `api/reports.php`):**
  - Reads directly from the pre-calculated database views.
- **The Database Magic (`sql/02_views.sql`):**
  - Instead of running slow, complicated calculations across thousands of individual rows every time the page loads, the database views provide clean, instant sums that keep the app fast and snappy.

---

## Summary Matrix: Business Need vs. Code Implementation

| Business Need | Real-World Risk | How the Code Solves It |
| :--- | :--- | :--- |
| **Accurate Stock Levels** | Selling items not physically present in the warehouse. | Database Trigger `trg_di_stock_check` halts any order exceeding available stock. |
| **Accurate Customer Debt** | Collecting more money than the customer owes. | Database Trigger `trg_payment_no_overpay` and procedure `sp_record_delivery_payment` stop overpayment. |
| **Simultaneous Payments** | Two clerks recording payments at once causing negative debt. | Database row-locking (`FOR UPDATE`) in `sp_record_delivery_payment` ensures payments queue up safely. |
| **Price Consistency** | Forgetting what an item cost months ago. | `trg_item_price_log` automatically preserves historical prices in `item_price_history`. |
| **Audit Accountability** | Not knowing why stock counts changed unexpectedly. | Append-only audit table `item_stock_audit` automatically logs every stock increase, decrease, or adjustment. |
