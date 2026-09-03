<?php

if (!function_exists('salesReportResolveCashierContext')) {
    function salesReportResolveCashierContext(PDO $userDb, string $cashierId): array
    {
        if ($cashierId === '') {
            return ['username' => '', 'user_id' => ''];
        }

        $stmt = $userDb->prepare('SELECT id, username FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$cashierId]);

        return [
            'username' => $cashierId,
            'user_id' => (string) ($stmt->fetchColumn() ?: ''),
        ];
    }
}

if (!function_exists('salesReportAppendCashierSql')) {
    function salesReportAppendCashierSql(
        PDO $db,
        string $whereClause,
        string $cashierColumn,
        string $cashierUsername,
        string $cashierUserId
    ): string {
        if ($cashierUsername === '') {
            return $whereClause;
        }

        $quotedUser = $db->quote($cashierUsername);
        if ($cashierUserId !== '') {
            $quotedId = $db->quote($cashierUserId);
            return "$whereClause AND ($cashierColumn = $quotedUser OR CAST($cashierColumn AS TEXT) = $quotedId)";
        }

        return "$whereClause AND $cashierColumn = $quotedUser";
    }
}
