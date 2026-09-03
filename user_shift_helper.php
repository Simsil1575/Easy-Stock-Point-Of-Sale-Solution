<?php

function resolveCashierUserId(PDO $userDb, string $username): ?string
{
    try {
        $stmt = $userDb->prepare("SELECT id FROM users WHERE LOWER(username) = LOWER(:username) LIMIT 1");
        $stmt->execute([':username' => $username]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (string) $id : null;
    } catch (PDOException $e) {
        return null;
    }
}

function cashierMatchesSql(string $column, bool $hasUserId): string
{
    if ($hasUserId) {
        return "(LOWER(CAST($column AS TEXT)) = LOWER(:username) OR CAST($column AS TEXT) = :user_id)";
    }
    return "LOWER(CAST($column AS TEXT)) = LOWER(:username)";
}

function getSalesTimestampTables(PDO $db): array
{
    $tables = [];
    foreach (['orders', 'credit_sales', 'medical_aid_sales'] as $table) {
        $check = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name=" . $db->quote($table));
        if ($check && $check->fetchColumn()) {
            $tables[] = $table;
        }
    }
    return $tables;
}

function queryCashierSaleTimestamp(
    PDO $db,
    array $tables,
    string $cashierUsername,
    ?string $userId,
    string $date,
    string $aggregate
): ?string
{
    if (empty($tables)) {
        return null;
    }

    $hasUserId = $userId !== null && $userId !== '';
    $parts = [];
    foreach ($tables as $table) {
        $match = cashierMatchesSql('cashier_id', $hasUserId);
        $parts[] = "SELECT created_at AS ts FROM $table WHERE $match AND DATE(created_at) = :date";
    }

    $fn = strtoupper($aggregate) === 'MAX' ? 'MAX' : 'MIN';
    $sql = "SELECT $fn(ts) FROM (" . implode(' UNION ALL ', $parts) . ")";

    $params = [':username' => $cashierUsername, ':date' => $date];
    if ($hasUserId) {
        $params[':user_id'] = $userId;
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $value = $stmt->fetchColumn();
    return $value !== false && $value !== null && $value !== '' ? (string) $value : null;
}

function getCashierShiftTimesForDate(
    PDO $db,
    string $cashierUsername,
    string $startDate,
    ?string $endDate = null,
    ?string $userId = null
): array {
    if (empty($cashierUsername) || empty($startDate)) {
        return ['start_time' => null, 'end_time' => null, 'has_shift_data' => false];
    }

    if (empty($endDate)) {
        $endDate = $startDate;
    }

    try {
        $tables = getSalesTimestampTables($db);
        if (empty($tables)) {
            return ['start_time' => null, 'end_time' => null, 'has_shift_data' => false];
        }

        $firstSale = queryCashierSaleTimestamp($db, $tables, $cashierUsername, $userId, $startDate, 'MIN');
        $lastSale = queryCashierSaleTimestamp($db, $tables, $cashierUsername, $userId, $endDate, 'MAX');

        $result = ['start_time' => null, 'end_time' => null, 'has_shift_data' => false];

        if ($firstSale) {
            $result['start_time'] = (new DateTime($firstSale))->format('H:i');
            $result['has_shift_data'] = true;
        }

        if ($lastSale) {
            $result['end_time'] = (new DateTime($lastSale))->format('H:i');
            $result['has_shift_data'] = true;
        }

        return $result;
    } catch (PDOException $e) {
        error_log("Error fetching cashier sales times: " . $e->getMessage());
        return ['start_time' => null, 'end_time' => null, 'has_shift_data' => false];
    }
}

function getCashierShiftTimes(PDO $db, string $cashierUsername, ?string $userId = null): array
{
    if (empty($cashierUsername)) {
        return [
            'start_date' => null,
            'start_time' => null,
            'end_date' => null,
            'end_time' => null,
            'has_shift_data' => false,
        ];
    }

    try {
        $tables = getSalesTimestampTables($db);
        if (empty($tables)) {
            return [
                'start_date' => null,
                'start_time' => null,
                'end_date' => null,
                'end_time' => null,
                'has_shift_data' => false,
            ];
        }

        $hasUserId = $userId !== null && $userId !== '';
        $parts = [];
        foreach ($tables as $table) {
            $match = cashierMatchesSql('cashier_id', $hasUserId);
            $parts[] = "SELECT created_at AS ts FROM $table WHERE $match";
        }

        $sql = "SELECT MAX(ts) FROM (" . implode(' UNION ALL ', $parts) . ")";
        $params = [':username' => $cashierUsername];
        if ($hasUserId) {
            $params[':user_id'] = $userId;
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $latestSale = $stmt->fetchColumn();

        if (!$latestSale) {
            return [
                'start_date' => null,
                'start_time' => null,
                'end_date' => null,
                'end_time' => null,
                'has_shift_data' => false,
            ];
        }

        $saleDate = (new DateTime($latestSale))->format('Y-m-d');
        $times = getCashierShiftTimesForDate($db, $cashierUsername, $saleDate, $saleDate, $userId);

        return [
            'start_date' => $saleDate,
            'start_time' => $times['start_time'],
            'end_date' => $saleDate,
            'end_time' => $times['end_time'],
            'has_shift_data' => $times['has_shift_data'],
        ];
    } catch (PDOException $e) {
        error_log("Error fetching cashier sales times: " . $e->getMessage());
        return [
            'start_date' => null,
            'start_time' => null,
            'end_date' => null,
            'end_time' => null,
            'has_shift_data' => false,
        ];
    }
}
