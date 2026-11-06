-- =============================================================
-- PMS Inventory Tracking & Delivery Monitoring System
-- File: sql/06_procedures.sql
-- Description: Stored procedures implementing the SQL Lab pattern
-- (sp_transfer_funds): ACID transaction + pessimistic row locking
-- (SELECT ... FOR UPDATE) + SIGNAL for server-side validation.
-- =============================================================

USE pms_inventory;

DELIMITER $$

DROP PROCEDURE IF EXISTS sp_record_delivery_payment$$

CREATE PROCEDURE sp_record_delivery_payment(
    IN p_delivery_id INT UNSIGNED,
    IN p_payment_date DATE,
    IN p_amount DECIMAL(12,2),
    IN p_method VARCHAR(50),
    IN p_reference_no VARCHAR(60),
    IN p_remarks VARCHAR(255)
)
BEGIN
    DECLARE v_status VARCHAR(20);
    DECLARE v_balance DECIMAL(12,2);

    START TRANSACTION;

    -- Lock the delivery row so concurrent payments serialize
    SELECT status, balance INTO v_status, v_balance
    FROM v_delivery_balance
    WHERE delivery_id = p_delivery_id
    FOR UPDATE;

    IF v_status IS NULL THEN
        ROLLBACK;
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Delivery not found';
    ELSEIF v_status != 'DELIVERED' THEN
        ROLLBACK;
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Delivery is not DELIVERED';
    ELSEIF p_amount <= 0 THEN
        ROLLBACK;
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment amount must be greater than zero';
    ELSEIF p_amount > v_balance THEN
        ROLLBACK;
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment exceeds the delivery balance';
    ELSE
        INSERT INTO payment (delivery_id, payment_date, amount, method, reference_no, remarks)
        VALUES (p_delivery_id, p_payment_date, p_amount, p_method, p_reference_no, p_remarks);
        COMMIT;
        SELECT LAST_INSERT_ID() AS payment_id,
               'Payment recorded successfully' AS message,
               (v_balance - p_amount) AS remaining_balance;
    END IF;
END$$

DELIMITER ;
