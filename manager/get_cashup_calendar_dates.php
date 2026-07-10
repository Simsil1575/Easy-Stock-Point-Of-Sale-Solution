<?php
/**
 * Returns calendar dates (Y-m-d) in a given month that have POS / cash activity
 * (orders, cash movements, credit, payments, etc.) for Cash Up day picker.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Africa/Harare');
header('Content-Type: application/json; charset=utf-8');

$allowedRoles = ['admin', 'manager'];
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || !in_array(strtolower($_SESSION['role']), $allowedRoles, true)) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$year = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
$month = isset($_GET['month']) ? (int) $_GET['month'] : (int) date('n');

if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
    echo json_encode(['success' => false, 'error' => 'Invalid year or month']);
    exit;
}

$start = sprintf('%04d-%02d-01', $year, $month);
$lastDay = (int) date('t', strtotime($start . ' 12:00:00'));
$end = sprintf('%04d-%02d-%02d', $year, $month, $lastDay);

try {
    $db = new PDO('sqlite:../pos.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database error']);
    exit;
}

function tableExists(PDO $db, $name) {
    static $cache = [];
    if (isset($cache[$name])) {
        return $cache[$name];
    }
    $stmt = $db->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1");
    $stmt->execute([$name]);
    $cache[$name] = (bool) $stmt->fetchColumn();
    return $cache[$name];
}

$dates = [];

$sources = [
    ['orders', 'created_at'],
    ['cash_transactions', 'created_at'],
    ['credit_sales', 'created_at'],
    ['payments', 'payment_date'],
    ['refunds', 'created_at'],
    ['credit_returns', 'created_at'],
];

if (tableExists($db, 'eft_payments')) {
    $sources[] = ['eft_payments', 'payment_date'];
}
if (tableExists($db, 'void_transactions')) {
    $sources[] = ['void_transactions', 'voided_at'];
}

foreach ($sources as $pair) {
    [$table, $col] = $pair;
    if (!tableExists($db, $table)) {
        continue;
    }
    try {
        $sql = "SELECT DISTINCT date({$col}) AS d FROM {$table} WHERE date({$col}) >= :start AND date({$col}) <= :end";
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':start', $start, PDO::PARAM_STR);
        $stmt->bindValue(':end', $end, PDO::PARAM_STR);
        $stmt->execute();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($row['d'])) {
                $dates[$row['d']] = true;
            }
        }
    } catch (PDOException $e) {
        continue;
    }
}

$out = array_keys($dates);
sort($out);

echo json_encode(['success' => true, 'dates' => $out, 'start' => $start, 'end' => $end]);
