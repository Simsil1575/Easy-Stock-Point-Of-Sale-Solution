-- ============================================
-- POS BUSINESS REPORTS - SQL QUERY EXAMPLES
-- ============================================
-- These are example queries for generating business reports from your POS database
-- Adjust date ranges and filters as needed

-- ============================================
-- 1. DAILY SALES REPORT
-- ============================================

-- Total Sales for a Specific Date
SELECT 
    DATE(created_at) as sale_date,
    COUNT(DISTINCT o.id) as transaction_count,
    SUM(o.total) as total_sales,
    SUM(o.cash_received) as cash_received,
    AVG(o.total) as avg_transaction_value
FROM orders o
WHERE DATE(created_at) = '2024-01-15'
GROUP BY DATE(created_at);

-- Sales by Payment Method (Cash, EFT, Mixed)
SELECT 
    DATE(o.created_at) as sale_date,
    'Cash' as payment_method,
    COUNT(*) as transaction_count,
    SUM(o.total) as total_amount
FROM orders o
WHERE DATE(o.created_at) = '2024-01-15'
    AND NOT EXISTS (SELECT 1 FROM eft_payments ep WHERE ep.order_id = o.id)
    AND NOT EXISTS (SELECT 1 FROM mixed_payments mp WHERE mp.order_id = o.id)
GROUP BY DATE(o.created_at)

UNION ALL

SELECT 
    DATE(o.created_at) as sale_date,
    'EFT' as payment_method,
    COUNT(*) as transaction_count,
    SUM(ep.amount) as total_amount
FROM orders o
JOIN eft_payments ep ON ep.order_id = o.id
WHERE DATE(o.created_at) = '2024-01-15'
GROUP BY DATE(o.created_at)

UNION ALL

SELECT 
    DATE(o.created_at) as sale_date,
    'Mixed' as payment_method,
    COUNT(*) as transaction_count,
    SUM(mp.cash_amount + mp.eft_amount) as total_amount
FROM orders o
JOIN mixed_payments mp ON mp.order_id = o.id
WHERE DATE(o.created_at) = '2024-01-15'
GROUP BY DATE(o.created_at);

-- ============================================
-- 2. PRODUCT SALES PERFORMANCE
-- ============================================

-- Top 10 Best-Selling Products (by quantity)
SELECT 
    oi.product_name,
    SUM(oi.quantity) as total_quantity_sold,
    SUM(oi.quantity * oi.price) as total_revenue,
    COUNT(DISTINCT oi.order_id) as times_sold
FROM order_items oi
JOIN orders o ON o.id = oi.order_id
WHERE DATE(o.created_at) BETWEEN '2024-01-01' AND '2024-01-31'
GROUP BY oi.product_name
ORDER BY total_quantity_sold DESC
LIMIT 10;

-- Product Sales with Profit Analysis
SELECT 
    oi.product_name,
    SUM(oi.quantity) as quantity_sold,
    SUM(oi.quantity * oi.price) as revenue,
    SUM(oi.quantity * COALESCE(oi.buying_price, 0)) as cost,
    SUM(oi.quantity * oi.price) - SUM(oi.quantity * COALESCE(oi.buying_price, 0)) as profit,
    ROUND(
        ((SUM(oi.quantity * oi.price) - SUM(oi.quantity * COALESCE(oi.buying_price, 0))) 
         / SUM(oi.quantity * oi.price)) * 100, 2
    ) as profit_margin_percent
FROM order_items oi
JOIN orders o ON o.id = oi.order_id
WHERE DATE(o.created_at) BETWEEN '2024-01-01' AND '2024-01-31'
GROUP BY oi.product_name
HAVING profit > 0
ORDER BY profit DESC;

-- ============================================
-- 3. SALES BY CASHIER PERFORMANCE
-- ============================================

-- Cashier Performance Report
SELECT 
    u.username,
    u.role,
    COUNT(DISTINCT o.id) as transaction_count,
    SUM(o.total) as total_sales,
    AVG(o.total) as avg_transaction_value,
    MIN(o.created_at) as first_transaction,
    MAX(o.created_at) as last_transaction
FROM orders o
JOIN users u ON u.id = o.cashier_id
WHERE DATE(o.created_at) BETWEEN '2024-01-01' AND '2024-01-31'
GROUP BY u.id, u.username, u.role
ORDER BY total_sales DESC;

-- ============================================
-- 4. PROFIT & LOSS REPORT
-- ============================================

-- Daily P&L Report
SELECT 
    DATE(o.created_at) as sale_date,
    SUM(oi.quantity * oi.price) as total_revenue,
    SUM(oi.quantity * COALESCE(oi.buying_price, 0)) as total_cost,
    SUM(oi.quantity * oi.price) - SUM(oi.quantity * COALESCE(oi.buying_price, 0)) as gross_profit,
    ROUND(
        ((SUM(oi.quantity * oi.price) - SUM(oi.quantity * COALESCE(oi.buying_price, 0))) 
         / SUM(oi.quantity * oi.price)) * 100, 2
    ) as profit_margin_percent
FROM orders o
JOIN order_items oi ON oi.order_id = o.id
WHERE DATE(o.created_at) BETWEEN '2024-01-01' AND '2024-01-31'
GROUP BY DATE(o.created_at)
ORDER BY sale_date;

-- ============================================
-- 5. INVENTORY REPORTS
-- ============================================

-- Low Stock Alert Report
SELECT 
    id,
    name,
    category,
    quantity,
    restock_level,
    (restock_level - quantity) as units_below_level,
    price,
    buying_price,
    (quantity * COALESCE(buying_price, 0)) as stock_value
FROM products
WHERE quantity <= restock_level
ORDER BY (restock_level - quantity) DESC;

-- Current Stock Valuation
SELECT 
    category,
    COUNT(*) as product_count,
    SUM(quantity) as total_quantity,
    SUM(quantity * COALESCE(buying_price, 0)) as total_stock_value
FROM products
WHERE quantity > 0
GROUP BY category
ORDER BY total_stock_value DESC;

-- Stock Movement Report
SELECT 
    p.name as product_name,
    sc.action,
    sc.quantity_change,
    sc.old_quantity,
    sc.new_quantity,
    sc.changed_at,
    u.username as changed_by
FROM stock_changes sc
JOIN products p ON p.id = sc.product_id
LEFT JOIN users u ON u.id = sc.changed_at
WHERE DATE(sc.changed_at) BETWEEN '2024-01-01' AND '2024-01-31'
ORDER BY sc.changed_at DESC;

-- Daily Stock Summary
SELECT 
    dss.date,
    p.name as product_name,
    p.category,
    dss.opening_quantity,
    dss.received_quantity,
    dss.sold_quantity,
    dss.damaged_quantity,
    dss.closing_quantity
FROM daily_stock_summary dss
JOIN products p ON p.id = dss.product_id
WHERE dss.date BETWEEN '2024-01-01' AND '2024-01-31'
ORDER BY dss.date DESC, p.name;

-- ============================================
-- 6. CREDIT SALES & ACCOUNTS RECEIVABLE
-- ============================================

-- Accounts Receivable Aging Report
SELECT 
    c.id,
    c.name as creditor_name,
    c.phone,
    c.credit_limit,
    c.balance as current_balance,
    COUNT(cs.id) as unpaid_sales_count,
    SUM(cs.total_amount - cs.paid_amount) as total_outstanding,
    MIN(cs.due_date) as oldest_due_date,
    CASE 
        WHEN MIN(cs.due_date) < DATE('now', '-90 days') THEN '90+ Days'
        WHEN MIN(cs.due_date) < DATE('now', '-60 days') THEN '61-90 Days'
        WHEN MIN(cs.due_date) < DATE('now', '-30 days') THEN '31-60 Days'
        WHEN MIN(cs.due_date) < DATE('now') THEN '0-30 Days'
        ELSE 'Current'
    END as aging_bucket
FROM creditors c
LEFT JOIN credit_sales cs ON cs.creditor_id = c.id 
    AND cs.payment_status IN ('unpaid', 'partial')
WHERE c.active = 1
GROUP BY c.id, c.name, c.phone, c.credit_limit, c.balance
HAVING total_outstanding > 0
ORDER BY total_outstanding DESC;

-- Credit Sales Summary
SELECT 
    DATE(cs.created_at) as sale_date,
    COUNT(*) as credit_sale_count,
    SUM(cs.total_amount) as total_credit_sales,
    SUM(cs.paid_amount) as total_paid,
    SUM(cs.total_amount - cs.paid_amount) as total_outstanding,
    COUNT(CASE WHEN cs.payment_status = 'paid' THEN 1 END) as paid_count,
    COUNT(CASE WHEN cs.payment_status = 'unpaid' THEN 1 END) as unpaid_count,
    COUNT(CASE WHEN cs.payment_status = 'partial' THEN 1 END) as partial_count
FROM credit_sales cs
WHERE DATE(cs.created_at) BETWEEN '2024-01-01' AND '2024-01-31'
GROUP BY DATE(cs.created_at)
ORDER BY sale_date;

-- ============================================
-- 7. CASH FLOW REPORT
-- ============================================

-- Daily Cash Flow
SELECT 
    DATE(created_at) as transaction_date,
    type,
    SUM(CASE WHEN type IN ('income', 'sale', 'payment') THEN amount ELSE 0 END) as cash_in,
    SUM(CASE WHEN type IN ('expense', 'withdrawal', 'payout') THEN amount ELSE 0 END) as cash_out,
    SUM(CASE WHEN type IN ('income', 'sale', 'payment') THEN amount ELSE -amount END) as net_cash_flow
FROM cash_transactions
WHERE DATE(created_at) BETWEEN '2024-01-01' AND '2024-01-31'
GROUP BY DATE(created_at), type
ORDER BY transaction_date, type;

-- ============================================
-- 8. DAMAGED GOODS REPORT
-- ============================================

-- Damaged Goods Summary
SELECT 
    p.name as product_name,
    p.category,
    SUM(dg.quantity) as total_damaged_quantity,
    SUM(dg.quantity * COALESCE(p.buying_price, 0)) as total_damage_value,
    COUNT(dg.id) as damage_incidents,
    GROUP_CONCAT(DISTINCT dg.reason) as reasons
FROM damaged_goods dg
JOIN products p ON p.id = dg.product_id
WHERE DATE(dg.date) BETWEEN '2024-01-01' AND '2024-01-31'
GROUP BY p.id, p.name, p.category
ORDER BY total_damage_value DESC;

-- ============================================
-- 9. TAB/ACCOUNT REPORTS
-- ============================================

-- Open Tabs Report
SELECT 
    t.id,
    t.tab_name,
    c.name as creditor_name,
    t.opening_balance,
    t.current_balance,
    t.status,
    t.opened_at,
    DATE('now') - DATE(t.opened_at) as days_open,
    u.username as opened_by
FROM tabs t
LEFT JOIN creditors c ON c.id = t.creditor_id
LEFT JOIN users u ON u.id = t.cashier_id
WHERE t.status = 'open'
ORDER BY t.current_balance DESC;

-- Tab Activity Summary
SELECT 
    DATE(t.opened_at) as date,
    COUNT(*) as tabs_opened,
    COUNT(CASE WHEN t.status = 'closed' THEN 1 END) as tabs_closed,
    SUM(t.current_balance) as total_outstanding,
    SUM(ti.quantity * ti.price) as total_items_value,
    SUM(tp.amount) as total_payments
FROM tabs t
LEFT JOIN tab_items ti ON ti.tab_id = t.id
LEFT JOIN tab_payments tp ON tp.tab_id = t.id
WHERE DATE(t.opened_at) BETWEEN '2024-01-01' AND '2024-01-31'
GROUP BY DATE(t.opened_at)
ORDER BY date;

-- ============================================
-- 10. EFT TRANSACTIONS REPORT
-- ============================================

-- EFT Transactions by Provider
SELECT 
    wallet_provider,
    COUNT(*) as transaction_count,
    SUM(amount) as total_amount,
    COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_count,
    COUNT(CASE WHEN status != 'completed' THEN 1 END) as failed_count,
    AVG(amount) as avg_transaction_amount
FROM eft_payments
WHERE DATE(payment_date) BETWEEN '2024-01-01' AND '2024-01-31'
GROUP BY wallet_provider
ORDER BY total_amount DESC;

-- ============================================
-- 11. SALES BY CATEGORY
-- ============================================

-- Category Performance Report
SELECT 
    p.category,
    COUNT(DISTINCT oi.order_id) as transaction_count,
    SUM(oi.quantity) as total_quantity_sold,
    SUM(oi.quantity * oi.price) as total_revenue,
    SUM(oi.quantity * COALESCE(oi.buying_price, 0)) as total_cost,
    SUM(oi.quantity * oi.price) - SUM(oi.quantity * COALESCE(oi.buying_price, 0)) as profit
FROM order_items oi
JOIN orders o ON o.id = oi.order_id
JOIN products p ON p.name = oi.product_name
WHERE DATE(o.created_at) BETWEEN '2024-01-01' AND '2024-01-31'
    AND p.category IS NOT NULL
GROUP BY p.category
ORDER BY total_revenue DESC;

-- ============================================
-- 12. COMPREHENSIVE DAILY SUMMARY
-- ============================================

-- Complete Daily Business Summary
SELECT 
    DATE(o.created_at) as business_date,
    -- Sales Metrics
    COUNT(DISTINCT o.id) as cash_transactions,
    SUM(o.total) as cash_sales,
    COUNT(DISTINCT cs.id) as credit_transactions,
    SUM(cs.total_amount) as credit_sales,
    COUNT(DISTINCT t.id) as tabs_opened,
    -- Payment Methods
    SUM(ep.amount) as eft_sales,
    SUM(mp.cash_amount + mp.eft_amount) as mixed_sales,
    -- Financial
    SUM(oi.quantity * oi.price) as total_revenue,
    SUM(oi.quantity * COALESCE(oi.buying_price, 0)) as total_cost,
    SUM(oi.quantity * oi.price) - SUM(oi.quantity * COALESCE(oi.buying_price, 0)) as gross_profit,
    -- Other
    SUM(dg.quantity * COALESCE(p.buying_price, 0)) as damage_value
FROM orders o
LEFT JOIN order_items oi ON oi.order_id = o.id
LEFT JOIN credit_sales cs ON DATE(cs.created_at) = DATE(o.created_at)
LEFT JOIN tabs t ON DATE(t.opened_at) = DATE(o.created_at)
LEFT JOIN eft_payments ep ON ep.order_id = o.id
LEFT JOIN mixed_payments mp ON mp.order_id = o.id
LEFT JOIN damaged_goods dg ON DATE(dg.date) = DATE(o.created_at)
LEFT JOIN products p ON p.id = dg.product_id
WHERE DATE(o.created_at) = '2024-01-15'
GROUP BY DATE(o.created_at);

-- ============================================
-- 13. USER ACTIVITY REPORT
-- ============================================

-- Staff Login/Logout Activity
SELECT 
    u.username,
    u.role,
    DATE(ul.action_time) as activity_date,
    COUNT(CASE WHEN ul.action_type = 'login' THEN 1 END) as login_count,
    MIN(CASE WHEN ul.action_type = 'login' THEN ul.action_time END) as first_login,
    MAX(CASE WHEN ul.action_type = 'logout' THEN ul.action_time END) as last_logout
FROM user_log ul
JOIN users u ON u.id = ul.user_id
WHERE DATE(ul.action_time) BETWEEN '2024-01-01' AND '2024-01-31'
GROUP BY u.id, u.username, u.role, DATE(ul.action_time)
ORDER BY activity_date DESC, u.username;

-- ============================================
-- NOTES:
-- ============================================
-- 1. Replace date ranges ('2024-01-01' to '2024-01-31') with your desired dates
-- 2. Adjust table names if your database uses different naming conventions
-- 3. Some queries may need optimization for large datasets
-- 4. Consider adding indexes on frequently queried columns
-- 5. Use parameterized queries in your application to prevent SQL injection
-- 6. Test queries on a sample dataset first
-- ============================================







