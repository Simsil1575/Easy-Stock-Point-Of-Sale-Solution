BEGIN TRANSACTION;
CREATE TABLE IF NOT EXISTS "cash_transactions" (
	"id"	INTEGER,
	"type"	TEXT NOT NULL,
	"amount"	DECIMAL(10, 2) NOT NULL,
	"description"	TEXT,
	"created_at"	DATETIME DEFAULT CURRENT_TIMESTAMP,
	"cashier_id"	INTEGER,
	PRIMARY KEY("id" AUTOINCREMENT)
);
CREATE TABLE IF NOT EXISTS "cash_up_summary" (
	"id"	INTEGER,
	"date"	DATE NOT NULL,
	"credit_returns_count"	INTEGER DEFAULT 0,
	"credit_returns_amount"	DECIMAL(10, 2) DEFAULT 0,
	"eft_income_count"	INTEGER DEFAULT 0,
	"eft_income_amount"	DECIMAL(10, 2) DEFAULT 0,
	"damages_count"	INTEGER DEFAULT 0,
	"damages_amount"	DECIMAL(10, 2) DEFAULT 0,
	"created_at"	DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY("id" AUTOINCREMENT)
);
CREATE TABLE IF NOT EXISTS "cashup_records" (
	"id"	INTEGER,
	"cashup_date"	DATE NOT NULL,
	"cashier_id"	VARCHAR(100) DEFAULT 'all',
	"cashier_name"	VARCHAR(255) DEFAULT 'All Staff',
	"is_individual_cashout"	INTEGER DEFAULT 0,
	"cash_sales_expected"	DECIMAL(10, 2) DEFAULT 0.00,
	"cash_on_hand"	DECIMAL(10, 2) DEFAULT 0.00,
	"over_short"	DECIMAL(10, 2) DEFAULT 0.00,
	"card_sales_expected"	DECIMAL(10, 2) DEFAULT 0.00,
	"eft_on_hand"	DECIMAL(10, 2) DEFAULT 0.00,
	"eft_over_short"	DECIMAL(10, 2) DEFAULT 0.00,
	"unpaid_credit_sales"	DECIMAL(10, 2) DEFAULT 0.00,
	"open_tabs_balance"	DECIMAL(10, 2) DEFAULT 0.00,
	"unpaid_tabs"	DECIMAL(10, 2) DEFAULT 0.00,
	"credit_returns"	DECIMAL(10, 2) DEFAULT 0.00,
	"expenses"	DECIMAL(10, 2) DEFAULT 0.00,
	"cash_back"	DECIMAL(10, 2) DEFAULT 0.00,
	"tips"	DECIMAL(10, 2) DEFAULT 0.00,
	"hubbly"	DECIMAL(10, 2) DEFAULT 0.00,
	"beerhouse"	DECIMAL(10, 2) DEFAULT 0.00,
	"voids"	DECIMAL(10, 2) DEFAULT 0.00,
	"refunds"	DECIMAL(10, 2) DEFAULT 0.00,
	"total_items_sold"	DECIMAL(10, 2) DEFAULT 0.00,
	"created_by"	VARCHAR(255),
	"created_at"	DATETIME DEFAULT CURRENT_TIMESTAMP,
	"notes"	TEXT,
	UNIQUE("cashup_date","cashier_id"),
	PRIMARY KEY("id" AUTOINCREMENT)
);
CREATE TABLE IF NOT EXISTS "closing_stock" (
	"id"	INTEGER,
	"product_id"	INTEGER NOT NULL,
	"closing_quantity"	INTEGER NOT NULL,
	"recorded_at"	DATETIME DEFAULT CURRENT_TIMESTAMP,
	"recorded_by"	INTEGER,
	"notes"	TEXT,
	PRIMARY KEY("id" AUTOINCREMENT),
	FOREIGN KEY("product_id") REFERENCES "products"("id"),
	FOREIGN KEY("recorded_by") REFERENCES "users"("id")
);
CREATE TABLE IF NOT EXISTS "credit_book" (
	"id"	INTEGER,
	"customer_name"	TEXT NOT NULL,
	"credit_amount"	DECIMAL(10, 2) NOT NULL,
	"created_at"	DATETIME DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY("id" AUTOINCREMENT)
);
CREATE TABLE IF NOT EXISTS "credit_returns" (
	"id"	INTEGER,
	"credit_sale_id"	INTEGER,
	"return_amount"	DECIMAL(10, 2) NOT NULL,
	"reason"	TEXT,
	"created_at"	DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	"cashier_id"	TEXT,
	PRIMARY KEY("id" AUTOINCREMENT),
	FOREIGN KEY("credit_sale_id") REFERENCES "credit_sales"("id")
);
CREATE TABLE IF NOT EXISTS "credit_sale_items" (
	"id"	INTEGER,
	"sale_id"	INTEGER,
	"product_name"	TEXT,
	"quantity"	INTEGER,
	"price"	REAL,
	"buying_price"	DECIMAL(10, 2) DEFAULT NULL,
	PRIMARY KEY("id" AUTOINCREMENT),
	FOREIGN KEY("sale_id") REFERENCES "credit_sales"("id")
);
CREATE TABLE IF NOT EXISTS "credit_sales" (
	"id"	INTEGER,
	"creditor_id"	INTEGER,
	"total_amount"	REAL,
	"due_date"	DATE,
	"created_at"	DATETIME,
	"paid_amount"	REAL DEFAULT 0,
	"payment_status"	TEXT DEFAULT 'unpaid',
	"cashier_id"	INTEGER,
	PRIMARY KEY("id" AUTOINCREMENT),
	FOREIGN KEY("creditor_id") REFERENCES "creditors"("id")
);
CREATE TABLE IF NOT EXISTS "creditors" (
	"id"	INTEGER,
	"name"	TEXT NOT NULL,
	"phone"	TEXT,
	"credit_limit"	REAL DEFAULT 0,
	"balance"	REAL DEFAULT 0,
	"active"	INTEGER DEFAULT 1,
	"created_at"	DATETIME DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY("id" AUTOINCREMENT)
);
CREATE TABLE IF NOT EXISTS "daily_stock_summary" (
	"id"	INTEGER,
	"date"	DATE NOT NULL,
	"product_id"	INTEGER NOT NULL,
	"opening_quantity"	INTEGER NOT NULL,
	"closing_quantity"	INTEGER NOT NULL,
	"received_quantity"	INTEGER DEFAULT 0,
	"sold_quantity"	INTEGER DEFAULT 0,
	"damaged_quantity"	INTEGER DEFAULT 0,
	"created_at"	DATETIME DEFAULT CURRENT_TIMESTAMP,
	UNIQUE("date","product_id"),
	PRIMARY KEY("id" AUTOINCREMENT),
	FOREIGN KEY("product_id") REFERENCES "products"("id")
);
CREATE TABLE IF NOT EXISTS "damaged_goods" (
	"id"	INTEGER,
	"product_id"	INTEGER NOT NULL,
	"quantity"	INTEGER NOT NULL,
	"reason"	TEXT,
	"date"	DATETIME DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY("id" AUTOINCREMENT),
	FOREIGN KEY("product_id") REFERENCES "products"("id")
);
CREATE TABLE IF NOT EXISTS "eft_payments" (
	"id"	INTEGER,
	"order_id"	INTEGER,
	"transaction_ref"	TEXT NOT NULL,
	"wallet_provider"	TEXT NOT NULL,
	"amount"	REAL NOT NULL,
	"payment_date"	DATETIME DEFAULT CURRENT_TIMESTAMP,
	"status"	TEXT DEFAULT 'completed',
	"cashier_id"	INTEGER,
	PRIMARY KEY("id" AUTOINCREMENT),
	FOREIGN KEY("order_id") REFERENCES "orders"("id")
);
CREATE TABLE IF NOT EXISTS "mixed_payments" (
	"id"	INTEGER,
	"order_id"	INTEGER NOT NULL,
	"cash_amount"	DECIMAL(10, 2) NOT NULL DEFAULT 0,
	"eft_amount"	DECIMAL(10, 2) NOT NULL DEFAULT 0,
	"eft_transaction_ref"	TEXT,
	"eft_wallet_provider"	TEXT,
	"created_at"	DATETIME DEFAULT CURRENT_TIMESTAMP,
	"cashier_id"	TEXT,
	PRIMARY KEY("id" AUTOINCREMENT),
	FOREIGN KEY("order_id") REFERENCES "orders"("id")
);
CREATE TABLE IF NOT EXISTS "opening_stock" (
	"id"	INTEGER,
	"product_id"	INTEGER NOT NULL,
	"opening_quantity"	INTEGER NOT NULL,
	"recorded_at"	DATETIME DEFAULT CURRENT_TIMESTAMP,
	"recorded_by"	INTEGER,
	"notes"	TEXT,
	PRIMARY KEY("id" AUTOINCREMENT),
	FOREIGN KEY("product_id") REFERENCES "products"("id"),
	FOREIGN KEY("recorded_by") REFERENCES "users"("id")
);
CREATE TABLE IF NOT EXISTS "order_items" (
	"id"	INTEGER NOT NULL UNIQUE,
	"order_id"	int(11) DEFAULT NULL,
	"product_name"	varchar(255) NOT NULL,
	"quantity"	int(11) NOT NULL,
	"price"	decimal(10, 2) NOT NULL,
	"buying_price"	DECIMAL(10, 2) DEFAULT NULL,
	PRIMARY KEY("id" AUTOINCREMENT)
);
CREATE TABLE IF NOT EXISTS "orders" (
	"id"	INTEGER NOT NULL UNIQUE,
	"total"	DECIMAL(10, 2) NOT NULL,
	"cash_received"	DECIMAL(10, 2) NOT NULL,
	"created_at"	TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	"cashier_id"	INTEGER,
	PRIMARY KEY("id" AUTOINCREMENT)
);
CREATE TABLE IF NOT EXISTS "payment_logs" (
	"id"	INTEGER,
	"sale_id"	INTEGER NOT NULL,
	"amount"	DECIMAL(10, 2) NOT NULL,
	"previous_balance"	DECIMAL(10, 2) NOT NULL,
	"new_balance"	DECIMAL(10, 2) NOT NULL,
	"payment_type"	VARCHAR(50) NOT NULL,
	"created_at"	DATETIME NOT NULL,
	PRIMARY KEY("id" AUTOINCREMENT),
	FOREIGN KEY("sale_id") REFERENCES "credit_sales"("id")
);
CREATE TABLE IF NOT EXISTS "payments" (
	"id"	INTEGER,
	"sale_id"	INTEGER,
	"amount"	REAL,
	"payment_date"	DATETIME DEFAULT CURRENT_TIMESTAMP,
	"cashier_id"	INTEGER,
	PRIMARY KEY("id" AUTOINCREMENT),
	FOREIGN KEY("sale_id") REFERENCES "credit_sales"("id")
);
CREATE TABLE IF NOT EXISTS "product_settings" (
	"id"	INTEGER,
	"show_all_products"	BOOLEAN NOT NULL DEFAULT 0,
	"default_print_receipt"	BOOLEAN NOT NULL DEFAULT 0,
	"hide_available_quantity"	BOOLEAN NOT NULL DEFAULT 0,
	PRIMARY KEY("id" AUTOINCREMENT)
);
CREATE TABLE IF NOT EXISTS "products" (
	"id"	INTEGER NOT NULL UNIQUE,
	"name"	varchar(255) NOT NULL UNIQUE,
	"quantity"	INTEGER DEFAULT NULL,
	"price"	decimal(10, 2) NOT NULL,
	"buying_price"	decimal(10, 2) DEFAULT NULL,
	"image_url"	varchar(255) DEFAULT NULL,
	"restock_level"	NUMERIC DEFAULT 0,
	"capacity"	TEXT,
	"expiry_date"	TEXT,
	"barcode"	TEXT,
	"discount_start"	DATETIME,
	"discount_end"	DATETIME,
	"category"	TEXT,
	"discount"	REAL DEFAULT 0,
	PRIMARY KEY("id" AUTOINCREMENT)
);
CREATE TABLE IF NOT EXISTS "receiving_items" (
	"id"	INTEGER,
	"record_id"	INTEGER NOT NULL,
	"product_id"	INTEGER NOT NULL,
	"product_name"	TEXT NOT NULL,
	"quantity_added"	INTEGER NOT NULL,
	"old_quantity"	INTEGER NOT NULL,
	"new_quantity"	INTEGER NOT NULL,
	"unit_price"	DECIMAL(10, 2) NOT NULL,
	"buying_price"	DECIMAL(10, 2) NOT NULL,
	"total_value"	DECIMAL(10, 2) NOT NULL,
	"total_cost"	DECIMAL(10, 2) NOT NULL,
	PRIMARY KEY("id" AUTOINCREMENT),
	FOREIGN KEY("product_id") REFERENCES "products"("id"),
	FOREIGN KEY("record_id") REFERENCES "receiving_records"("id") ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS "receiving_records" (
	"id"	INTEGER,
	"user_id"	INTEGER NOT NULL,
	"username"	TEXT NOT NULL,
	"receiving_date"	DATETIME NOT NULL,
	"total_items"	INTEGER NOT NULL DEFAULT 0,
	"total_quantity"	INTEGER NOT NULL DEFAULT 0,
	"total_value"	DECIMAL(10, 2) NOT NULL DEFAULT 0,
	"total_cost"	DECIMAL(10, 2) NOT NULL DEFAULT 0,
	"email_status"	TEXT NOT NULL DEFAULT 'pending' CHECK("email_status" IN ('pending', 'sent', 'failed', 'skipped')),
	"email_attempts"	INTEGER NOT NULL DEFAULT 0,
	"email_error"	TEXT,
	"email_sent_at"	DATETIME,
	"created_at"	DATETIME DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY("id" AUTOINCREMENT)
);
CREATE TABLE IF NOT EXISTS "refund_items" (
	"id"	INTEGER,
	"refund_id"	INTEGER NOT NULL,
	"order_item_id"	INTEGER,
	"product_name"	TEXT NOT NULL,
	"quantity"	INTEGER NOT NULL,
	"price"	DECIMAL(10, 2) NOT NULL,
	"buying_price"	DECIMAL(10, 2) DEFAULT 0,
	PRIMARY KEY("id" AUTOINCREMENT),
	FOREIGN KEY("order_item_id") REFERENCES "order_items"("id"),
	FOREIGN KEY("refund_id") REFERENCES "refunds"("id")
);
CREATE TABLE IF NOT EXISTS "refunds" (
	"id"	INTEGER,
	"order_id"	INTEGER NOT NULL,
	"total_amount"	DECIMAL(10, 2) NOT NULL,
	"reason"	TEXT,
	"cashier_id"	INTEGER,
	"created_at"	DATETIME DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY("id" AUTOINCREMENT),
	FOREIGN KEY("cashier_id") REFERENCES "users"("id"),
	FOREIGN KEY("order_id") REFERENCES "orders"("id")
);
CREATE TABLE IF NOT EXISTS "stock_changes" (
	"id"	INTEGER,
	"product_id"	INTEGER NOT NULL,
	"action"	TEXT NOT NULL,
	"quantity_change"	INTEGER NOT NULL,
	"old_quantity"	INTEGER NOT NULL,
	"new_quantity"	INTEGER NOT NULL,
	"changed_at"	DATETIME DEFAULT CURRENT_TIMESTAMP,
	"stocktaken"	TINYINT(1) DEFAULT 0,
	"is_stock_taken"	INTEGER DEFAULT 0,
	PRIMARY KEY("id" AUTOINCREMENT),
	FOREIGN KEY("product_id") REFERENCES "products"("id")
);
CREATE TABLE IF NOT EXISTS "tab_item_payments" (
	"id"	INTEGER,
	"tab_item_id"	INTEGER NOT NULL,
	"payment_id"	INTEGER NOT NULL,
	"amount"	DECIMAL(10, 2) NOT NULL,
	PRIMARY KEY("id" AUTOINCREMENT),
	FOREIGN KEY("payment_id") REFERENCES "tab_payments"("id"),
	FOREIGN KEY("tab_item_id") REFERENCES "tab_items"("id")
);
CREATE TABLE IF NOT EXISTS "tab_items" (
	"id"	INTEGER,
	"tab_id"	INTEGER NOT NULL,
	"product_name"	TEXT NOT NULL,
	"quantity"	INTEGER NOT NULL DEFAULT 1,
	"price"	DECIMAL(10, 2) NOT NULL,
	"added_at"	DATETIME DEFAULT CURRENT_TIMESTAMP,
	"added_by"	INTEGER,
	PRIMARY KEY("id" AUTOINCREMENT),
	FOREIGN KEY("added_by") REFERENCES "users"("id"),
	FOREIGN KEY("tab_id") REFERENCES "tabs"("id") ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS "tab_payments" (
	"id"	INTEGER,
	"tab_id"	INTEGER NOT NULL,
	"amount"	DECIMAL(10, 2) NOT NULL,
	"payment_method"	TEXT NOT NULL CHECK("payment_method" IN ('cash', 'eft', 'mixed')),
	"transaction_ref"	TEXT,
	"wallet_provider"	TEXT,
	"payment_date"	DATETIME DEFAULT CURRENT_TIMESTAMP,
	"cashier_id"	INTEGER,
	"order_id"	INTEGER,
	PRIMARY KEY("id" AUTOINCREMENT),
	FOREIGN KEY("cashier_id") REFERENCES "users"("id"),
	FOREIGN KEY("tab_id") REFERENCES "tabs"("id") ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS "tabs" (
	"id"	INTEGER,
	"creditor_id"	INTEGER,
	"tab_name"	TEXT NOT NULL,
	"opening_balance"	DECIMAL(10, 2) DEFAULT 0.00,
	"current_balance"	DECIMAL(10, 2) DEFAULT 0.00,
	"status"	TEXT NOT NULL DEFAULT 'open' CHECK("status" IN ('open', 'closed')),
	"opened_at"	DATETIME DEFAULT CURRENT_TIMESTAMP,
	"closed_at"	DATETIME,
	"closed_by"	INTEGER,
	"notes"	TEXT,
	"cashier_id"	INTEGER,
	"pending_manager_approval"	INTEGER DEFAULT 0,
	PRIMARY KEY("id" AUTOINCREMENT),
	FOREIGN KEY("cashier_id") REFERENCES "users"("id"),
	FOREIGN KEY("creditor_id") REFERENCES "creditors"("id")
);
CREATE TABLE IF NOT EXISTS "tips" (
	"id"	INTEGER,
	"amount"	REAL NOT NULL,
	"payment_method"	TEXT NOT NULL,
	"cashier_id"	TEXT NOT NULL,
	"notes"	TEXT,
	"created_at"	TEXT NOT NULL,
	PRIMARY KEY("id" AUTOINCREMENT)
);
CREATE TABLE IF NOT EXISTS "user_log" (
	"id"	INTEGER,
	"user_id"	INTEGER NOT NULL,
	"action_type"	TEXT NOT NULL CHECK("action_type" IN ('login', 'logout')),
	"action_time"	TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY("id" AUTOINCREMENT),
	FOREIGN KEY("user_id") REFERENCES "users"("id") ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS "users" (
	"id"	INTEGER,
	"username"	TEXT NOT NULL UNIQUE,
	"password_hash"	TEXT NOT NULL,
	"role"	TEXT NOT NULL DEFAULT 'cashier',
	"email"	TEXT,
	"created_at"	DATETIME DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY("id" AUTOINCREMENT)
);
CREATE TABLE IF NOT EXISTS "void_transactions" (
	"id"	INTEGER,
	"order_id"	INTEGER,
	"total"	DECIMAL(10, 2) NOT NULL,
	"cash_received"	DECIMAL(10, 2) NOT NULL,
	"items"	TEXT NOT NULL,
	"payment_method"	TEXT,
	"transaction_ref"	TEXT,
	"wallet_provider"	TEXT,
	"eft_amount"	DECIMAL(10, 2),
	"cashier_id"	TEXT,
	"voided_at"	DATETIME DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY("id" AUTOINCREMENT),
	FOREIGN KEY("order_id") REFERENCES "orders"("id")
);
CREATE INDEX IF NOT EXISTS "idx_cash_transactions_cashier" ON "cash_transactions" (
	"cashier_id"
);
CREATE INDEX IF NOT EXISTS "idx_cash_transactions_created_at" ON "cash_transactions" (
	"created_at"
);
CREATE INDEX IF NOT EXISTS "idx_cash_transactions_type" ON "cash_transactions" (
	"type"
);
CREATE INDEX IF NOT EXISTS "idx_cash_up_summary_date" ON "cash_up_summary" (
	"date"
);
CREATE INDEX IF NOT EXISTS "idx_closing_stock_date" ON "closing_stock" (
	"recorded_at"
);
CREATE INDEX IF NOT EXISTS "idx_closing_stock_product" ON "closing_stock" (
	"product_id"
);
CREATE INDEX IF NOT EXISTS "idx_credit_book_customer" ON "credit_book" (
	"customer_name"
);
CREATE INDEX IF NOT EXISTS "idx_credit_returns_cashier" ON "credit_returns" (
	"cashier_id"
);
CREATE INDEX IF NOT EXISTS "idx_credit_returns_sale_id" ON "credit_returns" (
	"credit_sale_id"
);
CREATE INDEX IF NOT EXISTS "idx_credit_sale_items_sale_id" ON "credit_sale_items" (
	"sale_id"
);
CREATE INDEX IF NOT EXISTS "idx_credit_sales_cashier" ON "credit_sales" (
	"cashier_id"
);
CREATE INDEX IF NOT EXISTS "idx_credit_sales_creditor" ON "credit_sales" (
	"creditor_id"
);
CREATE INDEX IF NOT EXISTS "idx_credit_sales_status" ON "credit_sales" (
	"payment_status"
);
CREATE INDEX IF NOT EXISTS "idx_creditors_active" ON "creditors" (
	"active"
);
CREATE INDEX IF NOT EXISTS "idx_creditors_phone" ON "creditors" (
	"phone"
);
CREATE INDEX IF NOT EXISTS "idx_daily_stock_summary_date" ON "daily_stock_summary" (
	"date"
);
CREATE INDEX IF NOT EXISTS "idx_daily_stock_summary_product" ON "daily_stock_summary" (
	"product_id"
);
CREATE INDEX IF NOT EXISTS "idx_damaged_goods_date" ON "damaged_goods" (
	"date"
);
CREATE INDEX IF NOT EXISTS "idx_damaged_goods_product" ON "damaged_goods" (
	"product_id"
);
CREATE INDEX IF NOT EXISTS "idx_eft_payments_cashier" ON "eft_payments" (
	"cashier_id"
);
CREATE INDEX IF NOT EXISTS "idx_eft_payments_order" ON "eft_payments" (
	"order_id"
);
CREATE INDEX IF NOT EXISTS "idx_eft_payments_tx_ref" ON "eft_payments" (
	"transaction_ref"
);
CREATE INDEX IF NOT EXISTS "idx_mixed_payments_cashier" ON "mixed_payments" (
	"cashier_id"
);
CREATE INDEX IF NOT EXISTS "idx_mixed_payments_order" ON "mixed_payments" (
	"order_id"
);
CREATE INDEX IF NOT EXISTS "idx_opening_stock_date" ON "opening_stock" (
	"recorded_at"
);
CREATE INDEX IF NOT EXISTS "idx_opening_stock_product" ON "opening_stock" (
	"product_id"
);
CREATE INDEX IF NOT EXISTS "idx_order_items_order" ON "order_items" (
	"order_id"
);
CREATE INDEX IF NOT EXISTS "idx_orders_cashier" ON "orders" (
	"cashier_id"
);
CREATE INDEX IF NOT EXISTS "idx_orders_created_at" ON "orders" (
	"created_at"
);
CREATE INDEX IF NOT EXISTS "idx_payment_logs_sale" ON "payment_logs" (
	"sale_id"
);
CREATE INDEX IF NOT EXISTS "idx_payment_logs_type" ON "payment_logs" (
	"payment_type"
);
CREATE INDEX IF NOT EXISTS "idx_payments_cashier" ON "payments" (
	"cashier_id"
);
CREATE INDEX IF NOT EXISTS "idx_payments_sale" ON "payments" (
	"sale_id"
);
CREATE INDEX IF NOT EXISTS "idx_product_settings_show_all" ON "product_settings" (
	"show_all_products"
);
CREATE INDEX IF NOT EXISTS "idx_products_barcode" ON "products" (
	"barcode"
);
CREATE INDEX IF NOT EXISTS "idx_products_category" ON "products" (
	"category"
);
CREATE INDEX IF NOT EXISTS "idx_receiving_items_product" ON "receiving_items" (
	"product_id"
);
CREATE INDEX IF NOT EXISTS "idx_receiving_items_record" ON "receiving_items" (
	"record_id"
);
CREATE INDEX IF NOT EXISTS "idx_receiving_records_date" ON "receiving_records" (
	"receiving_date"
);
CREATE INDEX IF NOT EXISTS "idx_receiving_records_email_status" ON "receiving_records" (
	"email_status"
);
CREATE INDEX IF NOT EXISTS "idx_receiving_records_user" ON "receiving_records" (
	"user_id"
);
CREATE INDEX IF NOT EXISTS "idx_refund_items_refund_id" ON "refund_items" (
	"refund_id"
);
CREATE INDEX IF NOT EXISTS "idx_refunds_cashier_id" ON "refunds" (
	"cashier_id"
);
CREATE INDEX IF NOT EXISTS "idx_refunds_created_at" ON "refunds" (
	"created_at"
);
CREATE INDEX IF NOT EXISTS "idx_refunds_order_id" ON "refunds" (
	"order_id"
);
CREATE INDEX IF NOT EXISTS "idx_stock_changes_changed_at" ON "stock_changes" (
	"changed_at"
);
CREATE INDEX IF NOT EXISTS "idx_stock_changes_is_stock_taken" ON "stock_changes" (
	"is_stock_taken"
);
CREATE INDEX IF NOT EXISTS "idx_stock_changes_product" ON "stock_changes" (
	"product_id"
);
CREATE INDEX IF NOT EXISTS "idx_tab_item_payments_item" ON "tab_item_payments" (
	"tab_item_id"
);
CREATE INDEX IF NOT EXISTS "idx_tab_item_payments_payment" ON "tab_item_payments" (
	"payment_id"
);
CREATE INDEX IF NOT EXISTS "idx_tab_items_added_by" ON "tab_items" (
	"added_by"
);
CREATE INDEX IF NOT EXISTS "idx_tab_items_tab_id" ON "tab_items" (
	"tab_id"
);
CREATE INDEX IF NOT EXISTS "idx_tab_payments_cashier" ON "tab_payments" (
	"cashier_id"
);
CREATE INDEX IF NOT EXISTS "idx_tab_payments_method" ON "tab_payments" (
	"payment_method"
);
CREATE INDEX IF NOT EXISTS "idx_tab_payments_tab_id" ON "tab_payments" (
	"tab_id"
);
CREATE INDEX IF NOT EXISTS "idx_tabs_cashier" ON "tabs" (
	"cashier_id"
);
CREATE INDEX IF NOT EXISTS "idx_tabs_creditor_id" ON "tabs" (
	"creditor_id"
);
CREATE INDEX IF NOT EXISTS "idx_tabs_opened_at" ON "tabs" (
	"opened_at"
);
CREATE INDEX IF NOT EXISTS "idx_tabs_status" ON "tabs" (
	"status"
);
CREATE INDEX IF NOT EXISTS "idx_user_log_action_time" ON "user_log" (
	"action_time"
);
CREATE INDEX IF NOT EXISTS "idx_user_log_user" ON "user_log" (
	"user_id"
);
CREATE INDEX IF NOT EXISTS "idx_users_email" ON "users" (
	"email"
);
CREATE INDEX IF NOT EXISTS "idx_users_role" ON "users" (
	"role"
);
CREATE TABLE IF NOT EXISTS "laybye_accounts" (
	"id"	INTEGER,
	"creditor_id"	INTEGER NOT NULL,
	"reference"	TEXT,
	"total_amount"	DECIMAL(10, 2) NOT NULL DEFAULT 0,
	"balance_due"	DECIMAL(10, 2) NOT NULL DEFAULT 0,
	"deposit_amount"	DECIMAL(10, 2) NOT NULL DEFAULT 0,
	"plan_frequency"	TEXT NOT NULL DEFAULT 'weekly' CHECK("plan_frequency" IN ('weekly', 'monthly')),
	"installment_amount"	DECIMAL(10, 2) NOT NULL DEFAULT 0,
	"next_due_date"	DATE,
	"status"	TEXT NOT NULL DEFAULT 'active' CHECK("status" IN ('active', 'completed', 'cancelled')),
	"opened_at"	DATETIME DEFAULT CURRENT_TIMESTAMP,
	"closed_at"	DATETIME,
	"cashier_id"	TEXT,
	"notes"	TEXT,
	PRIMARY KEY("id" AUTOINCREMENT),
	FOREIGN KEY("creditor_id") REFERENCES "creditors"("id")
);
CREATE TABLE IF NOT EXISTS "laybye_items" (
	"id"	INTEGER,
	"laybye_id"	INTEGER NOT NULL,
	"product_name"	TEXT NOT NULL,
	"quantity"	INTEGER NOT NULL DEFAULT 1,
	"price"	DECIMAL(10, 2) NOT NULL,
	"buying_price"	DECIMAL(10, 2),
	"added_at"	DATETIME DEFAULT CURRENT_TIMESTAMP,
	"added_by"	TEXT,
	PRIMARY KEY("id" AUTOINCREMENT),
	FOREIGN KEY("laybye_id") REFERENCES "laybye_accounts"("id") ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS "laybye_payments" (
	"id"	INTEGER,
	"laybye_id"	INTEGER NOT NULL,
	"amount"	DECIMAL(10, 2) NOT NULL,
	"payment_method"	TEXT NOT NULL CHECK("payment_method" IN ('cash', 'eft', 'mixed')),
	"transaction_ref"	TEXT,
	"wallet_provider"	TEXT,
	"payment_date"	DATETIME DEFAULT CURRENT_TIMESTAMP,
	"cashier_id"	TEXT,
	"order_id"	INTEGER,
	"payment_kind"	TEXT NOT NULL DEFAULT 'installment' CHECK("payment_kind" IN ('deposit', 'installment', 'refund')),
	PRIMARY KEY("id" AUTOINCREMENT),
	FOREIGN KEY("laybye_id") REFERENCES "laybye_accounts"("id") ON DELETE CASCADE
);
COMMIT;
