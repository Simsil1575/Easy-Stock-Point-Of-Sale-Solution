<?php

function productUpdatedAtNow(): string
{
    return date('Y-m-d H:i:s');
}

function ensureProductUpdatedAtSchema(PDO $db): void
{
    $cols = $db->query('PRAGMA table_info(products)')->fetchAll(PDO::FETCH_ASSOC);
    $hasColumn = false;
    foreach ($cols as $col) {
        if (($col['name'] ?? '') === 'updated_at') {
            $hasColumn = true;
            break;
        }
    }
    if (!$hasColumn) {
        $db->exec("ALTER TABLE products ADD COLUMN updated_at TEXT NOT NULL DEFAULT (datetime('now'))");
    }
}

function ensureProductUpdatedAtSchemaSQLite3(SQLite3 $db): void
{
    $cols = $db->query('PRAGMA table_info(products)');
    $hasColumn = false;
    while ($col = $cols->fetchArray(SQLITE3_ASSOC)) {
        if (($col['name'] ?? '') === 'updated_at') {
            $hasColumn = true;
            break;
        }
    }
    if (!$hasColumn) {
        $db->exec("ALTER TABLE products ADD COLUMN updated_at TEXT NOT NULL DEFAULT (datetime('now'))");
    }
}
