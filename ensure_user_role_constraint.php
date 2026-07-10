<?php
/**
 * Older user.db files define users.role CHECK as only ('cashier','manager','admin').
 * The add/edit user UI also allows 'waitress'. Rebuild the table once to extend CHECK.
 */
function ensureUsersTableRoleIncludesWaitress(PDO $db) {
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
        if (stripos($sql, "'waitress'") !== false) {
            $complete = true;
            return;
        }

        $db->beginTransaction();
        $db->exec('DROP TABLE IF EXISTS users_new');
        $db->exec("
            CREATE TABLE users_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL CHECK(role IN ('cashier', 'manager', 'admin', 'waitress')),
                email TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $db->exec("
            INSERT INTO users_new (id, username, password_hash, role, email, created_at)
            SELECT id, username, password_hash, role, email, created_at FROM users
        ");
        $db->exec('DROP TABLE users');
        $db->exec('ALTER TABLE users_new RENAME TO users');
        $db->commit();
        $complete = true;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('ensureUsersTableRoleIncludesWaitress: ' . $e->getMessage());
        throw $e;
    }
}
