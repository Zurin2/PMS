-- =============================================================
-- PMS Inventory Tracking & Delivery Monitoring System
-- File: sql/07_sample_data.sql
-- Description: Sample/demo dataset so a fresh install shows data
--              on every screen (items, stock, deliveries, payments,
--              adjustments, audit trail). Safe to re-run: every
--              insert is guarded (INSERT IGNORE / NOT EXISTS) and
--              never touches rows not created by this file.
-- Prereq: 01-06 already loaded. Trigger-enforced rules apply
--         (stock guard, office-only bidding, no-overpay).
-- =============================================================

USE pms_inventory;

-- -------------------------
-- 1) ITEMS (categories from 04_seed.sql; price history auto-logged)
-- -------------------------
INSERT IGNORE INTO item (category_id, item_code, name, unit, unit_price, low_stock_level) VALUES
((SELECT category_id FROM category WHERE name='School Supplies'),    'SS-1010', 'Mongol Pencil No. 2',            'piece',  12.00,  50),
((SELECT category_id FROM category WHERE name='School Supplies'),    'SS-1020', 'Ballpoint Pen (Black)',          'piece',  15.00,  80),
((SELECT category_id FROM category WHERE name='School Supplies'),    'SS-1030', 'Ballpoint Pen (Blue)',           'piece',  15.00,  80),
((SELECT category_id FROM category WHERE name='School Supplies'),    'SS-1040', 'Crayons 24-Count',               'box',    65.00,  20),
((SELECT category_id FROM category WHERE name='School Supplies'),    'SS-1050', 'Pad Paper (Intermediate)',       'pad',    25.00,  60),
((SELECT category_id FROM category WHERE name='School Supplies'),    'SS-1060', 'Notebook 80s',                   'piece',  38.00, 100),
((SELECT category_id FROM category WHERE name='Office Supplies'),    'OS-2010', 'Long Bond Paper 70gsm',          'ream',  210.00,  30),
((SELECT category_id FROM category WHERE name='Office Supplies'),    'OS-2020', 'Short Bond Paper 70gsm',         'ream',  185.00,  30),
((SELECT category_id FROM category WHERE name='Office Supplies'),    'OS-2030', 'Stapler (Heavy Duty)',           'piece', 250.00,   5),
((SELECT category_id FROM category WHERE name='Office Supplies'),    'OS-2040', 'Staples No. 3',                  'box',    30.00,  20),
((SELECT category_id FROM category WHERE name='Office Supplies'),    'OS-2050', 'Liquid Paper Corrector',         'piece',  65.00,  10),
((SELECT category_id FROM category WHERE name='Office Supplies'),    'OS-2060', 'File Folder (Legal)',            'piece',  18.00, 100),
((SELECT category_id FROM category WHERE name='Janitorial Supplies'),'JS-3010', 'Broom (Soft)',                   'piece',  95.00,  10),
((SELECT category_id FROM category WHERE name='Janitorial Supplies'),'JS-3020', 'Floor Wax',                      'gallon',320.00,   8);

-- -------------------------
-- 2) CUSTOMERS (schools + offices) and contacts
-- -------------------------
INSERT IGNORE INTO customer (name, address, customer_type) VALUES
('Zamboanga Central Elementary School', 'Capt. F. Marcos St., Zamboanga City', 'SCHOOL'),
('Pilar National High School',          'R.T. Lim Blvd., Zamboanga City',      'SCHOOL'),
('Ateneo de Zamboanga University',      'La Purisima St., Zamboanga City',     'SCHOOL'),
('City Treasurer''s Office',            'City Hall Complex, Zamboanga City',   'OFFICE'),
('DepEd Zamboanga Division',            'Gov. Alvarez St., Zamboanga City',    'OFFICE'),
('Western Mindanao State University',   'Normal Rd., Baliwasan, Zamboanga City','OFFICE'),
('ZAMPEN Marketing Inc.',               'Gov. Camins Rd., Zamboanga City',     'OFFICE');

INSERT IGNORE INTO school (customer_id, school_level, head_name) VALUES
((SELECT customer_id FROM customer WHERE name='Zamboanga Central Elementary School'), 'ELEMENTARY',  'Mrs. Elvira Reyes'),
((SELECT customer_id FROM customer WHERE name='Pilar National High School'),          'JUNIOR_HIGH', 'Mr. Lorenzo Cruz'),
((SELECT customer_id FROM customer WHERE name='Ateneo de Zamboanga University'),      'COLLEGE',     'Dr. Manuel Santos');

INSERT IGNORE INTO office (customer_id, office_type, contact_person) VALUES
((SELECT customer_id FROM customer WHERE name='City Treasurer''s Office'),              'GOVERNMENT', 'Ms. Andrea Lim'),
((SELECT customer_id FROM customer WHERE name='DepEd Zamboanga Division'),              'GOVERNMENT', 'Mr. Jose Bautista'),
((SELECT customer_id FROM customer WHERE name='Western Mindanao State University'),     'GOVERNMENT', 'Ms. Rahima Abdul'),
((SELECT customer_id FROM customer WHERE name='ZAMPEN Marketing Inc.'),                 'PRIVATE',    'Ms. Christine Tan');

INSERT IGNORE INTO customer_contact (customer_id, contact_type, contact_value) VALUES
((SELECT customer_id FROM customer WHERE name='Zamboanga Central Elementary School'), 'MOBILE', '0917-310-2214'),
((SELECT customer_id FROM customer WHERE name='Zamboanga Central Elementary School'), 'EMAIL',  'zces@deped.gov.ph'),
((SELECT customer_id FROM customer WHERE name='Pilar National High School'),          'MOBILE', '0918-447-3362'),
((SELECT customer_id FROM customer WHERE name='Ateneo de Zamboanga University'),      'EMAIL',  'purchasing@adzu.edu.ph'),
((SELECT customer_id FROM customer WHERE name='City Treasurer''s Office'),            'LANDLINE','062-991-2201'),
((SELECT customer_id FROM customer WHERE name='DepEd Zamboanga Division'),            'EMAIL',  'supply@depedzamboanga.ph'),
((SELECT customer_id FROM customer WHERE name='Western Mindanao State University'),   'MOBILE', '0926-118-4409'),
((SELECT customer_id FROM customer WHERE name='ZAMPEN Marketing Inc.'),               'MOBILE', '0995-220-7731');

-- -------------------------
-- 3) OPENING STOCK (guarded per item by the remark marker)
-- -------------------------
INSERT INTO stock_in (item_id, quantity, remarks, date_received)
SELECT s.item_id, s.qty, 'initial stock (sample data)', s.dt
FROM (
    SELECT (SELECT item_id FROM item WHERE item_code='SS-1010') AS item_id, 1000 AS qty, DATE'2026-09-01' AS dt UNION ALL
    SELECT (SELECT item_id FROM item WHERE item_code='SS-1020'), 1500, DATE'2026-09-01' UNION ALL
    SELECT (SELECT item_id FROM item WHERE item_code='SS-1030'), 1000, DATE'2026-09-02' UNION ALL
    SELECT (SELECT item_id FROM item WHERE item_code='SS-1040'),  400, DATE'2026-09-03' UNION ALL
    SELECT (SELECT item_id FROM item WHERE item_code='SS-1050'),  800, DATE'2026-09-03' UNION ALL
    SELECT (SELECT item_id FROM item WHERE item_code='SS-1060'), 1200, DATE'2026-09-05' UNION ALL
    SELECT (SELECT item_id FROM item WHERE item_code='OS-2010'),  350, DATE'2026-09-08' UNION ALL
    SELECT (SELECT item_id FROM item WHERE item_code='OS-2020'),  300, DATE'2026-09-08' UNION ALL
    SELECT (SELECT item_id FROM item WHERE item_code='OS-2030'),   80, DATE'2026-09-10' UNION ALL
    SELECT (SELECT item_id FROM item WHERE item_code='OS-2040'),  500, DATE'2026-09-10' UNION ALL
    SELECT (SELECT item_id FROM item WHERE item_code='OS-2050'),  250, DATE'2026-09-12' UNION ALL
    SELECT (SELECT item_id FROM item WHERE item_code='OS-2060'), 1500, DATE'2026-09-15' UNION ALL
    SELECT (SELECT item_id FROM item WHERE item_code='JS-3010'),  150, DATE'2026-09-18' UNION ALL
    SELECT (SELECT item_id FROM item WHERE item_code='JS-3020'),  120, DATE'2026-09-18'
) s
WHERE NOT EXISTS (
    SELECT 1 FROM stock_in si WHERE si.item_id = s.item_id AND si.remarks = 'initial stock (sample data)'
);

-- -------------------------
-- 4) DELIVERIES (triggers validate stock; bidding must be OFFICE)
--    Paid pattern: 2 fully paid, 3 partial, 2 unpaid, 1 cancelled
-- -------------------------
-- D1: ZCES (school, direct) — fully paid
INSERT IGNORE INTO delivery (receipt_no, customer_id, delivery_date, order_type, status)
VALUES ('DR-2026-0101', (SELECT customer_id FROM customer WHERE name='Zamboanga Central Elementary School'), DATE'2026-10-05', 'DIRECT', 'DELIVERED');
INSERT IGNORE INTO direct_delivery (delivery_id, po_number, po_date)
SELECT delivery_id, 'PO-2026-0011', DATE'2026-10-03' FROM delivery d
WHERE d.receipt_no='DR-2026-0101' AND NOT EXISTS (SELECT 1 FROM direct_delivery dd WHERE dd.delivery_id = d.delivery_id);
INSERT INTO delivery_item (delivery_id, item_id, quantity, unit_price)
SELECT s.did, s.iid, s.qty, s.price
FROM (
    SELECT (SELECT delivery_id FROM delivery WHERE receipt_no='DR-2026-0101') AS did,
           (SELECT item_id FROM item WHERE item_code='SS-1010')               AS iid, 200 AS qty,  12.00 AS price
    UNION ALL
    SELECT (SELECT delivery_id FROM delivery WHERE receipt_no='DR-2026-0101'),
           (SELECT item_id FROM item WHERE item_code='SS-1040'),                      50,       65.00
    UNION ALL
    SELECT (SELECT delivery_id FROM delivery WHERE receipt_no='DR-2026-0101'),
           (SELECT item_id FROM item WHERE item_code='SS-1050'),                     120,       25.00
    UNION ALL
    SELECT (SELECT delivery_id FROM delivery WHERE receipt_no='DR-2026-0101'),
           (SELECT item_id FROM item WHERE item_code='SS-1060'),                     150,       38.00
) s
WHERE NOT EXISTS (SELECT 1 FROM delivery_item di WHERE di.delivery_id = s.did AND di.item_id = s.iid);

-- D2: Pilar NHS (school, direct) — partial payment
INSERT IGNORE INTO delivery (receipt_no, customer_id, delivery_date, order_type, status)
VALUES ('DR-2026-0102', (SELECT customer_id FROM customer WHERE name='Pilar National High School'), DATE'2026-10-08', 'DIRECT', 'DELIVERED');
INSERT IGNORE INTO direct_delivery (delivery_id, po_number, po_date)
SELECT delivery_id, 'PO-2026-0014', DATE'2026-10-06' FROM delivery d
WHERE d.receipt_no='DR-2026-0102' AND NOT EXISTS (SELECT 1 FROM direct_delivery dd WHERE dd.delivery_id = d.delivery_id);
INSERT INTO delivery_item (delivery_id, item_id, quantity, unit_price)
SELECT s.did, s.iid, s.qty, s.price
FROM (
    SELECT (SELECT delivery_id FROM delivery WHERE receipt_no='DR-2026-0102') AS did,
           (SELECT item_id FROM item WHERE item_code='SS-1020')               AS iid, 300 AS qty,  15.00 AS price
    UNION ALL
    SELECT (SELECT delivery_id FROM delivery WHERE receipt_no='DR-2026-0102'),
           (SELECT item_id FROM item WHERE item_code='SS-1050'),                     200,       25.00
    UNION ALL
    SELECT (SELECT delivery_id FROM delivery WHERE receipt_no='DR-2026-0102'),
           (SELECT item_id FROM item WHERE item_code='SS-1060'),                     250,       38.00
) s
WHERE NOT EXISTS (SELECT 1 FROM delivery_item di WHERE di.delivery_id = s.did AND di.item_id = s.iid);

-- D3: City Treasurer (office, direct) — half paid
INSERT IGNORE INTO delivery (receipt_no, customer_id, delivery_date, order_type, status)
VALUES ('DR-2026-0103', (SELECT customer_id FROM customer WHERE name='City Treasurer''s Office'), DATE'2026-10-12', 'DIRECT', 'DELIVERED');
INSERT IGNORE INTO direct_delivery (delivery_id, po_number, po_date)
SELECT delivery_id, 'PO-2026-0017', DATE'2026-10-10' FROM delivery d
WHERE d.receipt_no='DR-2026-0103' AND NOT EXISTS (SELECT 1 FROM direct_delivery dd WHERE dd.delivery_id = d.delivery_id);
INSERT INTO delivery_item (delivery_id, item_id, quantity, unit_price)
SELECT s.did, s.iid, s.qty, s.price
FROM (
    SELECT (SELECT delivery_id FROM delivery WHERE receipt_no='DR-2026-0103') AS did,
           (SELECT item_id FROM item WHERE item_code='OS-2010')               AS iid,  60 AS qty, 210.00 AS price
    UNION ALL
    SELECT (SELECT delivery_id FROM delivery WHERE receipt_no='DR-2026-0103'),
           (SELECT item_id FROM item WHERE item_code='OS-2020'),                      40,      185.00
    UNION ALL
    SELECT (SELECT delivery_id FROM delivery WHERE receipt_no='DR-2026-0103'),
           (SELECT item_id FROM item WHERE item_code='OS-2040'),                      30,       30.00
    UNION ALL
    SELECT (SELECT delivery_id FROM delivery WHERE receipt_no='DR-2026-0103'),
           (SELECT item_id FROM item WHERE item_code='OS-2060'),                     300,       18.00
) s
WHERE NOT EXISTS (SELECT 1 FROM delivery_item di WHERE di.delivery_id = s.did AND di.item_id = s.iid);

-- D4: DepEd (office, bidding) — UNPAID
INSERT IGNORE INTO delivery (receipt_no, customer_id, delivery_date, order_type, status)
VALUES ('DR-2026-0104', (SELECT customer_id FROM customer WHERE name='DepEd Zamboanga Division'), DATE'2026-10-16', 'BIDDING', 'DELIVERED');
INSERT IGNORE INTO bidding_delivery (delivery_id, bid_ref_no, delivery_deadline)
SELECT delivery_id, 'BID-2026-045', DATE'2026-10-14' FROM delivery d
WHERE d.receipt_no='DR-2026-0104' AND NOT EXISTS (SELECT 1 FROM bidding_delivery bd WHERE bd.delivery_id = d.delivery_id);
INSERT INTO delivery_item (delivery_id, item_id, quantity, unit_price)
SELECT s.did, s.iid, s.qty, s.price
FROM (
    SELECT (SELECT delivery_id FROM delivery WHERE receipt_no='DR-2026-0104') AS did,
           (SELECT item_id FROM item WHERE item_code='OS-2010')               AS iid, 100 AS qty, 210.00 AS price
    UNION ALL
    SELECT (SELECT delivery_id FROM delivery WHERE receipt_no='DR-2026-0104'),
           (SELECT item_id FROM item WHERE item_code='OS-2060'),                     500,       18.00
    UNION ALL
    SELECT (SELECT delivery_id FROM delivery WHERE receipt_no='DR-2026-0104'),
           (SELECT item_id FROM item WHERE item_code='JS-3020'),                      20,      320.00
) s
WHERE NOT EXISTS (SELECT 1 FROM delivery_item di WHERE di.delivery_id = s.did AND di.item_id = s.iid);

-- D5: Ateneo (school, direct) — fully paid
INSERT IGNORE INTO delivery (receipt_no, customer_id, delivery_date, order_type, status)
VALUES ('DR-2026-0105', (SELECT customer_id FROM customer WHERE name='Ateneo de Zamboanga University'), DATE'2026-10-21', 'DIRECT', 'DELIVERED');
INSERT IGNORE INTO direct_delivery (delivery_id, po_number, po_date)
SELECT delivery_id, 'PO-2026-0021', DATE'2026-10-19' FROM delivery d
WHERE d.receipt_no='DR-2026-0105' AND NOT EXISTS (SELECT 1 FROM direct_delivery dd WHERE dd.delivery_id = d.delivery_id);
INSERT INTO delivery_item (delivery_id, item_id, quantity, unit_price)
SELECT s.did, s.iid, s.qty, s.price
FROM (
    SELECT (SELECT delivery_id FROM delivery WHERE receipt_no='DR-2026-0105') AS did,
           (SELECT item_id FROM item WHERE item_code='OS-2020')               AS iid,  50 AS qty, 185.00 AS price
    UNION ALL
    SELECT (SELECT delivery_id FROM delivery WHERE receipt_no='DR-2026-0105'),
           (SELECT item_id FROM item WHERE item_code='OS-2040'),                      25,       30.00
    UNION ALL
    SELECT (SELECT delivery_id FROM delivery WHERE receipt_no='DR-2026-0105'),
           (SELECT item_id FROM item WHERE item_code='OS-2050'),                      40,       65.00
    UNION ALL
    SELECT (SELECT delivery_id FROM delivery WHERE receipt_no='DR-2026-0105'),
           (SELECT item_id FROM item WHERE item_code='OS-2060'),                     200,       18.00
) s
WHERE NOT EXISTS (SELECT 1 FROM delivery_item di WHERE di.delivery_id = s.did AND di.item_id = s.iid);

-- D6: WMSU (office, bidding) — partial payment (big order)
INSERT IGNORE INTO delivery (receipt_no, customer_id, delivery_date, order_type, status)
VALUES ('DR-2026-0106', (SELECT customer_id FROM customer WHERE name='Western Mindanao State University'), DATE'2026-10-27', 'BIDDING', 'DELIVERED');
INSERT IGNORE INTO bidding_delivery (delivery_id, bid_ref_no, delivery_deadline)
SELECT delivery_id, 'BID-2026-052', DATE'2026-10-23' FROM delivery d
WHERE d.receipt_no='DR-2026-0106' AND NOT EXISTS (SELECT 1 FROM bidding_delivery bd WHERE bd.delivery_id = d.delivery_id);
INSERT INTO delivery_item (delivery_id, item_id, quantity, unit_price)
SELECT s.did, s.iid, s.qty, s.price
FROM (
    SELECT (SELECT delivery_id FROM delivery WHERE receipt_no='DR-2026-0106') AS did,
           (SELECT item_id FROM item WHERE item_code='OS-2010')               AS iid, 120 AS qty, 210.00 AS price
    UNION ALL
    SELECT (SELECT delivery_id FROM delivery WHERE receipt_no='DR-2026-0106'),
           (SELECT item_id FROM item WHERE item_code='OS-2020'),                      80,      185.00
    UNION ALL
    SELECT (SELECT delivery_id FROM delivery WHERE receipt_no='DR-2026-0106'),
           (SELECT item_id FROM item WHERE item_code='OS-2060'),                     400,       18.00
) s
WHERE NOT EXISTS (SELECT 1 FROM delivery_item di WHERE di.delivery_id = s.did AND di.item_id = s.iid);

-- D7: ZAMPEN (office, direct) — fully paid
INSERT IGNORE INTO delivery (receipt_no, customer_id, delivery_date, order_type, status)
VALUES ('DR-2026-0107', (SELECT customer_id FROM customer WHERE name='ZAMPEN Marketing Inc.'), DATE'2026-10-30', 'DIRECT', 'DELIVERED');
INSERT IGNORE INTO direct_delivery (delivery_id, po_number, po_date)
SELECT delivery_id, 'PO-2026-0025', DATE'2026-10-27' FROM delivery d
WHERE d.receipt_no='DR-2026-0107' AND NOT EXISTS (SELECT 1 FROM direct_delivery dd WHERE dd.delivery_id = d.delivery_id);
INSERT INTO delivery_item (delivery_id, item_id, quantity, unit_price)
SELECT s.did, s.iid, s.qty, s.price
FROM (
    SELECT (SELECT delivery_id FROM delivery WHERE receipt_no='DR-2026-0107') AS did,
           (SELECT item_id FROM item WHERE item_code='JS-3010')               AS iid,  20 AS qty,  95.00 AS price
    UNION ALL
    SELECT (SELECT delivery_id FROM delivery WHERE receipt_no='DR-2026-0107'),
           (SELECT item_id FROM item WHERE item_code='JS-3020'),                      10,      320.00
    UNION ALL
    SELECT (SELECT delivery_id FROM delivery WHERE receipt_no='DR-2026-0107'),
           (SELECT item_id FROM item WHERE item_code='OS-2030'),                      15,      250.00
    UNION ALL
    SELECT (SELECT delivery_id FROM delivery WHERE receipt_no='DR-2026-0107'),
           (SELECT item_id FROM item WHERE item_code='OS-2040'),                      40,       30.00
) s
WHERE NOT EXISTS (SELECT 1 FROM delivery_item di WHERE di.delivery_id = s.did AND di.item_id = s.iid);

-- D8: ZCES second order (school, direct) — UNPAID
INSERT IGNORE INTO delivery (receipt_no, customer_id, delivery_date, order_type, status)
VALUES ('DR-2026-0108', (SELECT customer_id FROM customer WHERE name='Zamboanga Central Elementary School'), DATE'2026-11-03', 'DIRECT', 'DELIVERED');
INSERT IGNORE INTO direct_delivery (delivery_id, po_number, po_date)
SELECT delivery_id, 'PO-2026-0028', DATE'2026-11-02' FROM delivery d
WHERE d.receipt_no='DR-2026-0108' AND NOT EXISTS (SELECT 1 FROM direct_delivery dd WHERE dd.delivery_id = d.delivery_id);
INSERT INTO delivery_item (delivery_id, item_id, quantity, unit_price)
SELECT s.did, s.iid, s.qty, s.price
FROM (
    SELECT (SELECT delivery_id FROM delivery WHERE receipt_no='DR-2026-0108') AS did,
           (SELECT item_id FROM item WHERE item_code='SS-1030')               AS iid, 250 AS qty,  15.00 AS price
    UNION ALL
    SELECT (SELECT delivery_id FROM delivery WHERE receipt_no='DR-2026-0108'),
           (SELECT item_id FROM item WHERE item_code='SS-1050'),                      80,       25.00
    UNION ALL
    SELECT (SELECT delivery_id FROM delivery WHERE receipt_no='DR-2026-0108'),
           (SELECT item_id FROM item WHERE item_code='SS-1060'),                     120,       38.00
) s
WHERE NOT EXISTS (SELECT 1 FROM delivery_item di WHERE di.delivery_id = s.did AND di.item_id = s.iid);

-- D9: City Treasurer follow-up — inserted DELIVERED then CANCELLED
--     (demonstrates the cancellation audit + stock restoration)
INSERT IGNORE INTO delivery (receipt_no, customer_id, delivery_date, order_type, status)
VALUES ('DR-2026-0109', (SELECT customer_id FROM customer WHERE name='City Treasurer''s Office'), DATE'2026-11-05', 'DIRECT', 'DELIVERED');
INSERT IGNORE INTO direct_delivery (delivery_id, po_number, po_date)
SELECT delivery_id, 'PO-2026-0030', DATE'2026-11-03' FROM delivery d
WHERE d.receipt_no='DR-2026-0109' AND NOT EXISTS (SELECT 1 FROM direct_delivery dd WHERE dd.delivery_id = d.delivery_id);
INSERT INTO delivery_item (delivery_id, item_id, quantity, unit_price)
SELECT (SELECT delivery_id FROM delivery WHERE receipt_no='DR-2026-0109'),
       (SELECT item_id FROM item WHERE item_code='SS-1010'), 100, 12.00
WHERE NOT EXISTS (
    SELECT 1 FROM delivery_item di
    WHERE di.delivery_id = (SELECT delivery_id FROM delivery WHERE receipt_no='DR-2026-0109')
      AND di.item_id = (SELECT item_id FROM item WHERE item_code='SS-1010')
);
UPDATE delivery SET status='CANCELLED' WHERE receipt_no='DR-2026-0109' AND status='DELIVERED';

-- -------------------------
-- 5) PAYMENTS (guarded per delivery by the remark marker)
-- -------------------------
INSERT INTO payment (delivery_id, payment_date, amount, method, reference_no, remarks)
SELECT p.delivery_id, p.dt, p.amt, p.pm, p.ref, 'sample collection'
FROM (
    SELECT (SELECT delivery_id FROM delivery WHERE receipt_no='DR-2026-0101') AS delivery_id, DATE'2026-10-05' AS dt, 14350.00 AS amt, 'CASH' AS pm, 'SC-001' AS ref UNION ALL
    SELECT (SELECT delivery_id FROM delivery WHERE receipt_no='DR-2026-0102'), DATE'2026-10-09', 15000.00, 'CHECK', 'CHK-000412' UNION ALL
    SELECT (SELECT delivery_id FROM delivery WHERE receipt_no='DR-2026-0103'), DATE'2026-10-15', 13150.00, 'BANK TRANSFER', 'BT-778321' UNION ALL
    SELECT (SELECT delivery_id FROM delivery WHERE receipt_no='DR-2026-0105'), DATE'2026-10-21', 16200.00, 'CASH', 'SC-003' UNION ALL
    SELECT (SELECT delivery_id FROM delivery WHERE receipt_no='DR-2026-0106'), DATE'2026-11-02', 20000.00, 'CHECK', 'CHK-000437' UNION ALL
    SELECT (SELECT delivery_id FROM delivery WHERE receipt_no='DR-2026-0107'), DATE'2026-10-30', 10050.00, 'CHECK', 'CHK-000429'
) p
WHERE NOT EXISTS (
    SELECT 1 FROM payment x WHERE x.delivery_id = p.delivery_id AND x.remarks = 'sample collection'
);

-- -------------------------
-- 6) PHYSICAL COUNT ADJUSTMENTS (guarded by the reason marker)
-- -------------------------
INSERT INTO stock_adjustment (item_id, adjust_date, system_qty, counted_qty, reason)
SELECT a.item_id, a.dt, a.sysq, a.ctrq, 'PERIODIC COUNT (sample data)'
FROM (
    SELECT (SELECT item_id FROM item WHERE item_code='SS-1060') AS item_id, DATE'2026-10-28' AS dt,  800 AS sysq, 795 AS ctrq UNION ALL
    SELECT (SELECT item_id FROM item WHERE item_code='SS-1020'), DATE'2026-11-02', 1200, 1198 UNION ALL
    SELECT (SELECT item_id FROM item WHERE item_code='SS-1040'), DATE'2026-11-02',  350,  352
) a
WHERE NOT EXISTS (
    SELECT 1 FROM stock_adjustment x WHERE x.item_id = a.item_id AND x.reason = 'PERIODIC COUNT (sample data)'
);
