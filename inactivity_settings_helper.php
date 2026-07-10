<?php

function debugInactivityLog(string $location, string $message, array $data = [], string $hypothesisId = 'A'): void
{
    // #region agent log
    @file_put_contents(
        __DIR__ . '/debug-036ded.log',
        json_encode([
            'sessionId' => '036ded',
            'hypothesisId' => $hypothesisId,
            'location' => $location,
            'message' => $message,
            'data' => $data,
            'timestamp' => (int) round(microtime(true) * 1000),
        ]) . "\n",
        FILE_APPEND
    );
    // #endregion
}

function ensureInactivitySettingsColumns(PDO $db): void
{
    foreach ([
        "ALTER TABLE product_settings ADD COLUMN cashier_idle_timeout_seconds INTEGER NOT NULL DEFAULT 120",
        "ALTER TABLE product_settings ADD COLUMN cashier_inactivity_enabled BOOLEAN NOT NULL DEFAULT 1",
        "ALTER TABLE product_settings ADD COLUMN inactivity_role_admin INTEGER NOT NULL DEFAULT 0",
        "ALTER TABLE product_settings ADD COLUMN inactivity_role_manager INTEGER NOT NULL DEFAULT 0",
        "ALTER TABLE product_settings ADD COLUMN inactivity_role_cashier INTEGER NOT NULL DEFAULT 1",
        "ALTER TABLE product_settings ADD COLUMN inactivity_role_waitress INTEGER NOT NULL DEFAULT 0",
    ] as $sql) {
        try {
            $db->exec($sql);
        } catch (PDOException $e) {
            // Column already exists
        }
    }
}

function loadInactivitySettings(PDO $db): array
{
    ensureInactivitySettingsColumns($db);

    $row = $db->query("
        SELECT cashier_inactivity_enabled, cashier_idle_timeout_seconds,
               inactivity_role_admin, inactivity_role_manager,
               inactivity_role_cashier, inactivity_role_waitress
        FROM product_settings LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);

    $idleSeconds = isset($row['cashier_idle_timeout_seconds']) ? (int) $row['cashier_idle_timeout_seconds'] : 120;
    if ($idleSeconds < 30) {
        $idleSeconds = 30;
    }
    if ($idleSeconds > 3600) {
        $idleSeconds = 3600;
    }

    $settings = [
        'enabled' => (int) ($row['cashier_inactivity_enabled'] ?? 1),
        'idle_seconds' => $idleSeconds,
        'role_admin' => (int) ($row['inactivity_role_admin'] ?? 0),
        'role_manager' => (int) ($row['inactivity_role_manager'] ?? 0),
        'role_cashier' => (int) ($row['inactivity_role_cashier'] ?? 1),
        'role_waitress' => (int) ($row['inactivity_role_waitress'] ?? 0),
    ];
    debugInactivityLog('inactivity_settings_helper.php:loadInactivitySettings', 'loaded settings from DB', [
        'settings' => $settings,
        'row_found' => $row !== false,
    ], 'A');
    return $settings;
}

function normalizeInactivityRole(string $role): string
{
    $role = strtolower(trim($role));
    if ($role === 'default') {
        return 'cashier';
    }
    return $role;
}

function inactivityEnabledForRole(array $settings, string $role): bool
{
    if (empty($settings['enabled'])) {
        return false;
    }

    $role = normalizeInactivityRole($role);
    $roleMap = [
        'admin' => !empty($settings['role_admin']),
        'manager' => !empty($settings['role_manager']),
        'cashier' => !empty($settings['role_cashier']),
        'waitress' => !empty($settings['role_waitress']),
    ];

    return $roleMap[$role] ?? false;
}

function inactivityEnabledForSession(array $settings): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $sessionRole = (string) ($_SESSION['role'] ?? '');
    $enabledForUser = inactivityEnabledForRole($settings, $sessionRole);
    debugInactivityLog('inactivity_settings_helper.php:inactivityEnabledForSession', 'resolved inactivity for session', [
        'session_role' => $sessionRole,
        'normalized_role' => normalizeInactivityRole($sessionRole),
        'enabled_for_user' => $enabledForUser,
        'settings' => $settings,
        'session_id_present' => session_status() === PHP_SESSION_ACTIVE,
    ], 'B');
    return $enabledForUser;
}
