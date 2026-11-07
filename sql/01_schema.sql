-- =============================================================
-- PMS Inventory Tracking & Delivery Monitoring System
-- File: sql/01_schema.sql
-- Description: Full database schema
-- =============================================================

CREATE DATABASE IF NOT EXISTS pms_inventory CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE pms_inventory;

-- -------------------------
-- CATEGORIES
-- -------------------------
CREATE TABLE category (
    category_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB;

-- -------------------------
-- ITEMS
-- -------------------------
CREATE TABLE item (
    item_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id INT UNSIGNED NOT NULL,
    item_code VARCHAR(30) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL,
    unit VARCHAR(20) NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL CHECK (unit_price >= 0),
    low_stock_level INT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('ACTIVE', 'ARCHIVED') NOT NULL DEFAULT 'ACTIVE',
    UNIQUE KEY uq_item_name_unit (name, unit),
    CONSTRAINT fk_item_category FOREIGN KEY (category_id) REFERENCES category (category_id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- -------------------------
-- ITEM PRICE HISTORY
-- -------------------------
CREATE TABLE item_price_history (
    item_id INT UNSIGNED NOT NULL,
    effective_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    price DECIMAL(10,2) NOT NULL CHECK (price >= 0),
    PRIMARY KEY (item_id, effective_at),
    CONSTRAINT fk_iph_item FOREIGN KEY (item_id) REFERENCES item(item_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- -------------------------
-- STOCK IN
-- -------------------------
CREATE TABLE stock_in (
    stock_in_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_id INT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL CHECK (quantity > 0),
    remarks VARCHAR(255) NULL,
    date_received DATE NOT NULL,
    KEY idx_si_item_date (item_id, date_received),
    CONSTRAINT fk_si_item FOREIGN KEY (item_id) REFERENCES item(item_id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- -------------------------
-- STOCK ADJUSTMENTS
-- -------------------------
CREATE TABLE stock_adjustment (
    adjustment_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_id INT UNSIGNED NOT NULL,
    adjust_date DATE NOT NULL,
    system_qty INT NOT NULL,
    counted_qty INT NOT NULL,
    reason VARCHAR(255) NULL,
    KEY idx_sa_item_date (item_id, adjust_date),
    CONSTRAINT fk_sa_item FOREIGN KEY (item_id) REFERENCES item(item_id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- -------------------------
-- CUSTOMERS (with subtypes)
-- -------------------------
CREATE TABLE customer (
    customer_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    address VARCHAR(255) NOT NULL,
    customer_type ENUM('SCHOOL', 'OFFICE') NOT NULL,
    UNIQUE KEY uq_customer_name_addr (name, address)
) ENGINE=InnoDB;

CREATE TABLE school (
    customer_id INT UNSIGNED PRIMARY KEY,
    school_level ENUM('ELEMENTARY', 'JUNIOR_HIGH', 'SENIOR_HIGH', 'COLLEGE', 'OTHER') NOT NULL,
    head_name VARCHAR(100) NULL,
    CONSTRAINT fk_school_customer FOREIGN KEY (customer_id) REFERENCES customer(customer_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE office (
    customer_id INT UNSIGNED PRIMARY KEY,
    office_type ENUM('GOVERNMENT', 'PRIVATE') NOT NULL,
    contact_person VARCHAR(100) NULL,
    CONSTRAINT fk_office_customer FOREIGN KEY (customer_id) REFERENCES customer(customer_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE customer_contact (
    customer_id INT UNSIGNED NOT NULL,
    contact_type ENUM('MOBILE', 'LANDLINE', 'EMAIL') NOT NULL,
    contact_value VARCHAR(120) NOT NULL,
    PRIMARY KEY (customer_id, contact_type, contact_value),
    CONSTRAINT fk_cc_customer FOREIGN KEY (customer_id) REFERENCES customer(customer_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- -------------------------
-- DELIVERIES
-- -------------------------
CREATE TABLE delivery (
    delivery_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    receipt_no VARCHAR(30) NOT NULL UNIQUE,
    customer_id INT UNSIGNED NOT NULL,
    delivery_date DATE NOT NULL,
    order_type ENUM('DIRECT', 'BIDDING') NOT NULL,
    status ENUM('DELIVERED', 'CANCELLED') NOT NULL DEFAULT 'DELIVERED',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_delivery_cust_date (customer_id, delivery_date),
    KEY idx_delivery_date (delivery_date),
    CONSTRAINT fk_delivery_customer FOREIGN KEY (customer_id) REFERENCES customer(customer_id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE direct_delivery (
    delivery_id INT UNSIGNED PRIMARY KEY,
    po_number VARCHAR(40) NOT NULL,
    po_date DATE NOT NULL,
    CONSTRAINT fk_dd_delivery FOREIGN KEY (delivery_id) REFERENCES delivery (delivery_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE bidding_delivery (
    delivery_id INT UNSIGNED PRIMARY KEY,
    bid_ref_no VARCHAR(40) NOT NULL,
    delivery_deadline DATE NOT NULL,
    CONSTRAINT fk_bd_delivery FOREIGN KEY (delivery_id) REFERENCES delivery (delivery_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE delivery_item (
    delivery_id INT UNSIGNED NOT NULL,
    item_id INT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL CHECK (quantity > 0),
    unit_price DECIMAL(10,2) NOT NULL CHECK (unit_price >= 0),
    PRIMARY KEY (delivery_id, item_id),
    KEY idx_di_item (item_id),
    CONSTRAINT fk_di_delivery FOREIGN KEY (delivery_id) REFERENCES delivery (delivery_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_di_item FOREIGN KEY (item_id) REFERENCES item(item_id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- -------------------------
-- PAYMENTS
-- -------------------------
CREATE TABLE payment (
    payment_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    delivery_id INT UNSIGNED NOT NULL,
    payment_date DATE NOT NULL,
    amount DECIMAL(12,2) NOT NULL CHECK (amount > 0),
    method ENUM('CASH', 'CHECK', 'BANK TRANSFER', 'OTHER') NOT NULL DEFAULT 'CASH',
    reference_no VARCHAR(60) NULL,
    remarks VARCHAR(255) NULL,
    KEY idx_pay_delivery_date (delivery_id, payment_date),
    CONSTRAINT fk_pay_delivery FOREIGN KEY (delivery_id) REFERENCES delivery(delivery_id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- -------------------------
-- USER ACCOUNT (single owner)
-- -------------------------
CREATE TABLE user_account (
    user_id TINYINT UNSIGNED NOT NULL DEFAULT 1 PRIMARY KEY CHECK (user_id = 1), -- only one owner row
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL
) ENGINE=InnoDB;
