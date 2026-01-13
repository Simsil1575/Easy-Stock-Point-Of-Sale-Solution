-- SQL Table for Tabs Management
-- This table tracks customer tabs (running credit accounts for bar/pub customers)

CREATE TABLE IF NOT EXISTS tabs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    creditor_id INTEGER,
    tab_name TEXT NOT NULL,
    opening_balance DECIMAL(10,2) DEFAULT 0.00,
    current_balance DECIMAL(10,2) DEFAULT 0.00,
    status TEXT NOT NULL DEFAULT 'open' CHECK(status IN ('open', 'closed')),
    opened_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    closed_at DATETIME,
    closed_by INTEGER,
    notes TEXT,
    cashier_id INTEGER,
    FOREIGN KEY(creditor_id) REFERENCES creditors(id),
    FOREIGN KEY(cashier_id) REFERENCES users(id)
);

-- Index for faster queries
CREATE INDEX IF NOT EXISTS idx_tabs_creditor_id ON tabs(creditor_id);
CREATE INDEX IF NOT EXISTS idx_tabs_status ON tabs(status);
CREATE INDEX IF NOT EXISTS idx_tabs_opened_at ON tabs(opened_at);

