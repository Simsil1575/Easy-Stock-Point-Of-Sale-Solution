<?php


session_start();

// Set timezone to Central Africa Time (CAT)
date_default_timezone_set('Africa/Harare');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    header("Location: ../");
    exit();
}

header('Content-Type: application/json');

if (!isset($_POST['date']) || !DateTime::createFromFormat('Y-m-d', $_POST['date'])) {
    die(json_encode(['error' => 'Invalid date format']));
}

$date = $_POST['date'];

try {
    $db = new PDO('sqlite:../pos.db');
} catch (PDOException $e) {
    die(json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]));
}

require_once __DIR__ . '/../business_day_helper.php';

$bdCtx = bdLoadBusinessHoursContext(__DIR__ . '/../info.db');
$closingTime = $bdCtx['closing_time'];
$isAfterMidnight = $bdCtx['is_after_midnight'];

$nextDay = date('Y-m-d', strtotime($date . ' +1 day'));

$bdWhereOCreated = bdSingleDayWhereSql('o.created_at', ':date', ':nextDay', $closingTime, $isAfterMidnight);
$bdWhereCsCreated = bdSingleDayWhereSql('cs.created_at', ':date', ':nextDay', $closingTime, $isAfterMidnight);
$bdWhereCreated = bdSingleDayWhereSql('created_at', ':date', ':nextDay', $closingTime, $isAfterMidnight);

$cashIncomeQuery = $db->prepare("
    SELECT 
        o.id, 
        o.total as amount, 
        o.created_at,
        'Cash Sale' as type,
        GROUP_CONCAT(oi.product_name || ' (x' || oi.quantity || ')', ', ') as details
    FROM orders o
    JOIN order_items oi ON o.id = oi.order_id
    LEFT JOIN eft_payments e ON o.id = e.order_id
    WHERE e.order_id IS NULL
    AND ($bdWhereOCreated)
    GROUP BY o.id
");
$cashIncomeQuery->bindParam(':date', $date);
$cashIncomeQuery->bindParam(':nextDay', $nextDay);
$cashIncomeQuery->execute();
$cashIncome = $cashIncomeQuery->fetchAll(PDO::FETCH_ASSOC);

$eftIncomeQuery = $db->prepare("
    SELECT 
        o.id, 
        o.total as amount, 
        o.created_at,
        'EFT Sale' as type,
        GROUP_CONCAT(oi.product_name || ' (x' || oi.quantity || ')', ', ') as details
    FROM orders o
    JOIN order_items oi ON o.id = oi.order_id
    JOIN eft_payments e ON o.id = e.order_id
    WHERE ($bdWhereOCreated)
    GROUP BY o.id
");
$eftIncomeQuery->bindParam(':date', $date);
$eftIncomeQuery->bindParam(':nextDay', $nextDay);
$eftIncomeQuery->execute();
$eftIncome = $eftIncomeQuery->fetchAll(PDO::FETCH_ASSOC);

$unpaidCreditQuery = $db->prepare("
    SELECT 
        cs.id, 
        cs.total_amount as amount, 
        cs.created_at,
        'Unpaid Credit' as type,
        GROUP_CONCAT(csi.product_name || ' (x' || csi.quantity || ')', ', ') as details
    FROM credit_sales cs
    JOIN credit_sale_items csi ON cs.id = csi.sale_id
    WHERE cs.payment_status = 'unpaid'
    AND ($bdWhereCsCreated)
    GROUP BY cs.id
");
$unpaidCreditQuery->bindParam(':date', $date);
$unpaidCreditQuery->bindParam(':nextDay', $nextDay);
$unpaidCreditQuery->execute();
$unpaidCredit = $unpaidCreditQuery->fetchAll(PDO::FETCH_ASSOC);

$paidCreditCashQuery = $db->prepare("
    SELECT 
        cs.id, 
        CASE 
            WHEN cs.payment_status = 'paid' THEN cs.total_amount
            WHEN cs.payment_status = 'paid_mixed' THEN cs.total_amount - COALESCE((SELECT SUM(e.amount) FROM eft_payments e WHERE e.order_id = cs.id), 0)
            ELSE cs.total_amount
        END as amount, 
        cs.created_at,
        'Credit (Paid Cash)' as type,
        GROUP_CONCAT(csi.product_name || ' (x' || csi.quantity || ')', ', ') as details
    FROM credit_sales cs
    JOIN credit_sale_items csi ON cs.id = csi.sale_id
    WHERE cs.payment_status IN ('paid', 'paid_mixed')
    AND ($bdWhereCsCreated)
    GROUP BY cs.id
    HAVING amount > 0.005
");
$paidCreditCashQuery->bindParam(':date', $date);
$paidCreditCashQuery->bindParam(':nextDay', $nextDay);
$paidCreditCashQuery->execute();
$paidCreditCash = $paidCreditCashQuery->fetchAll(PDO::FETCH_ASSOC);

$paidCreditEftQuery = $db->prepare("
    SELECT 
        cs.id, 
        COALESCE((SELECT SUM(e.amount) FROM eft_payments e WHERE e.order_id = cs.id), 0) as amount, 
        cs.created_at,
        'Credit (Paid EFT)' as type,
        GROUP_CONCAT(csi.product_name || ' (x' || csi.quantity || ')', ', ') as details
    FROM credit_sales cs
    JOIN credit_sale_items csi ON cs.id = csi.sale_id
    WHERE cs.payment_status IN ('eft', 'paid_mixed')
    AND ($bdWhereCsCreated)
    GROUP BY cs.id
    HAVING amount > 0.005
");
$paidCreditEftQuery->bindParam(':date', $date);
$paidCreditEftQuery->bindParam(':nextDay', $nextDay);
$paidCreditEftQuery->execute();
$paidCreditEft = $paidCreditEftQuery->fetchAll(PDO::FETCH_ASSOC);

$partialCreditQuery = $db->prepare("
    SELECT 
        cs.id, 
        cs.total_amount as amount, 
        cs.created_at,
        'Credit (Partial Payment)' as type,
        GROUP_CONCAT(csi.product_name || ' (x' || csi.quantity || ')', ', ') || ' - Paid: N$' || cs.paid_amount as details
    FROM credit_sales cs
    JOIN credit_sale_items csi ON cs.id = csi.sale_id
    WHERE cs.payment_status = 'partial'
    AND ($bdWhereCsCreated)
    GROUP BY cs.id
");
$partialCreditQuery->bindParam(':date', $date);
$partialCreditQuery->bindParam(':nextDay', $nextDay);
$partialCreditQuery->execute();
$partialCredit = $partialCreditQuery->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../cash_transactions_totals_helper.php';

$outflowWhere = cashReportOutflowWhereSql('description');
$expensesQuery = $db->prepare("
    SELECT 
        id,
        type,
        amount, 
        created_at,
        description
    FROM cash_transactions
    WHERE {$outflowWhere}
    AND ($bdWhereCreated)
");
$expensesQuery->bindParam(':date', $date);
$expensesQuery->bindParam(':nextDay', $nextDay);
$expensesQuery->execute();
$expenses = $expensesQuery->fetchAll(PDO::FETCH_ASSOC);

$cashBackWhere = cashBackDescriptionSql('description');
$cashBackQuery = $db->prepare("
    SELECT 
        id,
        type,
        amount, 
        created_at,
        description
    FROM cash_transactions
    WHERE type = 'cash-out'
    AND {$cashBackWhere}
    AND ($bdWhereCreated)
");
$cashBackQuery->bindParam(':date', $date);
$cashBackQuery->bindParam(':nextDay', $nextDay);
$cashBackQuery->execute();
$cashBackRows = $cashBackQuery->fetchAll(PDO::FETCH_ASSOC);
$expenses = array_merge($expenses, $cashBackRows);

$income = array_merge($cashIncome, $eftIncome, $unpaidCredit, $paidCreditCash, $paidCreditEft, $partialCredit);

usort($income, function($a, $b) {
    return strtotime($a['created_at']) - strtotime($b['created_at']);
});

$formattedIncome = [];
foreach ($income as $item) {
    $formattedIncome[] = [
        'type' => $item['type'],
        'amount' => $item['amount'],
        'time' => date('H:i', strtotime($item['created_at'])),
        'details' => $item['details']
    ];
}

$formattedExpenses = [];
foreach ($expenses as $expense) {
    $formattedExpenses[] = [
        'description' => $expense['description'],
        'amount' => abs((float) $expense['amount']),
        'time' => date('H:i', strtotime($expense['created_at']))
    ];
}

$totalIncome = array_sum(array_column($income, 'amount'));
$totalExpenses = array_sum(array_map(static fn($expense) => abs((float) $expense['amount']), $expenses));
$netAmount = $totalIncome - $totalExpenses;

echo json_encode([
    'income' => $formattedIncome,
    'expenses' => $formattedExpenses,
    'totals' => [
        'income' => $totalIncome,
        'expenses' => $totalExpenses,
        'net' => $netAmount
    ]
]);
