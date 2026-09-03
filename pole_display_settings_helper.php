<?php

function ensurePoleDisplaySettingsColumns(PDO $db): void
{
    foreach ([
        "ALTER TABLE product_settings ADD COLUMN pole_display_enabled BOOLEAN NOT NULL DEFAULT 0",
        "ALTER TABLE product_settings ADD COLUMN pole_display_port TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE product_settings ADD COLUMN pole_display_baud INTEGER NOT NULL DEFAULT 9600",
        "ALTER TABLE product_settings ADD COLUMN pole_display_mode TEXT NOT NULL DEFAULT 'epson'",
    ] as $sql) {
        try {
            $db->exec($sql);
        } catch (PDOException $e) {
            // Column already exists
        }
    }
}

function loadPoleDisplaySettings(PDO $db): array
{
    ensurePoleDisplaySettingsColumns($db);

    $row = $db->query(
        'SELECT pole_display_enabled, pole_display_port, pole_display_baud, pole_display_mode FROM product_settings LIMIT 1'
    )->fetch(PDO::FETCH_ASSOC);

    $baud = isset($row['pole_display_baud']) ? (int) $row['pole_display_baud'] : 9600;
    if (!in_array($baud, [2400, 4800, 9600, 19200], true)) {
        $baud = 9600;
    }

    $mode = strtolower(trim((string) ($row['pole_display_mode'] ?? 'epson')));
    if ($mode !== 'pst') {
        $mode = 'epson';
    }

    $port = strtoupper(trim((string) ($row['pole_display_port'] ?? '')));
    if ($port !== 'AUTO' && $port !== '' && !preg_match('/^COM\d+$/', $port)) {
        $port = '';
    }

    return [
        'enabled' => !empty($row['pole_display_enabled']),
        'port' => $port,
        'baud' => $baud,
        'mode' => $mode,
    ];
}
