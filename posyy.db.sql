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
	"payment_method"	TEXT NOT NULL CHECK("payment_method" IN ('cash', 'eft')),
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
	PRIMARY KEY("id" AUTOINCREMENT),
	FOREIGN KEY("cashier_id") REFERENCES "users"("id"),
	FOREIGN KEY("creditor_id") REFERENCES "creditors"("id")
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
CREATE INDEX IF NOT EXISTS "idx_closing_stock_date" ON "closing_stock" (
	"recorded_at"
);
CREATE INDEX IF NOT EXISTS "idx_closing_stock_product" ON "closing_stock" (
	"product_id"
);
CREATE INDEX IF NOT EXISTS "idx_daily_stock_summary_date" ON "daily_stock_summary" (
	"date"
);
CREATE INDEX IF NOT EXISTS "idx_daily_stock_summary_product" ON "daily_stock_summary" (
	"product_id"
);
CREATE INDEX IF NOT EXISTS "idx_opening_stock_date" ON "opening_stock" (
	"recorded_at"
);
CREATE INDEX IF NOT EXISTS "idx_opening_stock_product" ON "opening_stock" (
	"product_id"
);
CREATE INDEX IF NOT EXISTS "idx_tab_items_tab_id" ON "tab_items" (
	"tab_id"
);
CREATE INDEX IF NOT EXISTS "idx_tab_payments_tab_id" ON "tab_payments" (
	"tab_id"
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
COMMIT;
