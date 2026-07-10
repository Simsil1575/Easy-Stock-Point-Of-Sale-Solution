<?php
/**
 * Tab-level gratuity (not a product line): toggle + amount paid toward gratuity on the tab.
 */
function ensureTabGratuityColumns(PDO $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    foreach ([
        'ALTER TABLE tabs ADD COLUMN gratuity_enabled INTEGER NOT NULL DEFAULT 1',
        'ALTER TABLE tabs ADD COLUMN gratuity_paid REAL NOT NULL DEFAULT 0',
    ] as $sql) {
        try {
            $db->exec($sql);
        } catch (PDOException $e) {
            // Column already exists
        }
    }
    try {
        $db->exec('ALTER TABLE product_settings ADD COLUMN gratuity_open_tabs_backfill_done INTEGER NOT NULL DEFAULT 0');
    } catch (PDOException $e) {
        // Column already exists
    }
    try {
        $backfillDone = (int) ($db->query('SELECT gratuity_open_tabs_backfill_done FROM product_settings LIMIT 1')->fetchColumn() ?: 0);
        if ($backfillDone !== 1) {
            $settings = $db->query('SELECT gratuity_percent, gratuity_default_enabled FROM product_settings LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
            $percent = floatval($settings['gratuity_percent'] ?? 0);
            $defaultOn = (int) ($settings['gratuity_default_enabled'] ?? 1) === 1;
            if ($percent > 0 && $defaultOn) {
                $db->exec("UPDATE tabs SET gratuity_enabled = 1 WHERE status = 'open' AND gratuity_enabled = 0");
            }
            $db->exec('UPDATE product_settings SET gratuity_open_tabs_backfill_done = 1 WHERE id = 1');
        }
    } catch (PDOException $e) {
        // product_settings row may not exist yet
    }
    $done = true;
}
