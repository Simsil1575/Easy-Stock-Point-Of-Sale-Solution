-- Migration script to add buying_price column to order_items and credit_sale_items
-- This ensures COGS calculations use historical buying prices, not current ones

-- Add buying_price column to order_items table
ALTER TABLE order_items ADD COLUMN buying_price DECIMAL(10, 2) DEFAULT NULL;

-- Add buying_price column to credit_sale_items table
ALTER TABLE credit_sale_items ADD COLUMN buying_price DECIMAL(10, 2) DEFAULT NULL;

-- Backfill existing records with current buying prices from products table
-- This is a best-effort approach for historical data
UPDATE order_items 
SET buying_price = (
    SELECT p.buying_price 
    FROM products p 
    WHERE p.name = order_items.product_name
)
WHERE buying_price IS NULL;

UPDATE credit_sale_items 
SET buying_price = (
    SELECT p.buying_price 
    FROM products p 
    WHERE p.name = credit_sale_items.product_name
)
WHERE buying_price IS NULL;











