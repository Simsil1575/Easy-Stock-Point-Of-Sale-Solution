<?php
/**
 * Terminal / MAC address tracking for POS transactions.
 */

function normalizeMacAddress(?string $mac): ?string
{
    if ($mac === null || $mac === '') {
        return null;
    }

    $mac = strtoupper(trim($mac));

    // UUID fallback from client (device ID when QZ unavailable)
    if (preg_match('/^UUID:[0-9A-F-]{36}$/i', $mac)) {
        return strtoupper($mac);
    }

    $hex = preg_replace('/[^0-9A-Fa-f]/', '', $mac);
    if (strlen($hex) !== 12 || !ctype_xdigit($hex)) {
        return null;
    }

    return strtoupper(implode(':', str_split($hex, 2)));
}

function ensureTerminalSchema(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS terminals (
        mac_address TEXT PRIMARY KEY,
        terminal_name TEXT NOT NULL DEFAULT '',
        first_seen_at TEXT,
        last_seen_at TEXT
    )");

    $tables = [
        'orders',
        'credit_sales',
        'tab_payments',
        'void_transactions',
        'refunds',
        'eft_payments',
        'mixed_payments',
        'cash_transactions',
        'tabs',
    ];

    foreach ($tables as $table) {
        foreach (['terminal_mac TEXT', 'terminal_name TEXT'] as $columnDef) {
            $column = explode(' ', $columnDef)[0];
            try {
                $db->exec("ALTER TABLE {$table} ADD COLUMN {$columnDef}");
            } catch (PDOException $e) {
                // Column already exists
            }
        }
    }
}

function getTerminalByMac(PDO $db, string $mac): ?array
{
    $stmt = $db->prepare('SELECT mac_address, terminal_name, first_seen_at, last_seen_at FROM terminals WHERE mac_address = ? LIMIT 1');
    $stmt->execute([$mac]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function upsertTerminal(PDO $db, string $mac, ?string $name = null): void
{
    $now = date('Y-m-d H:i:s');
    $existing = getTerminalByMac($db, $mac);

    if ($existing) {
        $updateName = ($name !== null && trim($name) !== '') ? trim($name) : $existing['terminal_name'];
        $stmt = $db->prepare('UPDATE terminals SET terminal_name = ?, last_seen_at = ? WHERE mac_address = ?');
        $stmt->execute([$updateName, $now, $mac]);
        return;
    }

    $stmt = $db->prepare('INSERT INTO terminals (mac_address, terminal_name, first_seen_at, last_seen_at) VALUES (?, ?, ?, ?)');
    $stmt->execute([$mac, trim($name ?? ''), $now, $now]);
}

function resolveTerminalFromRequest(array $data, PDO $db): array
{
    ensureTerminalSchema($db);

    $mac = normalizeMacAddress($data['terminal_mac'] ?? null);
    $name = isset($data['terminal_name']) ? trim((string) $data['terminal_name']) : '';

    if ($mac === null) {
        return ['mac' => null, 'name' => null];
    }

    if ($name === '') {
        $existing = getTerminalByMac($db, $mac);
        $name = $existing['terminal_name'] ?? '';
    }

    upsertTerminal($db, $mac, $name !== '' ? $name : null);

    return [
        'mac' => $mac,
        'name' => $name !== '' ? $name : null,
    ];
}

function getAllTerminals(PDO $db): array
{
    ensureTerminalSchema($db);
    $stmt = $db->query('SELECT mac_address, terminal_name, first_seen_at, last_seen_at FROM terminals ORDER BY terminal_name COLLATE NOCASE, mac_address');
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function formatTerminalLabel(?string $name, ?string $mac): string
{
    if ($name !== null && trim($name) !== '') {
        return trim($name);
    }
    if ($mac !== null && trim($mac) !== '') {
        if (stripos($mac, 'UUID:') === 0) {
            return 'Unregistered device';
        }
        return $mac;
    }
    return 'Unknown terminal';
}
