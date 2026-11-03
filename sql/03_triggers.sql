-- =============================================================
-- PMS Inventory Tracking & Delivery Monitoring System
-- File: sql/03_triggers.sql
-- Description: Business rule enforcement via MySQL triggers
-- =============================================================

USE pms_inventory;

DELIMITER $$

-- Prevent delivery if insufficient stock
CREATE TRIGGER trg_di_stock_check BEFORE INSERT ON delivery_item FOR EACH ROW
BEGIN
    DECLARE soh INT;
    SELECT stock_on_hand INTO soh FROM v_item_stock WHERE item_id = NEW.item_id;
    IF soh < NEW.quantity THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Insufficient stock on hand';
    END IF;
END$$

-- Prevent stock_in deletion if it causes negative SOH
CREATE TRIGGER trg_si_delete_guard BEFORE DELETE ON stock_in FOR EACH ROW
BEGIN
    DECLARE soh INT;
    SELECT stock_on_hand INTO soh FROM v_item_stock WHERE item_id = OLD.item_id;
    IF soh - OLD.quantity < 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot delete: stock would go negative';
    END IF;
END$$

-- Bidding deliveries are only allowed for OFFICE customers
CREATE TRIGGER trg_bd_office_only BEFORE INSERT ON bidding_delivery FOR EACH ROW
BEGIN
    IF (SELECT c.customer_type FROM delivery d JOIN customer c ON c.customer_id = d.customer_id WHERE d.delivery_id = NEW.delivery_id) != 'OFFICE' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Bidding deliveries are for offices only';
    END IF;
END$$

-- Prevent payment amount from exceeding outstanding balance
CREATE TRIGGER trg_payment_no_overpay BEFORE INSERT ON payment FOR EACH ROW
BEGIN
    DECLARE due DECIMAL(12,2);
    SELECT balance INTO due FROM v_delivery_balance WHERE delivery_id = NEW.delivery_id;
    IF NEW.amount > due THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment exceeds the delivery balance';
    END IF;
END$$

-- Auto-log price when a new item is inserted
CREATE TRIGGER trg_item_price_ins AFTER INSERT ON item FOR EACH ROW
BEGIN
    INSERT INTO item_price_history (item_id, effective_at, price) VALUES (NEW.item_id, NOW(3), NEW.unit_price);
END$$

-- Auto-log price change when unit_price is updated
CREATE TRIGGER trg_item_price_log AFTER UPDATE ON item FOR EACH ROW
BEGIN
    IF NEW.unit_price != OLD.unit_price THEN
        INSERT INTO item_price_history (item_id, effective_at, price) VALUES (NEW.item_id, NOW(3), NEW.unit_price);
    END IF;
END$$

DELIMITER ;
