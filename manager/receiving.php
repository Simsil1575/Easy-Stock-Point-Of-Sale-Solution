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
                    $quantity = intval($item['quantity']);
                    
                    // Get current product info
                    $stmt = $db->prepare("SELECT name, quantity, price, buying_price FROM products WHERE id = ?");
                    $stmt->execute([$productId]);
                    $product = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($product) {
                        $oldQuantity = intval($product['quantity']);
                        // For PDF only, calculate what the new quantity would be (don't update)
                        // For AJAX, this is before update so it's correct
                        $newQuantity = $isPdfOnly ? $oldQuantity : ($oldQuantity + $quantity);
                        $itemValue = $quantity * $product['price'];
                        $itemCost = $quantity * ($product['buying_price'] ?? $product['price']);
                        
                        // Store item details for PDF
                        $receivingItems[] = [
                            'product_name' => $product['name'],
                            'old_quantity' => $isPdfOnly ? ($oldQuantity - $quantity) : $oldQuantity, // For PDF, stock was already updated
                            'added_quantity' => $quantity,
                            'new_quantity' => $oldQuantity, // Current quantity (already updated for PDF)
                            'price' => $product['price'],
                            'buying_price' => $product['buying_price'] ?? $product['price'],
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
                                'buying_price' => $product['buying_price'] ?? $product['price'],
                                'total_value' => $itemValue,
                                'total_cost' => $itemCost
                            ];
                            
                            // Update product quantity
                            $updateStmt = $db->prepare("UPDATE products SET quantity = ? WHERE id = ?");
                            $updateStmt->execute([$newQuantity, $productId]);
                            
                            // Log the stock change (use selected receiving date/time)
                            $logStmt = $db->prepare("INSERT INTO stock_changes (product_id, action, quantity_change, old_quantity, new_quantity, changed_at) VALUES (?, ?, ?, ?, ?, ?)");
                            $logStmt->execute([$productId, 'Restock', $quantity, $oldQuantity, $newQuantity, $selectedDateTime]);
                            
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
                    (user_id, username, receiving_date, total_items, total_quantity, total_value, total_cost, email_status, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', datetime('now'))
                ");
                $recordStmt->execute([
                    $_SESSION['user_id'],
                    $_SESSION['username'],
                    $selectedDateTime,
                    $totalItemsCount,
                    $totalQuantity,
                    $totalValue,
                    $totalCost
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
                
                // Create new PDF instance
                class ReceivingPDF extends FPDF {
                    function Header() {
                        $this->SetFont('Arial', 'B', 15);
                        $this->Cell(0, 10, 'Stock Receiving Report', 0, 1, 'C');
                        $this->SetFont('Arial', '', 12);
                        $this->Cell(0, 10, 'Generated on ' . date('Y-m-d H:i:s'), 0, 1, 'C');
                        $this->Ln(10);
                        
                        // Table header
                        $this->SetFont('Arial', 'B', 10);
                        $this->Cell(80, 10, 'Product', 1);
                        $this->Cell(35, 10, 'Added', 1);
                        $this->Cell(35, 10, 'Unit Price', 1);
                        $this->Cell(35, 10, 'Value Added', 1);
                        $this->Ln();
                    }
                    
                    function Footer() {
                        $this->SetY(-15);
                        $this->SetFont('Arial', 'I', 8);
                        $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
                    }
                }
                
                // Initialize PDF
                $pdf = new ReceivingPDF();
                $pdf->AliasNbPages();
                $pdf->AddPage();
                $pdf->SetFont('Arial', '', 9);
                
                $totalItems = 0;
                $totalValue = 0;
                
                // Add data to PDF
                foreach ($receivingItems as $item) {
                    $pdf->Cell(80, 8, $item['product_name'], 1);
                    $pdf->Cell(35, 8, '+' . $item['added_quantity'], 1);
                    $pdf->Cell(35, 8, 'N$' . number_format($item['price'], 2), 1);
                    $pdf->Cell(35, 8, 'N$' . number_format($item['total_value'], 2), 1);
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
                
                // Generate filename
                $fileName = 'Stock_Receiving_Report_' . date('Y-m-d_H-i-s') . '.pdf';
                
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
            transition: opacity 0.5s, transform 0.5s;
            transform: translateX(100%);
            opacity: 0;
            animation: slideIn 0.5s forwards;
        }
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
            table td:nth-child(5) .buying-price-input {
                width: 100% !important;
                max-width: calc(100% - 0.25rem) !important;
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
<body class="bg-gray-50">
    <div class="flex">
        <div class="sidebar fixed h-full">
            <?php include 'sidebar.php'; ?>
        </div>
        <div class="flex-1 content lg:ml-0 ml-0">
            <!-- Mobile Sidebar Overlay -->
            <div id="mobileOverlay" class="mobile-overlay lg:hidden" onclick="closeSidebar()"></div>
            
            <!-- Fixed Header -->
            <div class="fixed top-0 left-0 lg:left-64 right-0 z-50 bg-gray-50 py-3 sm:py-4 px-4 lg:px-8 shadow-sm">
                <div class="w-full max-w-7xl mx-auto">
                    <!-- Row 1: Title, Navigation, and Controls (same row on desktop) -->
                    <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 sm:gap-4 lg:gap-6 mb-2 sm:mb-0">
                        <!-- Left side: Hamburger, Title, Go Back -->
                        <div class="flex items-center gap-2 sm:gap-4 lg:gap-6">
                            <!-- Mobile Hamburger Menu Button -->
                            <div class="hamburger lg:hidden bg-[#f3f4f6] p-2 rounded" onclick="toggleSidebar()">
                                <span></span>
                                <span></span>
                                <span></span>
                            </div>
                            <h1 class="text-xl sm:text-2xl lg:text-3xl font-bold">Stock Receiving</h1>
                            <a href="inventory" class="inline-flex items-center px-2 sm:px-4 py-2 text-xs sm:text-sm lg:text-base border border-gray-300 rounded-md shadow-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500 transition duration-150 ease-in-out whitespace-nowrap">
                                <svg class="w-4 h-4 sm:w-5 sm:h-5 mr-1 sm:mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                                </svg>
                                <span class="hidden sm:inline">Go Back</span>
                                <span class="sm:hidden">Back</span>
                            </a>
                        </div>
                        
                        <!-- Right side: Controls (Category, Date, Search) -->
                        <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 sm:ml-auto w-full sm:w-auto">
                            <!-- Mobile: Category and Date in one row, evenly split -->
                            <div class="flex items-center gap-2 w-full sm:w-auto sm:flex-initial">
                                <select id="categoryFilter" class="flex-1 sm:flex-initial px-2 sm:px-4 py-2 text-xs sm:text-sm lg:text-base border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-teal-500 shadow-sm transition-colors min-w-0">
                                    <option value="">All Categories</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?= htmlspecialchars($category) ?>"><?= htmlspecialchars($category) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="flex items-center gap-2 flex-1 sm:flex-initial">
                                    <label for="receivingDate" class="text-xs sm:text-sm text-gray-700 hidden sm:inline whitespace-nowrap">Receiving date</label>
                                    <input type="datetime-local" id="receivingDate" class="flex-1 sm:flex-initial px-2 sm:px-3 py-2 text-xs sm:text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-teal-500 shadow-sm transition-colors min-w-0"/>
                                </div>
                            </div>
                            <!-- Search - full width on mobile, separate row -->
                            <div class="relative w-full sm:w-auto sm:flex-1 sm:max-w-md">
                                <input type="text" id="searchInput" placeholder="Search..." class="w-full pl-8 sm:pl-10 pr-2 sm:pr-4 py-2 text-xs sm:text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-teal-500 shadow-sm transition-colors">
                                <svg class="w-4 h-4 sm:w-5 sm:h-5 absolute left-2 top-2.5 sm:top-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                </svg>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Spacer for fixed header -->
            <div class="h-20 sm:h-20 mb-4"></div>
            
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16">

                <!-- Bulk Actions Panel -->
                <div id="bulkActionsPanel" class="hidden bg-white rounded-lg shadow-sm border border-gray-200 p-4 mb-6 bulk-actions">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-4">
                            <span id="selectedCount" class="text-sm font-medium text-gray-700">0 items selected</span>
                            <div class="flex items-center gap-2">
                                <label class="text-sm font-medium text-gray-700">Bulk Quantity:</label>
                                <input type="number" id="bulkQuantity" min="1" class="quantity-input px-2 py-1 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-teal-500" placeholder="Qty">
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
                                        <input type="number" min="0" step="0.01" class="buying-price-input quantity-input px-1 sm:px-2 py-0.5 sm:py-1 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-teal-500 text-center text-[10px] sm:text-xs" 
                                               placeholder="<?= number_format($product['buying_price'] ?? $product['price'], 2) ?>" 
                                               value="<?= number_format($product['buying_price'] ?? $product['price'], 2) ?>"
                                               data-original-buying-price="<?= $product['buying_price'] ?? $product['price'] ?>">
                                    </td>
                                    <td class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-4 whitespace-nowrap text-center text-[10px] sm:text-xs lg:text-sm text-black-500">
                                        <span class="current-stock"><?= $product['quantity'] ?></span>
                                    </td>
                                    <td class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-4 whitespace-nowrap text-center">
                                        <input type="number" min="0" class="receiving-quantity quantity-input px-1 sm:px-2 py-0.5 sm:py-1 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-teal-500 text-center text-[10px] sm:text-xs" placeholder="0">
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
            </div>
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
        
        // Cache for better performance
        let itemsBeingAddedCache = [];
        let updateItemsTimeout = null;

        // Initialize
        showPage(currentPage);
        updateBulkActionsVisibility();
        updateItemsBeingAdded(); // Initialize the real-time list

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
        });

        // Update new total when quantity changes - OPTIMIZED
        tableBody.addEventListener('input', (e) => {
            if (e.target.classList.contains('receiving-quantity')) {
                updateNewTotal(e.target.closest('tr'));
                scheduleUpdateItems();
            } else if (e.target.classList.contains('buying-price-input')) {
                scheduleUpdateItems();
            }
        });
        
        // Optimized update scheduling
        function scheduleUpdateItems() {
            if (updateItemsTimeout) clearTimeout(updateItemsTimeout);
            updateItemsTimeout = setTimeout(updateItemsBeingAdded, 10);
        }


        function updateNewTotal(row) {
            const currentStock = parseInt(row.querySelector('.current-stock').textContent) || 0;
            const receivingQty = parseInt(row.querySelector('.receiving-quantity').value) || 0;
            const newTotal = currentStock + receivingQty;
            row.querySelector('.new-total').textContent = newTotal;
        }

        // OPTIMIZED Real-time items being added functionality
        function updateItemsBeingAdded() {
            const items = [];
            const rows = allRows; // Use cached rows
            
            for (let i = 0; i < rows.length; i++) {
                const row = rows[i];
                const quantityInput = row.querySelector('.receiving-quantity');
                const quantity = parseInt(quantityInput.value) || 0;
                
                if (quantity > 0) {
                    const productId = row.dataset.productId;
                    const productName = row.children[2].textContent.trim();
                    const currentStock = parseInt(row.querySelector('.current-stock').textContent) || 0;
                    
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
            scheduleUpdateItems();
        });

        // Submit receiving with premium loader
        submitReceivingBtn.addEventListener('click', async () => {
            const items = [];
            const rows = document.querySelectorAll('.receiving-row');
            
            rows.forEach(row => {
                const quantityInput = row.querySelector('.receiving-quantity');
                const productId = row.dataset.productId;
                
                const quantity = parseInt(quantityInput.value) || 0;
                
                if (quantity > 0) {
                    items.push({
                        product_id: productId,
                        quantity: quantity
                    });
                }
            });
            
            if (items.length === 0) {
                showToast('Please enter quantities for at least one product', 'error');
                return;
            }
            
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
                    body: JSON.stringify({ items: items, receiving_date: receivingDate })
                });
                
                const ajaxResult = await ajaxResponse.json();
                
                if (!ajaxResult.success) {
                    throw new Error(ajaxResult.message || 'Failed to save receiving data');
                }
                
                const recordId = ajaxResult.record_id;
                
                // Show success message
                showToast('Stock received successfully! Downloading PDF...', 'success');
                
                // Step 2: Download PDF first using iframe (doesn't navigate away)
                await downloadPDF(items, receivingDate);
                
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
        function downloadPDF(items, receivingDate) {
            return new Promise((resolve) => {
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
                input.value = JSON.stringify({ items: items, receiving_date: receivingDate });
                
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
            const existingToasts = document.querySelectorAll('.toast-notification');
            existingToasts.forEach(toast => toast.remove());

            const icons = {
                success: `<svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>`,
                error: `<svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                </svg>`,
                info: `<svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>`
            };

            const toast = document.createElement('div');
            toast.className = `toast-notification fixed top-4 right-4 px-4 py-3 rounded-md text-white shadow-lg z-50 flex items-center gap-2 ${
                type === 'success' ? 'bg-teal-500' : 
                type === 'error' ? 'bg-rose-600' : 
                'bg-sky-500'
            }`;
            
            toast.innerHTML = `
                ${icons[type]}
                <span>${message}</span>
            `;

            document.body.appendChild(toast);

            setTimeout(() => {
                toast.classList.add('opacity-0', 'translate-x-full');
                setTimeout(() => {
                    toast.remove();
                }, 500);
            }, 3000);
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
