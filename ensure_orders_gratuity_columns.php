<?php
/**
 * Ensures pos.db orders table has gratuity columns (matches process_order.php).
 */
function ensure_orders_gratuity_columns(PDO $db): void {
    foreach ([
        'ALTER TABLE orders ADD COLUMN gratuity_amount REAL NOT NULL DEFAULT 0',
        'ALTER TABLE orders ADD COLUMN gratuity_percent_applied REAL',
        'ALTER TABLE orders ADD COLUMN gratuity_included_in_total INTEGER NOT NULL DEFAULT 1',
    ] as $sql) {
        try {
            $db->exec($sql);
        } catch (PDOException $e) {
            // Column already exists
        }
    }
}
