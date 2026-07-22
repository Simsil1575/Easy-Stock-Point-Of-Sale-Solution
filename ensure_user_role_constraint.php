<?php
/**
 * Ensure users.role CHECK includes waitress + hubbly, and assigned_category exists.
 *
 * @param PDO $db Connection to user.db
 */
function ensureUsersTableRoleIncludesWaitress(PDO $db): void
{
    ensureUsersTableSupportsHubbly($db);
}

/**
 * Extend role CHECK to include hubbly and ensure assigned_category column.
 */
function ensureUsersTableSupportsHubbly(PDO $db): void
{
    static $complete = false;
    if ($complete) {
        return;
    }

    try {
        $sql = $db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn();
        if ($sql === false || $sql === null || (string) $sql === '') {
            $complete = true;
            return;
        }
        $sql = (string) $sql;
        $hasHubbly = stripos($sql, "'hubbly'") !== false;
        $hasWaitress = stripos($sql, "'waitress'") !== false;

        if (!$hasHubbly || !$hasWaitress) {
            // Rebuild table to refresh CHECK constraint (SQLite cannot ALTER CHECK).
            $cols = $db->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC);
            $colNames = array_map(static fn($c) => (string) $c['name'], $cols);
            $hasIndex = in_array('indexfinger', $colNames, true);
            $hasMiddle = in_array('middlefinger', $colNames, true);
            $hasAssigned = in_array('assigned_category', $colNames, true);

            $createCols = "
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL CHECK(role IN ('cashier', 'manager', 'admin', 'waitress', 'hubbly')),
                email TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ";
            if ($hasIndex) {
                $createCols .= ",\n                indexfinger TEXT";
            }
            if ($hasMiddle) {
                $createCols .= ",\n                middlefinger TEXT";
            }
            $createCols .= ",\n                assigned_category TEXT";

            $selectCols = 'id, username, password_hash, role, email, created_at';
            $insertCols = 'id, username, password_hash, role, email, created_at';
            if ($hasIndex) {
                $selectCols .= ', indexfinger';
                $insertCols .= ', indexfinger';
            }
            if ($hasMiddle) {
                $selectCols .= ', middlefinger';
                $insertCols .= ', middlefinger';
            }
            if ($hasAssigned) {
                $selectCols .= ', assigned_category';
                $insertCols .= ', assigned_category';
            } else {
                $selectCols .= ', NULL AS assigned_category';
                $insertCols .= ', assigned_category';
            }

            $db->beginTransaction();
            $db->exec('DROP TABLE IF EXISTS users_new');
            $db->exec("CREATE TABLE users_new ($createCols)");
            $db->exec("INSERT INTO users_new ($insertCols) SELECT $selectCols FROM users");
            $db->exec('DROP TABLE users');
            $db->exec('ALTER TABLE users_new RENAME TO users');
            $db->commit();
        } else {
            // CHECK already has hubbly — only ensure assigned_category column.
            $cols = $db->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC);
            $colNames = array_map(static fn($c) => (string) $c['name'], $cols);
            if (!in_array('assigned_category', $colNames, true)) {
                $db->exec('ALTER TABLE users ADD COLUMN assigned_category TEXT');
            }
        }

        $complete = true;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('ensureUsersTableSupportsHubbly: ' . $e->getMessage());
        throw $e;
    }
}

/**
 * Load assigned_category for a user id from user.db.
 */
function getUserAssignedCategory(int $userId): string
{
    try {
        $path = __DIR__ . '/user.db';
        $db = new PDO('sqlite:' . $path);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        ensureUsersTableSupportsHubbly($db);
        $stmt = $db->prepare('SELECT assigned_category FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        return trim((string) ($stmt->fetchColumn() ?: ''));
    } catch (Throwable $e) {
        return '';
    }
}
