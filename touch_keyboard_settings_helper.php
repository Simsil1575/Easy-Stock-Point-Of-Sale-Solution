<?php

function ensureTouchKeyboardSettingsColumn(PDO $db): void
{
    try {
        $db->exec('ALTER TABLE product_settings ADD COLUMN touch_keyboard_enabled BOOLEAN NOT NULL DEFAULT 0');
    } catch (PDOException $e) {
        // Column already exists
    }
}

function loadTouchKeyboardEnabled(PDO $db): bool
{
    ensureTouchKeyboardSettingsColumn($db);

    $row = $db->query('SELECT touch_keyboard_enabled FROM product_settings LIMIT 1')->fetch(PDO::FETCH_ASSOC);

    return $row ? !empty($row['touch_keyboard_enabled']) : false;
}
