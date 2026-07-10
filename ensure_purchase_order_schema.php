<?php

/**
 * Ensures purchase order tables and optional receiving_records link exist on pos.db.
 */
function ensurePurchaseOrderSchema(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS suppliers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            phone TEXT,
            email TEXT,
            notes TEXT,
            active INTEGER NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS purchase_orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            supplier_id INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft', 'ordered', 'cancelled', 'partially_received', 'received')),
            order_date DATE NOT NULL,
            expected_date DATE,
            notes TEXT,
            total_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            created_by TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS purchase_order_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            purchase_order_id INTEGER NOT NULL,
            product_id INTEGER,
            product_name TEXT NOT NULL,
            quantity INTEGER NOT NULL DEFAULT 1,
            quantity_received INTEGER NOT NULL DEFAULT 0,
            unit_cost DECIMAL(10,2) NOT NULL DEFAULT 0,
            line_total DECIMAL(10,2) NOT NULL DEFAULT 0,
            FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
            FOREIGN KEY (product_id) REFERENCES products(id)
        )
    ");

    poMigratePurchaseOrdersStatus($db);
    poEnsureColumn($db, 'purchase_order_items', 'quantity_received', 'INTEGER NOT NULL DEFAULT 0');
    poEnsureReceivingRecordColumns($db);

    $db->exec('CREATE INDEX IF NOT EXISTS idx_purchase_orders_supplier ON purchase_orders(supplier_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_purchase_orders_status ON purchase_orders(status)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_po_items_po ON purchase_order_items(purchase_order_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_receiving_records_supplier ON receiving_records(supplier_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_receiving_records_po ON receiving_records(purchase_order_id)');
}

function poEnsureColumn(PDO $db, string $table, string $column, string $definition): void
{
    $cols = $db->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        if (($c['name'] ?? '') === $column) {
            return;
        }
    }
    $db->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
}

function poEnsureReceivingRecordColumns(PDO $db): void
{
    $tableExists = (int) $db->query(
        "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='receiving_records'"
    )->fetchColumn();
    if ($tableExists === 0) {
        return;
    }
    poEnsureColumn($db, 'receiving_records', 'purchase_order_id', 'INTEGER REFERENCES purchase_orders(id)');
    poEnsureColumn($db, 'receiving_records', 'supplier_id', 'INTEGER REFERENCES suppliers(id)');
}

function poMigratePurchaseOrdersStatus(PDO $db): void
{
    $row = $db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='purchase_orders'")->fetch(PDO::FETCH_ASSOC);
    $sql = (string) ($row['sql'] ?? '');
    if ($sql === '' || strpos($sql, 'partially_received') !== false) {
        return;
    }

    $db->exec('PRAGMA foreign_keys = OFF');
    $db->beginTransaction();
    try {
        $db->exec("
            CREATE TABLE purchase_orders_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                supplier_id INTEGER NOT NULL,
                status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft', 'ordered', 'cancelled', 'partially_received', 'received')),
                order_date DATE NOT NULL,
                expected_date DATE,
                notes TEXT,
                total_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
                created_by TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
            )
        ");
        $db->exec("
            INSERT INTO purchase_orders_new
                (id, supplier_id, status, order_date, expected_date, notes, total_amount, created_by, created_at, updated_at)
            SELECT id, supplier_id, status, order_date, expected_date, notes, total_amount, created_by, created_at, updated_at
            FROM purchase_orders
        ");
        $db->exec('DROP TABLE purchase_orders');
        $db->exec('ALTER TABLE purchase_orders_new RENAME TO purchase_orders');
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    } finally {
        $db->exec('PRAGMA foreign_keys = ON');
    }
}
