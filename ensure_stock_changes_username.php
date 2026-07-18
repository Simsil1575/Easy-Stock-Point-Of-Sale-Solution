<?php

/**
 * Ensure stock_changes.username exists and helpers to record who changed stock.
 */

function ensureStockChangesUsernameColumn($db): void
{
    if ($db instanceof PDO) {
        ensureStockChangesUsernameColumnPdo($db);
        return;
    }
    if ($db instanceof SQLite3) {
        ensureStockChangesUsernameColumnSqlite3($db);
    }
}

function ensureStockChangesUsernameColumnPdo(PDO $db): void
{
    static $done = [];
    $key = spl_object_id($db);
    if (isset($done[$key])) {
        return;
    }
    try {
        $exists = (int) $db->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='stock_changes'"
        )->fetchColumn();
        if ($exists === 0) {
            $done[$key] = true;
            return;
        }
        $cols = $db->query('PRAGMA table_info(stock_changes)')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $c) {
            if (($c['name'] ?? '') === 'username') {
                $done[$key] = true;
                return;
            }
        }
        $db->exec('ALTER TABLE stock_changes ADD COLUMN username TEXT');
    } catch (Throwable $e) {
        error_log('ensureStockChangesUsernameColumnPdo: ' . $e->getMessage());
    }
    $done[$key] = true;
}

function ensureStockChangesUsernameColumnSqlite3(SQLite3 $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    try {
        $check = $db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='stock_changes'");
        if (!(int) $check) {
            $done = true;
            return;
        }
        $has = false;
        $res = $db->query('PRAGMA table_info(stock_changes)');
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            if (($row['name'] ?? '') === 'username') {
                $has = true;
                break;
            }
        }
        if (!$has) {
            $db->exec('ALTER TABLE stock_changes ADD COLUMN username TEXT');
        }
    } catch (Throwable $e) {
        error_log('ensureStockChangesUsernameColumnSqlite3: ' . $e->getMessage());
    }
    $done = true;
}

function currentStockChangeUsername(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    $username = trim((string) ($_SESSION['username'] ?? ''));
    if ($username !== '') {
        return $username;
    }
    return 'Unknown';
}

/**
 * Best-effort backfill for older stock_changes rows that have no username.
 * 1) Match Restock lines to receiving_records by product + time window.
 * 2) Otherwise attribute to the most recent login before the change.
 */
function backfillStockChangesUsernames(PDO $db): void
{
    ensureStockChangesUsernameColumnPdo($db);
    try {
        if (auditLogTableExistsSafe($db, 'receiving_records') && auditLogTableExistsSafe($db, 'receiving_items')) {
            $db->exec("
                UPDATE stock_changes
                SET username = (
                    SELECT rr.username
                    FROM receiving_items ri
                    JOIN receiving_records rr ON rr.id = ri.record_id
                    WHERE ri.product_id = stock_changes.product_id
                      AND ABS(strftime('%s', rr.receiving_date) - strftime('%s', stock_changes.changed_at)) <= 120
                    ORDER BY ABS(strftime('%s', rr.receiving_date) - strftime('%s', stock_changes.changed_at)) ASC
                    LIMIT 1
                )
                WHERE (username IS NULL OR TRIM(username) = '')
                  AND LOWER(action) = 'restock'
                  AND EXISTS (
                    SELECT 1
                    FROM receiving_items ri
                    JOIN receiving_records rr ON rr.id = ri.record_id
                    WHERE ri.product_id = stock_changes.product_id
                      AND ABS(strftime('%s', rr.receiving_date) - strftime('%s', stock_changes.changed_at)) <= 120
                  )
            ");
        }

        if (auditLogTableExistsSafe($db, 'user_log')) {
            $db->exec("
                UPDATE stock_changes
                SET username = (
                    SELECT CAST(ul.user_id AS TEXT)
                    FROM user_log ul
                    WHERE ul.action_type = 'login'
                      AND ul.action_time <= stock_changes.changed_at
                    ORDER BY ul.action_time DESC
                    LIMIT 1
                )
                WHERE (username IS NULL OR TRIM(username) = '')
                  AND EXISTS (
                    SELECT 1
                    FROM user_log ul
                    WHERE ul.action_type = 'login'
                      AND ul.action_time <= stock_changes.changed_at
                  )
            ");
        }
    } catch (Throwable $e) {
        error_log('backfillStockChangesUsernames: ' . $e->getMessage());
    }
}

function auditLogTableExistsSafe(PDO $db, string $table): bool
{
    try {
        $stmt = $db->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?");
        $stmt->execute([$table]);
        return $stmt->fetchColumn() !== false;
    } catch (Throwable $e) {
        return false;
    }
}
