<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Set timezone
date_default_timezone_set('Africa/Harare');

// Include the receipt printing libraries
require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/receipt_payment_helper.php';
require_once __DIR__ . '/cashback_receipt_helper.php';
use Mike42\Escpos\Printer;
use Mike42\Escpos\PrintConnectors\WindowsPrintConnector;
use Mike42\Escpos\PrintConnectors\NetworkPrintConnector;

/**
 * Attach lay-bye account context when this order_id is a lay-bye till payment.
 */
function reprintEnrichLaybyeContext(PDO $dbPos, array &$receiptData): void
{
    if (empty($receiptData['order_id'])) {
        return;
    }
    $st = $dbPos->prepare("
        SELECT lp.payment_kind, la.reference AS laybye_reference, la.balance_due, la.plan_frequency,
               la.next_due_date, cr.name AS laybye_creditor_name
        FROM laybye_payments lp
        INNER JOIN laybye_accounts la ON la.id = lp.laybye_id
        LEFT JOIN creditors cr ON cr.id = la.creditor_id
        WHERE lp.order_id = ?
        LIMIT 1
    ");
    $st->execute([(int) $receiptData['order_id']]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return;
    }
    $receiptData['laybye_reference'] = $row['laybye_reference'];
    $receiptData['laybye_payment_kind'] = $row['payment_kind'];
    $receiptData['laybye_balance_due'] = round(floatval($row['balance_due']), 2);
    $receiptData['laybye_plan_frequency'] = $row['plan_frequency'];
    $receiptData['laybye_next_due_date'] = $row['next_due_date'];
    $receiptData['laybye_creditor_name'] = $row['laybye_creditor_name'];
}

/**
 * Normalize a DB line into receipt item format (price = line total).
 * order_items.price is line total; credit_sale_items.price is unit price.
 */
function reprintFormatLineItem(string $name, int $quantity, float $dbPrice, bool $dbPriceIsUnit): ?array
{
    if ($name === '' || $quantity < 1) {
        return null;
    }
    $unitPrice = $dbPriceIsUnit ? $dbPrice : ($quantity > 0 ? $dbPrice / $quantity : $dbPrice);
    $lineTotal = $dbPriceIsUnit ? $dbPrice * $quantity : $dbPrice;
    if ($lineTotal <= 0) {
        return null;
    }
    return [
        'name' => $name,
        'quantity' => $quantity,
        'unit_price' => round($unitPrice, 2),
        'price' => round($lineTotal, 2),
    ];
}

function reprintFormatLineItems(array $items, bool $dbPriceIsUnit): array
{
    $formattedItems = [];
    foreach ($items as $item) {
        $line = reprintFormatLineItem(
            (string) ($item['name'] ?? ''),
            intval($item['quantity'] ?? 0),
            floatval($item['price'] ?? 0),
            $dbPriceIsUnit
        );
        if ($line !== null) {
            $formattedItems[] = $line;
        }
    }
    return $formattedItems;
}

function reprintResolveCashierName(PDO $dbPos, ?string $cashierId, ?int $saleId = null, ?string $paymentStatus = null): string
{
    $cashierName = trim((string) $cashierId);

    if ($saleId !== null && in_array((string) $paymentStatus, ['paid', 'eft', 'partial'], true)) {
        $paymentStmt = $dbPos->prepare('SELECT cashier_id FROM payments WHERE sale_id = ? ORDER BY payment_date DESC LIMIT 1');
        $paymentStmt->execute([$saleId]);
        $paymentCashier = $paymentStmt->fetchColumn();
        if ($paymentCashier !== false && trim((string) $paymentCashier) !== '') {
            $cashierName = trim((string) $paymentCashier);
        }
    }

    return $cashierName !== '' ? $cashierName : 'Unknown';
}

/**
 * Load order line items and payment details for reprint (cash, eft, mixed, cash_back).
 */
function reprintBuildOrderReceiptData(PDO $dbPos, int $orderId, string $saleTypeLower, string $paymentStatus = ''): array
{
    $orderStmt = $dbPos->prepare("
        SELECT id as order_id, total, cash_received, created_at, cashier_id
        FROM orders
        WHERE id = ?
        LIMIT 1
    ");
    $orderStmt->execute([$orderId]);
    $orderData = $orderStmt->fetch(PDO::FETCH_ASSOC);
    if (!$orderData) {
        throw new Exception('Cash transaction not found');
    }

    $itemsQuery = $dbPos->prepare("
        SELECT product_name as name, quantity, price
        FROM order_items
        WHERE order_id = ?
    ");
    $itemsQuery->execute([$orderId]);
    $items = $itemsQuery->fetchAll(PDO::FETCH_ASSOC);
    $formattedItems = reprintFormatLineItems($items, false);

    $total = 0.0;
    foreach ($formattedItems as $item) {
        $total += floatval($item['price']);
    }
    if ($total <= 0) {
        $total = abs(floatval($orderData['total'] ?? 0));
    }

    $cashierName = reprintResolveCashierName($dbPos, $orderData['cashier_id'] ?? null);

    if ($saleTypeLower === 'cash_back') {
        $receiptData = buildCashBackReceiptData([
            'order_id' => $orderId,
            'amount' => $total > 0 ? $total : abs(floatval($orderData['total'] ?? 0)),
            'cashier_username' => $cashierName,
            'description' => 'Cash Back',
            'is_cash_back_copy' => true,
        ]);
        $receiptData['created_at'] = $orderData['created_at'];
        return $receiptData;
    }

    $receiptData = [
        'order_id' => $orderData['order_id'],
        'cash_received' => $orderData['cash_received'] ?? $orderData['total'] ?? $total,
        'items' => $formattedItems,
        'cashier_username' => $cashierName,
        'created_at' => $orderData['created_at'],
        'total' => $total,
        'payment_method' => 'cash',
    ];

    $mixedStmt = $dbPos->prepare("
        SELECT cash_amount, eft_amount, eft_transaction_ref, eft_wallet_provider
        FROM mixed_payments
        WHERE order_id = ?
        LIMIT 1
    ");
    $mixedStmt->execute([$orderId]);
    $mixed = $mixedStmt->fetch(PDO::FETCH_ASSOC);
    if ($mixed) {
        $receiptData['payment_method'] = 'mixed';
        $receiptData['cash_amount'] = floatval($mixed['cash_amount']);
        $receiptData['eft_amount'] = floatval($mixed['eft_amount']);
        $receiptData['transaction_ref'] = $mixed['eft_transaction_ref'] ?? '';
        $receiptData['wallet_provider'] = $mixed['eft_wallet_provider'] ?? '';
        $receiptData['cash_received'] = floatval($mixed['cash_amount']);
        return $receiptData;
    }

    $eftStmt = $dbPos->prepare("
        SELECT amount, wallet_provider, transaction_ref
        FROM eft_payments
        WHERE order_id = ?
        LIMIT 1
    ");
    $eftStmt->execute([$orderId]);
    $eft = $eftStmt->fetch(PDO::FETCH_ASSOC);
    if ($eft && ($saleTypeLower === 'eft' || strtolower($paymentStatus) === 'eft')) {
        $receiptData['payment_method'] = 'e-wallet';
        $receiptData['wallet_provider'] = $eft['wallet_provider'] ?? '';
        $receiptData['transaction_ref'] = $eft['transaction_ref'] ?? '';
        $receiptData['payment_amount'] = floatval($eft['amount'] ?? $total);
        return $receiptData;
    }

    return $receiptData;
}

// Get POST parameters
$transactionId = $_POST['transaction_id'] ?? '';
$saleType = trim((string) ($_POST['sale_type'] ?? ''));
$saleTypeLower = strtolower($saleType);
$paymentStatus = $_POST['payment_status'] ?? '';

if (empty($transactionId) || empty($saleType)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing transaction ID or sale type']);
    exit();
}

try {
    // Connect to databases
    $dbPos = new PDO('sqlite:pos.db');
    $dbInfo = new PDO('sqlite:info.db');

    // Load receipt printing mode (receipt.php vs qzreceipt.php)
    $use_qz_tray = 0;
    try {
        $dbPos->exec("ALTER TABLE product_settings ADD COLUMN use_qz_tray BOOLEAN NOT NULL DEFAULT 0");
    } catch (PDOException $e) {
        // Column already exists
    }
    try {
        $settingRow = $dbPos->query("SELECT use_qz_tray FROM product_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $use_qz_tray = (int)($settingRow['use_qz_tray'] ?? 0);
    } catch (PDOException $e) {
        $use_qz_tray = 0;
    }
    
    // Get business info
    $businessInfo = $dbInfo->query("SELECT * FROM business_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$businessInfo) {
        $businessInfo = [
            'name' => 'POS SOLUTION',
            'location' => 'Your Business Address',
            'phone' => 'Your Phone Number',
            'footer_text' => 'Thank you for your purchase!'
        ];
    }
    
    $receiptData = null;
    
    if ($saleTypeLower === 'credit' || $saleTypeLower === 'paid' || $saleTypeLower === 'partial' || stripos($saleType, 'credit') !== false) {
        // Handle credit sales
        $creditQuery = $dbPos->prepare("
            SELECT 
                cs.id as sale_id,
                cs.creditor_id,
                cs.total_amount,
                cs.paid_amount,
                cs.payment_status,
                cs.due_date,
                cs.created_at,
                cs.cashier_id,
                cr.name as creditor_name,
                GROUP_CONCAT(csi.product_name || ' x' || csi.quantity || ' @ N$' || csi.price, ', ') as products
            FROM credit_sales cs
            LEFT JOIN creditors cr ON cs.creditor_id = cr.id
            LEFT JOIN credit_sale_items csi ON cs.id = csi.sale_id
            WHERE cs.id = :transaction_id
            GROUP BY cs.id
        ");
        $creditQuery->bindParam(':transaction_id', $transactionId);
        $creditQuery->execute();
        $creditData = $creditQuery->fetch(PDO::FETCH_ASSOC);
        
        if (!$creditData) {
            throw new Exception('Credit transaction not found');
        }
        
        // Get items for the credit sale
        $itemsQuery = $dbPos->prepare("
            SELECT product_name as name, quantity, price
            FROM credit_sale_items 
            WHERE sale_id = :sale_id
        ");
        $itemsQuery->bindParam(':sale_id', $transactionId);
        $itemsQuery->execute();
        $items = $itemsQuery->fetchAll(PDO::FETCH_ASSOC);
        
        $formattedItems = reprintFormatLineItems($items, true);
        
        error_log("Credit sale - Formatted " . count($formattedItems) . " items from " . count($items) . " database items for sale_id: $transactionId");
        
        // Check if this was an EFT credit payment
        $eftPaymentQuery = $dbPos->prepare("
            SELECT ep.amount, ep.wallet_provider, ep.transaction_ref, ep.payment_date
            FROM eft_payments ep
            WHERE ep.order_id = :transaction_id
        ");
        $eftPaymentQuery->bindParam(':transaction_id', $transactionId);
        $eftPaymentQuery->execute();
        $eftPayment = $eftPaymentQuery->fetch(PDO::FETCH_ASSOC);
        
        $receiptData = [
            'sale_id' => $creditData['sale_id'],
            'creditor_id' => $creditData['creditor_id'],
            'creditor_name' => $creditData['creditor_name'],
            'total_amount' => $creditData['total_amount'],
            'due_date' => $creditData['due_date'],
            'items' => $formattedItems,
            'cashier_username' => reprintResolveCashierName(
                $dbPos,
                $creditData['cashier_id'] ?? null,
                (int) $creditData['sale_id'],
                $paymentStatus !== '' ? $paymentStatus : ($creditData['payment_status'] ?? null)
            ),
            'created_at' => $creditData['created_at']
        ];
        
        // Add payment method specific data
        if ($eftPayment && ($paymentStatus === 'eft' || stripos($saleType, 'eft') !== false)) {
            $receiptData['payment_method'] = 'e-wallet';
            $receiptData['wallet_provider'] = $eftPayment['wallet_provider'];
            $receiptData['transaction_ref'] = $eftPayment['transaction_ref'];
            $receiptData['payment_amount'] = $eftPayment['amount'];
        } else if ($paymentStatus === 'paid' || $paymentStatus === 'partial') {
            $receiptData['payment_type'] = 'cash';
            $receiptData['cash_received'] = $creditData['paid_amount'];
        }
        
    } else if ($saleTypeLower === 'eft') {
        // Handle EFT sales
        $eftQuery = $dbPos->prepare("
            SELECT 
                o.id as order_id,
                o.total,
                o.created_at,
                o.cashier_id,
                ep.amount as eft_amount,
                ep.wallet_provider,
                ep.transaction_ref,
                GROUP_CONCAT(oi.product_name || ' x' || oi.quantity || ' @ N$' || oi.price, ', ') as products
            FROM orders o
            JOIN eft_payments ep ON o.id = ep.order_id
            LEFT JOIN order_items oi ON o.id = oi.order_id
            WHERE o.id = :transaction_id
            GROUP BY o.id
        ");
        $eftQuery->bindParam(':transaction_id', $transactionId);
        $eftQuery->execute();
        $eftData = $eftQuery->fetch(PDO::FETCH_ASSOC);
        
        if (!$eftData) {
            throw new Exception('EFT transaction not found');
        }
        
        // Get items for the order
        $itemsQuery = $dbPos->prepare("
            SELECT product_name as name, quantity, price
            FROM order_items 
            WHERE order_id = :order_id
        ");
        $itemsQuery->bindParam(':order_id', $transactionId);
        $itemsQuery->execute();
        $items = $itemsQuery->fetchAll(PDO::FETCH_ASSOC);
        
        $formattedItems = reprintFormatLineItems($items, false);
        
        error_log("EFT order - Formatted " . count($formattedItems) . " items from " . count($items) . " database items for order_id: $transactionId");
        
        $receiptData = [
            'order_id' => $eftData['order_id'],
            'payment_method' => 'e-wallet',
            'wallet_provider' => $eftData['wallet_provider'],
            'transaction_ref' => $eftData['transaction_ref'],
            'items' => $formattedItems,
            'cashier_username' => reprintResolveCashierName($dbPos, $eftData['cashier_id'] ?? null),
            'created_at' => $eftData['created_at']
        ];
        reprintEnrichLaybyeContext($dbPos, $receiptData);
        
    } else {
        // Handle cash, cash_back, and mixed orders (lookup by order id — do not exclude EFT rows)
        $receiptData = reprintBuildOrderReceiptData($dbPos, (int) $transactionId, $saleTypeLower, (string) $paymentStatus);
        reprintEnrichLaybyeContext($dbPos, $receiptData);
    }
    
    if (!$receiptData) {
        throw new Exception('Transaction data could not be retrieved');
    }
    
    // Get business info
    $businessInfo = $dbInfo->query("SELECT * FROM business_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$businessInfo) {
        $businessInfo = [
            'name' => 'POS SOLUTION',
            'location' => 'Your Business Address',
            'phone' => 'Your Phone Number',
            'footer_text' => 'Thank you for your purchase!',
            'vat_inclusive' => 'exclusive',
            'vat_rate' => 15.0
        ];
    }
    
    // Add business info to receipt data
    $receiptData['business_name'] = $businessInfo['name'];
    $receiptData['location'] = $businessInfo['location'] ?? '';
    $receiptData['phone'] = $businessInfo['phone'] ?? '';
    $receiptData['footer_text'] = $businessInfo['footer_text'] ?? 'Thank you for your purchase!';
    $receiptData['vat_inclusive'] = $businessInfo['vat_inclusive'] ?? 'exclusive';
    $receiptData['vat_rate'] = floatval($businessInfo['vat_rate'] ?? 15.0);
    
    // Calculate total from items if not already set
    if (!isset($receiptData['total']) && isset($receiptData['items'])) {
        $calculatedTotal = 0;
        foreach ($receiptData['items'] as $item) {
            $calculatedTotal += floatval($item['price']);
        }
        $receiptData['total'] = $calculatedTotal;
    }
    
    // Check if this is an Android request (via User-Agent or explicit parameter)
    $isAndroid = isset($_POST['android_print']) || 
                 (isset($_SERVER['HTTP_USER_AGENT']) && 
                  (stripos($_SERVER['HTTP_USER_AGENT'], 'android') !== false || 
                   stripos($_SERVER['HTTP_USER_AGENT'], 'median') !== false));
    
    // If Android, return JSON data for native printing
    if ($isAndroid) {
        header('Content-Type: application/json');
        
        // Ensure items is a proper array (not empty)
        if (!isset($receiptData['items']) || empty($receiptData['items'])) {
            // Try to get items from database if missing
            if (isset($receiptData['order_id'])) {
                $itemsQuery = $dbPos->prepare("SELECT product_name as name, quantity, price FROM order_items WHERE order_id = ?");
                $itemsQuery->execute([$receiptData['order_id']]);
                $items = $itemsQuery->fetchAll(PDO::FETCH_ASSOC);
                $formattedItems = reprintFormatLineItems($items, false);
                $receiptData['items'] = $formattedItems;
                error_log("Android reprint - Fetched " . count($formattedItems) . " items from order_items for order_id: " . $receiptData['order_id']);
            } elseif (isset($receiptData['sale_id'])) {
                $itemsQuery = $dbPos->prepare("SELECT product_name as name, quantity, price FROM credit_sale_items WHERE sale_id = ?");
                $itemsQuery->execute([$receiptData['sale_id']]);
                $items = $itemsQuery->fetchAll(PDO::FETCH_ASSOC);
                $formattedItems = reprintFormatLineItems($items, true);
                $receiptData['items'] = $formattedItems;
                error_log("Android reprint - Fetched " . count($formattedItems) . " items from credit_sale_items for sale_id: " . $receiptData['sale_id']);
            }
        }
        
        // Ensure items is always an array (even if empty)
        if (!isset($receiptData['items'])) {
            $receiptData['items'] = [];
        }
        
        // Log for debugging
        error_log("Android reprint - Transaction ID: $transactionId, Sale Type: $saleType");
        error_log("Android reprint - Items count: " . count($receiptData['items']));
        if (!empty($receiptData['items'])) {
            error_log("Android reprint - First item: " . json_encode($receiptData['items'][0]));
            error_log("Android reprint - All items: " . json_encode($receiptData['items']));
        } else {
            error_log("WARNING: Android reprint - Items array is EMPTY!");
        }
        error_log("Android reprint - Receipt data keys: " . implode(', ', array_keys($receiptData)));
        
        // Verify items array structure before encoding
        if (isset($receiptData['items']) && is_array($receiptData['items'])) {
            foreach ($receiptData['items'] as $idx => $item) {
                if (!isset($item['name']) || !isset($item['quantity']) || !isset($item['price'])) {
                    error_log("ERROR: Item $idx is missing required fields: " . json_encode($item));
                }
            }
        }
        
        // Ensure print_only flag is set for Android printing
        $receiptData['print_only'] = true;
        
        // Return receipt_data with order_data field for Android interceptor compatibility
        $response = [
            'success' => true,
            'message' => 'Receipt data ready for printing',
            'receipt_data' => $receiptData,
            'order_data' => $receiptData,  // Also include as order_data for Android interceptor
            'transaction_id' => $transactionId,
            'sale_type' => $saleType
        ];
        
        // Log the full response structure
        error_log("Android reprint - Full response structure: " . json_encode($response, JSON_PRETTY_PRINT));
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }

    // Always return receipt_data for client-side printing (QZ Tray / sendToPrinter).
    // Reprints must not print server-side or open the cash drawer.
    $receiptData['print_only'] = true;

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => 'Receipt data ready for printing',
        'receipt_data' => $receiptData,
        'order_data' => $receiptData,
        'transaction_id' => $transactionId,
        'sale_type' => $saleType
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'transaction_id' => $transactionId,
        'sale_type' => $saleType
    ]);
}
?>
