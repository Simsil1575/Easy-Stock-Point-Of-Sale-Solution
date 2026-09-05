<?php

/**
 * Products can be marked as non-line items so they are omitted from sales reports
 * (e.g. service fees, deposits, internal charges).
 */
function ensureProductReportSchema(PDO $db): void
{
    $cols = $db->query('PRAGMA table_info(products)')->fetchAll(PDO::FETCH_ASSOC);
    $hasColumn = false;
    foreach ($cols as $col) {
        if (($col['name'] ?? '') === 'exclude_from_reports') {
            $hasColumn = true;
            break;
        }
    }
    if (!$hasColumn) {
        $db->exec('ALTER TABLE products ADD COLUMN exclude_from_reports INTEGER NOT NULL DEFAULT 0');
    }
}

function ensureProductReportSchemaSQLite3(SQLite3 $db): void
{
    $cols = $db->query('PRAGMA table_info(products)');
    $hasColumn = false;
    while ($col = $cols->fetchArray(SQLITE3_ASSOC)) {
        if (($col['name'] ?? '') === 'exclude_from_reports') {
            $hasColumn = true;
            break;
        }
    }
    if (!$hasColumn) {
        $db->exec('ALTER TABLE products ADD COLUMN exclude_from_reports INTEGER NOT NULL DEFAULT 0');
    }
}

/**
 * SQL fragment: include only products that should appear as line items in reports.
 *
 * @param string $productNameColumn e.g. "oi.product_name" or "t.product_name"
 */
function reportLineItemWhereInclude(string $productNameColumn): string
{
    return 'NOT EXISTS (SELECT 1 FROM products pr WHERE pr.name = ' . $productNameColumn . ' AND COALESCE(pr.exclude_from_reports, 0) = 1)';
}

/**
 * SQL fragment: include only products that belong in inventory/catalog reports.
 *
 * @param string $tableAlias e.g. "p" for "p.exclude_from_reports"
 */
function reportProductWhereInclude(string $tableAlias = ''): string
{
    $col = $tableAlias !== '' ? $tableAlias . '.exclude_from_reports' : 'exclude_from_reports';
    return 'COALESCE(' . $col . ', 0) = 0';
}

function productIsLineItemById(PDO $db, int $productId): bool
{
    ensureProductReportSchema($db);
    $stmt = $db->prepare('SELECT COALESCE(exclude_from_reports, 0) FROM products WHERE id = ?');
    $stmt->execute([$productId]);
    $val = $stmt->fetchColumn();

    return $val !== false && (int) $val === 0;
}
