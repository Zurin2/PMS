-- =============================================================
-- PMS Inventory Tracking & Delivery Monitoring System
-- File: sql/04_seed.sql
-- Description: Default seed data
-- =============================================================

USE pms_inventory;

-- Default item categories (safe to re-run: skips duplicates)
INSERT IGNORE INTO category (name) VALUES ('School Supplies'), ('Office Supplies'), ('Janitorial Supplies');

-- Default owner account (CHANGE PASSWORD BEFORE PRODUCTION)
-- Password: 'changeme' hashed with password_hash() in PHP — replace with actual bcrypt hash
-- Run: php -r "echo password_hash('changeme', PASSWORD_DEFAULT);"
INSERT INTO user_account (user_id, username, password_hash, full_name)
VALUES (1, 'owner', 'REPLACE WITH THE HASHED PASSWORD', 'System Owner')
ON DUPLICATE KEY UPDATE
    username      = VALUES(username),
    password_hash = VALUES(password_hash),
    full_name     = VALUES(full_name);
