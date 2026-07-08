<?php
/**
 * Send Receiving Email with Record ID
 * 
 * This script sends email reports for receiving records stored in the database.
 * It tracks email status and supports retry functionality for failed emails.
 */

// Include PHPMailer classes
require_once '../resetpass/PHPMailer/src/PHPMailer.php';
require_once '../resetpass/PHPMailer/src/SMTP.php';
require_once '../resetpass/PHPMailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

session_start();

// Set timezone
date_default_timezone_set('Africa/Harare');

// Set JSON response header
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Get request data
$requestData = json_decode(file_get_contents('php://input'), true);

// Validate input - support both record_id and items (for backwards compatibility)
$recordId = $requestData['record_id'] ?? null;
$items = $requestData['items'] ?? null;
$receivingDate = $requestData['receiving_date'] ?? null;

if (!$recordId && (!$items || !is_array($items))) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid data - record_id or items required']);
    exit();
}

try {
    // Connect to databases
    $db = new PDO('sqlite:../pos.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $userDb = new PDO('sqlite:../user.db');
    $userDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
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
        error_log("Could not fetch user email: " . $e->getMessage());
    }
    
    $receivingItems = [];
    $totalItems = 0;
    $totalValue = 0;
    $totalCost = 0;
    $reportDate = date('Y-m-d H:i:s');
    
    // If record_id is provided, fetch data from database
    if ($recordId) {
        // Fetch receiving record
        $recordStmt = $db->prepare("SELECT * FROM receiving_records WHERE id = ?");
        $recordStmt->execute([$recordId]);
        $record = $recordStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$record) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Receiving record not found']);
            exit();
        }
        
        // Check if email was already sent
        if ($record['email_status'] === 'sent') {
            echo json_encode(['success' => true, 'message' => 'Email already sent', 'already_sent' => true]);
            exit();
        }
        
        // Fetch receiving items
        $itemsStmt = $db->prepare("SELECT * FROM receiving_items WHERE record_id = ?");
        $itemsStmt->execute([$recordId]);
        $receivingItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Update totals from record
        $totalItems = $record['total_quantity'];
        $totalValue = $record['total_value'];
        $totalCost = $record['total_cost'];
        $reportDate = $record['receiving_date'];
        
        // Increment email attempt counter
        $updateAttemptStmt = $db->prepare("
            UPDATE receiving_records 
            SET email_attempts = email_attempts + 1 
            WHERE id = ?
        ");
        $updateAttemptStmt->execute([$recordId]);
        
    } else {
        // Legacy mode: items provided directly (for backwards compatibility)
        foreach ($items as $item) {
            if (!empty($item['product_id']) && !empty($item['quantity'])) {
                $productId = $item['product_id'];
                $quantity = intval($item['quantity']);
                
                // Get product info
                $stmt = $db->prepare("SELECT name, price, buying_price FROM products WHERE id = ?");
                $stmt->execute([$productId]);
                $product = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($product) {
                    $itemValue = $quantity * $product['price'];
                    $itemCost = $quantity * ($product['buying_price'] ?? $product['price']);
                    
                    $receivingItems[] = [
                        'product_name' => $product['name'],
                        'quantity_added' => $quantity,
                        'unit_price' => $product['price'],
                        'buying_price' => $product['buying_price'] ?? $product['price'],
                        'total_value' => $itemValue,
                        'total_cost' => $itemCost
                    ];
                    
                    $totalItems += $quantity;
                    $totalValue += $itemValue;
                    $totalCost += $itemCost;
                }
            }
        }
        
        if ($receivingDate) {
            $reportDate = $receivingDate;
        }
    }
    
    // If no items found, return error
    if (empty($receivingItems)) {
        updateEmailStatus($db, $recordId, 'failed', 'No valid items found');
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No valid items found']);
        exit();
    }
    
    // Include FPDF library
    if (!file_exists('../fpdf/fpdf.php')) {
        updateEmailStatus($db, $recordId, 'failed', 'FPDF library not found');
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'FPDF library not found']);
        exit();
    }
    require('../fpdf/fpdf.php');
    
    // Create new PDF instance for email
    class ReceivingPDF extends FPDF {
        private $reportDate;
        
        function setReportDate($date) {
            $this->reportDate = $date;
        }
        
        function Header() {
            $this->SetFont('Arial', 'B', 15);
            $this->Cell(0, 10, 'Stock Receiving Report', 0, 1, 'C');
            $this->SetFont('Arial', '', 12);
            $this->Cell(0, 10, 'Report Date: ' . ($this->reportDate ?? date('Y-m-d H:i:s')), 0, 1, 'C');
            $this->Cell(0, 6, 'Generated: ' . date('Y-m-d H:i:s'), 0, 1, 'C');
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
    $pdf->setReportDate($reportDate);
    $pdf->AliasNbPages();
    $pdf->AddPage();
    $pdf->SetFont('Arial', '', 9);
    
    // Add data to PDF
    foreach ($receivingItems as $item) {
        $productName = $item['product_name'];
        $addedQty = $item['quantity_added'] ?? $item['added_quantity'] ?? 0;
        $unitPrice = $item['unit_price'] ?? $item['price'] ?? 0;
        $itemTotal = $item['total_value'] ?? ($addedQty * $unitPrice);
        
        $pdf->Cell(80, 8, substr($productName, 0, 40), 1);
        $pdf->Cell(35, 8, '+' . $addedQty, 1);
        $pdf->Cell(35, 8, 'N$' . number_format($unitPrice, 2), 1);
        $pdf->Cell(35, 8, 'N$' . number_format($itemTotal, 2), 1);
        $pdf->Ln();
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
    $pdf->Cell(100, 8, 'Total Buying Cost:', 0, 0, 'L');
    $pdf->Cell(50, 8, 'N$' . number_format($totalCost, 2), 0, 1, 'L');
    
    // Generate PDF content as string for email attachment
    $pdfContent = $pdf->Output('S');
    $fileName = 'Stock_Receiving_Report_' . date('Y-m-d_H-i-s') . '.pdf';
    
    // Send email with PDF attachment
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'sourcecodedev6@gmail.com';
        $mail->Password = 'irfvlutirghpfbkl';
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;
        $mail->Timeout = 30; // 30 second timeout
        
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
        $mail->Subject = 'Stock Receiving Report - ' . date('Y-m-d H:i:s', strtotime($reportDate));
        $mail->Body = '
            <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
                <h2 style="color: #0d9488; border-bottom: 2px solid #0d9488; padding-bottom: 10px;">Stock Receiving Report</h2>
                <p><strong>Report Date:</strong> ' . htmlspecialchars($reportDate) . '</p>
                <p><strong>Processed By:</strong> ' . htmlspecialchars($_SESSION['username']) . '</p>
                <hr style="border: 1px solid #e5e7eb; margin: 20px 0;">
                <table style="width: 100%; border-collapse: collapse;">
                    <tr>
                        <td style="padding: 10px; background: #f3f4f6;"><strong>Total Items Received:</strong></td>
                        <td style="padding: 10px; background: #f3f4f6; text-align: right;">' . $totalItems . '</td>
                    </tr>
                    <tr>
                        <td style="padding: 10px;"><strong>Total Restock Value:</strong></td>
                        <td style="padding: 10px; text-align: right; color: #0d9488; font-weight: bold;">N$' . number_format($totalValue, 2) . '</td>
                    </tr>
                    <tr>
                        <td style="padding: 10px; background: #f3f4f6;"><strong>Total Buying Cost:</strong></td>
                        <td style="padding: 10px; background: #f3f4f6; text-align: right;">N$' . number_format($totalCost, 2) . '</td>
                    </tr>
                </table>
                <hr style="border: 1px solid #e5e7eb; margin: 20px 0;">
                <p>Please find the detailed report attached.</p>
                <p style="color: #6b7280; font-size: 12px; margin-top: 30px;">
                    This is an automated message from the POS System.<br>
                    Generated on ' . date('Y-m-d H:i:s') . '
                </p>
            </div>
        ';
        
        $mail->AltBody = "Stock Receiving Report\n\n"
            . "Report Date: $reportDate\n"
            . "Processed By: " . $_SESSION['username'] . "\n\n"
            . "Total Items Received: $totalItems\n"
            . "Total Restock Value: N$" . number_format($totalValue, 2) . "\n"
            . "Total Buying Cost: N$" . number_format($totalCost, 2) . "\n\n"
            . "Please see the attached PDF for details.";
        
        // Attach PDF
        $mail->addStringAttachment($pdfContent, $fileName, 'base64', 'application/pdf');
        
        $mail->send();
        
        // Update email status to sent
        updateEmailStatus($db, $recordId, 'sent', null);
        
        echo json_encode([
            'success' => true, 
            'message' => 'Email sent successfully',
            'recipient' => !empty($userEmail) ? $userEmail : 'info.easystockna@gmail.com'
        ]);
        
    } catch (Exception $e) {
        $errorMessage = $mail->ErrorInfo;
        updateEmailStatus($db, $recordId, 'failed', $errorMessage);
        
        error_log("Email sending failed: " . $errorMessage);
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Email sending failed: ' . $errorMessage]);
    }
    
} catch (PDOException $e) {
    error_log("Database error in send_receiving_email: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log("General error in send_receiving_email: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

/**
 * Update email status in the receiving_records table
 */
function updateEmailStatus($db, $recordId, $status, $errorMessage = null) {
    if (!$recordId) return;
    
    try {
        if ($status === 'sent') {
            $stmt = $db->prepare("
                UPDATE receiving_records 
                SET email_status = ?, email_sent_at = datetime('now'), email_error = NULL 
                WHERE id = ?
            ");
            $stmt->execute([$status, $recordId]);
        } else {
            $stmt = $db->prepare("
                UPDATE receiving_records 
                SET email_status = ?, email_error = ? 
                WHERE id = ?
            ");
            $stmt->execute([$status, $errorMessage, $recordId]);
        }
    } catch (Exception $e) {
        error_log("Failed to update email status: " . $e->getMessage());
    }
}
?>
