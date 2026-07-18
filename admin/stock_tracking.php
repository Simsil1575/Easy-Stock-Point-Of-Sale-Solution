<?php
// ... existing activation check code from inventory.php ...
session_start();

// Set timezone to Central Africa Time (CAT)
date_default_timezone_set('Africa/Harare');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    // Redirect to login page if not logged in
    header("Location: ../");
    exit();
}

// Database connection
$db = new PDO('sqlite:../pos.db');
require_once __DIR__ . '/../ensure_stock_changes_username.php';
ensureStockChangesUsernameColumn($db);
backfillStockChangesUsernames($db);

// Pagination setup
$rowsPerPage = 7;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $rowsPerPage;

// Search functionality
$search = isset($_GET['search']) ? $_GET['search'] : '';
$searchCondition = $search ? "AND (p.name LIKE :search OR sc.action LIKE :search OR IFNULL(sc.username, '') LIKE :search)" : "";

// Date filter for receiving report
$receivingDate = isset($_GET['receiving_date']) ? $_GET['receiving_date'] : '';
$dateCondition = '';
if ($receivingDate) {
    $dateCondition = "AND date(datetime(sc.changed_at, '+2 hours')) = :receiving_date";
}

// Get all dates that have stock receiving (Restock) data
$receivingDatesQuery = $db->prepare("
    SELECT DISTINCT date(datetime(changed_at, '+2 hours')) as receiving_date
    FROM stock_changes
    WHERE action = 'Restock'
    ORDER BY receiving_date DESC
");
$receivingDatesQuery->execute();
$receivingDates = $receivingDatesQuery->fetchAll(PDO::FETCH_COLUMN);

// Categories for optional inventory PDF filter
$categoriesStmt = $db->query("SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category != '' ORDER BY category");
$productCategories = $categoriesStmt->fetchAll(PDO::FETCH_COLUMN);

$todayDate = date('Y-m-d');

// Get total records for pagination
$totalQuery = $db->prepare("
    SELECT COUNT(*) 
    FROM stock_changes sc
    JOIN products p ON sc.product_id = p.id
    WHERE 1=1 $searchCondition
");
if ($search) $totalQuery->bindValue(':search', "%$search%");
$totalQuery->execute();
$totalRecords = $totalQuery->fetchColumn();
$totalPages = ceil($totalRecords / $rowsPerPage);

// Handle AJAX request for pagination
if (isset($_GET['ajax']) && $_GET['ajax'] === 'true') {
    // Fetch stock changes
    $stmt = $db->prepare("
        SELECT 
            sc.*,
            p.name as product_name,
            strftime('%Y-%m-%d %H:%M', datetime(sc.changed_at, '+2 hours')) as formatted_date
        FROM stock_changes sc
        JOIN products p ON sc.product_id = p.id
        WHERE 1=1 $searchCondition
        ORDER BY sc.changed_at DESC
        LIMIT $rowsPerPage OFFSET $offset
    ");
    if ($search) $stmt->bindValue(':search', "%$search%");
    $stmt->execute();
    $stockChanges = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $response = [
        'data' => $stockChanges,
        'pagination' => [
            'page' => $page,
            'totalPages' => $totalPages
        ]
    ];
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Normal request - fetch stock changes
$stmt = $db->prepare("
    SELECT 
        sc.*,
        p.name as product_name,
        strftime('%Y-%m-%d %H:%M', datetime(sc.changed_at, '+2 hours')) as formatted_date
    FROM stock_changes sc
    JOIN products p ON sc.product_id = p.id
    WHERE 1=1 $searchCondition
    ORDER BY sc.changed_at DESC
    LIMIT $rowsPerPage OFFSET $offset
");
if ($search) $stmt->bindValue(':search', "%$search%");
$stmt->execute();
$stockChanges = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Check if PDF export is requested
if (isset($_GET['export_pdf']) && $_GET['export_pdf'] == 'true') {
    // Include FPDF library
    require('../fpdf/fpdf.php');
    
    // Create new PDF instance
    class StockChangesPDF extends FPDF {
        protected $pageType = 'stock_changes';
        
        function setPageType($type) {
            $this->pageType = $type;
        }
        
        function Header() {
            // Common header for all pages
            $this->SetFont('Arial', 'B', 15);
            
            if ($this->pageType == 'stock_changes') {
                $this->Cell(0, 10, 'Stock Changes Report', 0, 1, 'C');
                $this->SetFont('Arial', '', 12);
                $this->Cell(0, 10, 'Generated on ' . date('Y-m-d H:i:s'), 0, 1, 'C');
                $this->Ln(10);
                
                // Table header for stock changes
                $this->SetFont('Arial', 'B', 9);
                $this->Cell(32, 8, 'Date', 1);
                $this->Cell(40, 8, 'Product', 1);
                $this->Cell(28, 8, 'Cashier', 1);
                $this->Cell(24, 8, 'Action', 1);
                $this->Cell(22, 8, 'Qty Change', 1);
                $this->Cell(18, 8, 'Old Qty', 1);
                $this->Cell(18, 8, 'New Qty', 1);
                $this->Ln();
            } 
            else if ($this->pageType == 'inventory') {
                $title = !empty($GLOBALS['inventory_date_for_pdf']) && $GLOBALS['inventory_date_for_pdf'] !== date('Y-m-d')
                    ? 'Stock Inventory (As of ' . $GLOBALS['inventory_date_for_pdf'] . ')'
                    : 'Current Stock Inventory';
                $this->Cell(0, 10, $title, 0, 1, 'C');
                $this->SetFont('Arial', '', 12);
                $this->Cell(0, 10, 'Stock On Hand', 0, 1, 'C');
                if (!empty($GLOBALS['inventory_categories_for_pdf'])) {
                    $this->Cell(0, 10, 'Categories: ' . implode(', ', $GLOBALS['inventory_categories_for_pdf']), 0, 1, 'C');
                }
                $this->Cell(0, 10, 'Generated on ' . date('Y-m-d H:i:s'), 0, 1, 'C');
                $this->Ln(5);
                
                // Table header for inventory
                $this->SetFont('Arial', 'B', 11);
                $this->Cell(10, 10, 'ID', 1);
                $this->Cell(55, 10, 'Product Name', 1);
                $this->Cell(25, 10, 'Category', 1);
                $this->Cell(18, 10, 'Quantity', 1);
                $this->Cell(20, 10, 'Price', 1);
                $this->Cell(20, 10, 'Cost', 1);
                $this->Cell(18, 10, 'Restock', 1);
                $this->Cell(24, 10, 'Value', 1);
                $this->Ln();
            }
            else if ($this->pageType == 'stock_receiving') {
                $this->Cell(0, 10, 'Stock Receiving Report', 0, 1, 'C');
                $this->SetFont('Arial', '', 12);
                // Display the selected receiving date instead of generation date
                $receivingDate = isset($GLOBALS['receiving_date_for_pdf']) ? $GLOBALS['receiving_date_for_pdf'] : date('Y-m-d');
                $this->Cell(0, 10, 'Receiving Date: ' . $receivingDate, 0, 1, 'C');
                $this->Cell(0, 10, 'Generated on ' . date('Y-m-d H:i:s'), 0, 1, 'C');
                $this->Ln(10);
                
                // Table header for stock receiving (matching receiving.php format)
                $this->SetFont('Arial', 'B', 10);
                $this->Cell(80, 10, 'Product', 1);
                $this->Cell(35, 10, 'Added', 1);
                $this->Cell(35, 10, 'Unit Price', 1);
                $this->Cell(35, 10, 'Value Added', 1);
                $this->Ln();
            }
        }
        
        function Footer() {
            $this->SetY(-15);
            $this->SetFont('Arial', 'I', 8);
            $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
        }
    }
    
    // Determine which report type to generate
    $reportType = isset($_GET['report_type']) ? $_GET['report_type'] : 'stock_changes';
    
    // Initialize PDF
    $pdf = new StockChangesPDF();
    $pdf->AliasNbPages();
    
    if ($reportType == 'stock_changes') {
        $pdf->setPageType('stock_changes');
        $pdf->AddPage();
        $pdf->SetFont('Arial', '', 10);
        
        // Get all stock changes for PDF (without pagination)
        $pdfStmt = $db->prepare("
            SELECT 
                sc.*,
                p.name as product_name,
                strftime('%Y-%m-%d %H:%M', datetime(sc.changed_at, '+2 hours')) as formatted_date
            FROM stock_changes sc
            JOIN products p ON sc.product_id = p.id
            WHERE 1=1 $searchCondition
            ORDER BY sc.changed_at DESC
        ");
        if ($search) $pdfStmt->bindValue(':search', "%$search%");
        $pdfStmt->execute();
        $allStockChanges = $pdfStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Add data to PDF
        foreach ($allStockChanges as $change) {
            $cashier = trim((string) ($change['username'] ?? ''));
            if ($cashier === '') {
                $cashier = '-';
            }
            $pdf->Cell(32, 8, $change['formatted_date'], 1);
            $pdf->Cell(40, 8, substr($change['product_name'], 0, 22), 1);
            $pdf->Cell(28, 8, substr($cashier, 0, 16), 1);
            $pdf->Cell(24, 8, ucfirst($change['action']), 1);
            $qtyChangeText = ($change['quantity_change'] > 0 ? '+' : '') . $change['quantity_change'];
            $pdf->Cell(22, 8, $qtyChangeText, 1);
            $pdf->Cell(18, 8, $change['old_quantity'], 1);
            $pdf->Cell(18, 8, $change['new_quantity'], 1);
            $pdf->Ln();
        }
        
        // Output PDF
        $fileName = 'Stock_Changes_Report_' . date('Y-m-d') . '.pdf';
        $pdf->Output('D', $fileName);
    }
    else if ($reportType == 'inventory') {
        $selectedCategories = [];
        if (isset($_GET['categories']) && is_array($_GET['categories'])) {
            $selectedCategories = array_values(array_filter(array_map('trim', $_GET['categories'])));
        } elseif (!empty($_GET['category'])) {
            $selectedCategories = [trim($_GET['category'])];
        }
        $selectedCategories = array_values(array_intersect($selectedCategories, $productCategories));
        if (!empty($selectedCategories)) {
            $GLOBALS['inventory_categories_for_pdf'] = $selectedCategories;
        }

        $inventoryDate = isset($_GET['inventory_date']) ? trim($_GET['inventory_date']) : '';
        if ($inventoryDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $inventoryDate)) {
            $inventoryDate = '';
        }
        if ($inventoryDate && $inventoryDate > $todayDate) {
            $inventoryDate = '';
        }
        if ($inventoryDate) {
            $GLOBALS['inventory_date_for_pdf'] = $inventoryDate;
        }

        $useHistoricalInventory = ($inventoryDate !== '' && $inventoryDate !== $todayDate);

        $pdf->setPageType('inventory');
        $pdf->AddPage();

        $inventoryParams = [];
        $categoryPlaceholders = '';
        if (!empty($selectedCategories)) {
            $placeholders = [];
            foreach ($selectedCategories as $i => $cat) {
                $key = ':cat' . $i;
                $placeholders[] = $key;
                $inventoryParams[$key] = $cat;
            }
            $categoryPlaceholders = implode(', ', $placeholders);
        }

        if ($useHistoricalInventory) {
            $inventoryParams[':inv_date'] = $inventoryDate;
            $categoryFilterSql = $categoryPlaceholders ? ' AND p.category IN (' . $categoryPlaceholders . ')' : '';
            $inventorySql = "
                SELECT *
                FROM (
                    SELECT
                        p.id,
                        p.name,
                        p.price,
                        p.buying_price,
                        p.restock_level,
                        p.category,
                        COALESCE(
                            (SELECT dss.closing_quantity
                             FROM daily_stock_summary dss
                             WHERE dss.product_id = p.id AND dss.date = :inv_date),
                            (SELECT sc.new_quantity
                             FROM stock_changes sc
                             WHERE sc.product_id = p.id
                               AND date(datetime(sc.changed_at, '+2 hours')) <= :inv_date
                             ORDER BY datetime(sc.changed_at, '+2 hours') DESC
                             LIMIT 1),
                            0
                        ) AS quantity
                    FROM products p
                    WHERE 1=1 $categoryFilterSql
                ) inv
                WHERE CAST(quantity AS INTEGER) > 0
                ORDER BY category ASC, name ASC
            ";
        } else {
            $categoryFilterSql = $categoryPlaceholders ? ' AND category IN (' . $categoryPlaceholders . ')' : '';
            $inventorySql = "
                SELECT id, name, quantity, price, buying_price, restock_level, category
                FROM products
                WHERE CAST(quantity AS INTEGER) > 0 $categoryFilterSql
                ORDER BY category ASC, name ASC
            ";
        }

        $inventoryStmt = $db->prepare($inventorySql);
        $inventoryStmt->execute($inventoryParams);
        $currentInventory = $inventoryStmt->fetchAll(PDO::FETCH_ASSOC);

        $pdf->SetFont('Arial', '', 9);
        $totalProducts = 0;
        $totalItems = 0;
        $totalValue = 0;

        foreach ($currentInventory as $product) {
            $lineValue = (float)$product['price'] * (int)$product['quantity'];
            $pdf->Cell(10, 8, $product['id'], 1);
            $pdf->Cell(55, 8, $product['name'], 1);
            $pdf->Cell(25, 8, $product['category'] ?? '', 1);
            $pdf->Cell(18, 8, $product['quantity'], 1);
            $pdf->Cell(20, 8, 'N$' . number_format($product['price'], 2), 1);
            $pdf->Cell(20, 8, 'N$' . number_format($product['buying_price'], 2), 1);
            $pdf->Cell(18, 8, $product['restock_level'], 1);
            $pdf->Cell(24, 8, 'N$' . number_format($lineValue, 2), 1);
            $pdf->Ln();

            $totalProducts++;
            $totalItems += (int)$product['quantity'];
            $totalValue += (float)$product['price'] * (int)$product['quantity'];
        }

        $pdf->Ln(5);
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 10, 'Inventory Summary', 0, 1, 'L');
        $pdf->SetFont('Arial', '', 11);
        $pdf->Cell(100, 8, 'Total Product Types:', 0, 0, 'L');
        $pdf->Cell(50, 8, $totalProducts, 0, 1, 'L');
        $pdf->Cell(100, 8, 'Total Items in Stock:', 0, 0, 'L');
        $pdf->Cell(50, 8, $totalItems, 0, 1, 'L');
        $pdf->Cell(100, 8, 'Total Inventory Value:', 0, 0, 'L');
        $pdf->Cell(50, 8, 'N$' . number_format($totalValue, 2), 0, 1, 'L');

        $fileSuffix = !empty($selectedCategories)
            ? '_' . preg_replace('/[^a-zA-Z0-9_-]+/', '_', implode('-', $selectedCategories))
            : '';
        $dateSuffix = $useHistoricalInventory ? '_' . $inventoryDate : '';
        $fileName = 'Inventory_Report' . $dateSuffix . $fileSuffix . '_' . date('Y-m-d') . '.pdf';
        $pdf->Output('D', $fileName);
    }
    else if ($reportType == 'stock_receiving') {
        // Get receiving date from query parameter
        $receivingDate = isset($_GET['receiving_date']) ? $_GET['receiving_date'] : '';
        
        if (!$receivingDate) {
            die('Please select a date for the stock receiving report.');
        }
        
        // Store receiving date in global variable for PDF header
        $GLOBALS['receiving_date_for_pdf'] = $receivingDate;
        
        $pdf->setPageType('stock_receiving');
        $pdf->AddPage();
        $pdf->SetFont('Arial', '', 9);
        
        // Get all stock changes for the selected date where action is 'Restock'
        $receivingStmt = $db->prepare("
            SELECT 
                sc.*,
                p.name as product_name,
                p.price as product_price
            FROM stock_changes sc
            JOIN products p ON sc.product_id = p.id
            WHERE sc.action = 'Restock'
                AND date(datetime(sc.changed_at, '+2 hours')) = :receiving_date
            ORDER BY p.name ASC
        ");
        $receivingStmt->bindValue(':receiving_date', $receivingDate);
        $receivingStmt->execute();
        $receivingItems = $receivingStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $totalItems = 0;
        $totalValue = 0;
        
        // Add data to PDF (matching receiving.php format exactly)
        foreach ($receivingItems as $item) {
            $addedQuantity = $item['quantity_change'];
            $unitPrice = floatval($item['product_price']);
            $valueAdded = $addedQuantity * $unitPrice;
            
            $pdf->Cell(80, 8, $item['product_name'], 1);
            $pdf->Cell(35, 8, '+' . $addedQuantity, 1);
            $pdf->Cell(35, 8, 'N$' . number_format($unitPrice, 2), 1);
            $pdf->Cell(35, 8, 'N$' . number_format($valueAdded, 2), 1);
            $pdf->Ln();
            
            $totalItems += $addedQuantity;
            $totalValue += $valueAdded;
        }
        
        // Add summary section (matching receiving.php format exactly)
        $pdf->Ln(5);
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 10, 'Receiving Summary', 0, 1, 'L');
        $pdf->SetFont('Arial', '', 11);
        $pdf->Cell(100, 8, 'Total Items Received:', 0, 0, 'L');
        $pdf->Cell(50, 8, $totalItems, 0, 1, 'L');
        $pdf->Cell(100, 8, 'Total Restock Value:', 0, 0, 'L');
        $pdf->Cell(50, 8, 'N$' . number_format($totalValue, 2), 0, 1, 'L');
        
        // Output PDF
        $fileName = 'Stock_Receiving_Report_' . $receivingDate . '.pdf';
        $pdf->Output('D', $fileName);
    }
    
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Tracking</title>
    <link href="../src/output.css" rel="stylesheet">
    <script src="../navigation.js" async></script>
    <script src="../src/howler.min.js"></script>
    <script src="../src/chart.js"></script>
    <meta name="google" content="notranslate">
    <link rel="icon" href="favicon.ico" type="image/png">
    <link rel="stylesheet" href="../src/font-awesome/css/all.min.css">
    <style>
        .stock-tracking-header-row {
            flex-wrap: nowrap;
            scrollbar-width: none;
        }
        .stock-tracking-header-row::-webkit-scrollbar {
            display: none;
        }
    </style>
</head>
<body class="bg-gray-100 overflow-x-hidden">
    <div class="flex min-h-screen">
        <?php include 'sidebar.php'; ?>

        <div id="mobileOverlay" class="mobile-overlay lg:hidden" onclick="closeSidebar()"></div>

        <div class="content flex-1 lg:ml-64 min-w-0">
            <main class="p-4 lg:p-6">
                <div class="w-full flex items-center gap-1.5 sm:gap-2 mb-4 overflow-x-auto stock-tracking-header-row" style="-webkit-overflow-scrolling: touch;">
                    <div class="hamburger lg:hidden bg-[#f3f4f6] p-1.5 rounded flex-shrink-0" onclick="toggleSidebar()">
                        <span></span>
                        <span></span>
                        <span></span>
                    </div>
                    <a href="inventory" class="inline-flex items-center justify-center h-9 px-2.5 border border-gray-300 rounded-md shadow-sm text-xs sm:text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 transition-colors whitespace-nowrap flex-shrink-0">
                        <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                        </svg>
                        Go Back
                    </a>
                    <h1 class="text-lg sm:text-xl font-bold whitespace-nowrap flex-shrink-0">Stock Tracking</h1>

                    <a href="?export_pdf=true&report_type=stock_changes<?= $search ? '&search='.urlencode($search) : '' ?>" class="inline-flex items-center justify-center h-9 px-2.5 bg-gray-600 text-white text-xs sm:text-sm font-medium rounded-md hover:bg-gray-700 transition-colors whitespace-nowrap flex-shrink-0">
                        <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        Stock Changes
                    </a>
                    <input type="date" id="inventoryDate" name="inventory_date" max="<?= htmlspecialchars($todayDate) ?>"
                           title="Optional: pick a date for historical stock. Leave empty for current stock."
                           class="h-9 px-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white text-xs sm:text-sm flex-shrink-0 w-[9.5rem]">
                    <select id="inventoryCategories" name="categories[]"
                           title="Optional: select a category. Leave as All for every category."
                           class="h-9 min-w-[7.5rem] max-w-[9rem] px-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white text-xs sm:text-sm flex-shrink-0">
                        <option value="">All categories</option>
                        <?php foreach ($productCategories as $cat): ?>
                            <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" onclick="downloadInventoryReport()" class="inline-flex items-center justify-center h-9 px-2.5 bg-blue-600 text-white text-xs sm:text-sm font-medium rounded-md hover:bg-blue-700 transition-colors whitespace-nowrap flex-shrink-0">
                        <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        Inventory
                    </button>
                    <select id="receivingDate" name="receiving_date"
                           class="h-9 px-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-teal-500 bg-white text-xs sm:text-sm flex-shrink-0 max-w-[9rem]">
                        <option value="">Select date</option>
                        <?php foreach ($receivingDates as $date): ?>
                            <option value="<?= htmlspecialchars($date) ?>" <?= $receivingDate === $date ? 'selected' : '' ?>>
                                <?= htmlspecialchars($date) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" onclick="downloadReceivingReport()" class="inline-flex items-center justify-center h-9 px-2.5 bg-teal-600 text-white text-xs sm:text-sm font-medium rounded-md hover:bg-teal-700 transition-colors whitespace-nowrap flex-shrink-0">
                        <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        Receiving
                    </button>
                    <div class="relative flex-1 min-w-[10rem]">
                        <input type="text" id="searchInput" name="search" placeholder="Search..."
                               value="<?= htmlspecialchars($search) ?>"
                               class="h-9 w-full pl-8 pr-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-teal-500 bg-white text-xs sm:text-sm">
                        <svg class="w-4 h-4 absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                    </div>
                </div>

                <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden mb-8">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-300 h-16">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-black uppercase">Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-black uppercase">Product</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-black uppercase">Cashier</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-black uppercase">Action</th>
                                    <th class="px-6 py-3 text-center text-xs font-medium text-black uppercase">Qty Change</th>
                                    <th class="px-6 py-3 text-center text-xs font-medium text-black uppercase">Old Qty</th>
                                    <th class="px-6 py-3 text-center text-xs font-medium text-black uppercase">New Qty</th>
                                </tr>
                            </thead>
                            <tbody id="stockChangesBody" class="bg-white divide-y divide-gray-200">
                                <?php foreach ($stockChanges as $change): ?>
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?= htmlspecialchars($change['formatted_date']) ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?= htmlspecialchars($change['product_name']) ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?= htmlspecialchars(!empty($change['username']) ? $change['username'] : '-') ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 capitalize"><?= htmlspecialchars($change['action']) ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-center text-sm <?= $change['quantity_change'] > 0 ? 'text-teal-600' : 'text-red-600' ?>">
                                        <?= ($change['quantity_change'] > 0 ? '+' : '') . $change['quantity_change'] ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-500"><?= $change['old_quantity'] ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-500"><?= $change['new_quantity'] ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="px-6 py-4 border-t border-gray-200">
                        <div class="flex flex-wrap justify-between items-center gap-2">
                            <button id="prev-page" data-page="<?= max(1, $page - 1) ?>" class="page-button inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md <?= $page > 1 ? 'text-gray-700 bg-white hover:bg-gray-50' : 'text-gray-300 cursor-not-allowed' ?>">
                                Previous
                            </button>

                            <span id="page-info" class="text-sm text-gray-700">
                                Page <?= $page ?> of <?= $totalPages ?>
                            </span>

                            <button id="next-page" data-page="<?= min($totalPages, $page + 1) ?>" class="page-button inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md <?= $page < $totalPages ? 'text-gray-700 bg-white hover:bg-gray-50' : 'text-gray-300 cursor-not-allowed' ?>">
                                Next
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Weekly Stock Table -->
                <h2 class="text-2xl font-bold mb-4">Weekly Stock Overview (Monday-Sunday)</h2>

                <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden mb-8">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-300 h-16">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-black uppercase sticky left-0 bg-gray-300 z-10">Product</th>
                                    <th class="px-6 py-3 text-center text-xs font-medium text-black uppercase">Monday</th>
                                    <th class="px-6 py-3 text-center text-xs font-medium text-black uppercase">Tuesday</th>
                                    <th class="px-6 py-3 text-center text-xs font-medium text-black uppercase">Wednesday</th>
                                    <th class="px-6 py-3 text-center text-xs font-medium text-black uppercase">Thursday</th>
                                    <th class="px-6 py-3 text-center text-xs font-medium text-black uppercase">Friday</th>
                                    <th class="px-6 py-3 text-center text-xs font-medium text-black uppercase">Saturday</th>
                                    <th class="px-6 py-3 text-center text-xs font-medium text-black uppercase">Sunday</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200" id="weekly-stock-body">
                                <?php
                                // Get the start and end date of the current week (Monday to Sunday)
                                $startOfWeek = date('Y-m-d', strtotime('monday this week'));
                                $endOfWeek = date('Y-m-d', strtotime('sunday this week'));

                                // Query to get all stock changes this week
                                $weeklyStmt = $db->prepare("
                                    SELECT
                                        p.id as product_id,
                                        p.name as product_name,
                                        strftime('%w', datetime(sc.changed_at, '+2 hours')) as day_of_week,
                                        SUM(sc.quantity_change) as total_change
                                    FROM stock_changes sc
                                    JOIN products p ON sc.product_id = p.id
                                    WHERE date(datetime(sc.changed_at, '+2 hours')) BETWEEN :start_date AND :end_date
                                    GROUP BY p.id, p.name, day_of_week
                                    ORDER BY p.name, day_of_week
                                ");

                                $weeklyStmt->bindValue(':start_date', $startOfWeek);
                                $weeklyStmt->bindValue(':end_date', $endOfWeek);
                                $weeklyStmt->execute();
                                $weeklyData = $weeklyStmt->fetchAll(PDO::FETCH_ASSOC);

                                // Organize data by product and day
                                // Day mapping: 0=Sunday, 1=Monday, 2=Tuesday, 3=Wednesday, 4=Thursday, 5=Friday, 6=Saturday
                                // We want: Monday=1, Tuesday=2, Wednesday=3, Thursday=4, Friday=5, Saturday=6, Sunday=0
                                $productData = [];

                                foreach ($weeklyData as $item) {
                                    $productId = $item['product_id'];
                                    $productName = $item['product_name'];
                                    $dayOfWeek = (int)$item['day_of_week'];

                                    if (!isset($productData[$productId])) {
                                        $productData[$productId] = [
                                            'name' => $productName,
                                            'days' => [
                                                1 => 0, // Monday
                                                2 => 0, // Tuesday
                                                3 => 0, // Wednesday
                                                4 => 0, // Thursday
                                                5 => 0, // Friday
                                                6 => 0, // Saturday
                                                0 => 0  // Sunday
                                            ]
                                        ];
                                    }

                                    $productData[$productId]['days'][$dayOfWeek] += (int)$item['total_change'];
                                }

                                // Get all products that had changes this week
                                $allProductsStmt = $db->prepare("
                                    SELECT DISTINCT p.id, p.name
                                    FROM products p
                                    INNER JOIN stock_changes sc ON p.id = sc.product_id
                                    WHERE date(datetime(sc.changed_at, '+2 hours')) BETWEEN :start_date AND :end_date
                                    ORDER BY p.name
                                ");
                                $allProductsStmt->bindValue(':start_date', $startOfWeek);
                                $allProductsStmt->bindValue(':end_date', $endOfWeek);
                                $allProductsStmt->execute();
                                $allProducts = $allProductsStmt->fetchAll(PDO::FETCH_ASSOC);

                                // Merge product data for products that had changes
                                foreach ($allProducts as $product) {
                                    if (!isset($productData[$product['id']])) {
                                        $productData[$product['id']] = [
                                            'name' => $product['name'],
                                            'days' => [
                                                1 => 0, // Monday
                                                2 => 0, // Tuesday
                                                3 => 0, // Wednesday
                                                4 => 0, // Thursday
                                                5 => 0, // Friday
                                                6 => 0, // Saturday
                                                0 => 0  // Sunday
                                            ]
                                        ];
                                    }
                                }

                                if (count($productData) > 0) {
                                    // Sort by product name
                                    uasort($productData, function($a, $b) {
                                        return strcmp($a['name'], $b['name']);
                                    });

                                    foreach ($productData as $productId => $data) {
                                        echo '<tr class="hover:bg-gray-50 transition-colors">';
                                        echo '<td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 sticky left-0 bg-white z-10">' . htmlspecialchars($data['name']) . '</td>';

                                        // Display Monday through Sunday
                                        $dayOrder = [1, 2, 3, 4, 5, 6, 0]; // Monday to Sunday
                                        foreach ($dayOrder as $day) {
                                            $value = $data['days'][$day];
                                            $cellClass = 'px-6 py-4 whitespace-nowrap text-center text-sm ';
                                            if ($value > 0) {
                                                $cellClass .= 'text-teal-600 font-semibold';
                                                $displayValue = '+' . $value;
                                            } elseif ($value < 0) {
                                                $cellClass .= 'text-red-600 font-semibold';
                                                $displayValue = $value;
                                            } else {
                                                $cellClass .= 'text-gray-400';
                                                $displayValue = '-';
                                            }

                                            echo '<td class="' . $cellClass . '">' . $displayValue . '</td>';
                                        }

                                        echo '</tr>';
                                    }
                                } else {
                                    echo '<tr><td colspan="8" class="px-6 py-4 text-center text-sm text-gray-500">No stock changes this week</td></tr>';
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script>
        // JavaScript for AJAX pagination and real-time search
        document.addEventListener('DOMContentLoaded', function() {
            // Get the current search value from URL
            const urlParams = new URLSearchParams(window.location.search);
            let currentSearch = urlParams.get('search') || '';
            let searchTimeout;

            // Add event listener to search input for real-time search
            const searchInput = document.getElementById('searchInput');
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(function() {
                    currentSearch = searchInput.value;
                    loadData(1); // Reset to first page when search changes
                }, 300); // Debounce for 300ms
            });

            // Function to load data via AJAX
            function loadData(page) {
                const xhr = new XMLHttpRequest();
                xhr.open('GET', `stock_tracking.php?page=${page}&search=${encodeURIComponent(currentSearch)}&ajax=true`, true);

                xhr.onload = function() {
                    if (this.status === 200) {
                        const response = JSON.parse(this.responseText);
                        updateTable(response.data);
                        updatePagination(response.pagination);

                        // Update URL without reloading
                        const url = `?page=${page}${currentSearch ? '&search=' + encodeURIComponent(currentSearch) : ''}`;
                        history.pushState({page: page, search: currentSearch}, '', url);
                    }
                };

                xhr.send();
            }

            function escapeHtml(text) {
                const div = document.createElement('div');
                div.textContent = text == null ? '' : String(text);
                return div.innerHTML;
            }

            // Function to update table with new data
            function updateTable(data) {
                const tbody = document.getElementById('stockChangesBody');
                tbody.innerHTML = '';

                data.forEach(change => {
                    const row = document.createElement('tr');
                    row.className = 'hover:bg-gray-50 transition-colors';
                    const cashier = change.username && String(change.username).trim() !== '' ? change.username : '-';

                    row.innerHTML = `
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">${escapeHtml(change.formatted_date)}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">${escapeHtml(change.product_name)}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">${escapeHtml(cashier)}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 capitalize">${escapeHtml(change.action)}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-center text-sm ${parseInt(change.quantity_change) > 0 ? 'text-teal-600' : 'text-red-600'}">
                            ${(parseInt(change.quantity_change) > 0 ? '+' : '') + escapeHtml(change.quantity_change)}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-500">${escapeHtml(change.old_quantity)}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-500">${escapeHtml(change.new_quantity)}</td>
                    `;

                    tbody.appendChild(row);
                });
            }

            // Function to update pagination controls
            function updatePagination(pagination) {
                const pageInfo = document.getElementById('page-info');
                pageInfo.textContent = `Page ${pagination.page} of ${pagination.totalPages}`;

                const prevButton = document.getElementById('prev-page');
                const nextButton = document.getElementById('next-page');

                if (pagination.page > 1) {
                    prevButton.classList.remove('text-gray-300', 'cursor-not-allowed');
                    prevButton.classList.add('text-gray-700');
                    prevButton.dataset.page = pagination.page - 1;
                } else {
                    prevButton.classList.remove('text-gray-700');
                    prevButton.classList.add('text-gray-300', 'cursor-not-allowed');
                    prevButton.dataset.page = 1;
                }

                if (pagination.page < pagination.totalPages) {
                    nextButton.classList.remove('text-gray-300', 'cursor-not-allowed');
                    nextButton.classList.add('text-gray-700');
                    nextButton.dataset.page = pagination.page + 1;
                } else {
                    nextButton.classList.remove('text-gray-700');
                    nextButton.classList.add('text-gray-300', 'cursor-not-allowed');
                    nextButton.dataset.page = pagination.totalPages;
                }
            }

            // Add event listeners to pagination buttons
            document.addEventListener('click', function(e) {
                if (e.target.matches('.page-button') || e.target.closest('.page-button')) {
                    e.preventDefault();
                    const button = e.target.matches('.page-button') ? e.target : e.target.closest('.page-button');
                    const page = parseInt(button.dataset.page);

                    if (!button.classList.contains('cursor-not-allowed')) {
                        loadData(page);
                    }
                }
            });

            // Handle back/forward browser navigation
            window.addEventListener('popstate', function(e) {
                if (e.state) {
                    if (e.state.search !== undefined) {
                        currentSearch = e.state.search;
                        searchInput.value = currentSearch;
                    }
                    loadData(e.state.page || 1);
                } else {
                    loadData(1);
                }
            });

            // Initialize state for current page
            history.replaceState({page: <?= $page ?>, search: currentSearch}, '', window.location.href);
        });

        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('mobileOverlay');
            const hamburger = document.querySelector('.hamburger');

            sidebar.classList.toggle('open');
            overlay.classList.toggle('active');
            hamburger.classList.toggle('open');
        }

        function closeSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('mobileOverlay');
            const hamburger = document.querySelector('.hamburger');

            sidebar.classList.remove('open');
            overlay.classList.remove('active');
            hamburger.classList.remove('open');
        }

        // Function to download current inventory report (optional category filter)
        function downloadInventoryReport() {
            const select = document.getElementById('inventoryCategories');
            const selected = Array.from(select.selectedOptions).map(function(opt) { return opt.value; }).filter(Boolean);
            const inventoryDate = document.getElementById('inventoryDate').value;
            let url = '?export_pdf=true&report_type=inventory';
            if (inventoryDate) {
                url += '&inventory_date=' + encodeURIComponent(inventoryDate);
            }
            selected.forEach(function(cat) {
                url += '&categories[]=' + encodeURIComponent(cat);
            });
            window.location.href = url;
        }

        // Function to download stock receiving report
        function downloadReceivingReport() {
            const receivingDate = document.getElementById('receivingDate').value;
            if (!receivingDate) {
                alert('Please select a date with receiving data.');
                return;
            }

            // Open PDF download link
            window.location.href = `?export_pdf=true&report_type=stock_receiving&receiving_date=${receivingDate}`;
        }
    </script>
</body>
</html>
