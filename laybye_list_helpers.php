<?php

/**
 * Build lay-bye list filters for SQL (search, status, scope, pagination).
 *
 * @return array{search:string,status:string,page:int,perPage:int,totalRows:int,rows:array}
 */
function laybyeFetchListPage(PDO $db, string $role, string $currentUsername, string $currentUserId): array
{
    $search = trim((string) ($_GET['q'] ?? ''));
    $status = strtolower(trim((string) ($_GET['status'] ?? 'all')));
    if (!in_array($status, ['all', 'active', 'completed', 'cancelled'], true)) {
        $status = 'all';
    }

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = (int) ($_GET['per_page'] ?? 15);
    if (!in_array($perPage, [10, 15, 25, 50, 100], true)) {
        $perPage = 15;
    }
    $offset = ($page - 1) * $perPage;

    $scopeSql = '';
    $scopeParams = [];
    if (!in_array(strtolower($role), ['admin', 'manager'], true)) {
        $scopeSql = ' AND (l.cashier_id = ? OR l.cashier_id = ?) ';
        $scopeParams[] = $currentUsername;
        $scopeParams[] = (string) $currentUserId;
    }

    $filterSql = '';
    $filterParams = [];
    if ($status !== 'all') {
        $filterSql = ' AND l.status = ? ';
        $filterParams[] = $status;
    }

    $searchSql = '';
    $searchParams = [];
    if ($search !== '') {
        $term = str_replace(['%', '_'], '', $search);
        if ($term !== '') {
            $like = '%' . $term . '%';
            if (ctype_digit($term)) {
                $searchSql = ' AND (
                    l.id = ?
                    OR l.reference LIKE ?
                    OR IFNULL(c.name, \'\') LIKE ?
                    OR IFNULL(c.phone, \'\') LIKE ?
                ) ';
                $searchParams[] = (int) $term;
                $searchParams[] = $like;
                $searchParams[] = $like;
                $searchParams[] = $like;
            } else {
                $searchSql = ' AND (
                    l.reference LIKE ?
                    OR IFNULL(c.name, \'\') LIKE ?
                    OR IFNULL(c.phone, \'\') LIKE ?
                ) ';
                $searchParams[] = $like;
                $searchParams[] = $like;
                $searchParams[] = $like;
            }
        }
    }

    $fromWhere = "
        FROM laybye_accounts l
        LEFT JOIN creditors c ON c.id = l.creditor_id
        WHERE 1=1
        $scopeSql
        $filterSql
        $searchSql
    ";

    $countStmt = $db->prepare("SELECT COUNT(*) $fromWhere");
    $countStmt->execute(array_merge($scopeParams, $filterParams, $searchParams));
    $totalRows = (int) $countStmt->fetchColumn();

    $totalPages = max(1, (int) ceil($totalRows / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $perPage;
    }

    $dataSql = "
        SELECT l.*, c.name AS creditor_name, c.phone AS creditor_phone
        $fromWhere
        ORDER BY l.opened_at DESC
        LIMIT ? OFFSET ?
    ";
    $dataStmt = $db->prepare($dataSql);
    $dataStmt->execute(array_merge($scopeParams, $filterParams, $searchParams, [$perPage, $offset]));
    $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'search' => $search,
        'status' => $status,
        'page' => $page,
        'perPage' => $perPage,
        'totalRows' => $totalRows,
        'totalPages' => $totalPages,
        'rows' => $rows,
    ];
}

/**
 * All lay-byes for the list table with client-side search (no ?q / ?page). Status + scope only.
 *
 * @return array{status:string,perPage:int,rows:array,totalLoaded:int}
 */
function laybyeFetchListForClientTable(PDO $db, string $role, string $currentUsername, string $currentUserId): array
{
    $status = strtolower(trim((string) ($_GET['status'] ?? 'all')));
    if (!in_array($status, ['all', 'active', 'completed', 'cancelled'], true)) {
        $status = 'all';
    }

    $perPage = (int) ($_GET['per_page'] ?? 15);
    if (!in_array($perPage, [10, 15, 25, 50, 100], true)) {
        $perPage = 15;
    }

    $scopeSql = '';
    $scopeParams = [];
    if (!in_array(strtolower($role), ['admin', 'manager'], true)) {
        $scopeSql = ' AND (l.cashier_id = ? OR l.cashier_id = ?) ';
        $scopeParams[] = $currentUsername;
        $scopeParams[] = (string) $currentUserId;
    }

    $filterSql = '';
    $filterParams = [];
    if ($status !== 'all') {
        $filterSql = ' AND l.status = ? ';
        $filterParams[] = $status;
    }

    $fromWhere = "
        FROM laybye_accounts l
        LEFT JOIN creditors c ON c.id = l.creditor_id
        WHERE 1=1
        $scopeSql
        $filterSql
    ";

    $dataSql = "
        SELECT l.*, c.name AS creditor_name, c.phone AS creditor_phone
        $fromWhere
        ORDER BY l.opened_at DESC
    ";
    $dataStmt = $db->prepare($dataSql);
    $dataStmt->execute(array_merge($scopeParams, $filterParams));
    $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'status' => $status,
        'perPage' => $perPage,
        'rows' => $rows,
        'totalLoaded' => count($rows),
    ];
}

/**
 * Build query string from explicit state (for pagination links and JS instant filter URLs).
 */
function laybyeListUrlFromState(string $q, string $status, int $perPage, int $page): string
{
    $parts = [];
    if (trim($q) !== '') {
        $parts['q'] = trim($q);
    }
    if (in_array($status, ['active', 'completed', 'cancelled'], true)) {
        $parts['status'] = $status;
    }
    if ($perPage !== 15) {
        $parts['per_page'] = $perPage;
    }
    if ($page > 1) {
        $parts['page'] = $page;
    }
    return empty($parts) ? '' : ('?' . http_build_query($parts));
}

function laybyeListQueryString(array $overrides): string
{
    $q = array_merge([
        'status' => $_GET['status'] ?? 'all',
        'per_page' => (int) ($_GET['per_page'] ?? 15),
    ], $overrides);
    $parts = [];
    if (in_array($q['status'], ['active', 'completed', 'cancelled'], true)) {
        $parts['status'] = $q['status'];
    }
    if ((int) $q['per_page'] !== 15) {
        $parts['per_page'] = (int) $q['per_page'];
    }
    return empty($parts) ? '' : ('?' . http_build_query($parts));
}
