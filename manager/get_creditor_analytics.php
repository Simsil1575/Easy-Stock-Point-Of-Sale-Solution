<?php
session_start();
header('Content-Type: application/json');

// Set timezone to Central Africa Time (CAT)
date_default_timezone_set('Africa/Harare');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Check activation status
try {
    $activationDb = new PDO('sqlite:../active.db');
    $activationStatus = $activationDb->query("SELECT COUNT(*) FROM software_keys WHERE is_used = 1")->fetchColumn();
    if ($activationStatus == 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Software not activated']);
        exit();
    }
} catch (PDOException $e) {
    // If activation check fails, continue anyway
}

try {
    $db = new PDO('sqlite:../pos.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get period parameter (today, week, month, year)
    $period = $_GET['period'] ?? 'today';
    
    // Calculate date range based on period
    $startDate = '';
    $endDate = date('Y-m-d');
    
    $noFilter = false;
    switch($period) {
        case 'all':
            $startDate = '1970-01-01';
            $endDate = '2099-12-31';
            $noFilter = true;
            break;
        case 'today':
            $startDate = date('Y-m-d');
            $endDate = date('Y-m-d');
            break;
        case 'week':
            $startDate = date('Y-m-d', strtotime('monday this week'));
            $endDate = date('Y-m-d', strtotime('sunday this week'));
            break;
        case 'month':
            $startDate = date('Y-m-01');
            $endDate = date('Y-m-t');
            break;
        case 'year':
            $startDate = date('Y-01-01');
            $endDate = date('Y-12-31');
            break;
        default:
            $startDate = date('Y-m-d');
            $endDate = date('Y-m-d');
            break;
    }
    
    // Get creditor analytics data
    $dateFilter = $noFilter ? "1=1" : "DATE(cs.created_at) BETWEEN :startDate AND :endDate";
    
    $analytics = $db->prepare("
        SELECT 
            c.id,
            c.name,
            COUNT(cs.id) as total_transactions,
            COALESCE(SUM(cs.total_amount), 0) as total_sales,
            COALESCE(SUM(cs.paid_amount), 0) as total_paid,
            COALESCE(SUM(cs.total_amount - cs.paid_amount), 0) as outstanding_balance,
            COUNT(CASE WHEN cs.payment_status = 'paid' THEN 1 END) as paid_transactions,
            COUNT(CASE WHEN cs.payment_status = 'unpaid' THEN 1 END) as unpaid_transactions
        FROM creditors c
        LEFT JOIN credit_sales cs ON c.id = cs.creditor_id 
            AND ($dateFilter)
        WHERE c.active = 1
        GROUP BY c.id, c.name
        HAVING total_transactions > 0 OR outstanding_balance > 0
        ORDER BY total_sales DESC
        LIMIT 10
    ");
    
    if ($noFilter) {
        $analytics->execute();
    } else {
        $analytics->execute([':startDate' => $startDate, ':endDate' => $endDate]);
    }
    $creditors = $analytics->fetchAll(PDO::FETCH_ASSOC);
    
    // Get summary statistics
    $summary = $db->prepare("
        SELECT 
            COUNT(DISTINCT c.id) as total_active_creditors,
            COUNT(cs.id) as total_credit_sales,
            COALESCE(SUM(cs.total_amount), 0) as total_credit_amount,
            COALESCE(SUM(cs.paid_amount), 0) as total_paid_amount,
            COALESCE(SUM(cs.total_amount - cs.paid_amount), 0) as total_outstanding
        FROM creditors c
        LEFT JOIN credit_sales cs ON c.id = cs.creditor_id 
            AND ($dateFilter)
        WHERE c.active = 1
    ");
    
    if ($noFilter) {
        $summary->execute();
    } else {
        $summary->execute([':startDate' => $startDate, ':endDate' => $endDate]);
    }
    $summaryData = $summary->fetch(PDO::FETCH_ASSOC);
    
    $dateRangeDisplay = $noFilter ? 'All Time' : "$startDate to $endDate";
    echo json_encode([
        'success' => true,
        'creditors' => $creditors,
        'summary' => $summaryData,
        'period' => $period,
        'dateRange' => $dateRangeDisplay
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error fetching creditor analytics: ' . $e->getMessage()]);
}

