<?php
/**
 * Shared SQLite user.db helpers for fingerprint columns: v2 JSON (finger/ Intermediate samples)
 * used by fingerprint_auth.php and enrollment in admin UI.
 */

/**
 * Ensure users table has indexfinger / middlefinger columns (FingerPrint-compatible names).
 */
function userdb_ensure_fingerprint_columns(PDO $userDb): void {
    $existing = [];
    $stmt = $userDb->query('PRAGMA table_info(users)');
    if ($stmt) {
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $existing[strtolower((string) $row['name'])] = true;
        }
    }
    foreach (['indexfinger', 'middlefinger'] as $col) {
        if (!isset($existing[$col])) {
            $userDb->exec('ALTER TABLE users ADD COLUMN ' . $col . ' TEXT NULL');
        }
    }
}

/**
 * Users that have at least one enrolled template (for 1:N fingerprint login).
 *
 * @return list<array<string, mixed>>
 */
function userdb_fetch_users_with_templates(PDO $userDb): array {
    $sql = <<<'SQL'
SELECT id, username, password_hash, role, indexfinger, middlefinger
FROM users
WHERE (indexfinger IS NOT NULL AND TRIM(indexfinger) != '')
   OR (middlefinger IS NOT NULL AND TRIM(middlefinger) != '')
SQL;
    $stmt = $userDb->query($sql);
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    return $rows ?: [];
}
