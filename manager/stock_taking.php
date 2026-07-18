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
require_once __DIR__ . '/../ensure_stock_changes_username.php';
ensureStockChangesUsernameColumn($db);

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

// Function to ensure daily stock summary exists for the current day
function ensureDailyStockSummary($db, $productId, $date) {
    $checkStmt = $db->prepare("
        SELECT COUNT(*) FROM daily_stock_summary 
        WHERE product_id = ? AND date = ?
    ");
    $checkStmt->execute([$productId, $date]);
    
    if ($checkStmt->fetchColumn() == 0) {
        $insertStmt = $db->prepare("
            INSERT INTO daily_stock_summary 
            (date, product_id, opening_quantity, closing_quantity, received_quantity, sold_quantity, damaged_quantity)
            VALUES (?, ?, 0, 0, 0, 0, 0)
        ");
        $insertStmt->execute([$date, $productId]);
    }
}

// Set the default timezone to Namibian time

// Handle form submission for stock taking
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db->beginTransaction();
        
        // Handle both JSON and form data
        $stockTakingData = null;
        if (isset($_POST['stock_taking_data'])) {
            // Form submission for PDF download
            $stockTakingData = json_decode($_POST['stock_taking_data'], true);
        } else {
            // JSON submission for AJAX
            $stockTakingData = json_decode(file_get_contents('php://input'), true);
        }
        
        if (isset($stockTakingData['items']) && is_array($stockTakingData['items'])) {
            $stockTakingItems = []; // Store items for PDF generation
            $today = date('Y-m-d');
            $stockType = isset($stockTakingData['stock_type']) ? $stockTakingData['stock_type'] : 'closing'; // 'opening' or 'closing'
            
            foreach ($stockTakingData['items'] as $item) {
                if (!empty($item['product_id']) && isset($item['actual_quantity'])) {
                    $productId = $item['product_id'];
                    $actualQuantity = floatval($item['actual_quantity']);
                    
                    // Get opening stock for today
                    $openingStmt = $db->prepare("
                        SELECT COALESCE(os.opening_quantity, 0) as opening_stock
                        FROM opening_stock os 
                        WHERE os.product_id = ? 
                        AND os.recorded_at >= date('now', 'start of day')
                        ORDER BY os.recorded_at DESC 
                        LIMIT 1
                    ");
                    $openingStmt->execute([$productId]);
                    $openingStock = $openingStmt->fetchColumn();
                    
                    // Get received stock for today (restocks that happened today)
                    $receivedStmt = $db->prepare("
                        SELECT COALESCE(SUM(quantity_change), 0) as total_changes 
                        FROM stock_changes 
                        WHERE product_id = ? 
                        AND action = 'Restock'
                        AND DATE(changed_at) = date('now')
                    ");
                    $receivedStmt->execute([$productId]);
                    $receivedStock = $receivedStmt->fetchColumn();
                    
                    // Get actual sales for today from both cash and credit sales
                    $salesStmt = $db->prepare("
                        SELECT COALESCE(
                            (SELECT SUM(oi.quantity)
                             FROM order_items oi
                             JOIN orders o ON oi.order_id = o.id
                             WHERE oi.product_name = (SELECT name FROM products WHERE id = ?)
                             AND DATE(o.created_at) = date('now')
                            ), 0
                        ) + COALESCE(
                            (SELECT SUM(csi.quantity)
                             FROM credit_sale_items csi
                             JOIN credit_sales cs ON csi.sale_id = cs.id
                             WHERE csi.product_name = (SELECT name FROM products WHERE id = ?)
                             AND DATE(cs.created_at) = date('now')
                            ), 0
                        ) as total_sold
                    ");
                    $salesStmt->execute([$productId, $productId]);
                    $actualSales = $salesStmt->fetchColumn();
                    
                    // Get product details and current quantity from products table
                    $stmt = $db->prepare("SELECT name, price, buying_price, quantity FROM products WHERE id = ?");
                    $stmt->execute([$productId]);
                    $product = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($product) {
                        // Ensure daily stock summary exists for this product and date
                        ensureDailyStockSummary($db, $productId, $today);
                        
                        // Expected quantity should always be the current quantity in products table
                        $expectedStock = (int)$product['quantity'];
                        $variance = $actualQuantity - $expectedStock;
                        
                        // Calculate sold quantity based on stock type
                        if ($stockType === 'opening') {
                            $soldQuantity = 0; // No sales calculation for opening stock
                        } else {
                            // Use actual recorded sales
                            $soldQuantity = $actualSales;
                        }
                        
                        // Store item details for PDF
                        $stockTakingItems[] = [
                            'product_id' => $productId,
                            'product_name' => $product['name'],
                            'opening_stock' => $openingStock,
                            'received_stock' => $receivedStock,
                            'expected_quantity' => $expectedStock,
                            'actual_quantity' => $actualQuantity,
                            'sold_quantity' => $soldQuantity,
                            'variance' => $variance,
                            'actual_sales' => $actualSales,
                            'price' => $product['price'],
                            'buying_price' => $product['buying_price'],
                            'sales_income' => $soldQuantity * $product['price'],
                            'profit' => ($soldQuantity * $product['price']) - ($soldQuantity * $product['buying_price'])
                        ];
                        
                        // Get current product quantity before update
                        $currentQtyStmt = $db->prepare("SELECT quantity FROM products WHERE id = ?");
                        $currentQtyStmt->execute([$productId]);
                        $currentQuantity = $currentQtyStmt->fetchColumn();
                        
                        // Update product quantity to actual count
                        $updateStmt = $db->prepare("UPDATE products SET quantity = ? WHERE id = ?");
                        $updateStmt->execute([$actualQuantity, $productId]);
                        
                        // Log the stock adjustment if there's a variance
                        if ($variance != 0) {
                            $action = $stockType === 'opening' ? 'Opening Stock Adjustment' : 'Closing Stock Adjustment';
                            $logStmt = $db->prepare("
                                INSERT INTO stock_changes 
                                (product_id, action, quantity_change, old_quantity, new_quantity, is_stock_taken, username) 
                                VALUES (?, ?, ?, ?, ?, 1, ?)
                            ");
                            $logStmt->execute([$productId, $action, $variance, $currentQuantity, $actualQuantity, currentStockChangeUsername()]);
                        }
                        
                        // Mark restocks as taken only for closing stock
                        if ($stockType === 'closing') {
                            $markStmt = $db->prepare("
                                UPDATE stock_changes 
                                SET is_stock_taken = 1 
                                WHERE product_id = ? 
                                AND action = 'Restock' 
                                AND DATE(changed_at) = date('now')
                            ");
                            $markStmt->execute([$productId]);
                        }
                        
                        // Record stock based on type (opening or closing)
                        if ($stockType === 'opening') {
                                                    // Record opening stock for the day
                        $openingStmt = $db->prepare("
                            INSERT INTO opening_stock (product_id, opening_quantity, recorded_by, notes) 
                            VALUES (?, ?, ?, ?)
                        ");
                        $openingStmt->execute([$productId, $actualQuantity, $_SESSION['user_id'], 'Opening stock recorded during stock taking']);
                        
                        // Reset sold quantities for the current day when opening stock is recorded
                        // This ensures sales tracking starts fresh from the opening stock
                        $resetSoldStmt = $db->prepare("
                            UPDATE daily_stock_summary 
                            SET sold_quantity = 0 
                            WHERE product_id = ? AND date = ?
                        ");
                        $resetSoldStmt->execute([$productId, $today]);
                        
                        // Also ensure that any existing daily stock summary for today is properly updated
                        $updateExistingStmt = $db->prepare("
                            UPDATE daily_stock_summary 
                            SET opening_quantity = ?, sold_quantity = 0
                            WHERE product_id = ? AND date = ?
                        ");
                        $updateExistingStmt->execute([$actualQuantity, $productId, $today]);
                            
                            // Update daily stock summary with opening stock
                            $summaryStmt = $db->prepare("
                                INSERT OR REPLACE INTO daily_stock_summary 
                                (date, product_id, opening_quantity, closing_quantity, received_quantity, sold_quantity, damaged_quantity)
                                VALUES (
                                    ?,
                                    ?,
                                    ?,
                                    COALESCE((SELECT closing_quantity FROM daily_stock_summary WHERE date = ? AND product_id = ?), 0),
                                    COALESCE((SELECT received_quantity FROM daily_stock_summary WHERE date = ? AND product_id = ?), 0),
                                    0,
                                    COALESCE((SELECT damaged_quantity FROM daily_stock_summary WHERE date = ? AND product_id = ?), 0)
                                )
                            ");
                            $summaryStmt->execute([
                                $today, $productId, $actualQuantity, $today, $productId,
                                $today, $productId, $today, $productId
                            ]);
                        } else {
                        // Record closing stock for the day
                        $closingStmt = $db->prepare("
                            INSERT INTO closing_stock (product_id, closing_quantity, recorded_by, notes) 
                            VALUES (?, ?, ?, ?)
                        ");
                        $closingStmt->execute([$productId, $actualQuantity, $_SESSION['user_id'], 'Closing stock recorded during stock taking']);
                        
                        // Update daily stock summary with closing stock and sold quantity
                        // Use actual recorded sales, not calculated from stock movement
                        $summaryStmt = $db->prepare("
                                INSERT OR REPLACE INTO daily_stock_summary 
                                (date, product_id, opening_quantity, closing_quantity, received_quantity, sold_quantity, damaged_quantity)
                                VALUES (
                                    ?,
                                    ?,
                                    COALESCE((SELECT opening_quantity FROM daily_stock_summary WHERE date = ? AND product_id = ?), ?),
                                    ?,
                                    COALESCE((SELECT received_quantity FROM daily_stock_summary WHERE date = ? AND product_id = ?), ?),
                                    ?,
                                    COALESCE((SELECT damaged_quantity FROM daily_stock_summary WHERE date = ? AND product_id = ?), 0)
                                )
                            ");
                            $summaryStmt->execute([
                                $today, $productId, $today, $productId, $openingStock, $actualQuantity,
                                $today, $productId, $receivedStock, $actualSales,
                                $today, $productId
                            ]);
                            
                            // Reset sold quantities for the next day after closing stock
                            // This ensures that sales tracking starts fresh for the new day
                            $resetSoldStmt = $db->prepare("
                                UPDATE daily_stock_summary 
                                SET sold_quantity = 0 
                                WHERE product_id = ? AND date > ?
                            ");
                            $resetSoldStmt->execute([$productId, $today]);
                            
                            // Reset received quantities for the next day after closing stock
                            // This ensures that receiving tracking starts fresh for the new day
                            $resetReceivedStmt = $db->prepare("
                                UPDATE daily_stock_summary 
                                SET received_quantity = 0 
                                WHERE product_id = ? AND date > ?
                            ");
                            $resetReceivedStmt->execute([$productId, $today]);
                            
                            // Also ensure that any future daily stock summaries have sold_quantity = 0 and received_quantity = 0
                            $ensureFutureResetStmt = $db->prepare("
                                INSERT OR REPLACE INTO daily_stock_summary 
                                (date, product_id, opening_quantity, closing_quantity, received_quantity, sold_quantity, damaged_quantity)
                                VALUES (?, ?, 0, 0, 0, 0, 0)
                            ");
                            $tomorrow = date('Y-m-d', strtotime('+1 day'));
                            $ensureFutureResetStmt->execute([$tomorrow, $productId]);
                            
                            // Automatically create opening stock for the next day
                            $tomorrow = date('Y-m-d', strtotime('+1 day'));
                            
                            // Check if opening stock for tomorrow already exists
                            $checkTomorrowStmt = $db->prepare("
                                SELECT COUNT(*) FROM opening_stock 
                                WHERE product_id = ? 
                                AND recorded_at >= date(?, 'start of day')
                                AND recorded_at < date(?, '+1 day')
                            ");
                            $checkTomorrowStmt->execute([$productId, $tomorrow, $tomorrow]);
                            $tomorrowExists = $checkTomorrowStmt->fetchColumn() > 0;
                            
                            if (!$tomorrowExists) {
                                // Create opening stock for tomorrow using today's closing stock
                                $tomorrowOpeningStmt = $db->prepare("
                                    INSERT INTO opening_stock (product_id, opening_quantity, recorded_by, notes) 
                                    VALUES (?, ?, ?, ?)
                                ");
                                $tomorrowOpeningStmt->execute([
                                    $productId, 
                                    $actualQuantity, 
                                    $_SESSION['user_id'], 
                                    'Opening stock automatically set from previous day closing stock'
                                ]);
                                
                                // Update daily stock summary for tomorrow with opening stock
                                $tomorrowSummaryStmt = $db->prepare("
                                    INSERT OR REPLACE INTO daily_stock_summary 
                                    (date, product_id, opening_quantity, closing_quantity, received_quantity, sold_quantity, damaged_quantity)
                                    VALUES (
                                        ?,
                                        ?,
                                        ?,
                                        COALESCE((SELECT closing_quantity FROM daily_stock_summary WHERE date = ? AND product_id = ?), 0),
                                        0,
                                        0,
                                        COALESCE((SELECT damaged_quantity FROM daily_stock_summary WHERE date = ? AND product_id = ?), 0)
                                    )
                                ");
                                $tomorrowSummaryStmt->execute([
                                    $tomorrow, $productId, $actualQuantity, $tomorrow, $productId,
                                    $tomorrow, $productId
                                ]);
                            }
                        }
                    }
                }
            }
            
            $db->commit();
            
            // Generate PDF if there are items and it's a form submission
            if (!empty($stockTakingItems) && isset($_POST['stock_taking_data'])) {
                // Include FPDF library
                require('../fpdf/fpdf.php');
                
                if ($stockType === 'opening') {
                    // For opening stock, generate current stock inventory report
                    class CurrentStockPDF extends FPDF {
                        function Header() {
                            $this->SetFont('Arial', 'B', 15);
                            $this->Cell(0, 10, 'Current Stock Inventory Report', 0, 1, 'C');
                            $this->SetFont('Arial', '', 12);
                            $this->Cell(0, 10, 'Generated after Opening Stock Recording', 0, 1, 'C');
                            $this->Cell(0, 10, 'Generated on ' . date('Y-m-d H:i:s'), 0, 1, 'C');
                            $this->Ln(5);
                            
                            // Table header for inventory
                            $this->SetFont('Arial', 'B', 11);
                            $this->Cell(10, 10, 'ID', 1);
                            $this->Cell(80, 10, 'Product Name', 1);
                            $this->Cell(25, 10, 'Quantity', 1);
                            $this->Cell(25, 10, 'Price', 1);
                            $this->Cell(35, 10, 'Total Value', 1);
                            $this->Ln();
                        }
                        
                        function Footer() {
                            $this->SetY(-15);
                            $this->SetFont('Arial', 'I', 8);
                            $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
                        }
                    }
                    
                    // Initialize PDF for current stock inventory
                    $pdf = new CurrentStockPDF();
                    $pdf->AliasNbPages();
                    $pdf->AddPage();
                    
                    // Get current inventory data after opening stock update
                    $inventoryStmt = $db->prepare("
                        SELECT 
                            id, name, quantity, price
                        FROM products
                        WHERE CAST(quantity AS INTEGER) > 0
                        ORDER BY name ASC
                    ");
                    $inventoryStmt->execute();
                    $currentInventory = $inventoryStmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Add inventory data to PDF
                    $pdf->SetFont('Arial', '', 10);
                    $totalProducts = 0;
                    $totalItems = 0;
                    $totalValue = 0;
                    
                    foreach ($currentInventory as $product) {
                        $totalValuePerProduct = (float)$product['price'] * (int)$product['quantity'];
                        
                        $pdf->Cell(10, 8, $product['id'], 1);
                        $pdf->Cell(80, 8, $product['name'], 1);
                        $pdf->Cell(25, 8, $product['quantity'], 1);
                        $pdf->Cell(25, 8, 'N$' . number_format($product['price'], 2), 1);
                        $pdf->Cell(35, 8, 'N$' . number_format($totalValuePerProduct, 2), 1);
                        $pdf->Ln();
                        
                        $totalProducts++;
                        $totalItems += (int)$product['quantity'];
                        $totalValue += $totalValuePerProduct;
                    }
                    
                    // Add summary section
                    $pdf->Ln(5);
                    $pdf->SetFont('Arial', 'B', 12);
                    $pdf->Cell(0, 10, 'Current Inventory Summary', 0, 1, 'L');
                    $pdf->SetFont('Arial', '', 11);
                    $pdf->Cell(100, 8, 'Total Product Types:', 0, 0, 'L');
                    $pdf->Cell(50, 8, $totalProducts, 0, 1, 'L');
                    $pdf->Cell(100, 8, 'Total Items in Stock:', 0, 0, 'L');
                    $pdf->Cell(50, 8, $totalItems, 0, 1, 'L');
                    $pdf->Cell(100, 8, 'Total Inventory Value:', 0, 0, 'L');
                    $pdf->Cell(50, 8, 'N$' . number_format($totalValue, 2), 0, 1, 'L');
                    
                    // Generate filename
                    $fileName = 'Current_Stock_Inventory_Report_' . date('Y-m-d_H-i-s') . '.pdf';
                    
                    // Generate PDF content as string for email attachment
                    $pdfContent = $pdf->Output('S');
                    
                    // Send email with PDF attachment (in background, don't block PDF download)
                    if (!empty($pdfContent)) {
                    try {
                        $mail = new PHPMailer(true);
                        
                        // Server settings
                        $mail->isSMTP();
                        $mail->Host = 'smtp.gmail.com';
                        $mail->SMTPAuth = true;
                        $mail->Username = 'sourcecodedev6@gmail.com';
                        $mail->Password = 'irfvlutirghpfbkl';
                        $mail->SMTPSecure = 'tls';
                        $mail->Port = 587;
                            $mail->SMTPOptions = array(
                                'ssl' => array(
                                    'verify_peer' => false,
                                    'verify_peer_name' => false,
                                    'allow_self_signed' => true
                                )
                            );
                        
                        // Recipients
                        $mail->setFrom('sourcecodedev6@gmail.com', 'POS System');
                        if (!empty($userEmail)) {
                            $mail->addAddress($userEmail, $_SESSION['username']);
                        } else {
                            // Fallback to default email if user email not found
                            $mail->addAddress('info.easystockna@gmail.com', 'Simsil Tech Solutions');
                        }
                        
                        // Content
                        $mail->isHTML(true);
                        $mail->Subject = 'Opening Stock Report - ' . date('Y-m-d H:i:s');
                        $mail->Body = '
                            <h2>Opening Stock Report</h2>
                            <p><strong>Date:</strong> ' . date('Y-m-d H:i:s') . '</p>
                            <p><strong>Total Product Types:</strong> ' . $totalProducts . '</p>
                            <p><strong>Total Items in Stock:</strong> ' . $totalItems . '</p>
                            <p><strong>Total Inventory Value:</strong> N$' . number_format($totalValue, 2) . '</p>
                            <br>
                            <p>Please find the detailed current stock inventory report attached.</p>
                            <br>
                            <p>Best regards,<br>POS System</p>
                        ';
                        
                        // Attach PDF
                        $mail->addStringAttachment($pdfContent, $fileName, 'base64', 'application/pdf');
                        
                            // Send email (don't wait for it to complete)
                        $mail->send();
                        } catch (Exception $emailException) {
                            error_log("Email sending failed: " . $emailException->getMessage());
                            // Continue to provide PDF download even if email fails
                        }
                    }
                    
                    // Clean any output buffers to ensure no content is sent before headers
                    while (ob_get_level()) {
                        ob_end_clean();
                    }
                    
                    // Set proper headers for PDF download (like receiving.php)
                    header('Content-Type: application/pdf');
                    header('Content-Disposition: attachment; filename="' . $fileName . '"');
                    header('Content-Length: ' . strlen($pdfContent));
                    header('Cache-Control: private, max-age=0, must-revalidate');
                    header('Pragma: public');
                    header('Expires: 0');
                    
                    // Output PDF for download and exit immediately (like receiving.php)
                    echo $pdfContent;
                    exit;
                } else {
                    // For closing stock, generate closing stock report
                    error_log("Starting closing stock PDF generation");
                    
                    try {
                    $today = date('Y-m-d');
                    
                    class ClosingStockPDF extends FPDF {
                        function Header() {
                            $this->SetFont('Arial', 'B', 16);
                            $this->Cell(0, 10, 'CLOSING STOCK REPORT', 0, 1, 'C');
                            $this->SetFont('Arial', '', 10);
                            $this->Cell(0, 8, 'Generated on ' . date('Y-m-d H:i:s'), 0, 1, 'C');
                            $this->Ln(3);
                        }
                        
                        function Footer() {
                            $this->SetY(-15);
                            $this->SetFont('Arial', 'I', 8);
                            $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
                        }
                    }
                    
                    // Initialize PDF in landscape orientation
                    $pdf = new ClosingStockPDF('L'); // Landscape orientation
                    $pdf->AliasNbPages();
                    $pdf->AddPage();
                    $pdf->SetFont('Arial', '', 9);
                    
                    // Calculate column widths for A4 landscape (297mm total width)
                    // Adjusted to fit: 20+60+28+32+30+26+32 = 228mm (leaves margin)
                    $colWidths = [
                        'id' => 20,
                        'name' => 60,
                        'unit_price' => 28,
                        'system_qty' => 32,
                        'physical_qty' => 30,
                        'difference' => 26,
                        'value_diff' => 32
                    ];
                    
                    // Draw table header
                    $pdf->SetFont('Arial', 'B', 9);
                    $pdf->Cell($colWidths['id'], 10, 'ID', 1, 0, 'C');
                    $pdf->Cell($colWidths['name'], 10, 'Product Name', 1, 0, 'C');
                    $pdf->Cell($colWidths['unit_price'], 10, 'Unit Price', 1, 0, 'C');
                    $pdf->Cell($colWidths['system_qty'], 10, 'System Qty (Exp)', 1, 0, 'C');
                    $pdf->Cell($colWidths['physical_qty'], 10, 'Physical (Act)', 1, 0, 'C');
                    $pdf->Cell($colWidths['difference'], 10, 'Difference', 1, 0, 'C');
                    $pdf->Cell($colWidths['value_diff'], 10, 'Value Diff', 1, 1, 'C');
                    
                    $totalValueDifference = 0;
                    $pdf->SetFont('Arial', '', 9);
                    
                    // Process each stock taking item
                    foreach ($stockTakingItems as $item) {
                        $productId = $item['product_id'];
                        $productName = $item['product_name'];
                        $unitPrice = $item['price'];
                        $systemQuantity = (int)$item['expected_quantity'];
                        $physicalCount = (float)$item['actual_quantity'];
                        $difference = $physicalCount - $systemQuantity;
                        $valueDifference = $difference * $unitPrice;
                        $totalValueDifference += $valueDifference;
                        
                        // Format difference with + or - sign
                        $differenceFormatted = $difference > 0 ? '+' . $difference : (string)$difference;
                        $valueDifferenceFormatted = $valueDifference > 0 ? '+' . number_format($valueDifference, 2) : number_format($valueDifference, 2);
                        
                        // Add row to PDF
                        $pdf->Cell($colWidths['id'], 8, $productId, 1, 0, 'C');
                        $pdf->Cell($colWidths['name'], 8, substr($productName, 0, 32), 1, 0, 'L');
                        $pdf->Cell($colWidths['unit_price'], 8, number_format($unitPrice, 2), 1, 0, 'R');
                        $pdf->Cell($colWidths['system_qty'], 8, $systemQuantity, 1, 0, 'C');
                        $pdf->Cell($colWidths['physical_qty'], 8, $physicalCount, 1, 0, 'C');
                        $pdf->Cell($colWidths['difference'], 8, $differenceFormatted, 1, 0, 'C');
                        $pdf->Cell($colWidths['value_diff'], 8, $valueDifferenceFormatted, 1, 1, 'R');
                    }
                    
                    // Add empty row for spacing
                    $pdf->Cell($colWidths['id'], 8, '', 1, 0, 'C');
                    $pdf->Cell($colWidths['name'], 8, '', 1, 0, 'L');
                    $pdf->Cell($colWidths['unit_price'], 8, '', 1, 0, 'R');
                    $pdf->Cell($colWidths['system_qty'], 8, '', 1, 0, 'C');
                    $pdf->Cell($colWidths['physical_qty'], 8, '', 1, 0, 'C');
                    $pdf->Cell($colWidths['difference'], 8, '', 1, 0, 'C');
                    $pdf->Cell($colWidths['value_diff'], 8, '', 1, 1, 'R');
                    
                    // Add total value difference line
                    $pdf->Ln(5);
                    $pdf->SetFont('Arial', 'B', 12);
                    $totalValueDiffFormatted = $totalValueDifference > 0 ? '+' . number_format($totalValueDifference, 2) : number_format($totalValueDifference, 2);
                    $pdf->Cell(0, 10, 'TOTAL VALUE DIFFERENCE: ' . $totalValueDiffFormatted, 0, 1, 'L');
                    
                    // Generate filename
                    $fileName = 'Closing_Stock_Report_' . date('Y-m-d_H-i-s') . '.pdf';
                    
                    // Generate PDF content as string for email attachment
                    $pdfContent = $pdf->Output('S');
                    
                    // Send email with PDF attachment (in background, don't block PDF download)
                    if (!empty($pdfContent)) {
                    try {
                        $mail = new PHPMailer(true);
                        
                        // Server settings
                        $mail->isSMTP();
                        $mail->Host = 'smtp.gmail.com';
                        $mail->SMTPAuth = true;
                        $mail->Username = 'sourcecodedev6@gmail.com';
                        $mail->Password = 'irfvlutirghpfbkl';
                        $mail->SMTPSecure = 'tls';
                        $mail->Port = 587;
                            $mail->SMTPOptions = array(
                                'ssl' => array(
                                    'verify_peer' => false,
                                    'verify_peer_name' => false,
                                    'allow_self_signed' => true
                                )
                            );
                        
                        // Recipients
                        $mail->setFrom('sourcecodedev6@gmail.com', 'POS System');
                        if (!empty($userEmail)) {
                            $mail->addAddress($userEmail, $_SESSION['username']);
                        } else {
                            // Fallback to default email if user email not found
                            $mail->addAddress('medusallemfillemon@gmail.com', 'Simsil Tech Solutions');
                        }
                        
                        // Content
                        $mail->isHTML(true);
                        $mail->Subject = 'Closing Stock Report - ' . date('Y-m-d H:i:s');
                        $mail->Body = '
                            <h2>Closing Stock Report</h2>
                            <p><strong>Date:</strong> ' . date('Y-m-d H:i:s') . '</p>
                            <p><strong>Total Value Difference:</strong> ' . $totalValueDiffFormatted . '</p>
                            <br>
                            <p>Please find the detailed closing stock report attached.</p>
                            <p>The report includes product ID, product name, unit price, system(expected), Actual count, difference, and value difference for each product.</p>
                            <br>
                            <p>Best regards,<br>POS System</p>
                        ';
                        
                        // Attach PDF
                        $mail->addStringAttachment($pdfContent, $fileName, 'base64', 'application/pdf');
                        
                            // Send email (don't wait for it to complete)
                        $mail->send();
                        } catch (Exception $emailException) {
                            error_log("Email sending failed: " . $emailException->getMessage());
                            // Continue to provide PDF download even if email fails
                        }
                    }
                    
                    // Clean any output buffers to ensure no content is sent before headers
                    while (ob_get_level()) {
                        ob_end_clean();
                    }
                    
                    // Set proper headers for PDF download (like receiving.php)
                    header('Content-Type: application/pdf');
                    header('Content-Disposition: attachment; filename="' . $fileName . '"');
                    header('Content-Length: ' . strlen($pdfContent));
                    header('Cache-Control: private, max-age=0, must-revalidate');
                    header('Pragma: public');
                    header('Expires: 0');
                    
                    // Output PDF for download and exit immediately (like receiving.php)
                    echo $pdfContent;
                    exit;
                        
                    } catch (Exception $e) {
                        error_log("PDF generation error: " . $e->getMessage());
                        // If PDF generation fails, show error
                        http_response_code(500);
                        echo '<script>alert("Error generating PDF: ' . htmlspecialchars($e->getMessage()) . '"); window.close();</script>';
                        exit;
                    }
                }
            }
            
            // Return JSON response for AJAX requests
            if (!isset($_POST['stock_taking_data'])) {
                http_response_code(200);
                echo json_encode(['success' => true, 'message' => 'Stock taking completed successfully']);
                exit;
            }
        }
    } catch (Exception $e) {
        $db->rollBack();
        if (!isset($_POST['stock_taking_data'])) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        } else {
            // For form submissions, show error in a simple way
            echo '<script>alert("Error: ' . $e->getMessage() . '"); window.close();</script>';
        }
        exit;
    }
}

// Fetch products from the database with correct sales calculation
$stmt = $db->query('
    SELECT 
        p.*,
        COALESCE(
            (SELECT SUM(oi.quantity)
             FROM order_items oi
             JOIN orders o ON oi.order_id = o.id
             WHERE oi.product_name = p.name
             AND DATE(o.created_at) = date("now")
            ), 0
        ) + COALESCE(
            (SELECT SUM(csi.quantity)
             FROM credit_sale_items csi
             JOIN credit_sales cs ON csi.sale_id = cs.id
             WHERE csi.product_name = p.name
             AND DATE(cs.created_at) = date("now")
            ), 0
        ) as total_sold,
        (SELECT COALESCE(SUM(sc.quantity_change), 0) 
         FROM stock_changes sc 
         WHERE sc.product_id = p.id 
         AND sc.action = "Restock"
         AND DATE(sc.changed_at) = date("now")) as received_stock,
        COALESCE(
            (SELECT os.opening_quantity
             FROM opening_stock os 
             WHERE os.product_id = p.id 
             AND os.recorded_at >= date("now", "start of day")
             ORDER BY os.recorded_at DESC 
             LIMIT 1),
            (SELECT os.opening_quantity
             FROM opening_stock os 
             WHERE os.product_id = p.id 
             ORDER BY os.recorded_at DESC 
             LIMIT 1),
            0
        ) as opening_stock
    FROM products p
    ORDER BY p.name ASC
');

$products = [];
$lowStock = [];
$outOfStock = [];

// Post-process the data to handle sold quantities that should be reset after closing stock
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    // Check if closing stock exists for this product and today
    $closingStmt = $db->prepare("
        SELECT COUNT(*) 
        FROM closing_stock 
        WHERE product_id = ? AND DATE(recorded_at) = date('now')
    ");
    $closingStmt->execute([$row['id']]);
    $hasClosingStock = $closingStmt->fetchColumn() > 0;
    
    // If closing stock exists for today, sold quantity should be 0
    if ($hasClosingStock) {
        $row['total_sold'] = 0;
    }
    
    // Also check if there's a closing stock record for a previous date that should reset today's sold quantities
    $previousClosingStmt = $db->prepare("
        SELECT COUNT(*) 
        FROM closing_stock 
        WHERE product_id = ? AND DATE(recorded_at) < date('now')
        ORDER BY recorded_at DESC
        LIMIT 1
    ");
    $previousClosingStmt->execute([$row['id']]);
    $hasPreviousClosingStock = $previousClosingStmt->fetchColumn() > 0;
    
    // If there's a previous closing stock, sold quantity should be 0
    if ($hasPreviousClosingStock) {
        $lastClosingStmt = $db->prepare("
            SELECT DATE(recorded_at) as closing_date
            FROM closing_stock 
            WHERE product_id = ? AND DATE(recorded_at) < date('now')
            ORDER BY recorded_at DESC
            LIMIT 1
        ");
        $lastClosingStmt->execute([$row['id']]);
        $lastClosingDate = $lastClosingStmt->fetchColumn();
        
        if ($lastClosingDate) {
            $row['total_sold'] = 0;
        }
    }
    
    $products[] = $row;
    if ($row['received_stock'] <= 0) {
        $outOfStock[] = $row;
    } else if ($row['received_stock'] < 5) {
        $lowStock[] = $row;
    }
}

// Fetch unique categories
$catStmt = $db->query("SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category != '' ORDER BY category");
$categories = [];
while ($cat = $catStmt->fetch(PDO::FETCH_ASSOC)) {
    $categories[] = $cat['category'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Taking</title>
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
        #stockToastHost {
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
        .page-toast {
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
        .page-toast--success { background: #0d9488; }
        .page-toast--error { background: #e11d48; }
        .page-toast--info { background: #0369a1; }
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
        .stock-taking-row {
            transition: all 0.3s ease;
        }
        .stock-taking-row:hover {
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
        .difference-positive {
            color: #059669;
            font-weight: 600;
        }
        .difference-negative {
            color: #dc2626;
            font-weight: 600;
        }
        .difference-zero {
            color: #6b7280;
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
            #itemsBeingCounted table th:nth-child(1),
            #itemsBeingCounted table td:nth-child(1) {
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
            
            /* Column width distribution for mobile - stock taking table has 7 columns */
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
                width: 22%; /* Product name */
            }
            
            table th:nth-child(4),
            table td:nth-child(4) {
                width: 15%; /* Opening Stock */
            }
            
            table th:nth-child(5),
            table td:nth-child(5) {
                width: 15%; /* Received Stock (hidden on closing) */
            }
            
            table th:nth-child(6),
            table td:nth-child(6) {
                width: 13%; /* Actual Count */
            }
            
            table th:nth-child(7),
            table td:nth-child(7) {
                width: 12%; /* Difference */
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
            
            /* Equal size for inputs in same column - actual quantity */
            table td:nth-child(6) .actual-quantity {
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
            
            #categoryFilter {
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
            
            /* Items Being Counted table - Product name takes most space */
            #itemsBeingCounted .mobile-table-container table {
                table-layout: fixed;
            }
            
            #itemsBeingCounted table th:nth-child(1),
            #itemsBeingCounted table td:nth-child(1) {
                width: 45% !important; /* Product name - most space */
            }
            
            #itemsBeingCounted table th:nth-child(2),
            #itemsBeingCounted table td:nth-child(2) {
                width: 18% !important; /* Expected */
            }
            
            #itemsBeingCounted table th:nth-child(3),
            #itemsBeingCounted table td:nth-child(3) {
                width: 18% !important; /* Actual */
            }
            
            #itemsBeingCounted table th:nth-child(4),
            #itemsBeingCounted table td:nth-child(4) {
                width: 19% !important; /* Actions */
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
            
            /* Items Being Counted table - Product name takes most space on desktop too */
            #itemsBeingCounted .mobile-table-container table {
                table-layout: fixed;
            }
            
            #itemsBeingCounted table th:nth-child(1),
            #itemsBeingCounted table td:nth-child(1) {
                width: 40% !important; /* Product name - most space */
            }
            
            #itemsBeingCounted table th:nth-child(2),
            #itemsBeingCounted table td:nth-child(2) {
                width: 20% !important; /* Expected */
            }
            
            #itemsBeingCounted table th:nth-child(3),
            #itemsBeingCounted table td:nth-child(3) {
                width: 20% !important; /* Actual */
            }
            
            #itemsBeingCounted table th:nth-child(4),
            #itemsBeingCounted table td:nth-child(4) {
                width: 20% !important; /* Actions */
            }
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
            border-top: 4px solid #3b82f6;
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
            background: #3b82f6;
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
            background: linear-gradient(90deg, #3b82f6, #2563eb);
            border-radius: 2px;
            width: 0%;
            animation: progress 3s ease-in-out;
        }
        
        @keyframes progress {
            0% { width: 0%; }
            50% { width: 70%; }
            100% { width: 95%; }
        }
    </style>
</head>
<body class="bg-gray-100" data-server-date="<?= htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>">
    <div class="flex">
        <div class="sidebar fixed h-full">
            <?php include 'sidebar.php'; ?>
        </div>
        <div class="content flex-1 lg:ml-64">
            <!-- Mobile Sidebar Overlay -->
            <div id="mobileOverlay" class="mobile-overlay lg:hidden" onclick="closeSidebar()"></div>
            
            <!-- Fixed Header -->
            <div class="sticky top-0 z-50 bg-gray-100 py-3 sm:py-4 px-4 lg:px-6 shadow-sm">
                <div class="w-full">
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
                            <h1 class="text-xl sm:text-2xl lg:text-3xl font-bold">Stock Taking</h1>
                            <a href="inventory" class="inline-flex items-center px-2 sm:px-4 py-2 text-xs sm:text-sm lg:text-base border border-gray-300 rounded-md shadow-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500 transition duration-150 ease-in-out whitespace-nowrap">
                                <svg class="w-4 h-4 sm:w-5 sm:h-5 mr-1 sm:mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                                </svg>
                                <span class="hidden sm:inline">Go Back</span>
                                <span class="sm:hidden">Back</span>
                            </a>
                        </div>
                        
                        <!-- Right side: Controls (Category, View All, Search) -->
                        <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 sm:ml-auto w-full sm:w-auto">
                            <!-- Mobile: Category and View All in one row, evenly split -->
                            <div class="flex items-center gap-2 w-full sm:w-auto sm:flex-initial">
                                <select id="categoryFilter" class="flex-1 sm:flex-initial px-2 sm:px-4 py-2 text-xs sm:text-sm lg:text-base border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-teal-500 shadow-sm transition-colors min-w-0">
                                    <option value="">All Categories</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?= htmlspecialchars($category) ?>"><?= htmlspecialchars($category) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button id="viewAllBtn" class="flex-1 sm:flex-initial inline-flex items-center justify-center px-2 sm:px-4 py-2 text-xs sm:text-sm lg:text-base border border-gray-300 rounded-md shadow-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500 transition duration-150 ease-in-out whitespace-nowrap">
                                    <svg class="w-4 h-4 sm:w-5 sm:h-5 mr-1 sm:mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"></path>
                                    </svg>
                                    <span class="hidden sm:inline">View All</span>
                                    <span class="sm:hidden">All</span>
                                </button>
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
            
            <div id="stockToastHost" aria-live="polite" aria-atomic="true"></div>
            
            <div class="w-full px-4 lg:px-6 py-6">

                <!-- Stock Type Selection -->
                <div class="mb-6 bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-medium text-gray-900 mb-2">Stock Type Selection</h3>
                            <p class="text-sm text-gray-600">Choose whether you are recording opening stock or closing stock for the day.</p>
                   
                        </div>
                        <div class="flex items-center gap-6">
                            <label class="flex items-center">
                                <input type="radio" name="stockType" value="opening" class="h-4 w-4 text-teal-600 focus:ring-teal-500 border-gray-300">
                                <span class="ml-2 text-sm font-medium text-gray-700">Opening Stock</span>
                            </label>
                            <label class="flex items-center">
                                <input type="radio" name="stockType" value="closing" class="h-4 w-4 text-teal-600 focus:ring-teal-500 border-gray-300" checked>
                                <span class="ml-2 text-sm font-medium text-gray-700">Closing Stock</span>
                            </label>
                        </div>
                    </div>
                </div>

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
                                <th scope="col" class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-3 text-center text-[10px] sm:text-xs lg:text-xs font-medium text-black uppercase tracking-wider cursor-pointer" onclick="sortTable(3, true)">
                                    <div class="flex items-center justify-center">
                                        <span class="hidden sm:inline">Opening Stock</span>
                                        <span class="sm:hidden">Opening</span>
                                        <svg class="w-2.5 h-2.5 sm:w-3 sm:h-3 lg:w-3 lg:h-3 ml-0.5 sm:ml-1.5 lg:ml-1.5 hidden lg:inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                                        </svg>
                                    </div>
                                </th>
                                <th scope="col" class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-3 text-center text-[10px] sm:text-xs lg:text-xs font-medium text-black uppercase tracking-wider cursor-pointer received-stock-column" onclick="sortTable(4, true)" style="display: none;">
                                    <div class="flex items-center justify-center">
                                        <span class="hidden sm:inline">Received Stock</span>
                                        <span class="sm:hidden">Received</span>
                                        <svg class="w-2.5 h-2.5 sm:w-3 sm:h-3 lg:w-3 lg:h-3 ml-0.5 sm:ml-1.5 lg:ml-1.5 hidden lg:inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                                        </svg>
                                    </div>
                                </th>
                                <th scope="col" class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-3 text-center text-[10px] sm:text-xs lg:text-xs font-medium text-black uppercase tracking-wider">
                                    <span class="hidden sm:inline">Actual Count</span>
                                    <span class="sm:hidden">Actual</span>
                                </th>
                                <th scope="col" class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-3 text-center text-[10px] sm:text-xs lg:text-xs font-medium text-black uppercase tracking-wider">
                                    <span class="hidden sm:inline">Difference</span>
                                    <span class="sm:hidden">Diff</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200" id="tableBody">
                            <?php foreach ($products as $product): ?>
                                <tr class="stock-taking-row hover:bg-gray-50 transition-colors" data-category="<?= htmlspecialchars($product['category'] ?? '') ?>" data-product-id="<?= $product['id'] ?>" data-sold="<?= $product['total_sold'] ?>" data-price="<?= htmlspecialchars($product['price'] ?? 0) ?>" data-buying-price="<?= htmlspecialchars($product['buying_price'] ?? 0) ?>" data-quantity="<?= $product['quantity'] ?>">
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
                                        <span class="expected-stock"><?= $product['opening_stock'] ?></span>
                                        <span class="expected-closing-stock" style="display: none;"><?= $product['quantity'] ?></span>
                                    </td>
                                    <td class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-4 whitespace-nowrap text-center text-[10px] sm:text-xs lg:text-sm text-black-500 received-stock-cell" style="display: none;">
                                        <span class="received-stock"><?= $product['received_stock'] ?></span>
                                    
                                    </td>
                                    <td class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-4 whitespace-nowrap text-center">
                                        <input type="number" min="0" step="any" class="actual-quantity quantity-input px-1 sm:px-2 py-0.5 sm:py-1 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-teal-500 text-center text-[10px] sm:text-xs" placeholder="0" value="">
                                    </td>
                                    <td class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-4 whitespace-nowrap text-center text-[10px] sm:text-xs lg:text-sm font-semibold">
                                        <span class="count-difference text-gray-400">—</span>
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

                <!-- Items Being Counted - Real-time List -->
                <div id="itemsBeingCounted" class="hidden mt-6 bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
                    <div class="px-6 py-4 bg-gradient-to-r from-blue-50 to-teal-50 border-b border-gray-200">
                        <div class="flex items-center justify-between">
                            <h3 class="text-lg font-semibold text-teal-800 flex items-center gap-2">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                </svg>
                                Items Being Counted
                            </h3>
                            <span id="countedItemsCount" class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-teal-100 text-teal-800">
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
                                            <span class="hidden sm:inline">Expected</span>
                                            <span class="sm:hidden">Exp</span>
                                        </th>
                                        <th scope="col" class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-3 text-center text-[10px] sm:text-xs lg:text-xs font-medium text-black uppercase tracking-wider">
                                            <span class="hidden sm:inline">Actual</span>
                                            <span class="sm:hidden">Act</span>
                                        </th>
                                        <th scope="col" class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-3 text-center text-[10px] sm:text-xs lg:text-xs font-medium text-black uppercase tracking-wider">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody id="itemsBeingCountedBody" class="bg-white divide-y divide-gray-200">
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="px-2 sm:px-4 lg:px-6 py-2 sm:py-3 lg:py-4 bg-gray-50 border-t border-gray-200">
                        <div class="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-2 sm:gap-4">
                            <div class="flex items-center gap-2 sm:gap-4 flex-wrap">
                                <span class="text-[10px] sm:text-xs lg:text-sm text-gray-600">Total Items:</span>
                                <span id="totalDiffItems" class="text-xs sm:text-base lg:text-lg font-semibold text-gray-900">0</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Submit Button -->
                <div class="mt-6 flex justify-end">
                    <button id="submitStockTakingBtn" class="inline-flex items-center justify-center rounded-lg text-sm font-semibold transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 bg-blue-100/80 text-blue-800 hover:bg-blue-200/90 border border-blue-200/60 shadow-sm h-12 px-10 backdrop-blur-sm">
                        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                        </svg>
                        Submit Stock Taking
                    </button>
                </div>
            </div>
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
        const submitStockTakingBtn = document.getElementById('submitStockTakingBtn');

        const STOCK_TAKING_DRAFT_PREFIX = 'stock_taking_qty_draft_';
        function getStockTakingDraftKey() {
            const serverDate = document.body.dataset.serverDate || new Date().toISOString().slice(0, 10);
            const stockType = document.querySelector('input[name="stockType"]:checked')?.value || 'closing';
            return STOCK_TAKING_DRAFT_PREFIX + serverDate + '_' + stockType;
        }
        function collectActualQuantitiesMap() {
            const map = {};
            document.querySelectorAll('.stock-taking-row').forEach(row => {
                const id = row.dataset.productId;
                const input = row.querySelector('.actual-quantity');
                if (!id || !input) return;
                const v = input.value.trim();
                if (v !== '') map[id] = v;
            });
            return map;
        }
        let stockTakingDraftSaveTimer = null;
        /** After a successful submit, skip draft save on beforeunload (it would re-persist cleared storage from DOM). */
        let suppressStockTakingDraftSave = false;
        function saveStockTakingDraftToStorage() {
            try {
                const map = collectActualQuantitiesMap();
                const key = getStockTakingDraftKey();
                if (Object.keys(map).length === 0) {
                    localStorage.removeItem(key);
                } else {
                    localStorage.setItem(key, JSON.stringify(map));
                }
            } catch (e) { /* ignore quota / private mode */ }
        }
        function scheduleSaveStockTakingDraft() {
            clearTimeout(stockTakingDraftSaveTimer);
            stockTakingDraftSaveTimer = setTimeout(saveStockTakingDraftToStorage, 250);
        }
        function applyStockTakingDraftFromStorage() {
            const key = getStockTakingDraftKey();
            let map = {};
            try {
                const raw = localStorage.getItem(key);
                if (raw) map = JSON.parse(raw) || {};
            } catch (e) { map = {}; }
            document.querySelectorAll('.stock-taking-row').forEach(row => {
                const id = row.dataset.productId;
                const input = row.querySelector('.actual-quantity');
                if (!input) return;
                if (map[id] !== undefined && map[id] !== null && String(map[id]).trim() !== '') {
                    input.value = String(map[id]);
                } else {
                    input.value = '';
                }
            });
            updateItemsBeingCounted();
            document.querySelectorAll('.stock-taking-row').forEach(updateCountDifference);
        }
        function clearCurrentStockTakingDraft() {
            try {
                localStorage.removeItem(getStockTakingDraftKey());
            } catch (e) { /* ignore */ }
        }
        function resetStockTakingAfterSuccessfulSubmit() {
            suppressStockTakingDraftSave = true;
            clearCurrentStockTakingDraft();
            document.querySelectorAll('.stock-taking-row .actual-quantity').forEach(input => {
                input.value = '';
            });
            document.querySelectorAll('.stock-taking-row').forEach(updateCountDifference);
            updateItemsBeingCounted();
        }

        function getExpectedForRow(row) {
            const stockType = document.querySelector('input[name="stockType"]:checked')?.value || 'closing';
            if (stockType === 'opening') {
                return parseQty(row.querySelector('.expected-stock')?.textContent) || 0;
            }
            return parseQty(row.querySelector('.expected-closing-stock')?.textContent) || parseQty(row.dataset.quantity) || 0;
        }

        function parseQty(value) {
            if (value === null || value === undefined) return NaN;
            const s = String(value).trim().replace(',', '.');
            if (s === '') return NaN;
            const n = parseFloat(s);
            return Number.isFinite(n) ? n : NaN;
        }

        function formatQty(n) {
            if (!Number.isFinite(n)) return '0';
            const rounded = Math.round(n * 10000) / 10000;
            return Object.is(rounded, -0) ? '0' : String(rounded);
        }

        function updateCountDifference(row) {
            if (!row) return;
            const diffEl = row.querySelector('.count-difference');
            const actualInput = row.querySelector('.actual-quantity');
            if (!diffEl || !actualInput) return;
            const raw = actualInput.value.trim();
            if (raw === '') {
                diffEl.textContent = '—';
                diffEl.className = 'count-difference text-gray-400';
                return;
            }
            const actual = parseQty(raw);
            if (!Number.isFinite(actual)) {
                diffEl.textContent = '—';
                diffEl.className = 'count-difference text-gray-400';
                return;
            }
            const expected = getExpectedForRow(row);
            const difference = actual - expected;
            if (difference > 0) {
                diffEl.textContent = '+' + formatQty(difference);
                diffEl.className = 'count-difference text-green-600';
            } else if (difference < 0) {
                diffEl.textContent = formatQty(difference);
                diffEl.className = 'count-difference text-red-600';
            } else {
                diffEl.textContent = '0';
                diffEl.className = 'count-difference text-gray-700';
            }
        }

        window.addEventListener('beforeunload', () => {
            clearTimeout(stockTakingDraftSaveTimer);
            if (suppressStockTakingDraftSave) return;
            saveStockTakingDraftToStorage();
        });

        // Initialize
        showPage(currentPage);
        updateBulkActionsVisibility();

        // Search and filter functionality
        searchInput.addEventListener('input', (e) => {
            filterRows(e.target.value);
        });

        categoryFilter.addEventListener('change', () => {
            filterRows(searchInput.value);
        });

        // View All button functionality
        document.getElementById('viewAllBtn').addEventListener('click', () => {
            categoryFilter.value = '';
            searchInput.value = '';
            filterRows('');
            // Show all rows without pagination
            showAllRows();
        });

        function filterRows(searchTerm) {
            const selectedCategory = categoryFilter.value;
            rows = allRows.filter(row => {
                const productName = row.children[2].textContent.toLowerCase();
                const productCategory = row.dataset.category || '';
                const matchesSearch = productName.includes(searchTerm.toLowerCase());
                const matchesCategory = !selectedCategory || productCategory === selectedCategory;
                return matchesSearch && matchesCategory;
            });
            currentPage = 1;
            
            // If no filters are applied, show all rows
            if (!searchTerm && !selectedCategory) {
                showAllRows();
            } else {
                showPage(currentPage);
            }
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
            
            // Update both mobile and desktop page numbers
            const pageNumberMobile = document.getElementById('pageNumber');
            const pageNumberDesktop = document.getElementById('pageNumberDesktop');
            if (pageNumberMobile) pageNumberMobile.textContent = `Showing all ${rows.length} items`;
            if (pageNumberDesktop) pageNumberDesktop.textContent = `Showing all ${rows.length} items`;
            
            // Clear both mobile and desktop page inputs
            const pageInputMobile = document.getElementById('pageInput');
            const pageInputDesktop = document.getElementById('pageInputDesktop');
            if (pageInputMobile) pageInputMobile.value = '';
            if (pageInputDesktop) pageInputDesktop.value = '';
            
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
                let aValue, bValue;
                    const stockType = document.querySelector('input[name="stockType"]:checked').value;
                
                if (columnIndex === 3 && stockType === 'closing') {
                    // For closing stock, column 3 shows expected closing stock
                    aValue = parseInt(a.querySelector('.expected-closing-stock')?.textContent) || parseInt(a.dataset.quantity) || 0;
                    bValue = parseInt(b.querySelector('.expected-closing-stock')?.textContent) || parseInt(b.dataset.quantity) || 0;
                } else {
                    aValue = a.children[columnIndex].textContent.trim();
                    bValue = b.children[columnIndex].textContent.trim();
                    if (isNumeric) {
                        aValue = parseFloat(aValue);
                        bValue = parseFloat(bValue);
                    } else {
                        aValue = aValue.toLowerCase();
                        bValue = bValue.toLowerCase();
                    }
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



        // Bulk actions
        document.getElementById('applyBulkBtn').addEventListener('click', () => {
            const bulkQuantity = document.getElementById('bulkQuantity').value;
            
            const checkedBoxes = document.querySelectorAll('.product-checkbox:checked');
            checkedBoxes.forEach(checkbox => {
                const row = checkbox.closest('tr');
                const quantityInput = row.querySelector('.actual-quantity');
                
                if (bulkQuantity) {
                    quantityInput.value = bulkQuantity;
                }
            });
            updateItemsBeingCounted();
            document.querySelectorAll('.stock-taking-row').forEach(updateCountDifference);
            saveStockTakingDraftToStorage();
        });

        // Update items being counted when quantity changes; auto-save draft for page reload / return visit
        tableBody.addEventListener('input', (e) => {
            if (e.target.classList.contains('actual-quantity')) {
                updateCountDifference(e.target.closest('tr'));
                updateItemsBeingCounted();
                scheduleSaveStockTakingDraft();
            }
        });

        // Real-time list of counted items
        function updateItemsBeingCounted() {
            const items = [];
            const rows = document.querySelectorAll('.stock-taking-row');
            const stockType = document.querySelector('input[name="stockType"]:checked').value;
            let totalShortAmount = 0;
            let totalOverAmount = 0;
            rows.forEach(row => {
                const productName = row.children[2].textContent.trim();
                const openingStock = parseQty(row.querySelector('.expected-stock').textContent) || 0;
                const actualQuantityInput = row.querySelector('.actual-quantity');
                const actualQuantityRaw = actualQuantityInput ? actualQuantityInput.value.trim() : '';
                const hasActualQuantity = actualQuantityRaw !== '';
                const actualQuantity = hasActualQuantity ? (parseQty(actualQuantityRaw) || 0) : 0;
                const unitPrice = parseFloat(row.dataset.price) || 0;
                
                let expected;
                if (stockType === 'opening') {
                    expected = openingStock;
                } else {
                    expected = parseQty(row.querySelector('.expected-closing-stock')?.textContent) || parseQty(row.dataset.quantity) || 0;
                }
                
                if (hasActualQuantity) {
                    const difference = actualQuantity - expected;
                    const amountDifference = difference * unitPrice;
                    if (amountDifference < 0) {
                        totalShortAmount += Math.abs(amountDifference);
                    } else if (amountDifference > 0) {
                        totalOverAmount += amountDifference;
                    }
                    items.push({ 
                        productName, 
                        expected, 
                        actualQuantity, 
                        productId: row.dataset.productId,
                        difference,
                        amountDifference
                    });
                }
            });

            const container = document.getElementById('itemsBeingCounted');
            const tbody = document.getElementById('itemsBeingCountedBody');
            const countedItemsCount = document.getElementById('countedItemsCount');

            const totalDiffItems = document.getElementById('totalDiffItems');

            if (items.length === 0) {
                container.classList.add('hidden');
                countedItemsCount.textContent = '0 items';
                totalDiffItems.textContent = '0';
                return;
            }

            container.classList.remove('hidden');
            countedItemsCount.textContent = `${items.length} item${items.length !== 1 ? 's' : ''}`;
            totalDiffItems.textContent = items.length;

            tbody.innerHTML = items.map((it, index) => `
                <tr class="hover:bg-gray-50 transition-colors" data-product-id="${it.productId}">
                    <td class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-4 text-[10px] sm:text-xs lg:text-sm font-medium text-gray-900 break-words">${it.productName}</td>
                    <td class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-4 whitespace-nowrap text-center text-[10px] sm:text-xs lg:text-sm text-gray-700">${formatQty(it.expected)}</td>
                    <td class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-4 whitespace-nowrap text-center text-[10px] sm:text-xs lg:text-sm text-gray-700">${formatQty(it.actualQuantity)}</td>
                    <td class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-4 whitespace-nowrap text-center text-[10px] sm:text-xs lg:text-sm">
                        <button onclick="removeItemFromCounted('${it.productId}')" class="inline-flex items-center px-2 py-1 text-xs font-medium text-red-600 hover:text-red-800 hover:bg-red-50 rounded-md transition-colors" title="Remove from list">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                            </svg>
                            <span class="ml-1 hidden sm:inline">Remove</span>
                        </button>
                    </td>
                </tr>
            `).join('');
        }

        // Function to remove item from counted list
        function removeItemFromCounted(productId) {
            // Find the row in the main table and clear the actual quantity input
            const mainTableRow = document.querySelector(`tr[data-product-id="${productId}"]`);
            if (mainTableRow) {
                const actualQuantityInput = mainTableRow.querySelector('.actual-quantity');
                if (actualQuantityInput) {
                    actualQuantityInput.value = '';
                }
                updateCountDifference(mainTableRow);
            }
            
            // Update the items being counted list
            updateItemsBeingCounted();
            saveStockTakingDraftToStorage();
        }

        // Submit stock taking
        submitStockTakingBtn.addEventListener('click', async () => {
            const items = [];
            const rows = document.querySelectorAll('.stock-taking-row');
            
            // Get selected stock type
            const stockType = document.querySelector('input[name="stockType"]:checked').value;
            
            rows.forEach(row => {
                const actualQuantityInput = row.querySelector('.actual-quantity');
                const productId = row.dataset.productId;
                const raw = actualQuantityInput ? actualQuantityInput.value.trim() : '';
                if (raw === '') return;
                
                const actualQuantity = parseQty(raw);
                if (!Number.isFinite(actualQuantity)) return;
                
                items.push({
                    product_id: productId,
                    actual_quantity: actualQuantity
                });
            });
            
            if (items.length === 0) {
                showToast('Please enter quantities for at least one product', 'error');
                return;
            }
            
            try {
                // Show premium loading overlay
                showLoadingOverlay();
                
                // Disable submit button to prevent double submission
                submitStockTakingBtn.disabled = true;
                submitStockTakingBtn.innerHTML = `
                    <div class="flex items-center justify-center">
                        <div class="w-5 h-5 mr-2 border-2 border-blue-800 border-t-transparent rounded-full animate-spin"></div>
                        Processing...
                    </div>
                `;
                
                // Small delay to show the loader animation
                await new Promise(resolve => setTimeout(resolve, 500));
                
                // Create form data for PDF download
                const formData = new FormData();
                formData.append('stock_taking_data', JSON.stringify({ 
                    items: items,
                    stock_type: stockType
                }));
                
                // Fetch PDF as blob for reliable download
                const pdfResponse = await fetch('stock_taking.php', {
                    method: 'POST',
                    body: formData
                });
                
                if (!pdfResponse.ok) {
                    throw new Error('Failed to generate PDF');
                }
                
                // Get PDF as blob
                const blob = await pdfResponse.blob();
                
                // Create download link and trigger download
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = stockType === 'opening' 
                    ? 'Current_Stock_Inventory_Report_' + new Date().toISOString().slice(0,19).replace(/:/g, '-') + '.pdf'
                    : 'Closing_Stock_Report_' + new Date().toISOString().slice(0,19).replace(/:/g, '-') + '.pdf';
                document.body.appendChild(a);
                a.click();
                
                // Cleanup
                setTimeout(() => {
                    window.URL.revokeObjectURL(url);
                    if (a.parentNode) {
                        a.parentNode.removeChild(a);
                    }
                }, 100);
                
                // Close loader after PDF generation starts
                setTimeout(() => {
                    hideLoadingOverlay();
                    
                    // Re-enable submit button
                    submitStockTakingBtn.disabled = false;
                    submitStockTakingBtn.innerHTML = `
                        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                        </svg>
                        Submit Stock Taking
                    `;
                
                    // Show success message
                    let successMessage = 'Stock taking completed successfully! PDF report is being downloaded.';
                    
                    if (stockType === 'opening') {
                        successMessage = 'Opening stock recorded successfully! Current stock inventory report is being downloaded.';
                    } else if (stockType === 'closing') {
                        const tomorrow = new Date();
                        tomorrow.setDate(tomorrow.getDate() + 1);
                        const tomorrowStr = tomorrow.toLocaleDateString();
                        successMessage += ` Opening stock for ${tomorrowStr} has been automatically set from today's closing stock. Sold quantities have been reset for the new day.`;
                    }
                    
                    showToast(successMessage, 'success');
                    
                    resetStockTakingAfterSuccessfulSubmit();
                    
                    // Refresh page after PDF download completes
                    setTimeout(() => {
                        location.reload();
                    }, 3000);
                }, 2000);
                
            } catch (error) {
                console.error('Error:', error);
                hideLoadingOverlay();
                showToast('Failed to submit stock taking', 'error');
                
                // Re-enable submit button
                submitStockTakingBtn.disabled = false;
                submitStockTakingBtn.innerHTML = `
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                    </svg>
                    Submit Stock Taking
                `;
            }
        });
        
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

        // Toast notification function
        function showToast(message, type = 'info') {
            const host = document.getElementById('stockToastHost');
            if (!host) return;

            const icons = {
                success: '<i class="fas fa-check-circle mt-0.5 flex-shrink-0"></i>',
                error: '<i class="fas fa-exclamation-circle mt-0.5 flex-shrink-0"></i>',
                info: '<i class="fas fa-info-circle mt-0.5 flex-shrink-0"></i>'
            };

            const toast = document.createElement('div');
            toast.className = `page-toast page-toast--${type} toast-notification`;
            toast.innerHTML = `${icons[type] || icons.info}<span>${message}</span>`;
            host.appendChild(toast);

            setTimeout(() => {
                toast.style.opacity = '0';
                setTimeout(() => toast.remove(), 300);
            }, 3500);
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('input[name="stockType"]').forEach(radio => {
                radio.addEventListener('change', () => { updateStockTypeDisplay(); });
            });
            updateStockTypeDisplay();
        });
        
        function updateStockTypeDisplay() {
            const stockType = document.querySelector('input[name="stockType"]:checked').value;
            const openingStockHeader = document.querySelector('th:nth-child(4) div');
            const receivedStockColumn = document.querySelector('.received-stock-column');
            const receivedStockCells = document.querySelectorAll('.received-stock-cell');
            
            if (stockType === 'opening') {
                openingStockHeader.innerHTML = '<span class="hidden sm:inline">Expected Opening</span><span class="sm:hidden">Opening</span>';
                // Hide received stock column for opening stock
                receivedStockColumn.style.display = 'none';
                receivedStockCells.forEach(cell => cell.style.display = 'none');
                
                // Show expected stock, hide expected closing stock
                const rows = document.querySelectorAll('.stock-taking-row');
                rows.forEach(row => {
                    const expectedStockSpan = row.querySelector('.expected-stock');
                    const expectedClosingStockSpan = row.querySelector('.expected-closing-stock');
                    expectedStockSpan.style.display = 'inline';
                    expectedClosingStockSpan.style.display = 'none';
                });
            } else {
                openingStockHeader.innerHTML = '<span class="hidden sm:inline">Expected Closing Stock</span><span class="sm:hidden">Closing</span>';
                // Hide received stock column for closing stock
                receivedStockColumn.style.display = 'none';
                receivedStockCells.forEach(cell => cell.style.display = 'none');
                
                // For closing stock, show closing stock (current quantity from inventory) instead of opening stock
                const rows = document.querySelectorAll('.stock-taking-row');
                rows.forEach(row => {
                    const expectedStockSpan = row.querySelector('.expected-stock');
                    const expectedClosingStockSpan = row.querySelector('.expected-closing-stock');
                    expectedStockSpan.style.display = 'none';
                    expectedClosingStockSpan.style.display = 'inline';
                });
            }
            applyStockTakingDraftFromStorage();
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
    
    <!-- Premium Loading Overlay -->
    <div id="loadingOverlay" class="loading-overlay">
        <div class="loading-container">
            <div class="spinner"></div>
            <h3 class="text-xl font-semibold text-gray-800 mb-2">Processing Stock Taking</h3>
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
</body>
</html>
