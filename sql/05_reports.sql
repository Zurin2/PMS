-- =============================================================
-- PMS Inventory Tracking & Delivery Monitoring System
-- File: sql/05_reports.sql
-- Description: Analytical reporting queries
-- =============================================================

USE pms_inventory;

-- -----------------------------------------------
-- Inventory Movement Report (date-range summary)
-- Usage: Set @start and @end before executing
-- -----------------------------------------------
SET @start = '2026-11-01', @end = '2026-11-30';

SELECT 
    i.item_id, 
    i.name,
    COALESCE(SUM(CASE WHEN m.move_date < @start THEN m.qty END), 0) AS beginning,
    COALESCE(SUM(CASE WHEN m.move_date BETWEEN @start AND @end AND m.move_type = 'IN' THEN m.qty END), 0) AS incoming,
    COALESCE(SUM(CASE WHEN m.move_date BETWEEN @start AND @end AND m.move_type = 'OUT' THEN -m.qty END), 0) AS outgoing,
    COALESCE(SUM(CASE WHEN m.move_date BETWEEN @start AND @end AND m.move_type = 'ADJ' THEN m.qty END), 0) AS adjustments,
    COALESCE(SUM(CASE WHEN m.move_date <= @end THEN m.qty END), 0) AS ending
FROM item i 
LEFT JOIN v_stock_movement m ON m.item_id = i.item_id
GROUP BY i.item_id, i.name 
ORDER BY i.name;
