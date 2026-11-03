-- =============================================================
-- PMS Inventory Tracking & Delivery Monitoring System
-- File: sql/02_views.sql
-- Description: Analytical views for stock and delivery balance
-- =============================================================

USE pms_inventory;

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

-- Current stock on hand per item
CREATE VIEW v_item_stock AS
SELECT i.item_id, i.item_code, i.name, c.name AS category, i.unit, i.unit_price, i.low_stock_level, i.status,
       COALESCE(m.soh, 0) AS stock_on_hand
FROM item i 
JOIN category c ON c.category_id = i.category_id
LEFT JOIN (SELECT item_id, SUM(qty) AS soh FROM v_stock_movement GROUP BY item_id) m ON m.item_id = i.item_id;

-- Delivery totals and outstanding balance per delivery
CREATE VIEW v_delivery_balance AS
SELECT d.delivery_id, d.receipt_no, d.customer_id, d.delivery_date, d.status,
       COALESCE(t.total, 0) AS total_amount, 
       COALESCE(p.paid, 0) AS total_paid,
       COALESCE(t.total, 0) - COALESCE(p.paid, 0) AS balance
FROM delivery d
LEFT JOIN (SELECT delivery_id, SUM(quantity * unit_price) AS total FROM delivery_item GROUP BY delivery_id) t ON t.delivery_id = d.delivery_id
LEFT JOIN (SELECT delivery_id, SUM(amount) AS paid FROM payment GROUP BY delivery_id) p ON p.delivery_id = d.delivery_id;
