<?php

session_start();

// Set timezone to Central Africa Time (CAT)
date_default_timezone_set('Africa/Harare');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    // Redirect to login page if not logged in
    header("Location: ../");
    exit();
}

// Check activation status
$pdo = new PDO('sqlite:active.db');
$activationStatus = $pdo->query("SELECT COUNT(*) FROM software_keys WHERE is_used = 1")->fetchColumn();
if ($activationStatus == 0) {
    header('Location: settings');
    exit();
}

// Connect to main database
$db = new PDO('sqlite:pos.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    $saleId = $_GET['id'] ?? null;
    
    if (!$saleId) {
        header('Location: credit-book.php');
        exit();
    }

    // Get creditor ID before deletion for redirect
    $stmt = $db->prepare("SELECT creditor_id FROM credit_sales WHERE id = ?");
    $stmt->execute([$saleId]);
    $creditorId = $stmt->fetchColumn();

    if (!$creditorId) {
        header('Location: credit-book.php');
        exit();
    }

    // Check if this is a paid credit sale that should be reset
    $stmt = $db->prepare("SELECT payment_status, paid_amount FROM credit_sales WHERE id = ?");
    $stmt->execute([$saleId]);
    $creditSale = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$creditSale) {
        header('Location: credit-book.php');
        exit();
    }

    $db->beginTransaction();
    
    // Only reset if the sale is currently paid or partially paid
    if ($creditSale['payment_status'] === 'paid' || $creditSale['payment_status'] === 'eft' || $creditSale['payment_status'] === 'paid_mixed' || $creditSale['payment_status'] === 'partial') {
        // Reset the credit sale to unpaid status
        $stmt = $db->prepare("UPDATE credit_sales SET paid_amount = 0, payment_status = 'unpaid' WHERE id = ?");
        $stmt->execute([$saleId]);
        
        // Delete related payment records
        $stmt = $db->prepare("DELETE FROM payments WHERE sale_id = ?");
        $stmt->execute([$saleId]);
        
        $stmt = $db->prepare("DELETE FROM eft_payments WHERE order_id = ?");
        $stmt->execute([$saleId]);
        
        $stmt = $db->prepare("DELETE FROM payment_logs WHERE sale_id = ?");
        $stmt->execute([$saleId]);
    } else {
        // If already unpaid, delete the entire credit sale record
        // Delete payments first
        $db->prepare("DELETE FROM payments WHERE sale_id = ?")
           ->execute([$saleId]);
        
        // Delete sale record
        $db->prepare("DELETE FROM credit_sales WHERE id = ?")
           ->execute([$saleId]);
    }

    $db->commit();

    header("Location: credit-transactions.php?creditor_id=$creditorId");
    exit();

} catch (Exception $e) {
    $db->rollBack();
    header("Content-type: text/html");
    die("Error deleting transaction: " . $e->getMessage());
}
?> 