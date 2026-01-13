<?php
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

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Get user email from user database
$userDb = new PDO('sqlite:../user.db');
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

// Get receiving data from POST
$receivingData = json_decode(file_get_contents('php://input'), true);

if (!$receivingData || !isset($receivingData['items']) || !is_array($receivingData['items'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid data']);
    exit();
}

try {
    // Connect to database to fetch product details
    $db = new PDO('sqlite:../pos.db');
    
    // Fetch product details for all items
    $receivingItems = [];
    foreach ($receivingData['items'] as $item) {
        if (!empty($item['product_id']) && !empty($item['quantity'])) {
            $productId = $item['product_id'];
            $quantity = intval($item['quantity']);
            
            // Get product info
            $stmt = $db->prepare("SELECT name, price, buying_price FROM products WHERE id = ?");
            $stmt->execute([$productId]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($product) {
                $receivingItems[] = [
                    'product_name' => $product['name'],
                    'added_quantity' => $quantity,
                    'price' => $product['price'],
                    'buying_price' => $product['buying_price'],
                    'total_value' => $quantity * $product['price'],
                    'total_cost' => $quantity * $product['buying_price']
                ];
            }
        }
    }
    
    // If no items found, return error
    if (empty($receivingItems)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No valid items found']);
        exit();
    }
    
    // Include FPDF library
    require('../fpdf/fpdf.php');
    
    // Create new PDF instance for email
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
    
    // Generate PDF content as string for email attachment
    $pdfContent = $pdf->Output('S');
    $fileName = 'Stock_Receiving_Report_' . date('Y-m-d_H-i-s') . '.pdf';
    
    // Send email with PDF attachment
    $mail = new PHPMailer(true);
    
    // Server settings
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'sourcecodedev6@gmail.com';
    $mail->Password = 'irfvlutirghpfbkl';
    $mail->SMTPSecure = 'tls';
    $mail->Port = 587;
    
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
    $mail->Subject = 'Stock Receiving Report - ' . date('Y-m-d H:i:s');
    $mail->Body = '
        <h2>Stock Receiving Report</h2>
        <p><strong>Date:</strong> ' . date('Y-m-d H:i:s') . '</p>
        <p><strong>Total Items Received:</strong> ' . $totalItems . '</p>
        <p><strong>Total Restock Value:</strong> N$' . number_format($totalValue, 2) . '</p>
        <br>
        <p>Please find the detailed report attached.</p>
        <br>
        <p>Best regards,<br>POS System</p>
    ';
    
    // Attach PDF
    $mail->addStringAttachment($pdfContent, $fileName, 'base64', 'application/pdf');
    
    $mail->send();
    
    echo json_encode(['success' => true, 'message' => 'Email sent successfully']);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Email sending failed: ' . $e->getMessage()]);
}
?>
