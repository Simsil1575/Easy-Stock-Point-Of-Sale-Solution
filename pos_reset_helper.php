<?php
/**
 * pos.db transaction reset — table list aligned with pos.db.sql (excludes catalog & settings).
 * Does not delete: products, product_settings, users (master / login-related data).
 */

function posDbTransactionTables(): array
{
    return [
        'tab_item_payments',
        'tab_items',
        'tab_payments',
        'tabs',
        'refund_items',
        'refunds',
        'eft_payments',
        'mixed_payments',
        'order_items',
        'orders',
        'payment_logs',
        'payments',
        'credit_sale_items',
        'credit_sales',
        'credit_returns',
        'credit_book',
        'cash_transactions',
        'cash_up_summary',
        'cashup_records',
        'tips',
        'void_transactions',
        'user_log',
        'damaged_goods',
        'stock_changes',
        'opening_stock',
        'closing_stock',
        'daily_stock_summary',
        'receiving_items',
        'receiving_records',
        'purchase_order_items',
        'purchase_orders',
        'suppliers',
        'recipe_items',
        'product_recipes',
        // Lay-bye (child tables first; FKs point at laybye_accounts → creditors)
        'laybye_payments',
        'laybye_items',
        'laybye_accounts',
        'creditors',
    ];
}

/** Same as posDbTransactionTables() but omit creditors (cashout preserves some creditor rows first). */
function posDbTransactionTablesWithoutCreditors(): array
{
    return array_values(array_filter(
        posDbTransactionTables(),
        static function ($t) {
            return $t !== 'creditors';
        }
    ));
}

function posDbDeleteAllFromTables(PDO $db, array $tables): void
{
    foreach ($tables as $table) {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $table)) {
            continue;
        }
        try {
            $db->exec('DELETE FROM "' . str_replace('"', '""', $table) . '"');
        } catch (PDOException $e) {
            // Older DBs may miss a table
        }
    }
}

/** Clear sqlite_sequence rows so the next AUTOINCREMENT id starts at 1. */
function posDbResetSqliteSequences(PDO $db, array $tables): void
{
    try {
        $chk = $db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='sqlite_sequence' LIMIT 1");
        if (!$chk || !$chk->fetchColumn()) {
            return;
        }
    } catch (PDOException $e) {
        return;
    }
    foreach ($tables as $table) {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $table)) {
            continue;
        }
        try {
            $db->exec('DELETE FROM sqlite_sequence WHERE name = ' . $db->quote($table));
        } catch (PDOException $e) {
        }
    }
}

/**
 * After INSERTs with explicit ids, set sqlite_sequence.seq to MAX(id) so the next row gets a fresh id.
 */
function posDbResequenceAfterExplicitInserts(PDO $db, string $table): void
{
    if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $table)) {
        return;
    }
    $q = '"' . str_replace('"', '""', $table) . '"';
    try {
        $max = (int) $db->query("SELECT COALESCE(MAX(id), 0) FROM $q")->fetchColumn();
    } catch (PDOException $e) {
        return;
    }
    try {
        $db->exec('DELETE FROM sqlite_sequence WHERE name = ' . $db->quote($table));
        if ($max > 0) {
            $db->exec('INSERT INTO sqlite_sequence (name, seq) VALUES (' . $db->quote($table) . ', ' . $max . ')');
        }
    } catch (PDOException $e) {
    }
}
