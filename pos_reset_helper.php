<?php
/**
 * pos.db transaction reset — clears all transactional data in pos.db.
 * Preserves catalog master data: products, product_settings, users.
 */

/** Tables that must never be cleared by transaction reset. */
function posDbProtectedTables(): array
{
    return [
        'products',
        'product_settings',
        'users',
    ];
}

/**
 * Known transactional tables in FK-safe delete order (child tables first).
 *
 * @return list<string>
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
        // Invoicing / quotations (child tables first)
        'invoice_payments',
        'invoice_items',
        'invoices',
        'quotation_items',
        'quotations',
        'customers',
        'document_sequence',
        // Lay-bye (child tables first; FKs point at laybye_accounts → creditors)
        'laybye_payments',
        'laybye_items',
        'laybye_accounts',
        'creditors',
        // Terminal tracking
        'terminals',
        // Category registry (not the products catalog itself)
        'product_categories',
    ];
}

/**
 * Merge the known ordered list with any other pos.db tables except protected ones.
 * Ensures newly added tables are cleared without updating this file every time.
 *
 * @return list<string>
 */
function posDbAllClearableTables(PDO $db): array
{
    $protected = array_flip(posDbProtectedTables());
    $ordered = posDbTransactionTables();
    $seen = array_flip($ordered);

    try {
        $stmt = $db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name COLLATE NOCASE");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $name = (string) ($row['name'] ?? '');
            if ($name === '' || isset($protected[$name]) || isset($seen[$name])) {
                continue;
            }
            $ordered[] = $name;
            $seen[$name] = true;
        }
    } catch (PDOException $e) {
        // Fall back to the explicit list only
    }

    return $ordered;
}

/** Same as posDbAllClearableTables() but omit creditors (cashout preserves some creditor rows first). */
function posDbTransactionTablesWithoutCreditors(PDO $db): array
{
    return array_values(array_filter(
        posDbAllClearableTables($db),
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
    posDbClearProductCategoryAssignments($db);
}

/**
 * Clear category registry and strip category labels from products (products rows are kept).
 */
function posDbClearProductCategoryAssignments(PDO $db): void
{
    try {
        $db->exec('DELETE FROM product_categories');
    } catch (PDOException $e) {
        // Table may not exist on older databases
    }
    try {
        $db->exec("UPDATE products SET category = '' WHERE category IS NOT NULL AND TRIM(category) != ''");
    } catch (PDOException $e) {
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

/** Delete all transactional rows and reset AUTOINCREMENT sequences. Keeps products/users/settings. */
function posDbResetAllTransactions(PDO $db): void
{
    $tables = posDbAllClearableTables($db);
    posDbDeleteAllFromTables($db, $tables);
    posDbResetSqliteSequences($db, $tables);
}
