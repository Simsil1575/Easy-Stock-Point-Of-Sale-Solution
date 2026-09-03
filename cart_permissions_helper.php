<?php
/**
 * POS cart extra-button permissions (info.db).
 */

const CART_FEATURE_DEFS = [
    'cash_back' => ['label' => 'Cash Back', 'default_allow' => 1, 'default_pin' => 1],
    'cash_up'   => ['label' => 'Cash Up', 'default_allow' => 1, 'default_pin' => 0],
    'tips'      => ['label' => 'Tips', 'default_allow' => 1, 'default_pin' => 0],
    'refund'    => ['label' => 'Refund', 'default_allow' => 1, 'default_pin' => 1],
    'exchange'  => ['label' => 'Exchange', 'default_allow' => 1, 'default_pin' => 1],
    'change'    => ['label' => 'Change', 'default_allow' => 1, 'default_pin' => 1],
];

function getCartPermissionsDb(): PDO
{
    $path = __DIR__ . DIRECTORY_SEPARATOR . 'info.db';
    $db = new PDO('sqlite:' . $path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    ensureCartPermissionsSchema($db);
    return $db;
}

function ensureCartPermissionsSchema(PDO $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS cart_permissions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        allow_cash_back BOOLEAN NOT NULL DEFAULT 1,
        allow_cash_up BOOLEAN NOT NULL DEFAULT 1,
        allow_tips BOOLEAN NOT NULL DEFAULT 1,
        allow_refund BOOLEAN NOT NULL DEFAULT 1,
        allow_exchange BOOLEAN NOT NULL DEFAULT 1,
        allow_change BOOLEAN NOT NULL DEFAULT 1,
        require_pin_cash_back BOOLEAN NOT NULL DEFAULT 1,
        require_pin_cash_up BOOLEAN NOT NULL DEFAULT 0,
        require_pin_tips BOOLEAN NOT NULL DEFAULT 0,
        require_pin_refund BOOLEAN NOT NULL DEFAULT 1,
        require_pin_exchange BOOLEAN NOT NULL DEFAULT 1,
        require_pin_change BOOLEAN NOT NULL DEFAULT 1
    )');

    foreach (array_keys(CART_FEATURE_DEFS) as $key) {
        foreach (['allow_', 'require_pin_'] as $prefix) {
            $col = $prefix . $key;
            try {
                $default = $prefix === 'allow_'
                    ? (int) CART_FEATURE_DEFS[$key]['default_allow']
                    : (int) CART_FEATURE_DEFS[$key]['default_pin'];
                $db->exec("ALTER TABLE cart_permissions ADD COLUMN {$col} BOOLEAN NOT NULL DEFAULT {$default}");
            } catch (PDOException $e) {
                // column exists
            }
        }
    }

    $count = (int) $db->query('SELECT COUNT(*) FROM cart_permissions')->fetchColumn();
    if ($count < 1) {
        $defaults = cartPermissionsDefaults();
        $cols = array_keys($defaults);
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $stmt = $db->prepare('INSERT INTO cart_permissions (' . implode(', ', $cols) . ') VALUES (' . $placeholders . ')');
        $stmt->execute(array_values($defaults));
    }
}

function cartPermissionsDefaults(): array
{
    $defaults = [];
    foreach (CART_FEATURE_DEFS as $key => $def) {
        $defaults['allow_' . $key] = (int) $def['default_allow'];
        $defaults['require_pin_' . $key] = (int) $def['default_pin'];
    }
    return $defaults;
}

function loadCartPermissions(): array
{
    $defaults = cartPermissionsDefaults();
    try {
        $db = getCartPermissionsDb();
        $row = $db->query('SELECT * FROM cart_permissions LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return $defaults;
        }
        $out = [];
        foreach ($defaults as $col => $defaultVal) {
            $out[$col] = array_key_exists($col, $row) ? (int) $row[$col] : $defaultVal;
        }
        return $out;
    } catch (Throwable $e) {
        return $defaults;
    }
}

function anyCartFeatureEnabled(?array $permissions = null): bool
{
    $permissions = $permissions ?? loadCartPermissions();
    foreach (array_keys(CART_FEATURE_DEFS) as $key) {
        if (!empty($permissions['allow_' . $key])) {
            return true;
        }
    }
    return false;
}

function saveCartPermissionsFromPost(array $post): void
{
    $db = getCartPermissionsDb();
    $values = [];
    $sets = [];
    foreach (array_keys(CART_FEATURE_DEFS) as $key) {
        $allowCol = 'allow_' . $key;
        $pinCol = 'require_pin_' . $key;
        $values[$allowCol] = isset($post[$allowCol]) ? 1 : 0;
        $values[$pinCol] = isset($post[$pinCol]) ? 1 : 0;
        $sets[] = "{$allowCol} = :{$allowCol}";
        $sets[] = "{$pinCol} = :{$pinCol}";
    }

    $stmt = $db->prepare('UPDATE cart_permissions SET ' . implode(', ', $sets) . ' WHERE id = 1');
    $stmt->execute($values);
}

function cartFeatureRequiresManagerPin(string $featureKey, ?string $role = null): bool
{
    if (!array_key_exists($featureKey, CART_FEATURE_DEFS)) {
        return false;
    }

    if ($role === null) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $role = strtolower(trim((string) ($_SESSION['role'] ?? '')));
    } else {
        $role = strtolower(trim($role));
    }

    if (in_array($role, ['manager', 'admin'], true)) {
        return false;
    }

    $permissions = loadCartPermissions();
    return !empty($permissions['require_pin_' . $featureKey]);
}

function cartFeatureIsAllowed(string $featureKey, ?array $permissions = null): bool
{
    if (!array_key_exists($featureKey, CART_FEATURE_DEFS)) {
        return false;
    }
    $permissions = $permissions ?? loadCartPermissions();
    return !empty($permissions['allow_' . $featureKey]);
}
