<?php
header('Content-Type: application/json');

if (!isset($_POST['date']) || !DateTime::createFromFormat('Y-m-d', $_POST['date'])) {
    die(json_encode(['error' => 'Invalid date format']));
}

$date = $_POST['date'];

// Database connection
try {
    $db = new PDO('sqlite:pos.db');
} catch (PDOException $e) {
    die(json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]));
}

// Get business closing time from business_info
$businessInfo = [];
try {
    $businessInfoDb = new PDO('sqlite:info.db');
    $businessInfo = $businessInfoDb->query("SELECT * FROM business_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $closingTime = $businessInfo['closing_time'] ?? '00:00'; // Default to midnight if not set
} catch (PDOException $e) {
    // Default closing time if DB error
    $closingTime = '00:00';
}

// Calculate business day boundaries based on closing time
$closingHour = (int)substr($closingTime, 0, 2);
$closingMinute = (int)substr($closingTime, 3, 2);

// If closing time is after midnight (e.g., 2:00 AM), we need to consider transactions
// that happened after midnight but before closing time as part of the previous day
$isAfterMidnight = $closingHour < 12;

// Calculate the next day date for queries
$nextDay = date('Y-m-d', strtotime($date . ' +1 day'));

// Fetch income transactions for this date - Orders (Cash)
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
    AND (
        (DATE(o.created_at) = :date AND strftime('%H:%M', o.created_at) >= :closingTime) OR
        (DATE(o.created_at) = :nextDay AND strftime('%H:%M', o.created_at) < :closingTime AND :isAfterMidnight = 1)
    )
    GROUP BY o.id
");
$cashIncomeQuery->bindParam(':date', $date);
$cashIncomeQuery->bindParam(':nextDay', $nextDay);
$cashIncomeQuery->bindParam(':closingTime', $closingTime);
$cashIncomeQuery->bindValue(':isAfterMidnight', $isAfterMidnight ? 1 : 0, PDO::PARAM_INT);
$cashIncomeQuery->execute();
$cashIncome = $cashIncomeQuery->fetchAll(PDO::FETCH_ASSOC);

// Fetch income transactions for this date - Orders (EFT)
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
    WHERE (
        (DATE(o.created_at) = :date AND strftime('%H:%M', o.created_at) >= :closingTime) OR
        (DATE(o.created_at) = :nextDay AND strftime('%H:%M', o.created_at) < :closingTime AND :isAfterMidnight = 1)
    )
    GROUP BY o.id
");
$eftIncomeQuery->bindParam(':date', $date);
$eftIncomeQuery->bindParam(':nextDay', $nextDay);
$eftIncomeQuery->bindParam(':closingTime', $closingTime);
$eftIncomeQuery->bindValue(':isAfterMidnight', $isAfterMidnight ? 1 : 0, PDO::PARAM_INT);
$eftIncomeQuery->execute();
$eftIncome = $eftIncomeQuery->fetchAll(PDO::FETCH_ASSOC);

// Fetch income transactions for this date - Credit Sales (Unpaid)
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
    AND (
        (DATE(cs.created_at) = :date AND strftime('%H:%M', cs.created_at) >= :closingTime) OR
        (DATE(cs.created_at) = :nextDay AND strftime('%H:%M', cs.created_at) < :closingTime AND :isAfterMidnight = 1)
    )
    GROUP BY cs.id
");
$unpaidCreditQuery->bindParam(':date', $date);
$unpaidCreditQuery->bindParam(':nextDay', $nextDay);
$unpaidCreditQuery->bindParam(':closingTime', $closingTime);
$unpaidCreditQuery->bindValue(':isAfterMidnight', $isAfterMidnight ? 1 : 0, PDO::PARAM_INT);
$unpaidCreditQuery->execute();
$unpaidCredit = $unpaidCreditQuery->fetchAll(PDO::FETCH_ASSOC);

// Fetch income transactions for this date - Credit Sales (Paid with Cash)
$paidCreditCashQuery = $db->prepare("
    SELECT 
        cs.id, 
        cs.total_amount as amount, 
        cs.created_at,
        'Credit (Paid Cash)' as type,
        GROUP_CONCAT(csi.product_name || ' (x' || csi.quantity || ')', ', ') as details
    FROM credit_sales cs
    JOIN credit_sale_items csi ON cs.id = csi.sale_id
    WHERE cs.payment_status = 'paid'
    AND (
        (DATE(cs.created_at) = :date AND strftime('%H:%M', cs.created_at) >= :closingTime) OR
        (DATE(cs.created_at) = :nextDay AND strftime('%H:%M', cs.created_at) < :closingTime AND :isAfterMidnight = 1)
    )
    GROUP BY cs.id
");
$paidCreditCashQuery->bindParam(':date', $date);
$paidCreditCashQuery->bindParam(':nextDay', $nextDay);
$paidCreditCashQuery->bindParam(':closingTime', $closingTime);
$paidCreditCashQuery->bindValue(':isAfterMidnight', $isAfterMidnight ? 1 : 0, PDO::PARAM_INT);
$paidCreditCashQuery->execute();
$paidCreditCash = $paidCreditCashQuery->fetchAll(PDO::FETCH_ASSOC);

// Fetch income transactions for this date - Credit Sales (Paid with EFT)
$paidCreditEftQuery = $db->prepare("
    SELECT 
        cs.id, 
        cs.total_amount as amount, 
        cs.created_at,
        'Credit (Paid EFT)' as type,
        GROUP_CONCAT(csi.product_name || ' (x' || csi.quantity || ')', ', ') as details
    FROM credit_sales cs
    JOIN credit_sale_items csi ON cs.id = csi.sale_id
    WHERE cs.payment_status = 'eft'
    AND (
        (DATE(cs.created_at) = :date AND strftime('%H:%M', cs.created_at) >= :closingTime) OR
        (DATE(cs.created_at) = :nextDay AND strftime('%H:%M', cs.created_at) < :closingTime AND :isAfterMidnight = 1)
    )
    GROUP BY cs.id
");
$paidCreditEftQuery->bindParam(':date', $date);
$paidCreditEftQuery->bindParam(':nextDay', $nextDay);
$paidCreditEftQuery->bindParam(':closingTime', $closingTime);
$paidCreditEftQuery->bindValue(':isAfterMidnight', $isAfterMidnight ? 1 : 0, PDO::PARAM_INT);
$paidCreditEftQuery->execute();
$paidCreditEft = $paidCreditEftQuery->fetchAll(PDO::FETCH_ASSOC);

// Fetch income transactions for this date - Credit Sales (Partial Payment)
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
    AND (
        (DATE(cs.created_at) = :date AND strftime('%H:%M', cs.created_at) >= :closingTime) OR
        (DATE(cs.created_at) = :nextDay AND strftime('%H:%M', cs.created_at) < :closingTime AND :isAfterMidnight = 1)
    )
    GROUP BY cs.id
");
$partialCreditQuery->bindParam(':date', $date);
$partialCreditQuery->bindParam(':nextDay', $nextDay);
$partialCreditQuery->bindParam(':closingTime', $closingTime);
$partialCreditQuery->bindValue(':isAfterMidnight', $isAfterMidnight ? 1 : 0, PDO::PARAM_INT);
$partialCreditQuery->execute();
$partialCredit = $partialCreditQuery->fetchAll(PDO::FETCH_ASSOC);

// Fetch expenses for this date
$expensesQuery = $db->prepare("
    SELECT 
        id, 
        amount, 
        created_at,
        description
    FROM cash_transactions
    WHERE type = 'cash-out'
    AND DATE(created_at) = :date
");
$expensesQuery->bindParam(':date', $date);
$expensesQuery->execute();
$expenses = $expensesQuery->fetchAll(PDO::FETCH_ASSOC);

// Combine all income sources
$income = array_merge($cashIncome, $eftIncome, $unpaidCredit, $paidCreditCash, $paidCreditEft, $partialCredit);

// Sort income by time
usort($income, function($a, $b) {
    return strtotime($a['created_at']) - strtotime($b['created_at']);
});

// Format the income data for display
$formattedIncome = [];
foreach ($income as $item) {
    $formattedIncome[] = [
        'type' => $item['type'],
        'amount' => $item['amount'],
        'time' => date('H:i', strtotime($item['created_at'])),
        'details' => $item['details']
    ];
}

// Format the expenses data for display
$formattedExpenses = [];
foreach ($expenses as $expense) {
    $formattedExpenses[] = [
        'description' => $expense['description'],
        'amount' => $expense['amount'],
        'time' => date('H:i', strtotime($expense['created_at']))
    ];
}

// Calculate totals
$totalIncome = array_sum(array_column($income, 'amount'));
$totalExpenses = array_sum(array_column($expenses, 'amount'));
$netAmount = $totalIncome - $totalExpenses;

// Prepare response
$response = [
    'income' => $formattedIncome,
    'expenses' => $formattedExpenses,
    'totals' => [
        'income' => $totalIncome,
        'expenses' => $totalExpenses,
        'net' => $netAmount
    ]
];

echo json_encode($response); 