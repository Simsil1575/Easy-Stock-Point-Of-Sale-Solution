-- Schema Updates for Period-Based Stock Tracking
-- This file contains all necessary changes to enable proper stock tracking across periods

-- 1. Fix the products table - change quantity from VARCHAR to INTEGER
ALTER TABLE products ADD COLUMN quantity_new INTEGER DEFAULT 0;
UPDATE products SET quantity_new = CAST(quantity AS INTEGER) WHERE quantity IS NOT NULL AND quantity != '';
ALTER TABLE products DROP COLUMN quantity;
ALTER TABLE products RENAME COLUMN quantity_new TO quantity;

-- 2. Create business periods table to manage daily/weekly/monthly periods
CREATE TABLE IF NOT EXISTS business_periods (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    period_type TEXT NOT NULL CHECK(period_type IN ('daily', 'weekly', 'monthly')),
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    status TEXT NOT NULL DEFAULT 'open' CHECK(status IN ('open', 'closed', 'locked')),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    closed_at DATETIME,
    closed_by INTEGER,
    notes TEXT,
    UNIQUE(start_date, period_type)
);

-- 3. Update opening_stock table to link with periods (SQLite compatible)
ALTER TABLE opening_stock ADD COLUMN period_id INTEGER;
ALTER TABLE opening_stock ADD COLUMN period_date DATE;

-- 4. Update closing_stock table to link with periods (SQLite compatible)
ALTER TABLE closing_stock ADD COLUMN period_id INTEGER;
ALTER TABLE closing_stock ADD COLUMN period_date DATE;

-- 5. Update daily_stock_summary to link with periods (SQLite compatible)
ALTER TABLE daily_stock_summary ADD COLUMN period_id INTEGER;

-- 6. Fix order_items table to reference product_id instead of product_name (SQLite compatible)
ALTER TABLE order_items ADD COLUMN product_id INTEGER;

-- 7. Create stock_period_transitions table to track period-to-period stock flow
CREATE TABLE IF NOT EXISTS stock_period_transitions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    from_period_id INTEGER,
    to_period_id INTEGER NOT NULL,
    closing_quantity INTEGER NOT NULL,
    opening_quantity INTEGER NOT NULL,
    transition_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    transition_type TEXT NOT NULL DEFAULT 'normal' CHECK(transition_type IN ('normal', 'adjustment', 'correction')),
    notes TEXT,
    created_by INTEGER
);

-- 8. Create stock_audit_log table for comprehensive audit trail
CREATE TABLE IF NOT EXISTS stock_audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    period_id INTEGER,
    action_type TEXT NOT NULL CHECK(action_type IN ('opening_stock', 'closing_stock', 'sale', 'restock', 'adjustment', 'damage', 'period_transition')),
    quantity_before INTEGER NOT NULL,
    quantity_after INTEGER NOT NULL,
    quantity_change INTEGER NOT NULL,
    reference_id INTEGER, -- ID of the related record (order_id, stock_change_id, etc.)
    reference_table TEXT, -- Table name of the reference (orders, stock_changes, etc.)
    action_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    performed_by INTEGER,
    notes TEXT
);

-- 9. Create period_settings table for configuration
CREATE TABLE IF NOT EXISTS period_settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    default_period_type TEXT NOT NULL DEFAULT 'daily' CHECK(default_period_type IN ('daily', 'weekly', 'monthly')),
    auto_create_periods BOOLEAN NOT NULL DEFAULT 1,
    require_stock_count BOOLEAN NOT NULL DEFAULT 1,
    allow_backdated_entries BOOLEAN NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 10. Create indexes for better performance
CREATE INDEX IF NOT EXISTS idx_business_periods_date_range ON business_periods(start_date, end_date);
CREATE INDEX IF NOT EXISTS idx_business_periods_status ON business_periods(status);
CREATE INDEX IF NOT EXISTS idx_opening_stock_period ON opening_stock(period_id, period_date);
CREATE INDEX IF NOT EXISTS idx_closing_stock_period ON closing_stock(period_id, period_date);
CREATE INDEX IF NOT EXISTS idx_stock_period_transitions_product ON stock_period_transitions(product_id);
CREATE INDEX IF NOT EXISTS idx_stock_period_transitions_periods ON stock_period_transitions(from_period_id, to_period_id);
CREATE INDEX IF NOT EXISTS idx_stock_audit_log_product_period ON stock_audit_log(product_id, period_id);
CREATE INDEX IF NOT EXISTS idx_stock_audit_log_date ON stock_audit_log(action_date);
CREATE INDEX IF NOT EXISTS idx_order_items_product_id ON order_items(product_id);

-- 11. Insert default period settings
INSERT OR IGNORE INTO period_settings (id, default_period_type, auto_create_periods, require_stock_count, allow_backdated_entries) 
VALUES (1, 'daily', 1, 1, 0);

-- 12. Create triggers for automatic stock tracking

-- Trigger to update stock_audit_log when opening stock is recorded
CREATE TRIGGER IF NOT EXISTS trigger_opening_stock_audit
AFTER INSERT ON opening_stock
BEGIN
    INSERT INTO stock_audit_log (
        product_id, period_id, action_type, quantity_before, quantity_after, 
        quantity_change, reference_id, reference_table, performed_by
    ) VALUES (
        NEW.product_id, NEW.period_id, 'opening_stock', 0, NEW.opening_quantity,
        NEW.opening_quantity, NEW.id, 'opening_stock', NEW.recorded_by
    );
END;

-- Trigger to update stock_audit_log when closing stock is recorded
CREATE TRIGGER IF NOT EXISTS trigger_closing_stock_audit
AFTER INSERT ON closing_stock
BEGIN
    INSERT INTO stock_audit_log (
        product_id, period_id, action_type, quantity_before, quantity_after,
        quantity_change, reference_id, reference_table, performed_by
    ) VALUES (
        NEW.product_id, NEW.period_id, 'closing_stock', 0, NEW.closing_quantity,
        NEW.closing_quantity, NEW.id, 'closing_stock', NEW.recorded_by
    );
END;

-- Trigger to update stock_audit_log when orders are created
CREATE TRIGGER IF NOT EXISTS trigger_order_items_audit
AFTER INSERT ON order_items
BEGIN
    INSERT INTO stock_audit_log (
        product_id, action_type, quantity_before, quantity_after, quantity_change,
        reference_id, reference_table
    ) 
    SELECT 
        NEW.product_id, 'sale', 
        COALESCE(p.quantity, 0), 
        COALESCE(p.quantity, 0) - NEW.quantity,
        -NEW.quantity, NEW.order_id, 'order_items'
    FROM products p WHERE p.id = NEW.product_id;
    
    -- Update product quantity
    UPDATE products SET quantity = quantity - NEW.quantity WHERE id = NEW.product_id;
END;

-- Trigger to update stock_audit_log when stock_changes are recorded
CREATE TRIGGER IF NOT EXISTS trigger_stock_changes_audit
AFTER INSERT ON stock_changes
BEGIN
    INSERT INTO stock_audit_log (
        product_id, action_type, quantity_before, quantity_after, quantity_change,
        reference_id, reference_table
    ) VALUES (
        NEW.product_id, 'adjustment', NEW.old_quantity, NEW.new_quantity,
        NEW.quantity_change, NEW.id, 'stock_changes'
    );
END;

-- 13. Create views for easier reporting

-- View for current period stock status
CREATE VIEW IF NOT EXISTS v_current_period_stock AS
SELECT 
    p.id as product_id,
    p.name as product_name,
    p.quantity as current_quantity,
    bp.id as period_id,
    bp.start_date,
    bp.end_date,
    bp.status as period_status,
    os.opening_quantity,
    cs.closing_quantity,
    COALESCE(cs.closing_quantity, p.quantity) as effective_closing_quantity
FROM products p
CROSS JOIN business_periods bp
LEFT JOIN opening_stock os ON p.id = os.product_id AND bp.id = os.period_id
LEFT JOIN closing_stock cs ON p.id = cs.product_id AND bp.id = cs.period_id
WHERE bp.status = 'open'
ORDER BY p.name;

-- View for period stock summary
CREATE VIEW IF NOT EXISTS v_period_stock_summary AS
SELECT 
    bp.id as period_id,
    bp.period_type,
    bp.start_date,
    bp.end_date,
    bp.status as period_status,
    p.id as product_id,
    p.name as product_name,
    os.opening_quantity,
    cs.closing_quantity,
    COALESCE(cs.closing_quantity - os.opening_quantity, 0) as net_change,
    (SELECT COALESCE(SUM(oi.quantity), 0) 
     FROM order_items oi 
     JOIN orders o ON oi.order_id = o.id 
     WHERE oi.product_id = p.id 
     AND DATE(o.created_at) BETWEEN bp.start_date AND bp.end_date) as sold_quantity,
    (SELECT COALESCE(SUM(sc.quantity_change), 0) 
     FROM stock_changes sc 
     WHERE sc.product_id = p.id 
     AND sc.action = 'restock'
     AND DATE(sc.changed_at) BETWEEN bp.start_date AND bp.end_date) as restocked_quantity,
    (SELECT COALESCE(SUM(dg.quantity), 0) 
     FROM damaged_goods dg 
     WHERE dg.product_id = p.id 
     AND DATE(dg.date) BETWEEN bp.start_date AND bp.end_date) as damaged_quantity
FROM business_periods bp
CROSS JOIN products p
LEFT JOIN opening_stock os ON p.id = os.product_id AND bp.id = os.period_id
LEFT JOIN closing_stock cs ON p.id = cs.product_id AND bp.id = cs.period_id
ORDER BY bp.start_date DESC, p.name;

-- 14. Data migration helpers

-- Update existing order_items to have product_id
UPDATE order_items 
SET product_id = (SELECT id FROM products WHERE name = order_items.product_name)
WHERE product_id IS NULL AND product_name IS NOT NULL;

-- Create initial business period for today if none exists
INSERT OR IGNORE INTO business_periods (period_type, start_date, end_date, status)
VALUES ('daily', DATE('now'), DATE('now'), 'open');

-- Update existing opening/closing stock records with period information
UPDATE opening_stock 
SET period_date = DATE(recorded_at),
    period_id = (SELECT id FROM business_periods WHERE start_date = DATE(opening_stock.recorded_at) LIMIT 1)
WHERE period_date IS NULL;

UPDATE closing_stock 
SET period_date = DATE(recorded_at),
    period_id = (SELECT id FROM business_periods WHERE start_date = DATE(closing_stock.recorded_at) LIMIT 1)
WHERE period_date IS NULL;

-- Update daily_stock_summary with period_id
UPDATE daily_stock_summary 
SET period_id = (SELECT id FROM business_periods WHERE start_date = daily_stock_summary.date LIMIT 1)
WHERE period_id IS NULL;

-- Enable foreign key constraints
PRAGMA foreign_keys = ON; 