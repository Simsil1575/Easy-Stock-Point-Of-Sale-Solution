<?php

/**
 * Ensures lay-bye tables and synthetic product exist on pos.db.
 */
function ensureLaybyeSchema(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS laybye_accounts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            creditor_id INTEGER NOT NULL,
            reference TEXT,
            total_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            balance_due DECIMAL(10,2) NOT NULL DEFAULT 0,
            deposit_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            plan_frequency TEXT NOT NULL DEFAULT 'weekly' CHECK(plan_frequency IN ('weekly', 'monthly')),
            plan_period INTEGER NOT NULL DEFAULT 12,
            installment_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            next_due_date DATE,
            status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active', 'completed', 'cancelled')),
            opened_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            closed_at DATETIME,
            cashier_id TEXT,
            notes TEXT,
            FOREIGN KEY (creditor_id) REFERENCES creditors(id)
        )
    ");

    $lbCols = $db->query('PRAGMA table_info(laybye_accounts)')->fetchAll(PDO::FETCH_ASSOC);
    $hasPlanPeriod = false;
    foreach ($lbCols as $c) {
        if (($c['name'] ?? '') === 'plan_period') {
            $hasPlanPeriod = true;
            break;
        }
    }
    if (!$hasPlanPeriod) {
        $db->exec('ALTER TABLE laybye_accounts ADD COLUMN plan_period INTEGER NOT NULL DEFAULT 12');
    }

    $db->exec("
        CREATE TABLE IF NOT EXISTS laybye_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            laybye_id INTEGER NOT NULL,
            product_name TEXT NOT NULL,
            quantity INTEGER NOT NULL DEFAULT 1,
            price DECIMAL(10,2) NOT NULL,
            buying_price DECIMAL(10,2),
            added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            added_by TEXT,
            FOREIGN KEY (laybye_id) REFERENCES laybye_accounts(id) ON DELETE CASCADE
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS laybye_payments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            laybye_id INTEGER NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            payment_method TEXT NOT NULL CHECK(payment_method IN ('cash', 'eft', 'mixed')),
            transaction_ref TEXT,
            wallet_provider TEXT,
            payment_date DATETIME DEFAULT CURRENT_TIMESTAMP,
            cashier_id TEXT,
            order_id INTEGER,
            payment_kind TEXT NOT NULL DEFAULT 'installment' CHECK(payment_kind IN ('deposit', 'installment', 'refund')),
            FOREIGN KEY (laybye_id) REFERENCES laybye_accounts(id) ON DELETE CASCADE
        )
    ");


}

/** Product name used for order_lines / till (no stock impact). */
function laybyePaymentProductName(): string
{
    return 'Lay-bye Payment';
}

/**
 * SQL fragment to exclude the synthetic lay-bye payment product from POS/inventory grids.
 *
 * @param string $nameColumn e.g. "p.name" or "name"
 */
function laybyePaymentProductWhereExclude(string $nameColumn = 'name'): string
{
    $n = str_replace("'", "''", laybyePaymentProductName());
    return $nameColumn . " != '" . $n . "'";
}

/**
 * Planned number of payments for display; uses frequency defaults if plan_period is missing.
 *
 * @param array<string,mixed> $acc laybye_accounts row (or list row including l.*).
 */
function laybyeEffectivePlanPeriod(array $acc): int
{
    $p = (int) ($acc['plan_period'] ?? 0);
    if ($p >= 1) {
        return min(120, $p);
    }
    $f = strtolower((string) ($acc['plan_frequency'] ?? 'weekly'));
    return $f === 'monthly' ? 4 : 12;
}
