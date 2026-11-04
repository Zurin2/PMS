-- =============================================================
-- PMS Inventory Tracking & Delivery Monitoring System
-- File: sql/04_seed.sql
-- Description: Default seed data
-- =============================================================

USE pms_inventory;

-- Default item categories (safe to re-run: skips duplicates)
INSERT IGNORE INTO category (name) VALUES ('School Supplies'), ('Office Supplies'), ('Janitorial Supplies');

-- Default owner account (Username: owner, Password: admin123)
INSERT INTO user_account (user_id, username, password_hash, full_name)
VALUES (1, 'owner', '$2y$10$NNvd5wks8n/hrzYKZaE6AeEbd7NQxa1roFeZsK6enB4h1fPeZWVbq', 'PMS Owner')
ON DUPLICATE KEY UPDATE
    username      = VALUES(username),
    password_hash = VALUES(password_hash),
    full_name     = VALUES(full_name);
