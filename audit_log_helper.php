<?php

/**
 * Unified audit log: login/logout, sales, receiving, adjustments, opening/closing stock.
 */
function buildAuditLogReportData(PDO $db, ?PDO $userDb, string $startDate, string $endDate, int $limit = 1000): array
{
    require_once __DIR__ . '/ensure_stock_changes_username.php';
    ensureStockChangesUsernameColumn($db);
    backfillStockChangesUsernames($db);

    $userMap = auditLogBuildUserMap($userDb);
    $logs = [];
    $start = auditLogSanitizeDate($startDate);
    $end = auditLogSanitizeDate($endDate);
    $dateBetween = "BETWEEN '{$start}' AND '{$end}'";

    $parts = [];

    if (auditLogTableExists($db, 'user_log')) {
        $parts[] = "
            SELECT
                'auth' AS source,
                CAST(ul.action_type AS TEXT) AS action_type,
                ul.action_time AS action_time,
                CAST(ul.user_id AS TEXT) AS username,
                NULL AS amount,
                CAST(ul.action_type AS TEXT) AS detail
            FROM user_log ul
            WHERE DATE(ul.action_time) {$dateBetween}
        ";
    }

    if (auditLogTableExists($db, 'orders')) {
        $parts[] = "
            SELECT
                'sale' AS source,
                'sale' AS action_type,
                o.created_at AS action_time,
                CAST(o.cashier_id AS TEXT) AS username,
                CAST(o.total AS REAL) AS amount,
                'Order #' || o.id AS detail
            FROM orders o
            WHERE DATE(o.created_at) {$dateBetween}
        ";
    }

    if (auditLogTableExists($db, 'credit_sales')) {
        $parts[] = "
            SELECT
                'sale' AS source,
                'credit_sale' AS action_type,
                cs.created_at AS action_time,
                CAST(cs.cashier_id AS TEXT) AS username,
                CAST(cs.total_amount AS REAL) AS amount,
                'Credit sale #' || cs.id AS detail
            FROM credit_sales cs
            WHERE DATE(cs.created_at) {$dateBetween}
        ";
    }

    if (auditLogTableExists($db, 'receiving_records')) {
        $parts[] = "
            SELECT
                'receiving' AS source,
                'receiving' AS action_type,
                rr.receiving_date AS action_time,
                CAST(rr.username AS TEXT) AS username,
                CAST(rr.total_cost AS REAL) AS amount,
                (CAST(rr.total_items AS TEXT) || ' items / qty ' || CAST(rr.total_quantity AS TEXT)) AS detail
            FROM receiving_records rr
            WHERE DATE(rr.receiving_date) {$dateBetween}
        ";
    }

    if (auditLogTableExists($db, 'stock_changes') && auditLogTableExists($db, 'products')) {
        $parts[] = "
            SELECT
                'adjustment' AS source,
                CAST(sc.action AS TEXT) AS action_type,
                sc.changed_at AS action_time,
                CAST(COALESCE(NULLIF(TRIM(sc.username), ''), '') AS TEXT) AS username,
                NULL AS amount,
                (COALESCE(p.name, 'Product #' || CAST(sc.product_id AS TEXT))
                    || ': ' || CAST(sc.quantity_change AS TEXT)
                    || ' (' || CAST(sc.old_quantity AS TEXT) || '→' || CAST(sc.new_quantity AS TEXT) || ')') AS detail
            FROM stock_changes sc
            LEFT JOIN products p ON p.id = sc.product_id
            WHERE DATE(sc.changed_at) {$dateBetween}
              AND LOWER(CAST(sc.action AS TEXT)) IN (
                  'adjust', 'damaged', 'add', 'tip', 'restock',
                  'opening stock adjustment', 'closing stock adjustment'
              )
        ";
    }

    if (auditLogTableExists($db, 'opening_stock') && auditLogTableExists($db, 'products')) {
        $parts[] = "
            SELECT
                'opening_stock' AS source,
                'opening_stock' AS action_type,
                os.recorded_at AS action_time,
                CAST(os.recorded_by AS TEXT) AS username,
                NULL AS amount,
                (COALESCE(p.name, 'Product #' || CAST(os.product_id AS TEXT))
                    || ': qty ' || CAST(os.opening_quantity AS TEXT)) AS detail
            FROM opening_stock os
            LEFT JOIN products p ON p.id = os.product_id
            WHERE DATE(os.recorded_at) {$dateBetween}
        ";
    }

    if (auditLogTableExists($db, 'closing_stock') && auditLogTableExists($db, 'products')) {
        $parts[] = "
            SELECT
                'closing_stock' AS source,
                'closing_stock' AS action_type,
                cs.recorded_at AS action_time,
                CAST(cs.recorded_by AS TEXT) AS username,
                NULL AS amount,
                (COALESCE(p.name, 'Product #' || CAST(cs.product_id AS TEXT))
                    || ': qty ' || CAST(cs.closing_quantity AS TEXT)) AS detail
            FROM closing_stock cs
            LEFT JOIN products p ON p.id = cs.product_id
            WHERE DATE(cs.recorded_at) {$dateBetween}
        ";
    }

    if (empty($parts)) {
        return [
            'logs' => [],
            'summary' => auditLogEmptySummary(),
        ];
    }

    $sql = 'SELECT * FROM (' . implode(' UNION ALL ', $parts) . ') ORDER BY action_time DESC LIMIT ' . (int) $limit;

    try {
        $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Audit log query failed: ' . $e->getMessage());
        $rows = [];
    }

    $summary = auditLogEmptySummary();

    foreach ($rows as $row) {
        $username = trim((string) ($row['username'] ?? ''));
        $source = (string) ($row['source'] ?? '');
        $action = (string) ($row['action_type'] ?? '');

        // Resolve numeric user ids (opening/closing stock recorded_by)
        if ($username !== '' && ctype_digit($username) && isset($userMap[$username])) {
            $username = $userMap[$username];
        }
        if ($username === '' || $username === '0') {
            $username = 'Unknown';
        }

        $normalizedAction = auditLogNormalizeAction($action, $source);
        $logs[] = [
            'source' => $source,
            'action_type' => $normalizedAction,
            'action_time' => $row['action_time'] ?? '',
            'username' => $username,
            'amount' => isset($row['amount']) && $row['amount'] !== null && $row['amount'] !== ''
                ? (float) $row['amount']
                : null,
            'detail' => (string) ($row['detail'] ?? ''),
        ];

        $summary['total_entries']++;
        if ($source === 'auth') {
            $summary['auth']++;
        } elseif ($source === 'sale') {
            $summary['sales']++;
        } elseif ($source === 'receiving') {
            $summary['receiving']++;
        } elseif ($source === 'adjustment') {
            $summary['adjustments']++;
        } elseif ($source === 'opening_stock') {
            $summary['opening_stock']++;
        } elseif ($source === 'closing_stock') {
            $summary['closing_stock']++;
        }
    }

    return [
        'logs' => $logs,
        'summary' => $summary,
    ];
}

/**
 * Same activity set for Activity Logs UI (no date filter).
 */
function buildAuditLogActivityRows(PDO $db, ?PDO $userDb = null, int $limit = 5000): array
{
    $end = date('Y-m-d');
    $start = '1970-01-01';
    $data = buildAuditLogReportData($db, $userDb, $start, $end, $limit);
    $rows = [];
    foreach ($data['logs'] as $log) {
        $rows[] = [
            'source' => $log['source'],
            'user_id' => $log['username'],
            'action_type' => $log['action_type'],
            'action_time' => $log['action_time'],
            'cashier_id' => $log['username'],
            'amount' => $log['amount'],
            'username' => $log['username'],
            'detail' => $log['detail'],
        ];
    }
    return $rows;
}

function auditLogBadgeClass(string $actionType): string
{
    $action = strtolower($actionType);
    if (in_array($action, ['login', 'sale', 'receiving', 'opening_stock'], true)) {
        return 'badge-success';
    }
    if (in_array($action, ['logout', 'damaged', 'adjust', 'closing_stock'], true)) {
        return 'badge-warning';
    }
    if (in_array($action, ['credit_sale'], true)) {
        return 'badge-info';
    }
    return 'badge-info';
}

function auditLogNormalizeAction(string $action, string $source): string
{
    $action = trim($action);
    if ($action === '') {
        return $source !== '' ? $source : 'activity';
    }
    $map = [
        'Adjust' => 'adjust',
        'Restock' => 'restock',
        'Opening Stock Adjustment' => 'opening_adjust',
        'Closing Stock Adjustment' => 'closing_adjust',
    ];
    if (isset($map[$action])) {
        return $map[$action];
    }
    return strtolower(str_replace(' ', '_', $action));
}

function auditLogTableExists(PDO $db, string $table): bool
{
    static $cache = [];
    $key = spl_object_id($db) . ':' . $table;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    try {
        $stmt = $db->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?");
        $stmt->execute([$table]);
        $cache[$key] = $stmt->fetchColumn() !== false;
    } catch (PDOException $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

function auditLogBuildUserMap(?PDO $userDb): array
{
    $map = [];
    if (!$userDb) {
        return $map;
    }
    try {
        $rows = $userDb->query('SELECT id, username FROM users')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $map[(string) $row['id']] = (string) $row['username'];
        }
    } catch (PDOException $e) {
    }
    return $map;
}

function auditLogEmptySummary(): array
{
    return [
        'total_entries' => 0,
        'auth' => 0,
        'sales' => 0,
        'receiving' => 0,
        'adjustments' => 0,
        'opening_stock' => 0,
        'closing_stock' => 0,
    ];
}

function auditLogSanitizeDate(string $date): string
{
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if ($dt && $dt->format('Y-m-d') === $date) {
        return $date;
    }
    return date('Y-m-d');
}
