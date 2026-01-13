-- SQL Script to Reset Product IDs from 1 to End
-- WARNING: Make a backup of your database before running this script!

BEGIN TRANSACTION;

-- Step 1: Disable foreign key constraints temporarily
PRAGMA foreign_keys = OFF;

-- Step 2: Create a temporary table with the new sequential IDs
-- This will renumber ALL products sequentially from 1, regardless of gaps
-- CHOOSE ONE ORDERING METHOD (uncomment the one you want):

-- Option 1: Order by OLD ID (keeps same order, just fills gaps)
CREATE TEMPORARY TABLE products_new AS
SELECT 
    ROW_NUMBER() OVER (ORDER BY id ASC) as new_id,
    id as old_id,
    name,
    quantity,
    price,
    buying_price,
    image_url,
    restock_level,
    capacity,
    expiry_date,
    barcode,
    discount_start,
    discount_end,
    category,
    discount
FROM products
ORDER BY id;

-- Option 2: Order by PRODUCT NAME (alphabetical)
-- CREATE TEMPORARY TABLE products_new AS
-- SELECT 
--     ROW_NUMBER() OVER (ORDER BY name ASC) as new_id,
--     id as old_id,
--     name,
--     quantity,
--     price,
--     buying_price,
--     image_url,
--     restock_level,
--     capacity,
--     expiry_date,
--     barcode,
--     discount_start,
--     discount_end,
--     category,
--     discount
-- FROM products
-- ORDER BY name;

-- Option 3: Order by CATEGORY then NAME
-- CREATE TEMPORARY TABLE products_new AS
-- SELECT 
--     ROW_NUMBER() OVER (ORDER BY category ASC, name ASC) as new_id,
--     id as old_id,
--     name,
--     quantity,
--     price,
--     buying_price,
--     image_url,
--     restock_level,
--     capacity,
--     expiry_date,
--     barcode,
--     discount_start,
--     discount_end,
--     category,
--     discount
-- FROM products
-- ORDER BY category, name;

-- Step 3: Update foreign key references in related tables
-- Update closing_stock
UPDATE closing_stock
SET product_id = (SELECT new_id FROM products_new WHERE old_id = closing_stock.product_id)
WHERE product_id IN (SELECT old_id FROM products_new);

-- Update daily_stock_summary
UPDATE daily_stock_summary
SET product_id = (SELECT new_id FROM products_new WHERE old_id = daily_stock_summary.product_id)
WHERE product_id IN (SELECT old_id FROM products_new);

-- Update damaged_goods
UPDATE damaged_goods
SET product_id = (SELECT new_id FROM products_new WHERE old_id = damaged_goods.product_id)
WHERE product_id IN (SELECT old_id FROM products_new);

-- Update opening_stock
UPDATE opening_stock
SET product_id = (SELECT new_id FROM products_new WHERE old_id = opening_stock.product_id)
WHERE product_id IN (SELECT old_id FROM products_new);

-- Update stock_changes
UPDATE stock_changes
SET product_id = (SELECT new_id FROM products_new WHERE old_id = stock_changes.product_id)
WHERE product_id IN (SELECT old_id FROM products_new);

-- Step 4: Delete all records from products table
DELETE FROM products;

-- Step 5: Insert products with new sequential IDs
INSERT INTO products (id, name, quantity, price, buying_price, image_url, restock_level, capacity, expiry_date, barcode, discount_start, discount_end, category, discount)
SELECT 
    new_id,
    name,
    quantity,
    price,
    buying_price,
    image_url,
    restock_level,
    capacity,
    expiry_date,
    barcode,
    discount_start,
    discount_end,
    category,
    discount
FROM products_new
ORDER BY new_id;

-- Step 6: Reset the AUTOINCREMENT counter
DELETE FROM sqlite_sequence WHERE name = 'products';
INSERT INTO sqlite_sequence (name, seq) 
VALUES ('products', (SELECT MAX(id) FROM products));

-- Step 7: Drop the temporary table
DROP TABLE products_new;

-- Step 8: Re-enable foreign key constraints
PRAGMA foreign_keys = ON;

-- Verify the changes
SELECT 'Total products after reset:' AS info, COUNT(*) AS count FROM products;
SELECT 'Min ID:' AS info, MIN(id) AS value FROM products;
SELECT 'Max ID:' AS info, MAX(id) AS value FROM products;

COMMIT;

-- To run this script in SQLite:
-- sqlite3 path/to/your/database.db < reset_product_ids.sql

