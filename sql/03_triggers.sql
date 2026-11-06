-- =============================================================
-- PMS Inventory Tracking & Delivery Monitoring System
-- File: sql/03_triggers.sql
-- Description: Business rule enforcement via MySQL triggers
-- =============================================================

USE pms_inventory;

DELIMITER $$

-- Prevent delivery if insufficient stock
DROP TRIGGER IF EXISTS trg_di_stock_check;
CREATE TRIGGER trg_di_stock_check BEFORE INSERT ON delivery_item FOR EACH ROW
BEGIN
    DECLARE soh INT;
    SELECT stock_on_hand INTO soh FROM v_item_stock WHERE item_id = NEW.item_id;
    IF soh < NEW.quantity THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Insufficient stock on hand';
    END IF;
END$$

-- Prevent stock_in deletion if it causes negative SOH
DROP TRIGGER IF EXISTS trg_si_delete_guard;
CREATE TRIGGER trg_si_delete_guard BEFORE DELETE ON stock_in FOR EACH ROW
BEGIN
    DECLARE soh INT;
    SELECT stock_on_hand INTO soh FROM v_item_stock WHERE item_id = OLD.item_id;
    IF soh - OLD.quantity < 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot delete: stock would go negative';
    END IF;
END$$

-- Bidding deliveries are only allowed for OFFICE customers
DROP TRIGGER IF EXISTS trg_bd_office_only;
CREATE TRIGGER trg_bd_office_only BEFORE INSERT ON bidding_delivery FOR EACH ROW
BEGIN
    IF (SELECT c.customer_type FROM delivery d JOIN customer c ON c.customer_id = d.customer_id WHERE d.delivery_id = NEW.delivery_id) != 'OFFICE' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Bidding deliveries are for offices only';
    END IF;
END$$

-- Prevent payment amount from exceeding outstanding balance
DROP TRIGGER IF EXISTS trg_payment_no_overpay;
CREATE TRIGGER trg_payment_no_overpay BEFORE INSERT ON payment FOR EACH ROW
BEGIN
    DECLARE due DECIMAL(12,2);
    SELECT balance INTO due FROM v_delivery_balance WHERE delivery_id = NEW.delivery_id;
    IF NEW.amount > due THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment exceeds the delivery balance';
    END IF;
END$$

-- Auto-log price when a new item is inserted
DROP TRIGGER IF EXISTS trg_item_price_ins;
CREATE TRIGGER trg_item_price_ins AFTER INSERT ON item FOR EACH ROW
BEGIN
    INSERT INTO item_price_history (item_id, effective_at, price) VALUES (NEW.item_id, NOW(3), NEW.unit_price);
END$$

-- Auto-log price change when unit_price is updated
DROP TRIGGER IF EXISTS trg_item_price_log;
CREATE TRIGGER trg_item_price_log AFTER UPDATE ON item FOR EACH ROW
BEGIN
    IF NEW.unit_price != OLD.unit_price THEN
        INSERT INTO item_price_history (item_id, effective_at, price) VALUES (NEW.item_id, NOW(3), NEW.unit_price);
    END IF;
END$$

-- -------------------------
-- STOCK AUDIT LEDGER (SQL Lab: trg_after_inventory_update pattern)
-- Append-only audit trail of every stock movement. Stock in PMS is a
-- computed ledger (v_item_stock), so the audit triggers live on the
-- stock tables and snapshot balance_after from v_item_stock each time.
-- -------------------------
CREATE TABLE IF NOT EXISTS item_stock_audit (
    audit_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_id INT UNSIGNED NOT NULL,
    change_type ENUM('STOCK_IN', 'STOCK_OUT', 'ADJUSTMENT', 'CANCELLATION') NOT NULL,
    qty_delta INT NOT NULL,
    balance_after INT NOT NULL,
    source VARCHAR(60) NULL,
    created_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    KEY idx_isa_item_time (item_id, created_at),
    CONSTRAINT fk_isa_item FOREIGN KEY (item_id) REFERENCES item(item_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- Goods received -> STOCK_IN
DROP TRIGGER IF EXISTS trg_si_audit;
CREATE TRIGGER trg_si_audit AFTER INSERT ON stock_in FOR EACH ROW
BEGIN
    DECLARE v_bal INT;
    SELECT stock_on_hand INTO v_bal FROM v_item_stock WHERE item_id = NEW.item_id;
    IF v_bal IS NULL THEN SET v_bal = 0; END IF;
    INSERT INTO item_stock_audit (item_id, change_type, qty_delta, balance_after, source, created_at)
    VALUES (NEW.item_id, 'STOCK_IN', NEW.quantity, v_bal, CONCAT('stock_in #', NEW.stock_in_id), NOW(3));
END$$

-- Goods delivered -> STOCK_OUT (only when delivery is actually DELIVERED)
DROP TRIGGER IF EXISTS trg_di_audit;
CREATE TRIGGER trg_di_audit AFTER INSERT ON delivery_item FOR EACH ROW
BEGIN
    DECLARE v_status VARCHAR(20);
    DECLARE v_bal INT;
    SELECT d.status INTO v_status FROM delivery d WHERE d.delivery_id = NEW.delivery_id;
    IF v_status = 'DELIVERED' THEN
        SELECT stock_on_hand INTO v_bal FROM v_item_stock WHERE item_id = NEW.item_id;
        INSERT INTO item_stock_audit (item_id, change_type, qty_delta, balance_after, source, created_at)
        VALUES (NEW.item_id, 'STOCK_OUT', -NEW.quantity, v_bal, CONCAT('delivery #', NEW.delivery_id), NOW(3));
    END IF;
END$$

-- Delivery cancelled -> stock restored, one audit row per affected item
DROP TRIGGER IF EXISTS trg_delivery_cancel_audit;
CREATE TRIGGER trg_delivery_cancel_audit AFTER UPDATE ON delivery FOR EACH ROW
BEGIN
    IF OLD.status = 'DELIVERED' AND NEW.status = 'CANCELLED' THEN
        INSERT INTO item_stock_audit (item_id, change_type, qty_delta, balance_after, source, created_at)
        SELECT di.item_id, 'CANCELLATION', di.quantity, COALESCE(v.stock_on_hand, 0),
               CONCAT('delivery #', NEW.delivery_id), NOW(3)
        FROM delivery_item di
        LEFT JOIN v_item_stock v ON v.item_id = di.item_id
        WHERE di.delivery_id = NEW.delivery_id;
    END IF;
END$$

-- Physical count variance -> ADJUSTMENT (delta = counted - system)
DROP TRIGGER IF EXISTS trg_sa_audit;
CREATE TRIGGER trg_sa_audit AFTER INSERT ON stock_adjustment FOR EACH ROW
BEGIN
    DECLARE v_bal INT;
    SELECT stock_on_hand INTO v_bal FROM v_item_stock WHERE item_id = NEW.item_id;
    IF v_bal IS NULL THEN SET v_bal = 0; END IF;
    INSERT INTO item_stock_audit (item_id, change_type, qty_delta, balance_after, source, created_at)
    VALUES (NEW.item_id, 'ADJUSTMENT', CAST(NEW.counted_qty AS SIGNED) - NEW.system_qty, v_bal,
            CONCAT('adjustment #', NEW.adjustment_id), NOW(3));
END$$

DELIMITER ;
