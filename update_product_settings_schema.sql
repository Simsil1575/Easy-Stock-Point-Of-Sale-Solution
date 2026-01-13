-- Add hide_available_quantity column to product_settings table
-- This script is safe to run multiple times (will fail silently if column already exists)

ALTER TABLE product_settings ADD COLUMN hide_available_quantity BOOLEAN NOT NULL DEFAULT 0;

-- Verify the column was added
SELECT * FROM product_settings LIMIT 1;






