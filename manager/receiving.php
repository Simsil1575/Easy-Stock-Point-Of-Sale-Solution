<?php

// Include PHPMailer classes at the top
require_once '../resetpass/PHPMailer/src/PHPMailer.php';
require_once '../resetpass/PHPMailer/src/SMTP.php';
require_once '../resetpass/PHPMailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

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
require_once __DIR__ . '/../recipe_stock_helper.php';
require_once __DIR__ . '/../purchase_order_lib.php';
require_once __DIR__ . '/../ensure_purchase_order_schema.php';
require_once __DIR__ . '/../ensure_stock_changes_username.php';
configureSqlitePdo($db);
ensurePurchaseOrderSchema($db);
ensureStockChangesUsernameColumn($db);
$userDb = new PDO('sqlite:../user.db');

// Get user email from user database
$userEmail = '';
try {
    $userStmt = $userDb->prepare("SELECT email FROM users WHERE id = ?");
    $userStmt->execute([$_SESSION['user_id']]);
    $userData = $userStmt->fetch(PDO::FETCH_ASSOC);
    if ($userData && !empty($userData['email'])) {
        $userEmail = $userData['email'];
    }
} catch (Exception $e) {
    // If email not found, use a default or leave empty
    error_log("Could not fetch user email: " . $e->getMessage());
}

// Set the default timezone to Namibian time
date_default_timezone_set('Africa/Harare');

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'po_lines') {
    header('Content-Type: application/json');
    $poId = (int) ($_GET['po_id'] ?? 0);
    $data = poLinesForReceiving($db, $poId);
    if (!$data) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Purchase order not found or not open for receiving.']);
    } else {
        echo json_encode(['success' => true, 'data' => $data]);
    }
    exit;
}

// Handle form submission for bulk receiving
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Handle both JSON and form data
        $receivingData = null;
        $isAjaxRequest = false;
        $isPdfOnly = false;
        
        if (isset($_POST['receiving_data'])) {
            // Form submission for PDF download ONLY (data already saved via AJAX)
            $receivingData = json_decode($_POST['receiving_data'], true);
            $isPdfOnly = true; // Don't save to database again
        } else {
            // JSON submission for AJAX - this saves to database
            $receivingData = json_decode(file_get_contents('php://input'), true);
            $isAjaxRequest = true;
        }
        
        if (isset($receivingData['items']) && is_array($receivingData['items'])) {
            $receivingItems = []; // Store items for PDF generation
            $receivingItemsForDb = []; // Store items for database
            
            // Determine selected receiving date/time (supports backdating)
            $selectedDateTime = null;
            if (!empty($receivingData['receiving_date'])) {
                try {
                    $dt = new DateTime($receivingData['receiving_date']);
                    $selectedDateTime = $dt->format('Y-m-d H:i:s');
                } catch (Exception $e) {
                    $selectedDateTime = date('Y-m-d H:i:s');
                }
            } else {
                $selectedDateTime = date('Y-m-d H:i:s');
            }
            $today = substr($selectedDateTime, 0, 10);

            $purchaseOrderId = !empty($receivingData['purchase_order_id']) ? (int) $receivingData['purchase_order_id'] : null;
            $supplierIdInput = !empty($receivingData['supplier_id']) ? (int) $receivingData['supplier_id'] : null;
            $supplierNameForPdf = (string) ($receivingData['supplier_name'] ?? '');
            $poNumberForPdf = (string) ($receivingData['po_number'] ?? '');

            if (!$isPdfOnly) {
                $resolved = poResolveSupplierForReceiving(
                    $db,
                    ($purchaseOrderId !== null && $purchaseOrderId > 0) ? $purchaseOrderId : null,
                    $supplierIdInput
                );
                $purchaseOrderId = $resolved['purchase_order_id'];
                $supplierId = $resolved['supplier_id'];
                $supplierNameForPdf = (string) ($receivingData['supplier_name'] ?? '');
                if ($supplierId !== null && $supplierId > 0) {
                    $supRow = $db->prepare('SELECT name FROM suppliers WHERE id = ?');
                    $supRow->execute([$supplierId]);
                    $supplierNameForPdf = (string) ($supRow->fetchColumn() ?: $supplierNameForPdf);
                }
                if ($purchaseOrderId !== null) {
                    $poNumberForPdf = poFormatNumber($purchaseOrderId);
                }
            } else {
                $supplierId = ($supplierIdInput !== null && $supplierIdInput > 0) ? $supplierIdInput : null;
                if ($purchaseOrderId !== null && $purchaseOrderId < 1) {
                    $purchaseOrderId = null;
                }
            }
            
            // Running totals for the receiving record
            $totalItemsCount = 0;
            $totalQuantity = 0;
            $totalValue = 0;
            $totalCost = 0;
            
            // Only update database if this is NOT a PDF-only request
            if (!$isPdfOnly) {
                $db->beginTransaction();
            }
            
            foreach ($receivingData['items'] as $item) {
                if (!empty($item['product_id']) && !empty($item['quantity']) && $item['quantity'] > 0) {
                    $productId = $item['product_id'];
                    $quantity = floatval($item['quantity']);
                    
                    // Get current product info
                    $stmt = $db->prepare("SELECT name, quantity, price, buying_price FROM products WHERE id = ?");
                    $stmt->execute([$productId]);
                    $product = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($product) {
                        $oldQuantity = floatval($product['quantity']);
                        // For PDF only, calculate what the new quantity would be (don't update)
                        // For AJAX, this is before update so it's correct
                        $newQuantity = $isPdfOnly ? $oldQuantity : ($oldQuantity + $quantity);
                        $unitCost = array_key_exists('buying_price', $item)
                            ? floatval($item['buying_price'])
                            : floatval($product['buying_price'] ?? $product['price']);
                        $itemValue = $quantity * floatval($product['price']);
                        $itemCost = $quantity * $unitCost;
                        
                        // Store item details for PDF
                        $receivingItems[] = [
                            'product_name' => $product['name'],
                            'old_quantity' => $isPdfOnly ? ($oldQuantity - $quantity) : $oldQuantity, // For PDF, stock was already updated
                            'added_quantity' => $quantity,
                            'new_quantity' => $oldQuantity, // Current quantity (already updated for PDF)
                            'price' => $product['price'],
                            'buying_price' => $unitCost,
                            'total_value' => $itemValue,
                            'total_cost' => $itemCost
                        ];
                        
                        // Update running totals
                        $totalItemsCount++;
                        $totalQuantity += $quantity;
                        $totalValue += $itemValue;
                        $totalCost += $itemCost;
                        
                        // Only update database if NOT PDF-only request
                        if (!$isPdfOnly) {
                            // Store item details for database
                            $receivingItemsForDb[] = [
                                'product_id' => $productId,
                                'product_name' => $product['name'],
                                'quantity_added' => $quantity,
                                'old_quantity' => $oldQuantity,
                                'new_quantity' => $newQuantity,
                                'unit_price' => $product['price'],
                                'buying_price' => $unitCost,
                                'total_value' => $itemValue,
                                'total_cost' => $itemCost
                            ];
                            
                            // Update product quantity and cost price used for this receive
                            $updateStmt = $db->prepare("UPDATE products SET quantity = ?, buying_price = ? WHERE id = ?");
                            $updateStmt->execute([$newQuantity, $unitCost, $productId]);
                            adjustRecipeStockByProductId($db, (int) $productId, (float) $quantity);
                            
                            // Log the stock change (use selected receiving date/time)
                            $logStmt = $db->prepare("INSERT INTO stock_changes (product_id, action, quantity_change, old_quantity, new_quantity, changed_at, username) VALUES (?, ?, ?, ?, ?, ?, ?)");
                            $logStmt->execute([$productId, 'Restock', $quantity, $oldQuantity, $newQuantity, $selectedDateTime, currentStockChangeUsername()]);
                            
                            // Update or insert daily stock summary - only update received quantity
                            $summaryStmt = $db->prepare("
                                INSERT OR REPLACE INTO daily_stock_summary 
                                (date, product_id, opening_quantity, closing_quantity, received_quantity, sold_quantity, damaged_quantity)
                                VALUES (
                                    ?,
                                    ?,
                                    COALESCE((SELECT opening_quantity FROM daily_stock_summary WHERE date = ? AND product_id = ?), 0),
                                    COALESCE((SELECT closing_quantity FROM daily_stock_summary WHERE date = ? AND product_id = ?), ?),
                                    COALESCE((SELECT received_quantity FROM daily_stock_summary WHERE date = ? AND product_id = ?), 0) + ?,
                                    COALESCE((SELECT sold_quantity FROM daily_stock_summary WHERE date = ? AND product_id = ?), 0),
                                    COALESCE((SELECT damaged_quantity FROM daily_stock_summary WHERE date = ? AND product_id = ?), 0)
                                )
                            ");
                            $summaryStmt->execute([
                                $today, $productId, $today, $productId, $today, $productId, $newQuantity,
                                $today, $productId, $quantity,
                                $today, $productId,
                                $today, $productId
                            ]);
                        }
                    }
                }
            }
            
            // Create receiving record in database (for tracking and email retry) - ONLY for AJAX requests
            $receivingRecordId = null;
            if (!$isPdfOnly && !empty($receivingItemsForDb)) {
                // Insert receiving record
                $recordStmt = $db->prepare("
                    INSERT INTO receiving_records 
                    (user_id, username, receiving_date, total_items, total_quantity, total_value, total_cost, email_status, purchase_order_id, supplier_id, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, datetime('now'))
                ");
                $recordStmt->execute([
                    $_SESSION['user_id'],
                    $_SESSION['username'],
                    $selectedDateTime,
                    $totalItemsCount,
                    $totalQuantity,
                    $totalValue,
                    $totalCost,
                    $purchaseOrderId,
                    $supplierId,
                ]);
                $receivingRecordId = $db->lastInsertId();
                
                // Insert receiving items
                $itemStmt = $db->prepare("
                    INSERT INTO receiving_items 
                    (record_id, product_id, product_name, quantity_added, old_quantity, new_quantity, unit_price, buying_price, total_value, total_cost)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                foreach ($receivingItemsForDb as $dbItem) {
                    $itemStmt->execute([
                        $receivingRecordId,
                        $dbItem['product_id'],
                        $dbItem['product_name'],
                        $dbItem['quantity_added'],
                        $dbItem['old_quantity'],
                        $dbItem['new_quantity'],
                        $dbItem['unit_price'],
                        $dbItem['buying_price'],
                        $dbItem['total_value'],
                        $dbItem['total_cost']
                    ]);
                }

                $receivedForPo = [];
                foreach ($receivingItemsForDb as $dbItem) {
                    $receivedForPo[] = [
                        'product_id' => (int) $dbItem['product_id'],
                        'quantity' => (int) $dbItem['quantity_added'],
                    ];
                }
                if ($purchaseOrderId !== null && $purchaseOrderId > 0) {
                    poApplyReceiving($db, $purchaseOrderId, $receivedForPo);
                }
            }
            
            // Commit transaction only if we started one
            if (!$isPdfOnly) {
                $db->commit();
            }
            
            // Generate PDF if there are items and it's a form submission (PDF only)
            if (!empty($receivingItems) && $isPdfOnly) {
                // Include FPDF library
                if (!file_exists('../fpdf/fpdf.php')) {
                    die('FPDF library not found at ../fpdf/fpdf.php');
                }
                require('../fpdf/fpdf.php');
                
                // Create new PDF instance (receiving date passed in as displayDate)
                class ReceivingPDF extends FPDF {
                    var $displayDate;
                    var $supplierName;
                    var $poNumber;
                    function Header() {
                        $this->SetFont('Arial', 'B', 15);
                        $this->Cell(0, 10, 'Stock Receiving Report', 0, 1, 'C');
                        $this->SetFont('Arial', '', 12);
                        $this->Cell(0, 8, 'Receiving date: ' . $this->displayDate, 0, 1, 'C');
                        if ($this->supplierName !== '') {
                            $this->Cell(0, 8, 'Supplier: ' . $this->supplierName, 0, 1, 'C');
                        }
                        if ($this->poNumber !== '') {
                            $this->Cell(0, 8, 'Purchase order: ' . $this->poNumber, 0, 1, 'C');
                        }
                        $this->Ln(6);
                        
                        // Table header (fits A4 portrait ~190mm)
                        $this->SetFont('Arial', 'B', 9);
                        $this->Cell(62, 10, 'Product', 1);
                        $this->Cell(16, 10, 'Added', 1);
                        $this->Cell(26, 10, 'Sell Price', 1);
                        $this->Cell(26, 10, 'Cost Price', 1);
                        $this->Cell(28, 10, 'Value Added', 1);
                        $this->Cell(28, 10, 'Total Cost', 1);
                        $this->Ln();
                    }
                    
                    function Footer() {
                        $this->SetY(-15);
                        $this->SetFont('Arial', 'I', 8);
                        $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
                    }
                }
                
                // Initialize PDF with selected receiving date
                $pdf = new ReceivingPDF();
                $pdf->displayDate = $selectedDateTime;
                $pdf->supplierName = $supplierNameForPdf;
                $pdf->poNumber = $poNumberForPdf;
                $pdf->AliasNbPages();
                $pdf->AddPage();
                $pdf->SetFont('Arial', '', 9);
                
                $totalItems = 0;
                $totalValue = 0;
                
                // Add data to PDF
                foreach ($receivingItems as $item) {
                    $name = $item['product_name'];
                    if (strlen($name) > 38) {
                        $name = substr($name, 0, 35) . '...';
                    }
                    $pdf->Cell(62, 8, $name, 1);
                    $pdf->Cell(16, 8, '+' . $item['added_quantity'], 1, 0, 'C');
                    $pdf->Cell(26, 8, 'N$' . number_format($item['price'], 2), 1, 0, 'R');
                    $pdf->Cell(26, 8, 'N$' . number_format($item['buying_price'], 2), 1, 0, 'R');
                    $pdf->Cell(28, 8, 'N$' . number_format($item['total_value'], 2), 1, 0, 'R');
                    $pdf->Cell(28, 8, 'N$' . number_format($item['total_cost'], 2), 1, 0, 'R');
                    $pdf->Ln();
                    
                    $totalItems += $item['added_quantity'];
                    $totalValue += $item['total_value'];
                }
                
                // Add summary section
                $pdf->Ln(5);
                $pdf->SetFont('Arial', 'B', 12);
                $pdf->Cell(0, 10, 'Receiving Summary', 0, 1, 'L');
                $pdf->SetFont('Arial', '', 11);
                $pdf->Cell(100, 8, 'Total Items Received:', 0, 0, 'L');
                $pdf->Cell(50, 8, $totalItems, 0, 1, 'L');
                $pdf->Cell(100, 8, 'Total Restock Value:', 0, 0, 'L');
                $pdf->Cell(50, 8, 'N$' . number_format($totalValue, 2), 0, 1, 'L');
                $pdf->Cell(100, 8, 'Total Cost (at cost price):', 0, 0, 'L');
                $pdf->Cell(50, 8, 'N$' . number_format($totalCost, 2), 0, 1, 'L');
                
                // Generate filename using selected receiving date
                $fileName = 'Stock_Receiving_Report_' . date('Y-m-d_H-i-s', strtotime($selectedDateTime)) . '.pdf';
                
                // Set proper headers for PDF download
                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="' . $fileName . '"');
                header('Cache-Control: private, max-age=0, must-revalidate');
                header('Pragma: public');
                
                // Output PDF for download and exit immediately
                $pdf->Output('D', $fileName);
                exit;
            }
            
            // Return JSON response for AJAX requests
            if ($isAjaxRequest) {
                http_response_code(200);
                echo json_encode([
                    'success' => true, 
                    'message' => 'Stock received successfully',
                    'record_id' => $receivingRecordId
                ]);
                exit;
            }
        }
    } catch (Exception $e) {
        // Only rollback if we started a transaction
        if (!$isPdfOnly) {
            $db->rollBack();
        }
        if ($isAjaxRequest) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        } else {
            // For form submissions, show error in a simple way
            echo '<script>alert("Error: ' . addslashes($e->getMessage()) . '"); window.close();</script>';
        }
        exit;
    }
}

// Fetch products from the database
$stmt = $db->query('
    SELECT p.*, COALESCE(SUM(oi.quantity), 0) as total_sold
    FROM products p
    LEFT JOIN order_items oi ON p.name = oi.product_name
    GROUP BY p.id
    ORDER BY p.name ASC
');

$products = [];
$lowStock = [];
$outOfStock = [];

// Fetch unique categories
$catStmt = $db->query("SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category != '' ORDER BY category");
$categories = [];
while ($cat = $catStmt->fetch(PDO::FETCH_ASSOC)) {
    $categories[] = $cat['category'];
}

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $products[] = $row;
    if ($row['quantity'] <= 0) {
        $outOfStock[] = $row;
    } else if ($row['quantity'] < 5) {
        $lowStock[] = $row;
    }
}

$openPurchaseOrders = poListOpenForReceiving($db);
$activeSuppliers = poListActiveSuppliers($db);
$preselectedPoId = isset($_GET['po_id']) ? (int) $_GET['po_id'] : 0;
$preselectedSupplierId = isset($_GET['supplier_id']) ? (int) $_GET['supplier_id'] : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Receiving</title>
    <link href="../src/output.css" rel="stylesheet">
    <script src="../navigation.js" async></script>
    <script src="../src/howler.min.js"></script>
    <script src="../src/chart.js"></script>
    <meta name="google" content="notranslate">
    <link rel="icon" href="favicon.ico" type="image/png">
    <link rel="stylesheet" href="../src/font-awesome/css/all.min.css">
    <script src="3.4.16"></script>
    <style>
        .toast-notification {
            transition: opacity 0.3s ease;
        }
        #receivingToastHost {
            position: fixed;
            top: 5.5rem;
            left: 50%;
            transform: translateX(-50%);
            z-index: 10000;
            display: flex;
            flex-direction: column;
            align-items: stretch;
            gap: 0.5rem;
            width: min(22rem, calc(100vw - 2rem));
            pointer-events: none;
        }
        .receiving-toast {
            padding: 0.75rem 1rem;
            border-radius: 0.5rem;
            color: #fff;
            font-size: 0.875rem;
            line-height: 1.35;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.18);
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
            pointer-events: auto;
        }
        .receiving-toast--success { background: #0d9488; }
        .receiving-toast--error { background: #e11d48; }
        .receiving-toast--info { background: #0369a1; }
        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        .receiving-row {
            transition: all 0.3s ease;
        }
        .receiving-row:hover {
            background-color: #f8fafc;
        }
        .quantity-input {
            width: 80px;
            max-width: 100%;
            text-align: center;
        }
        
        /* Responsive quantity inputs - equal size within context */
        @media (max-width: 640px) {
            .quantity-input {
                width: 100% !important;
                max-width: 100%;
                min-width: 0;
            }
            
            /* Ensure inputs in table cells fit properly */
            table td .quantity-input {
                width: 100% !important;
                max-width: calc(100% - 0.5rem);
            }
        }
        
        @media (min-width: 641px) and (max-width: 1023px) {
            .quantity-input {
                width: 70px;
                max-width: 100%;
            }
        }
        .selected-row {
            background-color: #eff6ff !important;
            border-left: 4px solid #3b82f6;
        }
        .bulk-actions {
            transition: all 0.3s ease;
        }
        
        .calculator-popup {
            min-width: 250px;
        }
        .calculator-icon {
            padding: 8px;
            margin: -8px;
            display: inline-block;
            position: relative;
        }
        .calculator-icon::after {
            content: '';
            position: absolute;
            top: -8px;
            left: -8px;
            right: -8px;
            bottom: -8px;
            z-index: -1;
        }
        .buying-price-wrap {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.125rem;
            min-width: 0;
            max-width: 100%;
        }
        .buying-price-wrap .buying-price-input {
            flex: 1 1 0;
            min-width: 0;
        }
        
        /* Premium Loading Overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(8px);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }
        
        .loading-overlay.show {
            opacity: 1;
            visibility: visible;
        }
        
        .loading-container {
            background: white;
            border-radius: 20px;
            padding: 3rem 2.5rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            text-align: center;
            transform: scale(0.8) translateY(20px);
            transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        
        .loading-overlay.show .loading-container {
            transform: scale(1) translateY(0);
        }
        
        .spinner {
            width: 60px;
            height: 60px;
            border: 4px solid #f3f4f6;
            border-top: 4px solid #10b981;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 1.5rem;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .pulse-dots {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-top: 1rem;
        }
        
        .pulse-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #10b981;
            animation: pulse-dot 1.4s ease-in-out infinite both;
        }
        
        .pulse-dot:nth-child(1) { animation-delay: -0.32s; }
        .pulse-dot:nth-child(2) { animation-delay: -0.16s; }
        .pulse-dot:nth-child(3) { animation-delay: 0s; }
        
        @keyframes pulse-dot {
            0%, 80%, 100% {
                transform: scale(0);
                opacity: 0.5;
            }
            40% {
                transform: scale(1);
                opacity: 1;
            }
        }
        
        .progress-bar {
            width: 100%;
            height: 4px;
            background: #f3f4f6;
            border-radius: 2px;
            overflow: hidden;
            margin-top: 1.5rem;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #10b981, #059669);
            border-radius: 2px;
            width: 0%;
            animation: progress 3s ease-in-out;
        }
        
        @keyframes progress {
            0% { width: 0%; }
            50% { width: 70%; }
            100% { width: 95%; }
        }
        
        /* Mobile hamburger menu styles */
        .hamburger {
            position: relative;
            width: 30px;
            height: 24px;
            cursor: pointer;
            z-index: 10000; /* Highest - always accessible */
        }
        
        .hamburger span {
            display: block;
            position: absolute;
            height: 3px;
            width: 100%;
            background: rgb(0, 0, 0);
            border-radius: 2px;
            opacity: 1;
            left: 0;
            transform: rotate(0deg);
            transition: .25s ease-in-out;
        }
        
        .hamburger span:nth-child(1) {
            top: 0px;
        }
        
        .hamburger span:nth-child(2) {
            top: 10px;
        }
        
        .hamburger span:nth-child(3) {
            top: 20px;
        }
        
        .hamburger.open span:nth-child(1) {
            top: 10px;
            transform: rotate(135deg);
        }
        
        .hamburger.open span:nth-child(2) {
            opacity: 0;
            left: -60px;
        }
        
        .hamburger.open span:nth-child(3) {
            top: 10px;
            transform: rotate(-135deg);
        }
        
        /* Mobile sidebar overlay */
        .mobile-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 80; /* Below sidebar (9999) and hamburger (10000) - matches credit-tabs.php */
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }
        
        .mobile-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        /* Receiving header: keep one row; hide scrollbar while allowing swipe scroll */
        .receiving-header-row {
            flex-wrap: nowrap;
            scrollbar-width: none;
        }
        .receiving-header-row::-webkit-scrollbar {
            display: none;
        }
        
        /* Ensure sidebar maintains proper z-index above overlay */
        .sidebar {
            z-index: 10000 !important;
        }
        
        #sidebar {
            z-index: 10000 !important;
        }
        
        /* Mobile responsive adjustments */
        @media (max-width: 1023px) {
            /* Remove left margin on mobile */
            .ml-64 {
                margin-left: 0 !important;
            }
            
            /* Ensure content takes full width on mobile */
            .flex-1 {
                width: 100%;
                max-width: 100vw;
                overflow-x: hidden;
            }
            
            .content {
                margin-left: 0 !important;
                width: 100% !important;
                max-width: 100vw !important;
                overflow-x: hidden;
            }
        }
        
        /* Ensure table fits on mobile without horizontal scroll */
        @media (max-width: 640px) {
            .mobile-table-container {
                width: 100%;
                overflow: visible;
                max-width: 100vw;
            }
            
            .mobile-table-container table {
                overflow: visible;
            }
            
            table {
                font-size: 0.7rem;
                table-layout: fixed;
                width: 100%;
                max-width: 100%;
            }
            
            /* Keep same padding as desktop - maintain column spacing */
            table th,
            table td {
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            
            /* Product name column - allow wrapping, no ellipsis */
            #itemsBeingAdded table th:nth-child(1),
            #itemsBeingAdded table td:nth-child(1) {
                white-space: normal !important;
                overflow: visible !important;
                text-overflow: clip !important;
                word-wrap: break-word;
                word-break: break-word;
            }
            
            /* Slim headers on mobile */
            table th:first-child {
                padding: 0.5rem 1.5rem; /* Slim on mobile - py-2 */
            }
            
            table th:not(:first-child) {
                padding: 0.5rem 1.5rem; /* Slim on mobile - py-2 */
            }
            
            table td {
                padding: 0.75rem 1.5rem; /* Slightly reduced on mobile - py-3 */
            }
            
            /* Column width distribution for mobile - receiving table has 8 columns */
            table th:nth-child(1),
            table td:nth-child(1) {
                width: 8%; /* Checkbox */
                min-width: 0;
            }
            
            table th:nth-child(2),
            table td:nth-child(2) {
                width: 10%; /* Image */
            }
            
            table th:nth-child(3),
            table td:nth-child(3) {
                width: 20%; /* Product name */
            }
            
            table th:nth-child(4),
            table td:nth-child(4) {
                width: 12%; /* Unit Price */
            }
            
            table th:nth-child(5),
            table td:nth-child(5) {
                width: 12%; /* Buying Price */
            }
            
            table th:nth-child(6),
            table td:nth-child(6) {
                width: 12%; /* Current Stock */
            }
            
            table th:nth-child(7),
            table td:nth-child(7) {
                width: 13%; /* Receiving Qty */
            }
            
            table th:nth-child(8),
            table td:nth-child(8) {
                width: 13%; /* New Total */
            }
            
            /* Make buttons smaller on mobile */
            button, .btn, a[class*="inline-flex"] {
                padding: 0.375rem 0.75rem !important;
                font-size: 0.75rem !important;
            }
            
            button svg, .btn svg, a[class*="inline-flex"] svg {
                width: 0.875rem !important;
                height: 0.875rem !important;
            }
            
            /* Remove sort icons on mobile to save space */
            table th svg {
                display: none;
            }
            
            /* Make inputs smaller on mobile and ensure they fit */
            input[type="text"],
            input[type="number"],
            input[type="datetime-local"],
            select {
                padding: 0.375rem 0.5rem !important;
                font-size: 0.75rem !important;
                width: 100% !important;
                max-width: 100% !important;
                box-sizing: border-box;
            }
            
            /* Ensure table cell inputs fit within their cells */
            table td input[type="number"],
            table td input[type="text"] {
                width: 100% !important;
                max-width: calc(100% - 0.25rem) !important;
                min-width: 0 !important;
            }
            
            /* Equal size for inputs in same column - receiving quantity and buying price */
            table td:nth-child(7) .receiving-quantity,
            table td:nth-child(5) .buying-price-wrap .buying-price-input {
                width: 100% !important;
                max-width: 100% !important;
                min-width: 0 !important;
            }
            
            /* Ensure bulk quantity input fits */
            #bulkQuantity {
                width: 100% !important;
                max-width: 120px !important;
                min-width: 60px !important;
            }
            
            /* Header inputs - ensure they fit and are equal size in context */
            #searchInput {
                width: 100% !important;
                max-width: 100% !important;
                min-width: 120px !important;
                box-sizing: border-box;
            }
            
            #categoryFilter,
            #receivingDate {
                width: auto !important;
                min-width: 100px !important;
                max-width: 100% !important;
                box-sizing: border-box;
            }
            
            /* Ensure header input containers don't overflow */
            .flex.items-center.gap-2.flex-wrap {
                width: 100%;
            }
            
            .flex.items-center.gap-2.flex-wrap > * {
                flex: 0 1 auto;
                min-width: 0;
            }
            
            /* Ensure relative container for search input doesn't overflow */
            .relative {
                max-width: 100%;
            }
            
            /* Items Being Added table - Product name takes most space */
            #itemsBeingAdded .mobile-table-container table {
                table-layout: fixed;
            }
            
            #itemsBeingAdded table th:nth-child(1),
            #itemsBeingAdded table td:nth-child(1) {
                width: 40% !important; /* Product name - most space */
            }
            
            #itemsBeingAdded table th:nth-child(2),
            #itemsBeingAdded table td:nth-child(2) {
                width: 12% !important; /* Unit Price */
            }
            
            #itemsBeingAdded table th:nth-child(3),
            #itemsBeingAdded table td:nth-child(3) {
                width: 10% !important; /* Adding */
            }
            
            #itemsBeingAdded table th:nth-child(4),
            #itemsBeingAdded table td:nth-child(4) {
                width: 12% !important; /* Current Stock */
            }
            
            #itemsBeingAdded table th:nth-child(5),
            #itemsBeingAdded table td:nth-child(5) {
                width: 12% !important; /* New Total */
            }
            
            #itemsBeingAdded table th:nth-child(6),
            #itemsBeingAdded table td:nth-child(6) {
                width: 14% !important; /* Actions */
            }
        }
        
        /* Desktop table - keep original size */
        @media (min-width: 1024px) {
            .mobile-table-container table {
                table-layout: auto;
            }
            
            /* Restore original desktop padding - override mobile styles */
            .mobile-table-container table th:first-child {
                padding: 1.5rem 1.5rem !important; /* py-6 px-6 */
            }
            
            .mobile-table-container table th:not(:first-child) {
                padding: 0.75rem 1.5rem !important; /* py-3 px-6 */
            }
            
            .mobile-table-container table td {
                padding: 1rem 1.5rem !important; /* py-4 px-6 */
            }
            
            /* Remove fixed width constraints on desktop */
            .mobile-table-container table th,
            .mobile-table-container table td {
                width: auto !important;
            }
            
            /* Items Being Added table - Product name takes most space on desktop too */
            #itemsBeingAdded .mobile-table-container table {
                table-layout: fixed;
            }
            
            #itemsBeingAdded table th:nth-child(1),
            #itemsBeingAdded table td:nth-child(1) {
                width: 35% !important; /* Product name - most space */
            }
            
            #itemsBeingAdded table th:nth-child(2),
            #itemsBeingAdded table td:nth-child(2) {
                width: 13% !important; /* Unit Price */
            }
            
            #itemsBeingAdded table th:nth-child(3),
            #itemsBeingAdded table td:nth-child(3) {
                width: 12% !important; /* Adding */
            }
            
            #itemsBeingAdded table th:nth-child(4),
            #itemsBeingAdded table td:nth-child(4) {
                width: 13% !important; /* Current Stock */
            }
            
            #itemsBeingAdded table th:nth-child(5),
            #itemsBeingAdded table td:nth-child(5) {
                width: 13% !important; /* New Total */
            }
            
            #itemsBeingAdded table th:nth-child(6),
            #itemsBeingAdded table td:nth-child(6) {
                width: 14% !important; /* Actions */
            }
        }
    </style>
</head>
<body class="bg-gray-100 overflow-x-hidden" data-server-date="<?= htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>">
    <div class="flex min-h-screen">
        <?php include 'sidebar.php'; ?>
        
        <!-- Mobile Overlay -->
        <div id="mobileOverlay" class="mobile-overlay lg:hidden" onclick="closeSidebar()"></div>
        
        <!-- Main Content -->
        <div class="content flex-1 lg:ml-64">
            <!-- Sticky Header — single row, scrolls horizontally on narrow screens -->
            <div id="receivingFixedHeader" class="sticky top-0 z-50 bg-gray-100 py-2 sm:py-3 px-2 sm:px-4 lg:px-6 shadow-sm border-b border-gray-200">
                <div class="w-full flex items-center gap-1.5 sm:gap-2 overflow-x-auto receiving-header-row" style="-webkit-overflow-scrolling: touch;">
                    <div class="hamburger lg:hidden bg-[#f3f4f6] p-1.5 sm:p-2 rounded flex-shrink-0" onclick="toggleSidebar()">
                        <span></span>
                        <span></span>
                        <span></span>
                    </div>
                    <h1 class="text-sm sm:text-lg lg:text-xl font-bold whitespace-nowrap flex-shrink-0">
                        <span class="sm:hidden">Receiving</span>
                        <span class="hidden sm:inline">Stock Receiving</span>
                    </h1>
                    <select id="poFilter" class="flex-shrink-0 w-[7.5rem] sm:w-auto sm:min-w-[9rem] lg:min-w-[11rem] px-1.5 sm:px-2 lg:px-3 py-1.5 sm:py-2 text-[11px] sm:text-xs lg:text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-teal-500 shadow-sm bg-white">
                        <option value="">No PO (ad-hoc)</option>
                        <?php foreach ($openPurchaseOrders as $opo): ?>
                            <option value="<?= (int) $opo['id'] ?>" data-supplier-id="<?= (int) $opo['supplier_id'] ?>" <?= $preselectedPoId === (int) $opo['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars(poFormatNumber((int) $opo['id']) . ' — ' . $opo['supplier_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select id="supplierFilter" class="flex-shrink-0 w-[7rem] sm:w-auto sm:min-w-[8rem] lg:min-w-[9rem] px-1.5 sm:px-2 lg:px-3 py-1.5 sm:py-2 text-[11px] sm:text-xs lg:text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-teal-500 shadow-sm bg-white">
                        <option value="">Supplier</option>
                        <?php foreach ($activeSuppliers as $sup): ?>
                            <option value="<?= (int) $sup['id'] ?>" <?= $preselectedSupplierId === (int) $sup['id'] && $preselectedPoId < 1 ? 'selected' : '' ?>><?= htmlspecialchars($sup['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select id="categoryFilter" class="flex-shrink-0 w-[7rem] sm:w-auto sm:min-w-[8rem] lg:min-w-[9rem] px-1.5 sm:px-2 lg:px-3 py-1.5 sm:py-2 text-[11px] sm:text-xs lg:text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-teal-500 shadow-sm bg-white">
                        <option value="">Category</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= htmlspecialchars($category) ?>"><?= htmlspecialchars($category) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="flex items-center gap-1 sm:gap-1.5 flex-shrink-0">
                        <label for="receivingDate" class="hidden md:inline text-xs lg:text-sm text-gray-700 whitespace-nowrap">Date</label>
                        <input type="datetime-local" id="receivingDate" title="Receiving date" class="w-[9.5rem] sm:w-[11rem] lg:w-auto min-w-0 px-1 sm:px-2 lg:px-3 py-1.5 sm:py-2 text-[11px] sm:text-xs lg:text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-teal-500 shadow-sm bg-white"/>
                    </div>
                    <div class="relative flex-shrink-0 w-[7rem] sm:w-[9rem] lg:w-[12rem]">
                        <input type="text" id="searchInput" placeholder="Search..." class="w-full pl-7 sm:pl-8 lg:pl-9 pr-2 py-1.5 sm:py-2 text-[11px] sm:text-xs lg:text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-teal-500 shadow-sm bg-white">
                        <svg class="w-3.5 h-3.5 sm:w-4 sm:h-4 absolute left-2 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                    </div>
                    <a href="inventory" class="inline-flex items-center justify-center flex-shrink-0 ml-auto px-2 sm:px-3 lg:px-4 py-1.5 sm:py-2 text-[11px] sm:text-xs lg:text-sm border border-gray-300 rounded-md shadow-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500 transition duration-150 ease-in-out whitespace-nowrap">
                        <svg class="w-4 h-4 sm:mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                        </svg>
                        <span class="hidden sm:inline">Back</span>
                    </a>
                </div>
            </div>
            
            <div id="receivingToastHost" aria-live="polite" aria-atomic="true"></div>
            
            <main class="p-4 lg:p-6">

                <!-- Bulk Actions Panel -->
                <div id="bulkActionsPanel" class="hidden bg-white rounded-lg shadow-sm border border-gray-200 p-4 mb-6 bulk-actions">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-4">
                            <span id="selectedCount" class="text-sm font-medium text-gray-700">0 items selected</span>
                            <div class="flex items-center gap-2">
                                <label class="text-sm font-medium text-gray-700">Bulk Quantity:</label>
                                <input type="number" id="bulkQuantity" min="0" step="any" class="quantity-input px-2 py-1 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-teal-500" placeholder="Qty">
                            </div>
                        </div>
                        <button id="applyBulkBtn" class="inline-flex items-center px-2 sm:px-4 py-1.5 sm:py-2 bg-teal-600 hover:bg-teal-700 text-white text-xs sm:text-sm font-medium rounded-md shadow-sm">
                            <svg class="w-4 h-4 sm:w-5 sm:h-5 mr-1 sm:mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                            </svg>
                            <span class="hidden sm:inline">Apply to Selected</span>
                            <span class="sm:hidden">Apply</span>
                        </button>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow-sm overflow-hidden">
                    <div class="mobile-table-container w-full">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-300">
                            <tr>
                                <th scope="col" class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-3 text-left text-[10px] sm:text-xs lg:text-xs font-medium text-black uppercase tracking-wider">
                                    <input type="checkbox" id="selectAllCheckbox" class="rounded border-gray-300 text-teal-600 focus:ring-teal-500">
                                </th>
                                <th scope="col" class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-3 text-center text-[10px] sm:text-xs lg:text-xs font-medium text-black uppercase tracking-wider">
                                    <span class="hidden lg:inline">Image</span>
                                    <span class="lg:hidden">Img</span>
                                </th>
                                <th scope="col" class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-6 text-left text-[10px] sm:text-xs lg:text-xs font-medium text-black uppercase tracking-wider cursor-pointer" onclick="sortTable(2)">
                                    <div class="flex items-center">
                                        <span class="hidden sm:inline">Product</span>
                                        <span class="sm:hidden">Prod</span>
                                        <svg class="w-2.5 h-2.5 sm:w-3 sm:h-3 lg:w-3 lg:h-3 ml-0.5 sm:ml-1.5 lg:ml-1.5 hidden lg:inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                                        </svg>
                                    </div>
                                </th>
                                <th scope="col" class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-3 text-center text-[10px] sm:text-xs lg:text-xs font-medium text-black uppercase tracking-wider">
                                    <span class="hidden sm:inline">Unit Price</span>
                                    <span class="sm:hidden">Price</span>
                                </th>
                                <th scope="col" class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-3 text-center text-[10px] sm:text-xs lg:text-xs font-medium text-black uppercase tracking-wider">
                                    <span class="hidden sm:inline">Buying Price</span>
                                    <span class="sm:hidden">Cost</span>
                                </th>
                                <th scope="col" class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-3 text-center text-[10px] sm:text-xs lg:text-xs font-medium text-black uppercase tracking-wider cursor-pointer" onclick="sortTable(3, true)">
                                    <div class="flex items-center justify-center">
                                        <span class="hidden sm:inline">Current Stock</span>
                                        <span class="sm:hidden">Stock</span>
                                        <svg class="w-2.5 h-2.5 sm:w-3 sm:h-3 lg:w-3 lg:h-3 ml-0.5 sm:ml-1.5 lg:ml-1.5 hidden lg:inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                                        </svg>
                                    </div>
                                </th>
                                <th scope="col" class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-3 text-center text-[10px] sm:text-xs lg:text-xs font-medium text-black uppercase tracking-wider">
                                    <span class="hidden sm:inline">Receiving Qty</span>
                                    <span class="sm:hidden">Qty</span>
                                </th>
                                <th scope="col" class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-3 text-center text-[10px] sm:text-xs lg:text-xs font-medium text-black uppercase tracking-wider">
                                    <span class="hidden sm:inline">New Total</span>
                                    <span class="sm:hidden">Total</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200" id="tableBody">
                            <?php foreach ($products as $product): ?>
                                <tr class="receiving-row hover:bg-gray-50 transition-colors" data-category="<?= htmlspecialchars($product['category'] ?? '') ?>" data-product-id="<?= $product['id'] ?>">
                                    <td class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-4 whitespace-nowrap text-center">
                                        <input type="checkbox" class="product-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500" data-product-id="<?= $product['id'] ?>">
                                    </td>
                                    <td class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-4 whitespace-nowrap text-center">
                                        <div class="flex items-center justify-center"><img src="../products/<?= htmlspecialchars($product['image_url']) ?>" alt="Product" class="w-6 h-6 sm:w-8 sm:h-8 lg:w-10 lg:h-10 rounded-lg object-cover" onerror="this.onerror=null;this.style.display='none';this.nextElementSibling.style.display='inline-block';"><i class="fas fa-cube text-gray-400 text-xl sm:text-2xl lg:text-3xl" style="display:none;"></i></div>
                                    </td>
                                    <td class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-4 whitespace-nowrap text-[10px] sm:text-xs lg:text-sm font-medium text-black-900 truncate" title="<?= htmlspecialchars($product['name']) ?>">
                                        <?= htmlspecialchars($product['name']) ?>
                                        <?php if ($product['quantity'] <= 0): ?>
                                            <span class="ml-1 sm:ml-2 inline-flex items-center px-1 sm:px-2.5 py-0.5 rounded-full text-[8px] sm:text-xs font-medium bg-red-100 text-red-800">Out</span>
                                        <?php elseif ($product['quantity'] < 5): ?>
                                            <span class="ml-1 sm:ml-2 inline-flex items-center px-1 sm:px-2.5 py-0.5 rounded-full text-[8px] sm:text-xs font-medium bg-yellow-100 text-yellow-800">Low</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-4 whitespace-nowrap text-center text-[10px] sm:text-xs lg:text-sm text-black-500">
                                        <span class="product-price font-medium text-teal-600">N$ <?= number_format($product['price'], 2) ?></span>
                                    </td>
                                    <td class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-4 whitespace-nowrap text-center">
                                        <div class="buying-price-wrap">
                                            <input type="number" step="0.01" class="buying-price-input quantity-input px-1 sm:px-2 py-0.5 sm:py-1 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-teal-500 text-center text-[10px] sm:text-xs" 
                                                   placeholder="<?= number_format($product['buying_price'] ?? $product['price'], 2) ?>" 
                                                   value="<?= number_format($product['buying_price'] ?? $product['price'], 2) ?>"
                                                   data-original-buying-price="<?= $product['buying_price'] ?? $product['price'] ?>">
                                            <span class="receiving-cost-calculator-icon calculator-icon" title="Cost calculator">
                                                <i class="fas fa-calculator text-gray-500 hover:text-teal-500 cursor-pointer text-[10px] sm:text-xs"></i>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-4 whitespace-nowrap text-center text-[10px] sm:text-xs lg:text-sm text-black-500">
                                        <span class="current-stock"><?= $product['quantity'] ?></span>
                                    </td>
                                    <td class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-4 whitespace-nowrap text-center">
                                        <input type="number" min="0" step="any" class="receiving-quantity quantity-input px-1 sm:px-2 py-0.5 sm:py-1 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-teal-500 text-center text-[10px] sm:text-xs" placeholder="0">
                                    </td>
                                    <td class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-4 whitespace-nowrap text-center text-[10px] sm:text-xs lg:text-sm text-black-500">
                                        <span class="new-total"><?= $product['quantity'] ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>

                    <div class="px-2 sm:px-4 lg:px-6 py-3 sm:py-4 border-t border-gray-200">
                        <!-- Mobile: Compact pagination layout -->
                        <div class="flex flex-col gap-3 sm:hidden">
                            <div class="flex items-center justify-between gap-2">
                                <div class="flex gap-1">
                                    <button id="firstPage" class="inline-flex items-center justify-center px-2 py-1.5 border border-gray-300 text-[10px] font-medium rounded text-gray-700 bg-white hover:bg-gray-50 min-w-[2rem]">
                                        <<
                                    </button>
                                    <button id="prevPage" class="inline-flex items-center justify-center px-2 py-1.5 border border-gray-300 text-[10px] font-medium rounded text-gray-700 bg-white hover:bg-gray-50 min-w-[2rem]">
                                        <
                                    </button>
                                </div>
                                <div class="flex items-center gap-2 flex-1 justify-center">
                                    <span id="pageNumber" class="text-[10px] text-gray-700 whitespace-nowrap">Page 1 of 1</span>
                                    <input type="number" id="pageInput" min="1" class="w-12 px-1.5 py-1 border rounded text-[10px] text-center" placeholder="Pg">
                                </div>
                                <div class="flex gap-1">
                                    <button id="nextPage" class="inline-flex items-center justify-center px-2 py-1.5 border border-gray-300 text-[10px] font-medium rounded text-gray-700 bg-white hover:bg-gray-50 min-w-[2rem]">
                                        >
                                    </button>
                                    <button id="lastPage" class="inline-flex items-center justify-center px-2 py-1.5 border border-gray-300 text-[10px] font-medium rounded text-gray-700 bg-white hover:bg-gray-50 min-w-[2rem]">
                                        >>
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Desktop: Full pagination layout -->
                        <div class="hidden sm:flex sm:flex-row sm:justify-between sm:items-center gap-4">
                            <div class="flex gap-2">
                                <button id="firstPageDesktop" class="inline-flex items-center px-2 sm:px-4 py-2 border border-gray-300 text-xs sm:text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                    <<
                                </button>
                                <button id="prevPageDesktop" class="inline-flex items-center px-2 sm:px-4 py-2 border border-gray-300 text-xs sm:text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                    Previous
                                </button>
                            </div>
                            <div class="flex items-center gap-4">
                                <span id="pageNumberDesktop" class="text-xs sm:text-sm text-gray-700">Page 1 of 1</span>
                                <div class="flex items-center gap-2">
                                    <input type="number" id="pageInputDesktop" min="1" class="w-16 sm:w-20 px-2 py-1 border rounded text-xs sm:text-sm" placeholder="Page">
                                </div>
                            </div>
                            <div class="flex gap-2">
                                <button id="nextPageDesktop" class="inline-flex items-center px-2 sm:px-4 py-2 border border-gray-300 text-xs sm:text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                    Next
                                </button>
                                <button id="lastPageDesktop" class="inline-flex items-center px-2 sm:px-4 py-2 border border-gray-300 text-xs sm:text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                    >>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Items Being Added - Real-time List -->
                <div id="itemsBeingAdded" class="hidden mt-6 bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
                    <div class="px-6 py-4 bg-gradient-to-r from-teal-50 to-teal-50 border-b border-gray-200">
                        <div class="flex items-center justify-between">
                            <h3 class="text-lg font-semibold text-teal-800 flex items-center gap-2">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                </svg>
                                Items Being Added
                            </h3>
                            <span id="totalItemsCount" class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-teal-100 text-teal-800">
                                0 items
                            </span>
                        </div>
                    </div>
                    
                    <div class="overflow-hidden">
                        <div class="mobile-table-container w-full">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-300">
                                    <tr>
                                        <th scope="col" class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-3 text-left text-[10px] sm:text-xs lg:text-xs font-medium text-black uppercase tracking-wider">
                                            <span class="hidden sm:inline">Product</span>
                                            <span class="sm:hidden">Prod</span>
                                        </th>
                                        <th scope="col" class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-3 text-center text-[10px] sm:text-xs lg:text-xs font-medium text-black uppercase tracking-wider">
                                            <span class="hidden sm:inline">Unit Price</span>
                                            <span class="sm:hidden">Price</span>
                                        </th>
                                        <th scope="col" class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-3 text-center text-[10px] sm:text-xs lg:text-xs font-medium text-black uppercase tracking-wider">
                                            <span class="hidden sm:inline">Adding</span>
                                            <span class="sm:hidden">Add</span>
                                        </th>
                                        <th scope="col" class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-3 text-center text-[10px] sm:text-xs lg:text-xs font-medium text-black uppercase tracking-wider">
                                            <span class="hidden sm:inline">Current Stock</span>
                                            <span class="sm:hidden">Stock</span>
                                        </th>
                                        <th scope="col" class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-3 text-center text-[10px] sm:text-xs lg:text-xs font-medium text-black uppercase tracking-wider">
                                            <span class="hidden sm:inline">New Total</span>
                                            <span class="sm:hidden">Total</span>
                                        </th>
                                        <th scope="col" class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-3 text-center text-[10px] sm:text-xs lg:text-xs font-medium text-black uppercase tracking-wider">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody id="itemsBeingAddedBody" class="bg-white divide-y divide-gray-200">
                                    <!-- Items will be populated here dynamically -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <div class="px-6 py-4 bg-gray-50 border-t border-gray-200">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-4">
                                <span class="text-sm text-gray-600">Total Items:</span>
                                <span id="totalItems" class="text-lg font-semibold text-gray-900">0</span>
                                <span class="text-sm text-gray-600">Total Value:</span>
                                <span id="totalValue" class="text-lg font-semibold text-teal-600">N$ 0.00</span>
                            </div>
                            <div class="flex items-center gap-3">
                                <button id="clearAllBtn" class="inline-flex items-center px-2 sm:px-3 py-1.5 sm:py-2 border border-gray-300 rounded-md shadow-sm text-xs sm:text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500 transition duration-150 ease-in-out">
                                    <svg class="w-3.5 h-3.5 sm:w-4 sm:h-4 mr-1 sm:mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                    </svg>
                                    <span class="hidden sm:inline">Clear All</span>
                                    <span class="sm:hidden">Clear</span>
                                </button>
                                <button id="submitReceivingBtn" class="inline-flex items-center justify-center rounded-lg text-xs sm:text-sm font-semibold transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 bg-teal-600 hover:bg-teal-700 text-white border border-teal-600 shadow-sm h-8 sm:h-10 px-3 sm:px-6">
                                    <svg class="w-4 h-4 sm:w-5 sm:h-5 mr-1 sm:mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                    </svg>
                                    <span class="hidden sm:inline">Submit Receiving</span>
                                    <span class="sm:hidden">Submit</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <!-- Premium Loading Overlay -->
    <div id="loadingOverlay" class="loading-overlay">
        <div class="loading-container">
            <div class="spinner"></div>
            <h3 class="text-xl font-semibold text-gray-800 mb-2">Processing Stock Receiving</h3>
            <p class="text-gray-600 mb-4">Please wait while we update your inventory and generate the report...</p>
            <div class="progress-bar">
                <div class="progress-fill"></div>
            </div>
            <div class="pulse-dots">
                <div class="pulse-dot"></div>
                <div class="pulse-dot"></div>
                <div class="pulse-dot"></div>
            </div>
            <p class="text-sm text-gray-500 mt-4">This may take a few moments</p>
        </div>
    </div>

    <script>
        function syncReceivingHeaderSpacer() {
            const header = document.getElementById('receivingFixedHeader');
            const spacer = document.getElementById('receivingHeaderSpacer');
            if (header && spacer) {
                spacer.style.height = (header.offsetHeight + 8) + 'px';
            }
        }
        window.addEventListener('load', syncReceivingHeaderSpacer);
        window.addEventListener('resize', syncReceivingHeaderSpacer);

        // Dynamic rows per page: 10 on mobile, 6 on desktop
        function getRowsPerPage() {
            return window.innerWidth < 640 ? 10 : 6;
        }
        
        let rowsPerPage = getRowsPerPage();
        const tableBody = document.getElementById("tableBody");
        let allRows = Array.from(tableBody.children);
        let rows = [...allRows];
        const pageNumber = document.getElementById("pageNumber");
        let sortDirection = {};
        let currentPage = 1;
        
        // Update rowsPerPage on window resize
        let resizeTimeout;
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(() => {
                const newRowsPerPage = getRowsPerPage();
                if (newRowsPerPage !== rowsPerPage) {
                    rowsPerPage = newRowsPerPage;
                    currentPage = 1;
                    showPage(currentPage);
                }
            }, 100);
        });
        const searchInput = document.getElementById('searchInput');
        const categoryFilter = document.getElementById('categoryFilter');
        const selectAllCheckbox = document.getElementById('selectAllCheckbox');
        const bulkActionsPanel = document.getElementById('bulkActionsPanel');
        const selectedCount = document.getElementById('selectedCount');
        const submitReceivingBtn = document.getElementById('submitReceivingBtn');
        const poFilter = document.getElementById('poFilter');
        const supplierFilter = document.getElementById('supplierFilter');
        let linkedPoMeta = { supplier_id: null, supplier_name: '', po_number: '' };

        function updateSupplierFieldState() {
            const poId = poFilter ? poFilter.value : '';
            if (!supplierFilter) return;
            if (poId) {
                const opt = poFilter.options[poFilter.selectedIndex];
                const sid = opt ? opt.getAttribute('data-supplier-id') : '';
                if (sid) {
                    supplierFilter.value = sid;
                }
                supplierFilter.disabled = true;
                supplierFilter.classList.add('bg-gray-100');
            } else {
                supplierFilter.disabled = false;
                supplierFilter.classList.remove('bg-gray-100');
            }
        }

        async function loadPoLines(poId) {
            if (!poId) {
                linkedPoMeta = { supplier_id: null, supplier_name: '', po_number: '' };
                return;
            }
            const resp = await fetch('receiving.php?action=po_lines&po_id=' + encodeURIComponent(poId));
            const json = await resp.json();
            if (!json.success || !json.data) {
                showToast(json.message || 'Could not load purchase order lines', 'error');
                return;
            }
            linkedPoMeta = {
                supplier_id: json.data.supplier_id,
                supplier_name: json.data.supplier_name || '',
                po_number: json.data.po_number || ''
            };
            document.querySelectorAll('.receiving-row').forEach(row => {
                row.querySelector('.receiving-quantity').value = '';
                updateNewTotal(row);
            });
            (json.data.lines || []).forEach(line => {
                const row = document.querySelector('.receiving-row[data-product-id="' + line.product_id + '"]');
                if (!row) return;
                const qtyInput = row.querySelector('.receiving-quantity');
                const priceInput = row.querySelector('.buying-price-input');
                qtyInput.value = line.quantity_remaining;
                if (priceInput && line.unit_cost) {
                    priceInput.value = parseFloat(line.unit_cost).toFixed(2);
                }
                updateNewTotal(row);
            });
            scheduleUpdateItems();
            const searchVal = searchInput ? searchInput.value.trim().toLowerCase() : '';
            if (searchVal) {
                filterRows();
            } else {
                showPage(currentPage);
            }
        }

        function getReceivingMeta() {
            const poId = poFilter && poFilter.value ? parseInt(poFilter.value, 10) : null;
            let supplierId = supplierFilter && supplierFilter.value ? parseInt(supplierFilter.value, 10) : null;
            let supplierName = linkedPoMeta.supplier_name || '';
            let poNumber = linkedPoMeta.po_number || '';
            if (poId) {
                supplierId = linkedPoMeta.supplier_id || supplierId;
            } else if (supplierFilter && supplierFilter.selectedIndex > 0) {
                supplierName = supplierFilter.options[supplierFilter.selectedIndex].text;
            }
            return {
                purchase_order_id: poId || null,
                supplier_id: supplierId || null,
                supplier_name: supplierName,
                po_number: poNumber
            };
        }

        if (poFilter) {
            poFilter.addEventListener('change', async () => {
                updateSupplierFieldState();
                await loadPoLines(poFilter.value);
                applyReceivingDraftFromStorage();
            });
        }
        updateSupplierFieldState();
        
        // Cache for better performance
        let itemsBeingAddedCache = [];
        let updateItemsTimeout = null;

        // Draft persistence (same idea as stock taking) — survive refresh / navigation
        const RECEIVING_DRAFT_PREFIX = 'receiving_qty_draft_';
        function getReceivingDraftKey() {
            const serverDate = document.body.dataset.serverDate || new Date().toISOString().slice(0, 10);
            const poId = (poFilter && poFilter.value) ? String(poFilter.value) : 'adhoc';
            return RECEIVING_DRAFT_PREFIX + serverDate + '_po_' + poId;
        }
        function collectReceivingDraftMap() {
            const map = {};
            document.querySelectorAll('.receiving-row').forEach(row => {
                const id = row.dataset.productId;
                const qtyInput = row.querySelector('.receiving-quantity');
                const bpInput = row.querySelector('.buying-price-input');
                if (!id || !qtyInput) return;
                const q = qtyInput.value.trim();
                if (q === '' || !(parseFloat(q) > 0)) return;
                map[id] = {
                    q: q,
                    bp: bpInput ? bpInput.value.trim() : ''
                };
            });
            return map;
        }
        let receivingDraftSaveTimer = null;
        let suppressReceivingDraftSave = false;
        function saveReceivingDraftToStorage() {
            try {
                const map = collectReceivingDraftMap();
                const key = getReceivingDraftKey();
                if (Object.keys(map).length === 0) {
                    localStorage.removeItem(key);
                } else {
                    localStorage.setItem(key, JSON.stringify(map));
                }
            } catch (e) { /* ignore quota / private mode */ }
        }
        function scheduleSaveReceivingDraft() {
            clearTimeout(receivingDraftSaveTimer);
            receivingDraftSaveTimer = setTimeout(saveReceivingDraftToStorage, 250);
        }
        function applyReceivingDraftFromStorage() {
            let map = {};
            try {
                const raw = localStorage.getItem(getReceivingDraftKey());
                if (raw) map = JSON.parse(raw) || {};
            } catch (e) { map = {}; }
            if (!map || Object.keys(map).length === 0) return;
            document.querySelectorAll('.receiving-row').forEach(row => {
                const id = row.dataset.productId;
                const entry = map[id];
                if (!entry) return;
                const qtyInput = row.querySelector('.receiving-quantity');
                const bpInput = row.querySelector('.buying-price-input');
                if (qtyInput && entry.q !== undefined && String(entry.q).trim() !== '') {
                    qtyInput.value = String(entry.q);
                    updateNewTotal(row);
                }
                if (bpInput && entry.bp !== undefined && String(entry.bp).trim() !== '') {
                    bpInput.value = String(entry.bp);
                }
            });
            scheduleUpdateItems();
        }
        function clearCurrentReceivingDraft() {
            try {
                localStorage.removeItem(getReceivingDraftKey());
            } catch (e) { /* ignore */ }
        }
        window.addEventListener('beforeunload', () => {
            clearTimeout(receivingDraftSaveTimer);
            if (suppressReceivingDraftSave) return;
            saveReceivingDraftToStorage();
        });

        // Cost calculator next to buying price (same pattern as inventory.php)
        (function initReceivingCostCalculator() {
            const popup = document.createElement('div');
            popup.className = 'calculator-popup hidden absolute bg-white p-4 rounded-lg shadow-lg border border-gray-200 z-[10000]';
            popup.innerHTML = `
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Total Cost</label>
                    <input type="number" id="receivingCalcTotalCost" class="w-full px-3 py-2 border rounded-md" placeholder="Enter total cost" step="0.01">
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Number of Items</label>
                    <input type="number" id="receivingCalcItemCount" class="w-full px-3 py-2 border rounded-md" placeholder="Receiving qty" min="0" step="any">
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Cost per Item</label>
                    <div id="receivingCalcCostPerItem" class="w-full px-3 py-2 bg-gray-50 rounded-md">0.00</div>
                </div>
                <button type="button" id="receivingCalcApply" class="w-full bg-teal-600 text-white px-4 py-2 rounded-md hover:bg-teal-700">Apply</button>
            `;
            document.body.appendChild(popup);

            const totalCostInput = popup.querySelector('#receivingCalcTotalCost');
            const itemCountInput = popup.querySelector('#receivingCalcItemCount');
            const costPerItemDiv = popup.querySelector('#receivingCalcCostPerItem');
            const applyBtn = popup.querySelector('#receivingCalcApply');

            let hideTimeout;
            let currentIcon = null;
            let targetBuyingInput = null;

            const updatePopupPosition = (iconEl) => {
                const rect = iconEl.getBoundingClientRect();
                const left = Math.min(rect.left + window.scrollX, window.scrollX + Math.max(8, window.innerWidth - 268));
                popup.style.top = `${rect.bottom + window.scrollY + 5}px`;
                popup.style.left = `${left}px`;
            };

            const calculateCost = () => {
                const totalCost = parseFloat(totalCostInput.value) || 0;
                const itemCount = parseFloat(itemCountInput.value) || 0;
                const costPerItem = itemCount > 0 ? totalCost / itemCount : 0;
                costPerItemDiv.textContent = costPerItem.toFixed(2);
            };

            totalCostInput.addEventListener('input', calculateCost);
            itemCountInput.addEventListener('input', calculateCost);
            totalCostInput.addEventListener('click', function () { this.select(); });
            itemCountInput.addEventListener('click', function () { this.select(); });

            applyBtn.addEventListener('click', () => {
                const costPerItem = parseFloat(costPerItemDiv.textContent);
                if (!isNaN(costPerItem) && targetBuyingInput) {
                    targetBuyingInput.value = costPerItem.toFixed(2);
                    targetBuyingInput.dispatchEvent(new Event('input', { bubbles: true }));
                    scheduleUpdateItems();
                    popup.classList.add('hidden');
                }
            });

            document.addEventListener('click', (e) => {
                if (!popup.contains(e.target) && !(e.target.closest && e.target.closest('.receiving-cost-calculator-icon'))) {
                    popup.classList.add('hidden');
                }
            });

            document.querySelectorAll('.receiving-cost-calculator-icon').forEach((calculatorIcon) => {
                calculatorIcon.addEventListener('mouseenter', (e) => {
                    e.stopPropagation();
                    clearTimeout(hideTimeout);
                    currentIcon = calculatorIcon;
                    targetBuyingInput = calculatorIcon.closest('.buying-price-wrap')?.querySelector('.buying-price-input');
                    const row = calculatorIcon.closest('tr');
                    const qtyInput = row?.querySelector('.receiving-quantity');
                    const qty = qtyInput ? (parseInt(qtyInput.value, 10) || 0) : 0;
                    itemCountInput.value = qty > 0 ? String(qty) : '';
                    calculateCost();
                    popup.classList.remove('hidden');
                    updatePopupPosition(calculatorIcon);
                });

                calculatorIcon.addEventListener('mouseleave', (e) => {
                    const toElement = e.relatedTarget;
                    if (!popup.contains(toElement)) {
                        hideTimeout = setTimeout(() => {
                            popup.classList.add('hidden');
                        }, 500);
                    }
                });
            });

            popup.addEventListener('mouseenter', () => clearTimeout(hideTimeout));
            popup.addEventListener('mouseleave', (e) => {
                const toElement = e.relatedTarget;
                if (currentIcon && !currentIcon.contains(toElement)) {
                    hideTimeout = setTimeout(() => {
                        popup.classList.add('hidden');
                    }, 500);
                }
            });
        })();

        // Initialize
        showPage(currentPage);
        updateBulkActionsVisibility();

        (async function bootReceivingDraft() {
            if (poFilter && poFilter.value) {
                await loadPoLines(poFilter.value);
            }
            applyReceivingDraftFromStorage();
            updateItemsBeingAdded();
        })();

        // Search and filter functionality - INSTANT response
        searchInput.addEventListener('input', (e) => {
            filterRows(e.target.value);
        });

        categoryFilter.addEventListener('change', () => {
            filterRows(searchInput.value);
        });

        // View All button functionality removed - button doesn't exist in HTML

        function filterRows(searchTerm) {
            const selectedCategory = categoryFilter.value;
            const searchLower = searchTerm.toLowerCase();
            
            // Use faster filtering approach
            if (!searchTerm && !selectedCategory) {
                rows = [...allRows];
                showAllRows();
                return;
            }
            
            rows = [];
            for (let i = 0; i < allRows.length; i++) {
                const row = allRows[i];
                const productName = row.children[2].textContent.toLowerCase();
                const productCategory = row.dataset.category || '';
                
                if ((searchLower === '' || productName.includes(searchLower)) &&
                    (selectedCategory === '' || productCategory === selectedCategory)) {
                    rows.push(row);
                }
            }
            
            currentPage = 1;
            showPage(currentPage);
        }

        function showPage(page) {
            const start = (page - 1) * rowsPerPage;
            const end = start + rowsPerPage;
            const maxPage = Math.ceil(rows.length / rowsPerPage) || 1;
            
            allRows.forEach(row => row.style.display = 'none');
            rows.slice(start, end).forEach(row => row.style.display = 'table-row');
            
            // Update both mobile and desktop page numbers
            const pageNumberMobile = document.getElementById('pageNumber');
            const pageNumberDesktop = document.getElementById('pageNumberDesktop');
            if (pageNumberMobile) pageNumberMobile.textContent = `Page ${page} of ${maxPage}`;
            if (pageNumberDesktop) pageNumberDesktop.textContent = `Page ${page} of ${maxPage}`;
            
            // Update both mobile and desktop page inputs
            const pageInputMobile = document.getElementById('pageInput');
            const pageInputDesktop = document.getElementById('pageInputDesktop');
            if (pageInputMobile) {
                pageInputMobile.value = page;
                pageInputMobile.placeholder = `Pg (1-${maxPage})`;
            }
            if (pageInputDesktop) {
                pageInputDesktop.value = page;
                pageInputDesktop.placeholder = `Page (1-${maxPage})`;
            }
        }

        function showAllRows() {
            // Show all rows without pagination
            allRows.forEach(row => row.style.display = 'table-row');
            pageNumber.textContent = `Showing all ${rows.length} items`;
            document.getElementById('pageInput').value = '';
            currentPage = 1;
        }

        // Sorting functionality
        function sortTable(columnIndex, isNumeric = false) {
            if (!sortDirection[columnIndex]) {
                sortDirection[columnIndex] = 'asc';
            } else {
                sortDirection[columnIndex] = sortDirection[columnIndex] === 'asc' ? 'desc' : 'asc';
            }

            rows.sort((a, b) => {
                let aValue = a.children[columnIndex].textContent.trim();
                let bValue = b.children[columnIndex].textContent.trim();

                if (isNumeric) {
                    aValue = parseFloat(aValue);
                    bValue = parseFloat(bValue);
                } else {
                    aValue = aValue.toLowerCase();
                    bValue = bValue.toLowerCase();
                }

                if (sortDirection[columnIndex] === 'asc') {
                    return aValue > bValue ? 1 : -1;
                } else {
                    return aValue < bValue ? 1 : -1;
                }
            });

            while (tableBody.firstChild) {
                tableBody.removeChild(tableBody.firstChild);
            }
            rows.forEach(row => tableBody.appendChild(row));

            showPage(currentPage);
        }

        // Helper function to handle prev page
        function handlePrevPage() {
            if (currentPage > 1) {
                currentPage--;
                showPage(currentPage);
            }
        }
        
        // Helper function to handle next page
        function handleNextPage() {
            if (currentPage * rowsPerPage < rows.length) {
                currentPage++;
                showPage(currentPage);
            }
        }
        
        // Helper function to handle first page
        function handleFirstPage() {
            currentPage = 1;
            showPage(currentPage);
        }
        
        // Helper function to handle last page
        function handleLastPage() {
            currentPage = Math.ceil(rows.length / rowsPerPage);
            showPage(currentPage);
        }
        
        // Helper function to handle page input
        function handlePageInput(inputElement) {
            const desiredPage = parseInt(inputElement.value);
            if (!isNaN(desiredPage)) {
                const maxPage = Math.ceil(rows.length / rowsPerPage) || 1;
                currentPage = Math.min(Math.max(1, desiredPage), maxPage);
                showPage(currentPage);
            }
        }
        
        // Mobile pagination controls
        const prevPageMobile = document.getElementById("prevPage");
        const nextPageMobile = document.getElementById("nextPage");
        const firstPageMobile = document.getElementById("firstPage");
        const lastPageMobile = document.getElementById("lastPage");
        const pageInputMobile = document.getElementById("pageInput");
        
        // Desktop pagination controls
        const prevPageDesktop = document.getElementById("prevPageDesktop");
        const nextPageDesktop = document.getElementById("nextPageDesktop");
        const firstPageDesktop = document.getElementById("firstPageDesktop");
        const lastPageDesktop = document.getElementById("lastPageDesktop");
        const pageInputDesktop = document.getElementById("pageInputDesktop");
        
        // Add event listeners for mobile
        if (prevPageMobile) prevPageMobile.addEventListener("click", handlePrevPage);
        if (nextPageMobile) nextPageMobile.addEventListener("click", handleNextPage);
        if (firstPageMobile) firstPageMobile.addEventListener("click", handleFirstPage);
        if (lastPageMobile) lastPageMobile.addEventListener("click", handleLastPage);
        if (pageInputMobile) pageInputMobile.addEventListener("change", () => handlePageInput(pageInputMobile));
        
        // Add event listeners for desktop
        if (prevPageDesktop) prevPageDesktop.addEventListener("click", handlePrevPage);
        if (nextPageDesktop) nextPageDesktop.addEventListener("click", handleNextPage);
        if (firstPageDesktop) firstPageDesktop.addEventListener("click", handleFirstPage);
        if (lastPageDesktop) lastPageDesktop.addEventListener("click", handleLastPage);
        if (pageInputDesktop) pageInputDesktop.addEventListener("change", () => handlePageInput(pageInputDesktop));

        // Checkbox functionality
        selectAllCheckbox.addEventListener('change', (e) => {
            const checkboxes = document.querySelectorAll('.product-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = e.target.checked;
            });
            updateBulkActionsVisibility();
            updateSelectedCount();
        });

        // Individual checkbox handling
        tableBody.addEventListener('change', (e) => {
            if (e.target.classList.contains('product-checkbox')) {
                updateBulkActionsVisibility();
                updateSelectedCount();
                updateSelectAllCheckbox();
            }
        });

        function updateBulkActionsVisibility() {
            const checkedBoxes = document.querySelectorAll('.product-checkbox:checked');
            if (checkedBoxes.length > 0) {
                bulkActionsPanel.classList.remove('hidden');
            } else {
                bulkActionsPanel.classList.add('hidden');
            }
        }

        function updateSelectedCount() {
            const checkedBoxes = document.querySelectorAll('.product-checkbox:checked');
            selectedCount.textContent = `${checkedBoxes.length} items selected`;
        }

        function updateSelectAllCheckbox() {
            const checkboxes = document.querySelectorAll('.product-checkbox');
            const checkedBoxes = document.querySelectorAll('.product-checkbox:checked');
            selectAllCheckbox.checked = checkboxes.length === checkedBoxes.length;
            selectAllCheckbox.indeterminate = checkedBoxes.length > 0 && checkedBoxes.length < checkboxes.length;
        }



        // Bulk actions - OPTIMIZED
        document.getElementById('applyBulkBtn').addEventListener('click', () => {
            const bulkQuantity = document.getElementById('bulkQuantity').value;
            
            if (!bulkQuantity) return;
            
            const checkedBoxes = document.querySelectorAll('.product-checkbox:checked');
            for (let i = 0; i < checkedBoxes.length; i++) {
                const row = checkedBoxes[i].closest('tr');
                const quantityInput = row.querySelector('.receiving-quantity');
                quantityInput.value = bulkQuantity;
                updateNewTotal(row);
            }
            
            // Single update after all changes
            scheduleUpdateItems();
            scheduleSaveReceivingDraft();
        });

        // Update new total when quantity changes - OPTIMIZED
        tableBody.addEventListener('input', (e) => {
            if (e.target.classList.contains('receiving-quantity')) {
                updateNewTotal(e.target.closest('tr'));
                scheduleUpdateItems();
                scheduleSaveReceivingDraft();
            } else if (e.target.classList.contains('buying-price-input')) {
                scheduleUpdateItems();
                scheduleSaveReceivingDraft();
            }
        });
        
        // Optimized update scheduling
        function scheduleUpdateItems() {
            if (updateItemsTimeout) clearTimeout(updateItemsTimeout);
            updateItemsTimeout = setTimeout(updateItemsBeingAdded, 10);
        }


        function updateNewTotal(row) {
            const currentStock = parseFloat(row.querySelector('.current-stock').textContent) || 0;
            const receivingQty = parseFloat(row.querySelector('.receiving-quantity').value) || 0;
            const newTotal = currentStock + receivingQty;
            row.querySelector('.new-total').textContent = Number.isInteger(newTotal) ? String(newTotal) : String(Math.round(newTotal * 10000) / 10000);
        }

        // OPTIMIZED Real-time items being added functionality
        function updateItemsBeingAdded() {
            const items = [];
            const rows = allRows; // Use cached rows
            
            for (let i = 0; i < rows.length; i++) {
                const row = rows[i];
                const quantityInput = row.querySelector('.receiving-quantity');
                const quantity = parseFloat(quantityInput.value) || 0;
                
                if (quantity > 0) {
                    const productId = row.dataset.productId;
                    const productName = row.children[2].textContent.trim();
                    const currentStock = parseFloat(row.querySelector('.current-stock').textContent) || 0;
                    
                    // Get the product price from the row data
                    const priceElement = row.querySelector('.product-price');
                    const price = priceElement ? parseFloat(priceElement.textContent.replace('N$', '').replace(/,/g, '').trim()) : 0;
                    
                    // Get the buying price
                    const buyingPriceInput = row.querySelector('.buying-price-input');
                    const buyingPrice = buyingPriceInput ? (parseFloat(buyingPriceInput.value) || parseFloat(buyingPriceInput.dataset.originalBuyingPrice)) : price;
                    
                    items.push({
                        product_id: productId,
                        product_name: productName,
                        current_stock: currentStock,
                        quantity: quantity,
                        new_total: currentStock + quantity,
                        price: price,
                        buying_price: buyingPrice,
                        total_value: price * quantity,
                        total_cost: buyingPrice * quantity
                    });
                }
            }
            
            // Cache the items to avoid unnecessary DOM updates
            if (JSON.stringify(items) !== JSON.stringify(itemsBeingAddedCache)) {
                itemsBeingAddedCache = items;
                displayItemsBeingAdded(items);
                updateSummaryTotals(items);
            }
        }

        // OPTIMIZED display function with reduced DOM manipulation
        function displayItemsBeingAdded(items) {
            const container = document.getElementById('itemsBeingAdded');
            const tbody = document.getElementById('itemsBeingAddedBody');
            const totalCount = document.getElementById('totalItemsCount');
            
            if (items.length === 0) {
                container.classList.add('hidden');
                return;
            }
            
            container.classList.remove('hidden');
            totalCount.textContent = `${items.length} item${items.length !== 1 ? 's' : ''}`;
            
            // Build HTML string instead of creating elements one by one
            let htmlContent = '';
            let totalItems = 0;
            let totalValue = 0;
            
            for (let i = 0; i < items.length; i++) {
                const item = items[i];
                totalItems += item.quantity;
                totalValue += item.total_value;
                
                htmlContent += `
                    <tr class="hover:bg-gray-50 transition-colors">
                        <td class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-4 text-[10px] sm:text-xs lg:text-sm font-medium text-gray-900">
                            <div class="flex items-center gap-1 sm:gap-2 lg:gap-3">
                                <div class="w-4 h-4 sm:w-6 sm:h-6 lg:w-8 lg:h-8 bg-teal-100 rounded-full flex items-center justify-center flex-shrink-0">
                                    <svg class="w-2 h-2 sm:w-3 sm:h-3 lg:w-4 lg:h-4 text-teal-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                    </svg>
                                </div>
                                <span class="text-[10px] sm:text-xs lg:text-sm break-words">${item.product_name}</span>
                            </div>
                        </td>
                        <td class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-4 whitespace-nowrap text-center text-[10px] sm:text-xs lg:text-sm text-gray-900 font-medium">
                            <span class="text-teal-600">N$ ${item.price.toFixed(2)}</span>
                        </td>
                        <td class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-4 whitespace-nowrap text-center text-[10px] sm:text-xs lg:text-sm text-teal-600 font-semibold">
                            +${item.quantity}
                        </td>
                        <td class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-4 whitespace-nowrap text-center text-[10px] sm:text-xs lg:text-sm text-gray-500">
                            <span class="inline-flex items-center px-1 sm:px-2 lg:px-2.5 py-0.5 rounded-full text-[9px] sm:text-[10px] lg:text-xs font-medium bg-gray-100 text-gray-800">
                                ${item.current_stock}
                            </span>
                        </td>
                        <td class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-4 whitespace-nowrap text-center text-[10px] sm:text-xs lg:text-sm text-gray-900 font-medium">
                            <span class="inline-flex items-center px-1 sm:px-2 lg:px-2.5 py-0.5 rounded-full text-[9px] sm:text-[10px] lg:text-xs font-medium bg-blue-100 text-blue-800">
                                ${item.new_total}
                            </span>
                        </td>
                        <td class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-4 whitespace-nowrap text-center text-[10px] sm:text-xs lg:text-sm font-medium">
                            <button onclick="removeItem('${item.product_id}')" class="text-red-600 hover:text-red-800 text-[9px] sm:text-[10px] lg:text-sm">Remove</button>
                        </td>
                    </tr>`;
            }
            
            // Add summary row
            htmlContent += `
                <tr class="bg-gray-50 border-t-2 border-gray-300">
                    <td class="px-1 sm:px-2 lg:px-6 py-2 sm:py-2 lg:py-3 text-[10px] sm:text-xs lg:text-sm font-semibold text-gray-900">SUMMARY</td>
                    <td class="px-1 sm:px-2 lg:px-6 py-2 sm:py-2 lg:py-3 text-center text-[10px] sm:text-xs lg:text-sm font-semibold text-teal-600">N$ ${totalValue.toFixed(2)}</td>
                    <td class="px-1 sm:px-2 lg:px-6 py-2 sm:py-2 lg:py-3 text-center text-[10px] sm:text-xs lg:text-sm font-semibold text-blue-600">${totalItems}</td>
                    <td class="px-1 sm:px-2 lg:px-6 py-2 sm:py-2 lg:py-3 text-center text-[10px] sm:text-xs lg:text-sm font-semibold text-blue-600">-</td>
                    <td class="px-1 sm:px-2 lg:px-6 py-2 sm:py-2 lg:py-3 text-center text-[10px] sm:text-xs lg:text-sm font-semibold text-blue-600">-</td>
                    <td class="px-1 sm:px-2 lg:px-6 py-2 sm:py-2 lg:py-3 text-center text-[10px] sm:text-xs lg:text-sm font-medium text-gray-500">-</td>
                </tr>`;
            
            // Single DOM update
            tbody.innerHTML = htmlContent;
        }

        // OPTIMIZED summary totals update
        function updateSummaryTotals(items) {
            const totalItemsElement = document.getElementById('totalItems');
            const totalValueElement = document.getElementById('totalValue');
            
            let totalItems = 0;
            let totalValue = 0;
            let totalCost = 0;
            
            // Single loop for all calculations
            for (let i = 0; i < items.length; i++) {
                totalItems += items[i].quantity;
                totalValue += items[i].total_value;
                totalCost += items[i].total_cost;
            }
            
            totalItemsElement.textContent = totalItems;
            totalValueElement.textContent = `N$ ${totalValue.toFixed(2)}`;
            
            // Simplified cost display update - element doesn't exist in HTML, so skip
            // let costElement = document.getElementById('totalCost');
            // if (costElement) {
            //     costElement.textContent = `N$ ${totalCost.toFixed(2)}`;
            // }
        }

        function removeItem(productId) {
            const row = document.querySelector(`[data-product-id="${productId}"]`);
            if (row) {
                const quantityInput = row.querySelector('.receiving-quantity');
                quantityInput.value = '';
                updateNewTotal(row);
                scheduleUpdateItems();
                scheduleSaveReceivingDraft();
            }
        }

        // Clear all button functionality - OPTIMIZED
        document.getElementById('clearAllBtn').addEventListener('click', () => {
            const rows = document.querySelectorAll('.receiving-row');
            for (let i = 0; i < rows.length; i++) {
                const row = rows[i];
                const quantityInput = row.querySelector('.receiving-quantity');
                const buyingPriceInput = row.querySelector('.buying-price-input');
                const originalBuyingPrice = buyingPriceInput.dataset.originalBuyingPrice;
                
                quantityInput.value = '';
                buyingPriceInput.value = originalBuyingPrice;
                updateNewTotal(row);
            }
            
            // Clear checkboxes
            const checkboxes = document.querySelectorAll('.product-checkbox');
            for (let i = 0; i < checkboxes.length; i++) {
                checkboxes[i].checked = false;
            }
            selectAllCheckbox.checked = false;
            updateBulkActionsVisibility();
            updateSelectedCount();
            
            // Single update after all changes
            clearCurrentReceivingDraft();
            scheduleUpdateItems();
        });

        // Submit receiving with premium loader
        submitReceivingBtn.addEventListener('click', async () => {
            const items = [];
            const rows = document.querySelectorAll('.receiving-row');
            
            rows.forEach(row => {
                const quantityInput = row.querySelector('.receiving-quantity');
                const productId = row.dataset.productId;
                
                const quantity = parseFloat(quantityInput.value) || 0;
                
                if (quantity > 0) {
                    const buyingPriceInput = row.querySelector('.buying-price-input');
                    const buyingPrice = buyingPriceInput
                        ? (parseFloat(buyingPriceInput.value) || parseFloat(buyingPriceInput.dataset.originalBuyingPrice) || 0)
                        : 0;
                    items.push({
                        product_id: productId,
                        quantity: quantity,
                        buying_price: buyingPrice
                    });
                }
            });
            
            if (items.length === 0) {
                showToast('Please enter quantities for at least one product', 'error');
                return;
            }

            const meta = getReceivingMeta();
            
            try {
                // Show premium loading overlay
                showLoadingOverlay();
                
                // Disable submit button to prevent double submission
                submitReceivingBtn.disabled = true;
                submitReceivingBtn.innerHTML = `
                    <div class="flex items-center justify-center">
                        <div class="w-5 h-5 mr-2 border-2 border-white border-t-transparent rounded-full animate-spin"></div>
                        Processing...
                    </div>
                `;
                
                const receivingDateEl = document.getElementById('receivingDate');
                const receivingDate = receivingDateEl && receivingDateEl.value ? receivingDateEl.value : null;
                
                // Step 1: First submit via AJAX to save data and get record ID
                const ajaxResponse = await fetch('receiving.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        items: items,
                        receiving_date: receivingDate,
                        purchase_order_id: meta.purchase_order_id,
                        supplier_id: meta.supplier_id,
                        supplier_name: meta.supplier_name,
                        po_number: meta.po_number
                    })
                });
                
                const ajaxResult = await ajaxResponse.json();
                
                if (!ajaxResult.success) {
                    throw new Error(ajaxResult.message || 'Failed to save receiving data');
                }
                
                const recordId = ajaxResult.record_id;
                
                // Show success message
                showToast('Stock received successfully! Downloading PDF...', 'success');
                
                // Step 2: Download PDF first using iframe (doesn't navigate away)
                await downloadPDF(items, receivingDate, meta);
                
                // Step 3: After PDF download initiated, send email
                showToast('PDF downloaded! Sending email report...', 'info');
                
                try {
                    const emailResult = await sendReceivingEmail(recordId);
                    if (emailResult && emailResult.success) {
                        showToast('Email report sent successfully!', 'success');
                    } else {
                        showToast('Email sending failed - can be retried later', 'error');
                    }
                } catch (emailError) {
                    console.error('Email sending error:', emailError);
                    showToast('Email sending failed - can be retried later', 'error');
                }
                
                // Reset form
                suppressReceivingDraftSave = true;
                clearCurrentReceivingDraft();
                rows.forEach(row => {
                    row.querySelector('.receiving-quantity').value = '';
                    updateNewTotal(row);
                });
                // Clear selections
                document.querySelectorAll('.product-checkbox').forEach(cb => cb.checked = false);
                selectAllCheckbox.checked = false;
                updateBulkActionsVisibility();
                updateSelectedCount();
                
                // Clear the real-time list
                updateItemsBeingAdded();
                
                // Hide loading overlay after a delay
                setTimeout(() => {
                    hideLoadingOverlay();
                    // Refresh current stock values
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                }, 2000);
                
            } catch (error) {
                console.error('Error:', error);
                hideLoadingOverlay();
                showToast('Failed to submit receiving: ' + error.message, 'error');
                
                // Re-enable submit button
                submitReceivingBtn.disabled = false;
                submitReceivingBtn.innerHTML = `
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                    Submit Receiving
                `;
            }
        });
        
        // Function to download PDF using hidden iframe
        function downloadPDF(items, receivingDate, meta) {
            return new Promise((resolve) => {
                const payload = {
                    items: items,
                    receiving_date: receivingDate,
                    purchase_order_id: meta ? meta.purchase_order_id : null,
                    supplier_id: meta ? meta.supplier_id : null,
                    supplier_name: meta ? meta.supplier_name : '',
                    po_number: meta ? meta.po_number : ''
                };
                // Create hidden iframe for PDF download
                const iframe = document.createElement('iframe');
                iframe.style.display = 'none';
                iframe.name = 'pdfDownloadFrame';
                document.body.appendChild(iframe);
                
                // Create form targeting the iframe
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'receiving.php';
                form.target = 'pdfDownloadFrame';
                form.style.display = 'none';
                
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'receiving_data';
                input.value = JSON.stringify(payload);
                
                form.appendChild(input);
                document.body.appendChild(form);
                form.submit();
                
                // Clean up and resolve after a short delay to allow download to start
                setTimeout(() => {
                    document.body.removeChild(form);
                    // Keep iframe for a bit longer to ensure download completes
                    setTimeout(() => {
                        document.body.removeChild(iframe);
                    }, 5000);
                    resolve();
                }, 1500);
            });
        }
        
        // Premium loading overlay functions
        function showLoadingOverlay() {
            const overlay = document.getElementById('loadingOverlay');
            overlay.classList.add('show');
            
            // Prevent scrolling while loading
            document.body.style.overflow = 'hidden';
        }
        
        function hideLoadingOverlay() {
            const overlay = document.getElementById('loadingOverlay');
            overlay.classList.remove('show');
            
            // Re-enable scrolling
            document.body.style.overflow = 'auto';
        }

        // Function to send receiving email using record ID
        async function sendReceivingEmail(recordId) {
            try {
                const response = await fetch('send_receiving_email.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ 
                        record_id: recordId
                    })
                });
                
                const result = await response.json();
                return result;
            } catch (error) {
                console.error('Email sending error:', error);
                return { success: false, message: error.message };
            }
        }

        // Toast notification function
        function showToast(message, type = 'info') {
            const host = document.getElementById('receivingToastHost');
            if (!host) return;

            const icons = {
                success: '<i class="fas fa-check-circle mt-0.5 flex-shrink-0"></i>',
                error: '<i class="fas fa-exclamation-circle mt-0.5 flex-shrink-0"></i>',
                info: '<i class="fas fa-info-circle mt-0.5 flex-shrink-0"></i>'
            };

            const toast = document.createElement('div');
            toast.className = `receiving-toast receiving-toast--${type} toast-notification`;
            toast.innerHTML = `${icons[type] || icons.info}<span>${message}</span>`;
            host.appendChild(toast);

            setTimeout(() => {
                toast.style.opacity = '0';
                setTimeout(() => toast.remove(), 300);
            }, 3500);
        }
    </script>

    <script>
        // Mobile sidebar functions
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
    </script>
</body>
</html>
