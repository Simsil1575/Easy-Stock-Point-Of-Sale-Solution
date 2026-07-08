<?php
// Suppress warnings and notices, and clear output buffer
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0);
if (ob_get_level()) ob_clean();

// Check if JavaScript mode is requested (for outputting sendToPrinter function)
if (isset($_GET['js']) && $_GET['js'] === 'true') {
    header('Content-Type: application/javascript');
    // Decide receipt printing mode server-side so all pages using receipt.php?js=true adapt automatically.
    $use_qz_tray = 0;
    try {
        $posDb = new PDO('sqlite:pos.db');
        $posDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        try {
            $posDb->exec("ALTER TABLE product_settings ADD COLUMN use_qz_tray BOOLEAN NOT NULL DEFAULT 0");
        } catch (PDOException $e) {
            // Column already exists, continue
        }
        $row = $posDb->query("SELECT use_qz_tray FROM product_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $use_qz_tray = (int)($row['use_qz_tray'] ?? 0);
    } catch (Exception $e) {
        $use_qz_tray = 0;
    }
    ?>
/**
 * sendToPrinter function - Complete implementation for all receipt types
 * 
 * SUPPORTS ALL RECEIPT TYPES:
 * 1. Regular Receipts (print_only: true)
 * 2. Cash-up Reports (is_cashup_report: true)
 * 3. Balance Receipts (is_balance_receipt: true)
 * 4. Tab Balance Receipts (is_tab_balance_receipt: true)
 * 5. Payment Receipts (is_payment_receipt: true)
 * 6. Tab Sales / Kitchen Tickets (tab_id or table_id)
 * 7. Credit Sales (sale_id)
 * 8. Cash Drawer Only (open_drawer_only: true)
 * 
 * The Android interceptor will automatically catch calls to receipt.php and handle printing.
 * The printer will automatically stop after printing (cut + close + initialize commands).
 */
var useQzTray = <?php echo $use_qz_tray ? 'true' : 'false'; ?>;
function sendToPrinter(receiptData) {
    // Validate receipt data
    if (!receiptData || typeof receiptData !== 'object') {
        console.error('[sendToPrinter] Invalid receipt data provided');
        return Promise.reject({ success: false, message: 'Invalid receipt data' });
    }
    
    // Determine receipt type and set appropriate flags
    var isCashupReport = receiptData.is_cashup_report === true;
    var isBalanceReceipt = receiptData.is_balance_receipt === true;
    var isTabBalanceReceipt = receiptData.is_tab_balance_receipt === true;
    var isPaymentReceipt = receiptData.is_payment_receipt === true;
    var isRefundReceipt = receiptData.is_refund_receipt === true;
    var isTabSale = !!(receiptData.tab_id || receiptData.table_id);
    var isCreditSale = !!receiptData.sale_id;
    var isDrawerOnly = receiptData.open_drawer_only === true;
    
    // Ensure print_only flag is set for regular receipts (not special types)
    if (!receiptData.print_only && !isCashupReport && !isBalanceReceipt && !isTabBalanceReceipt && !isPaymentReceipt && !isRefundReceipt && !isDrawerOnly) {
        receiptData.print_only = true;
    }
    
    // Add business info to receipt data - prioritize receiptData, then window.businessInfo, then businessInfo
    // This ensures business info from info.db is always included
    var businessInfoSource = window.businessInfo || (typeof businessInfo !== 'undefined' ? businessInfo : null);
    var dataWithBusiness = Object.assign({}, receiptData, {
        business_name: receiptData.business_name || (businessInfoSource ? businessInfoSource.business_name : null),
        location: receiptData.location || (businessInfoSource ? businessInfoSource.location : null),
        phone: receiptData.phone || (businessInfoSource ? businessInfoSource.phone : null),
        footer_text: receiptData.footer_text || (businessInfoSource ? businessInfoSource.footer_text : null),
        vat_inclusive: receiptData.vat_inclusive || (businessInfoSource ? businessInfoSource.vat_inclusive : null),
        vat_rate: receiptData.vat_rate || (businessInfoSource ? businessInfoSource.vat_rate : null)
    });
    
    // Log receipt type for debugging
    var receiptType = 'Unknown';
    if (isCashupReport) receiptType = 'Cash-up Report';
    else if (isTabBalanceReceipt) receiptType = 'Tab Balance Receipt';
    else if (isBalanceReceipt) receiptType = 'Balance Receipt';
    else if (isPaymentReceipt) receiptType = 'Payment Receipt';
    else if (isRefundReceipt) receiptType = 'Refund Receipt';
    else if (isTabSale) receiptType = 'Tab Sale / Kitchen Ticket';
    else if (isCreditSale) receiptType = 'Credit Sale';
    else if (isDrawerOnly) receiptType = 'Cash Drawer Only';
    else receiptType = 'Regular Receipt';
    
    console.log('[sendToPrinter] Receipt type: ' + receiptType);
    console.log('[sendToPrinter] Called with data:', JSON.stringify(dataWithBusiness).substring(0, 200));
    
    // QZ Tray printing (desktop/web)
    var ua = (navigator.userAgent || '').toLowerCase();
    var isAndroidLike = ua.indexOf('android') !== -1 || ua.indexOf('median') !== -1;
    if (useQzTray && !isAndroidLike) {
        window.__qzTrayPrintQueue = window.__qzTrayPrintQueue || Promise.resolve();
        window.__qzTrayPrintQueue = window.__qzTrayPrintQueue.then(function() {
            return new Promise(function(resolve) {
                // Route all receipt types through QZ Tray. The server generates the exact ESC/POS bytes in raw mode,
                // so QZ output matches receipt.php even for tabs/kitchen tickets/payment receipts.
                var qzSupported = true;
                if (!qzSupported) {
                    return resolve({
                        success: false,
                        message: 'Unsupported receipt type for QZ Tray',
                        printer_type: 'qz_tray',
                        receipt_type: receiptType
                    });
                }

                var iframe = document.createElement('iframe');
                iframe.style.display = 'none';
                iframe.width = '0';
                iframe.height = '0';

                var encoded = encodeURIComponent(JSON.stringify(dataWithBusiness));
                var timeoutId = null;

                function cleanup(handlerFn, result) {
                    try {
                        if (handlerFn) window.removeEventListener('message', handlerFn);
                    } catch (e) {}
                    if (timeoutId) clearTimeout(timeoutId);
                    try { iframe.remove(); } catch (e) {}
                    resolve(result);
                }

                function onMessage(event) {
                    if (!event || !event.data || event.data.type !== 'printComplete') return;
                    cleanup(onMessage, {
                        success: !!event.data.success,
                        message: event.data.message || 'QZ Tray print completed',
                        printer_type: 'qz_tray',
                        receipt_type: receiptType
                    });
                }

                timeoutId = setTimeout(function() {
                    cleanup(onMessage, { success: false, message: 'QZ Tray print timeout', printer_type: 'qz_tray', receipt_type: receiptType });
                }, 60000);

                window.addEventListener('message', onMessage);
                // Always use absolute path so pages in subfolders (e.g. /manager/*) don't try /manager/qzreceipt.php
                iframe.src = (window.location && window.location.origin ? window.location.origin : '') + '/qzreceipt.php?data=' + encoded;
                document.body.appendChild(iframe);
            });
        });

        return window.__qzTrayPrintQueue;
    }

    // Check for AndroidReceiptHandler (the correct interface name from MainActivity.java)
    if (window.AndroidReceiptHandler && typeof window.AndroidReceiptHandler.handleReceipt === 'function') {
        console.log('[sendToPrinter] Found AndroidReceiptHandler, using native printing');
        try {
            var jsonData = JSON.stringify(dataWithBusiness);
            console.log('[sendToPrinter] Calling handleReceipt with data length:', jsonData.length);
            window.AndroidReceiptHandler.handleReceipt(jsonData);
            console.log('[sendToPrinter] handleReceipt called successfully');
            return Promise.resolve({ 
                success: true, 
                message: 'Printed via Android: ' + receiptType, 
                printer_type: 'android_native',
                receipt_type: receiptType
            });
        } catch (e) {
            console.error('[sendToPrinter] Android print error:', e.message);
            // Fall through to fetch if direct call fails
        }
    } else {
        console.log('[sendToPrinter] AndroidReceiptHandler not found, will use fetch interceptor');
    }
    
    // Use fetch to receipt.php - the JavaScript interceptor in MainActivity.java will catch this
    // and send it to AndroidReceiptHandler.handleReceipt() automatically
    // The printer will automatically stop after printing (receipt.php sends cut + close + initialize commands)
    console.log('[sendToPrinter] Using fetch to receipt.php (will be intercepted by Android if available)');
    var receiptUrl = (window.location && window.location.origin ? window.location.origin : '') + '/receipt.php';
    return fetch(receiptUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(dataWithBusiness)
    }).then(function(r) { 
        return r.json().then(function(result) {
            // The interceptor should have already handled printing
            // But return the result for UI feedback
            console.log('[sendToPrinter] Receipt response:', result);
            
            // If interceptor didn't work, try direct call one more time
            if (window.AndroidReceiptHandler && typeof window.AndroidReceiptHandler.handleReceipt === 'function' && result.order_data) {
                console.log('[sendToPrinter] Interceptor may have missed, trying direct call with order_data');
                try {
                    window.AndroidReceiptHandler.handleReceipt(JSON.stringify(result.order_data));
                } catch (e) {
                    console.error('[sendToPrinter] Fallback direct call failed:', e.message);
                }
            }
            
            // Add receipt type to result for debugging
            result.receipt_type = receiptType;
            return result;
        });
    }).catch(function(error) {
        console.error('[sendToPrinter] Fetch error:', error);
        return { success: false, message: 'Print failed: ' + error.message, receipt_type: receiptType };
    });
}
    <?php
    exit;
}

/* Call this file 'hello-world.php' */
// Ensure JSON header is set early for all responses (but not in JS mode)
if (!isset($_GET['js']) || $_GET['js'] !== 'true') {
    header('Content-Type: application/json');
}

// Load autoloader with error handling
$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Autoload file not found: ' . $autoloadPath]);
    exit;
}

try {
    require $autoloadPath;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to load autoloader: ' . $e->getMessage()]);
    exit;
}

use Mike42\Escpos\Printer;
use Mike42\Escpos\PrintConnectors\WindowsPrintConnector;
use Mike42\Escpos\PrintConnectors\NetworkPrintConnector;
use Mike42\Escpos\PrintConnectors\PrintConnector;

/**
 * Keeps raw ESC/POS bytes available after Printer::close() in raw mode.
 */
final class RawCaptureConnector implements PrintConnector
{
    /** @var string */
    private $buffer = '';

    public function write($data)
    {
        $this->buffer .= (string)$data;
    }

    public function read($len)
    {
        return '';
    }

    public function finalize()
    {
        // Intentionally keep buffer after finalize.
    }

    public function __destruct()
    {
        // Nothing to clean up.
    }

    public function getData()
    {
        return $this->buffer;
    }
}

// Get the order data from the POST request
$orderData = json_decode(file_get_contents('php://input'), true);

if (!$orderData) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No order data received']);
    exit;
}

// When `?raw=1` is requested, capture the exact ESC/POS bytes (instead of printing)
// and return them as base64 for QZ Tray.
$rawMode = isset($_GET['raw']) && $_GET['raw'] === '1';
$rawConnector = null;

// Ensure raw mode returns JSON even on fatal errors (helps QZ debug).
if ($rawMode) {
    register_shutdown_function(function () {
        $err = error_get_last();
        if (!$err) return;
        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
        if (!in_array($err['type'] ?? 0, $fatalTypes, true)) return;
        if (headers_sent()) return;
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Fatal error in receipt.php raw mode',
            'error' => $err
        ]);
    });
}

// Open cash drawer without printing when requested
if (isset($orderData['open_drawer_only']) && $orderData['open_drawer_only']) {
    try {
        // Determine printer to use based on client IP (same logic as below)
        $clientIP = $_SERVER['REMOTE_ADDR'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_CLIENT_IP'] ?? '127.0.0.1';
        $printerName = '';
        $isNetworkPrinter = false;
        if ($clientIP === '127.0.0.1' || $clientIP === '::1' || $clientIP === 'localhost' || $clientIP === $_SERVER['SERVER_ADDR']) {
            $printerName = "XP-58SERIES";
            $isNetworkPrinter = false;
        } else if ($clientIP === '192.168.178.87') {
            $printerName = "POSPrinter POS-80C";
            $isNetworkPrinter = true;
        } else {
            $printerName = "XP-58SERIES";
            $isNetworkPrinter = false;
        }

        error_log("Opening cash drawer - Client IP: $clientIP, Printer: $printerName, Network: " . ($isNetworkPrinter ? 'Yes' : 'No'));

        if ($rawMode) {
            $connector = new RawCaptureConnector();
            $rawConnector = $connector;
        } else if ($isNetworkPrinter) {
            $connector = new NetworkPrintConnector("192.168.1.7", 9100);
        } else {
            $connector = new WindowsPrintConnector($printerName);
        }

        $printer = new Printer($connector);
        $printer->pulse();
        // Initialize printer to stop further printing
        $printer->initialize();
        $printer->close();

        error_log("Cash drawer opened successfully");
        
        // Get business info for Android interceptor
        $db = new PDO('sqlite:info.db');
        $businessInfo = $db->query("SELECT * FROM business_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);
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
        
        // Enrich orderData with business info
        $orderData['business_name'] = $businessInfo['name'] ?? 'POS SOLUTION';
        $orderData['location'] = $businessInfo['location'] ?? '';
        $orderData['phone'] = $businessInfo['phone'] ?? '';
        $orderData['footer_text'] = $businessInfo['footer_text'] ?? 'Thank you for your purchase!';
        $orderData['vat_inclusive'] = $businessInfo['vat_inclusive'] ?? 'exclusive';
        $orderData['vat_rate'] = isset($businessInfo['vat_rate']) ? floatval($businessInfo['vat_rate']) : 15.0;

        if ($rawMode) {
            $rawBytes = $rawConnector ? $rawConnector->getData() : '';
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'raw_base64' => base64_encode($rawBytes),
                'order_data' => $orderData
            ]);
            exit;
        } else {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'message' => 'Drawer opened',
                'printer_used' => $printerName,
                'client_ip' => $clientIP,
                'connection_type' => $isNetworkPrinter ? 'network' : 'local',
                'order_data' => $orderData  // Include enriched orderData for Android
            ]);
            exit;
        }
    } catch (\Throwable $e) {
        error_log("Cash drawer opening failed: " . $e->getMessage());
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to open drawer: ' . $e->getMessage()
        ]);
        exit;
    }
}

// Handle cash-up MASTER receipt printing (new detailed format)
// Amounts are recalculated using the same logic as admin/cashupmaster.php so receipt always balances with the page.
if (isset($orderData['is_cashup_master_report']) && $orderData['is_cashup_master_report']) {
    try {
        // Set timezone to Namibia
        date_default_timezone_set('Africa/Harare');
        
        $baseDir = __DIR__;
        $infoDbPath = $baseDir . DIRECTORY_SEPARATOR . 'info.db';
        $posDbPath = $baseDir . DIRECTORY_SEPARATOR . 'pos.db';

        // Connect to database and get business info
        $dbInfo = new PDO('sqlite:' . $infoDbPath);
        $dbInfo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $businessInfo = $dbInfo->query("SELECT * FROM business_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$businessInfo) {
            $businessInfo = [
                'name' => 'POS SOLUTION',
                'location' => 'Your Business Address',
                'phone' => 'Your Phone Number',
                'footer_text' => 'Thank you for your purchase!',
                'printer_port' => 'COM4',
                'vat_inclusive' => 'exclusive',
                'vat_rate' => 15.0
            ];
        }

        // Recalculate all amounts using same logic as admin/cashupmaster.php (same business day, same queries)
        $selectedDate = $orderData['date'] ?? date('Y-m-d');
        $closingTime = $businessInfo['closing_time'] ?? '22:00';
        $closingHour = (int)substr($closingTime, 0, 2);
        $isAfterMidnight = $closingHour < 12;
        $nextBusinessDay = date('Y-m-d', strtotime($selectedDate . ' +1 day'));
        
        // Get cashier filter info
        $filterCashierId = $orderData['filter_cashier_id'] ?? 'all';
        $filterCashierName = $orderData['filter_cashier_name'] ?? 'All Staff';
        $isIndividualCashout = isset($orderData['is_individual_cashout']) && $orderData['is_individual_cashout'];
        
        // Get numeric user ID for tables that store it numerically (like tabs)
        $filterCashierNumericId = null;
        if ($filterCashierId !== 'all' && !empty($filterCashierId)) {
            try {
                $userDbPath = $baseDir . DIRECTORY_SEPARATOR . 'user.db';
                $userDb = new PDO('sqlite:' . $userDbPath);
                $userDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                
                if (is_numeric($filterCashierId)) {
                    $filterCashierNumericId = intval($filterCashierId);
                } else {
                    $idLookup = $userDb->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
                    $idLookup->execute([$filterCashierId]);
                    $idResult = $idLookup->fetch(PDO::FETCH_ASSOC);
                    if ($idResult) {
                        $filterCashierNumericId = intval($idResult['id']);
                    }
                }
            } catch (PDOException $e) {
                // If lookup fails, leave as null
            }
        }

        $getBusinessDayWhere = function($dateField) use ($selectedDate, $nextBusinessDay, $closingTime, $isAfterMidnight) {
            return "
                (DATE($dateField) = :selectedDate AND strftime('%H:%M', $dateField) >= :closingTime) OR
                (DATE($dateField) = :nextBusinessDay AND strftime('%H:%M', $dateField) < :closingTime AND :isAfterMidnight = 1)
            ";
        };
        
        // Helper function to get cashier filter (for username-based cashier_id)
        $getCashierFilter = function($cashierIdField) use ($filterCashierId) {
            if ($filterCashierId === 'all' || empty($filterCashierId)) {
                return "";
            }
            return " AND $cashierIdField = :cashierId";
        };
        
        // Helper function to bind cashier param (for username-based)
        $bindCashierParam = function($stmt) use ($filterCashierId) {
            if ($filterCashierId !== 'all' && !empty($filterCashierId)) {
                $stmt->bindParam(':cashierId', $filterCashierId);
            }
        };
        
        // Helper function to get cashier filter for numeric ID fields (like tabs)
        $getCashierFilterNumeric = function($cashierIdField) use ($filterCashierNumericId) {
            if ($filterCashierNumericId === null) {
                return "";
            }
            return " AND $cashierIdField = :cashierNumericId";
        };
        
        // Helper function to bind cashier param for numeric ID
        $bindCashierParamNumeric = function($stmt) use ($filterCashierNumericId) {
            if ($filterCashierNumericId !== null) {
                $stmt->bindValue(':cashierNumericId', $filterCashierNumericId, PDO::PARAM_INT);
            }
        };

        $db = new PDO('sqlite:' . $posDbPath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $eftTableExists = false;
        try {
            $checkEftTable = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='eft_payments'");
            $eftTableExists = ($checkEftTable->fetchColumn() !== false);
        } catch (PDOException $e) {
            $eftTableExists = false;
        }

        if ($eftTableExists) {
            $cashSalesQuery = $db->prepare("
                SELECT COALESCE(SUM(
                    o.total - COALESCE((SELECT SUM(amount) FROM eft_payments ep WHERE ep.order_id = o.id), 0)
                ), 0)
                FROM orders o
                WHERE (" . $getBusinessDayWhere('o.created_at') . ")" . $getCashierFilter('o.cashier_id') . "
            ");
        } else {
            $cashSalesQuery = $db->prepare("
                SELECT COALESCE(SUM(total), 0) 
                FROM orders 
                WHERE (" . $getBusinessDayWhere('created_at') . ")" . $getCashierFilter('cashier_id') . "
            ");
        }
        $cashSalesQuery->bindParam(':selectedDate', $selectedDate);
        $cashSalesQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
        $cashSalesQuery->bindParam(':closingTime', $closingTime);
        $cashSalesQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
        $bindCashierParam($cashSalesQuery);
        $cashSalesQuery->execute();
        $totalCashSales = $cashSalesQuery->fetchColumn();

        $cashInQuery = $db->prepare("
            SELECT COALESCE(SUM(amount), 0) 
            FROM cash_transactions 
            WHERE type='cash-in' AND (" . $getBusinessDayWhere('created_at') . ")" . $getCashierFilter('cashier_id') . "
        ");
        $cashInQuery->bindParam(':selectedDate', $selectedDate);
        $cashInQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
        $cashInQuery->bindParam(':closingTime', $closingTime);
        $cashInQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
        $bindCashierParam($cashInQuery);
        $cashInQuery->execute();
        $totalCashIn = $cashInQuery->fetchColumn();

        $creditPaymentsQuery = $db->prepare("
            SELECT COALESCE(SUM(p.amount), 0) 
            FROM payments p
            JOIN credit_sales cs ON p.sale_id = cs.id
            WHERE cs.payment_status = 'paid' AND (" . $getBusinessDayWhere('p.payment_date') . ")" . $getCashierFilter('p.cashier_id') . "
        ");
        $creditPaymentsQuery->bindParam(':selectedDate', $selectedDate);
        $creditPaymentsQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
        $creditPaymentsQuery->bindParam(':closingTime', $closingTime);
        $creditPaymentsQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
        $bindCashierParam($creditPaymentsQuery);
        $creditPaymentsQuery->execute();
        $totalCreditPayments = $creditPaymentsQuery->fetchColumn();

        $cashOutQuery = $db->prepare("
            SELECT COALESCE(SUM(amount), 0) 
            FROM cash_transactions 
            WHERE type='cash-out' AND (" . $getBusinessDayWhere('created_at') . ")" . $getCashierFilter('cashier_id') . "
        ");
        $cashOutQuery->bindParam(':selectedDate', $selectedDate);
        $cashOutQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
        $cashOutQuery->bindParam(':closingTime', $closingTime);
        $cashOutQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
        $bindCashierParam($cashOutQuery);
        $cashOutQuery->execute();
        $totalCashOut = $cashOutQuery->fetchColumn();

        $cashInTill = $totalCashIn + $totalCashSales + $totalCreditPayments - $totalCashOut;
        $cashSalesExpected = $cashInTill;

        $cardSalesExpected = 0;
        if ($eftTableExists) {
            $cardSalesQuery = $db->prepare("
                SELECT COALESCE(SUM(ep.amount), 0)
                FROM eft_payments ep
                JOIN orders o ON ep.order_id = o.id
                WHERE (" . $getBusinessDayWhere('ep.payment_date') . ")" . $getCashierFilter('ep.cashier_id') . "
            ");
            $cardSalesQuery->bindParam(':selectedDate', $selectedDate);
            $cardSalesQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
            $cardSalesQuery->bindParam(':closingTime', $closingTime);
            $cardSalesQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
            $bindCashierParam($cardSalesQuery);
            $cardSalesQuery->execute();
            $cardSalesExpected = $cardSalesQuery->fetchColumn();
        }

        $unpaidCreditSalesQuery = $db->prepare("
            SELECT COALESCE(SUM(total_amount - paid_amount), 0)
            FROM credit_sales
            WHERE payment_status = 'unpaid' AND (" . $getBusinessDayWhere('created_at') . ")" . $getCashierFilter('cashier_id') . "
        ");
        $unpaidCreditSalesQuery->bindParam(':selectedDate', $selectedDate);
        $unpaidCreditSalesQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
        $unpaidCreditSalesQuery->bindParam(':closingTime', $closingTime);
        $unpaidCreditSalesQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
        $bindCashierParam($unpaidCreditSalesQuery);
        $unpaidCreditSalesQuery->execute();
        $unpaidCreditSales = $unpaidCreditSalesQuery->fetchColumn();

        // Note: tabs table stores cashier_id as numeric user ID, not username
        $openTabsQuery = $db->prepare("
            SELECT COALESCE(SUM(current_balance), 0)
            FROM tabs
            WHERE status = 'open' AND (" . $getBusinessDayWhere('opened_at') . ")" . $getCashierFilterNumeric('cashier_id') . "
        ");
        $openTabsQuery->bindParam(':selectedDate', $selectedDate);
        $openTabsQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
        $openTabsQuery->bindParam(':closingTime', $closingTime);
        $openTabsQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
        $bindCashierParamNumeric($openTabsQuery);
        $openTabsQuery->execute();
        $openTabsBalance = $openTabsQuery->fetchColumn();

        $creditReturnsQuery = $db->prepare("
            SELECT COALESCE(SUM(return_amount), 0)
            FROM credit_returns
            WHERE (" . $getBusinessDayWhere('created_at') . ")" . $getCashierFilter('cashier_id') . "
        ");
        $creditReturnsQuery->bindParam(':selectedDate', $selectedDate);
        $creditReturnsQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
        $creditReturnsQuery->bindParam(':closingTime', $closingTime);
        $creditReturnsQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
        $bindCashierParam($creditReturnsQuery);
        $creditReturnsQuery->execute();
        $creditReturnsAmount = $creditReturnsQuery->fetchColumn();
        $creditReturns = $creditReturnsAmount + $totalCreditPayments;

        $expensesQuery = $db->prepare("
            SELECT COALESCE(SUM(amount), 0)
            FROM cash_transactions
            WHERE type = 'cash-out' 
            AND (description NOT LIKE '%Tips%' AND description NOT LIKE '%Cash Back%' AND description NOT LIKE '%tip%' AND description NOT LIKE '%cash back%')
            AND (" . $getBusinessDayWhere('created_at') . ")" . $getCashierFilter('cashier_id') . "
        ");
        $expensesQuery->bindParam(':selectedDate', $selectedDate);
        $expensesQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
        $expensesQuery->bindParam(':closingTime', $closingTime);
        $expensesQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
        $bindCashierParam($expensesQuery);
        $expensesQuery->execute();
        $expenses = $expensesQuery->fetchColumn();

        $cashBackQuery = $db->prepare("
            SELECT COALESCE(SUM(amount), 0)
            FROM cash_transactions
            WHERE type = 'cash-out' 
            AND (description LIKE '%Cash Back%' OR description LIKE '%cash back%')
            AND (" . $getBusinessDayWhere('created_at') . ")" . $getCashierFilter('cashier_id') . "
        ");
        $cashBackQuery->bindParam(':selectedDate', $selectedDate);
        $cashBackQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
        $cashBackQuery->bindParam(':closingTime', $closingTime);
        $cashBackQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
        $bindCashierParam($cashBackQuery);
        $cashBackQuery->execute();
        $cashBackSystem = $cashBackQuery->fetchColumn();

        $voidsQuery = $db->prepare("
            SELECT COALESCE(SUM(total), 0)
            FROM void_transactions
            WHERE (" . $getBusinessDayWhere('voided_at') . ")" . $getCashierFilter('cashier_id') . "
        ");
        $voidsQuery->bindParam(':selectedDate', $selectedDate);
        $voidsQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
        $voidsQuery->bindParam(':closingTime', $closingTime);
        $voidsQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
        $bindCashierParam($voidsQuery);
        $voidsQuery->execute();
        $voids = $voidsQuery->fetchColumn();

        $refundsQuery = $db->prepare("
            SELECT COALESCE(SUM(total_amount), 0)
            FROM refunds
            WHERE (" . $getBusinessDayWhere('created_at') . ")" . $getCashierFilter('cashier_id') . "
        ");
        $refundsQuery->bindParam(':selectedDate', $selectedDate);
        $refundsQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
        $refundsQuery->bindParam(':closingTime', $closingTime);
        $refundsQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
        $bindCashierParam($refundsQuery);
        $refundsQuery->execute();
        $refunds = $refundsQuery->fetchColumn();

        $totalItemsSoldQuery = $db->prepare("
            SELECT COALESCE(SUM(total), 0)
            FROM orders
            WHERE (" . $getBusinessDayWhere('created_at') . ")" . $getCashierFilter('cashier_id') . "
        ");
        $totalItemsSoldQuery->bindParam(':selectedDate', $selectedDate);
        $totalItemsSoldQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
        $totalItemsSoldQuery->bindParam(':closingTime', $closingTime);
        $totalItemsSoldQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
        $bindCashierParam($totalItemsSoldQuery);
        $totalItemsSoldQuery->execute();
        $totalItemsSold = $totalItemsSoldQuery->fetchColumn() ?? 0;

        // User-entered / modal amounts from orderData so receipt reflects exactly what was in the cash-up modal
        $cashOnHand = floatval($orderData['cash_on_hand'] ?? 0);
        $eftOnHand = floatval($orderData['eft_on_hand'] ?? 0);
        $eftOverShort = floatval($orderData['eft_over_short'] ?? 0);
        $cashBack = isset($orderData['cash_back']) ? floatval($orderData['cash_back']) : $cashBackSystem;
        $tips = floatval($orderData['tips'] ?? 0);
        $hansaCash = floatval($orderData['hansa_cash'] ?? 0);
        $hansaEft = floatval($orderData['hansa_eft'] ?? 0);
        $hubbly = floatval($orderData['hubbly'] ?? 0);
        $beerhouse = floatval($orderData['beerhouse'] ?? 0);
        if (isset($orderData['cash_sales_expected'])) {
            $cashSalesExpected = floatval($orderData['cash_sales_expected']);
        }
        if (isset($orderData['card_sales_expected'])) {
            $cardSalesExpected = floatval($orderData['card_sales_expected']);
        }
        if (isset($orderData['unpaid_credit_sales'])) {
            $unpaidCreditSales = floatval($orderData['unpaid_credit_sales']);
        }
        if (isset($orderData['credit_returns'])) {
            $creditReturns = floatval($orderData['credit_returns']);
        }
        if (isset($orderData['open_tabs_balance'])) {
            $openTabsBalance = floatval($orderData['open_tabs_balance']);
        }
        if (isset($orderData['expenses'])) {
            $expenses = floatval($orderData['expenses']);
        }
        if (isset($orderData['voids'])) {
            $voids = floatval($orderData['voids']);
        }
        if (isset($orderData['refunds'])) {
            $refunds = floatval($orderData['refunds']);
        }
        if (isset($orderData['total_items_sold'])) {
            $totalItemsSold = floatval($orderData['total_items_sold']);
        }
        $overShort = $cashOnHand - $cashSalesExpected;

        // Receipt width configuration: 42 for 80mm, 32 for 58mm
        $receiptWidth = 32; // 58mm printer
        
        // Printer selection logic
        $clientIP = $_SERVER['REMOTE_ADDR'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_CLIENT_IP'] ?? '127.0.0.1';
        $printerName = '';
        $isNetworkPrinter = false;
        if ($clientIP === '127.0.0.1' || $clientIP === '::1' || $clientIP === 'localhost' || $clientIP === $_SERVER['SERVER_ADDR']) {
            $printerName = "XP-58SERIES";
            $isNetworkPrinter = false;
        } else if ($clientIP === '192.168.178.87') {
            $printerName = "XP-58SERIES";
            $isNetworkPrinter = true;
        } else {
            $printerName = "XP-58SERIES";
            $isNetworkPrinter = false;
        }
        
        // Create printer connection
        try {
            if ($rawMode) {
                $connector = new RawCaptureConnector();
                $rawConnector = $connector;
            } else if ($isNetworkPrinter) {
                $connector = new NetworkPrintConnector("192.168.1.7", 9100);
            } else {
                $connector = new WindowsPrintConnector($printerName);
            }
            $printer = new Printer($connector);
        } catch (Exception $printerError) {
            throw new Exception("Printer connection failed for $printerName: " . $printerError->getMessage());
        }
        
        // Helper function to format currency line
        $formatLine = function($label, $amount) use ($receiptWidth) {
            $amountStr = 'N$ ' . number_format(floatval($amount), 2);
            $spaces = $receiptWidth - strlen($label) - strlen($amountStr);
            if ($spaces < 1) $spaces = 1;
            return $label . str_repeat(' ', $spaces) . $amountStr . "\n";
        };
        
        // Print header
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->selectPrintMode(Printer::MODE_DOUBLE_WIDTH | Printer::MODE_DOUBLE_HEIGHT | Printer::MODE_EMPHASIZED);
        $printer->text($businessInfo['name'] . "\n");
        $printer->selectPrintMode();
        $printer->setEmphasis(true);
        if ($isIndividualCashout) {
            $printer->text("CASHOUT REPORT\n");
        } else {
            $printer->text("CASH UP REPORT\n");
        }
        $printer->setEmphasis(false);
        $printer->text($businessInfo['location'] . "\n");
        $printer->text("Tel: " . $businessInfo['phone'] . "\n");
        $printer->text(str_repeat('-', $receiptWidth) . "\n");
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->text("Date: " . ($orderData['date'] ?? date('Y-m-d')) . "\n");
        $printer->text("Time: " . date('H:i') . "\n");
        if ($isIndividualCashout) {
            $printer->setEmphasis(true);
            $printer->text("Staff: " . $filterCashierName . "\n");
            $printer->setEmphasis(false);
        }
        $printer->text("Printed by: " . ($orderData['cashier_username'] ?? 'N/A') . "\n");
        
        // CASH SECTION
        $printer->text(str_repeat('-', $receiptWidth) . "\n");
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->setEmphasis(true);
        $printer->text("CASH\n");
        $printer->setEmphasis(false);
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->text(str_repeat('-', $receiptWidth) . "\n");
        $printer->text($formatLine("Cash Sales (Expected)", $cashSalesExpected));
        $printer->text($formatLine("Cash on Hand", $cashOnHand));
        $printer->text($formatLine("Over / Short", $overShort));
        $printer->text(str_repeat('-', $receiptWidth) . "\n");
        
        // CARD & CREDIT SECTION
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->setEmphasis(true);
        $printer->text("CARD & CREDIT\n");
        $printer->setEmphasis(false);
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->text(str_repeat('-', $receiptWidth) . "\n");
        $printer->text($formatLine("Card Sales (Expected)", $cardSalesExpected));
        $printer->text($formatLine("EFT on Hand", $eftOnHand));
        $printer->text($formatLine("EFT Over / Short", $eftOverShort));
        $printer->text($formatLine("Unpaid Credit Sales", $unpaidCreditSales));
        $printer->text($formatLine("Open Tabs Balance", $openTabsBalance));
        $printer->text($formatLine("Credit Returns", $creditReturns));
        $printer->text(str_repeat('-', $receiptWidth) . "\n");
        
        // DEDUCTIONS SECTION
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->setEmphasis(true);
        $printer->text("DEDUCTIONS\n");
        $printer->setEmphasis(false);
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->text(str_repeat('-', $receiptWidth) . "\n");
        $printer->text($formatLine("Expenses", $expenses));
        $printer->text($formatLine("Cash Back", $cashBack));
        $printer->text($formatLine("Tips", $tips));
        $printer->text(str_repeat('-', $receiptWidth) . "\n");
        
        // SALES SOURCES (INFO) SECTION
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->setEmphasis(true);
        $printer->text("SALES SOURCES\n");
        $printer->setEmphasis(false);
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->text(str_repeat('-', $receiptWidth) . "\n");
        $printer->text($formatLine("Hansa (Cash)", $hansaCash));
        $printer->text($formatLine("Hansa (EFT)", $hansaEft));
        $printer->text($formatLine("Hubbly", $hubbly));
        $printer->text($formatLine("Beerhouse", $beerhouse));
        $printer->text(str_repeat('-', $receiptWidth) . "\n");
        
        // ADJUSTMENTS SECTION
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->setEmphasis(true);
        $printer->text("ADJUSTMENTS\n");
        $printer->setEmphasis(false);
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->text(str_repeat('-', $receiptWidth) . "\n");
        $printer->text($formatLine("Voids", $voids));
        $printer->text($formatLine("Refunds", $refunds));
        $printer->text(str_repeat('-', $receiptWidth) . "\n");
        
        // TOTAL VALUE OF ITEMS SOLD SECTION
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->setEmphasis(true);
        $printer->text("TOTAL VALUE OF ITEMS SOLD\n");
        $printer->setEmphasis(false);
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->text(str_repeat('-', $receiptWidth) . "\n");
        $printer->text($formatLine("Total Value of Items Sold", $totalItemsSold));
        $printer->text(str_repeat('-', $receiptWidth) . "\n");
        
        // Footer
        $printer->feed(1);
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        if ($isIndividualCashout) {
            $printer->text("*Cashout for " . $filterCashierName . "*\n");
        } else {
            $printer->text("*End of day cash up*\n");
        }
        $printer->feed(3);
        $printer->cut();
        $printer->pulse();
        $printer->initialize();
        $printer->close();
        
        // Enrich orderData with business info before returning
        $orderData['business_name'] = $businessInfo['name'] ?? 'POS SOLUTION';
        $orderData['location'] = $businessInfo['location'] ?? '';
        $orderData['phone'] = $businessInfo['phone'] ?? '';
        $orderData['footer_text'] = $businessInfo['footer_text'] ?? 'Thank you for your purchase!';
        $orderData['vat_inclusive'] = $businessInfo['vat_inclusive'] ?? 'exclusive';
        $orderData['vat_rate'] = isset($businessInfo['vat_rate']) ? floatval($businessInfo['vat_rate']) : 15.0;
        
        // Ensure no output before JSON
        if (ob_get_level()) ob_end_clean();
        
        if ($rawMode) {
            $rawBytes = $rawConnector ? $rawConnector->getData() : '';
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'raw_base64' => base64_encode($rawBytes),
                'order_data' => $orderData
            ]);
            exit;
        }

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true, 
            'message' => 'Cash-up master receipt printed',
            'order_data' => $orderData
        ]);
        exit;
    } catch (Exception $e) {
        // Ensure no output before JSON
        if (ob_get_level()) ob_end_clean();
        
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode([
            'success' => false, 
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
        exit;
    } catch (Error $e) {
        // Catch PHP 7+ errors
        if (ob_get_level()) ob_end_clean();
        
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode([
            'success' => false, 
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
        exit;
    }
}

// Handle inventory receipt printing
if (isset($orderData['is_inventory_receipt']) && $orderData['is_inventory_receipt']) {
    try {
        // Set timezone to Namibia
        date_default_timezone_set('Africa/Harare');
        
        $baseDir = __DIR__;
        $infoDbPath = $baseDir . DIRECTORY_SEPARATOR . 'info.db';

        // Connect to database and get business info
        $dbInfo = new PDO('sqlite:' . $infoDbPath);
        $dbInfo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $businessInfo = $dbInfo->query("SELECT * FROM business_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$businessInfo) {
            $businessInfo = [
                'name' => 'POS SOLUTION',
                'location' => 'Your Business Address',
                'phone' => 'Your Phone Number',
                'footer_text' => 'Thank you for your purchase!',
                'printer_port' => 'COM4',
                'vat_inclusive' => 'exclusive',
                'vat_rate' => 15.0
            ];
        }

        // Receipt width configuration: 42 for 80mm, 32 for 58mm
        $receiptWidth = 32; // 58mm printer
        
        // Printer selection logic
        $clientIP = $_SERVER['REMOTE_ADDR'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_CLIENT_IP'] ?? '127.0.0.1';
        $printerName = '';
        $isNetworkPrinter = false;
        if ($clientIP === '127.0.0.1' || $clientIP === '::1' || $clientIP === 'localhost' || $clientIP === $_SERVER['SERVER_ADDR']) {
            $printerName = "XP-58SERIES";
            $isNetworkPrinter = false;
        } else if ($clientIP === '192.168.178.87') {
            $printerName = "XP-58SERIES";
            $isNetworkPrinter = true;
        } else {
            $printerName = "XP-58SERIES";
            $isNetworkPrinter = false;
        }
        
        // Create printer connection
        try {
            if ($rawMode) {
                $connector = new RawCaptureConnector();
                $rawConnector = $connector;
            } else if ($isNetworkPrinter) {
                $connector = new NetworkPrintConnector("192.168.1.7", 9100);
            } else {
                $connector = new WindowsPrintConnector($printerName);
            }
            $printer = new Printer($connector);
        } catch (Exception $printerError) {
            throw new Exception("Printer connection failed for $printerName: " . $printerError->getMessage());
        }
        
        // Helper function to format line with right-aligned amount
        $formatLine = function($label, $amount) use ($receiptWidth) {
            $amountStr = 'N$ ' . number_format(floatval($amount), 2);
            $spaces = $receiptWidth - strlen($label) - strlen($amountStr);
            if ($spaces < 1) $spaces = 1;
            return $label . str_repeat(' ', $spaces) . $amountStr . "\n";
        };
        
        // Print header
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->selectPrintMode(Printer::MODE_DOUBLE_WIDTH | Printer::MODE_DOUBLE_HEIGHT | Printer::MODE_EMPHASIZED);
        $printer->text($businessInfo['name'] . "\n");
        $printer->selectPrintMode();
        $printer->setEmphasis(true);
        $printer->text("INVENTORY REPORT\n");
        $printer->setEmphasis(false);
        $printer->text($businessInfo['location'] . "\n");
        $printer->text("Tel: " . $businessInfo['phone'] . "\n");
        $printer->text(str_repeat('-', $receiptWidth) . "\n");
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->text("Date: " . date('Y-m-d') . "\n");
        $printer->text("Time: " . date('H:i') . "\n");
        $printer->text("Printed by: " . ($orderData['cashier_username'] ?? 'Admin') . "\n");
        $printer->text(str_repeat('-', $receiptWidth) . "\n");
        
        // Print column headers
        $printer->setEmphasis(true);
        $printer->text(sprintf("%-14s %3s %6s %7s\n", "Name", "Qty", "Price", "Value"));
        $printer->setEmphasis(false);
        $printer->text(str_repeat('-', $receiptWidth) . "\n");
        
        // Print inventory items
        $grandTotal = 0;
        if (isset($orderData['items']) && is_array($orderData['items'])) {
            foreach ($orderData['items'] as $item) {
                $name = $item['name'] ?? 'Unknown';
                $quantity = intval($item['quantity'] ?? 0);
                $price = floatval($item['price'] ?? 0);
                $totalValue = floatval($item['total_value'] ?? ($quantity * $price));
                $grandTotal += $totalValue;
                
                // Truncate name if too long (max 14 chars)
                if (strlen($name) > 14) {
                    $name = substr($name, 0, 11) . '...';
                }
                
                // Format: Name(14) Qty(3) Price(6) Value(7)
                $printer->text(sprintf("%-14s %3d %6.0f %7.0f\n", $name, $quantity, $price, $totalValue));
            }
        }
        
        // Print total section
        $printer->text(str_repeat('=', $receiptWidth) . "\n");
        $printer->setEmphasis(true);
        $printer->text($formatLine("TOTAL INVENTORY VALUE:", $grandTotal));
        $printer->setEmphasis(false);
        $printer->text(str_repeat('=', $receiptWidth) . "\n");
        
        // Print item count
        $itemCount = isset($orderData['items']) ? count($orderData['items']) : 0;
        $printer->text("Total Products: " . $itemCount . "\n");
        
        // Footer
        $printer->feed(1);
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->text("*End of Inventory Report*\n");
        $printer->feed(3);
        $printer->cut();
        $printer->pulse();
        $printer->initialize();
        $printer->close();
        
        // Enrich orderData with business info before returning
        $orderData['business_name'] = $businessInfo['name'] ?? 'POS SOLUTION';
        $orderData['location'] = $businessInfo['location'] ?? '';
        $orderData['phone'] = $businessInfo['phone'] ?? '';
        $orderData['footer_text'] = $businessInfo['footer_text'] ?? 'Thank you for your purchase!';
        $orderData['vat_inclusive'] = $businessInfo['vat_inclusive'] ?? 'exclusive';
        $orderData['vat_rate'] = isset($businessInfo['vat_rate']) ? floatval($businessInfo['vat_rate']) : 15.0;
        
        // Ensure no output before JSON
        if (ob_get_level()) ob_end_clean();
        
        if ($rawMode) {
            $rawBytes = $rawConnector ? $rawConnector->getData() : '';
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'raw_base64' => base64_encode($rawBytes),
                'order_data' => $orderData
            ]);
            exit;
        }

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true, 
            'message' => 'Inventory receipt printed',
            'order_data' => $orderData
        ]);
        exit;
    } catch (Exception $e) {
        // Ensure no output before JSON
        if (ob_get_level()) ob_end_clean();
        
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode([
            'success' => false, 
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
        exit;
    } catch (Error $e) {
        // Catch PHP 7+ errors
        if (ob_get_level()) ob_end_clean();
        
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode([
            'success' => false, 
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
        exit;
    }
}

// Handle cash-up receipt printing (Z-report)
if (isset($orderData['is_cashup_report']) && $orderData['is_cashup_report']) {
    try {
        // Set timezone to Namibia
        date_default_timezone_set('Africa/Harare');

        // Connect to database and get business info
        $dbInfo = new PDO('sqlite:info.db');
        $businessInfo = $dbInfo->query("SELECT * FROM business_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$businessInfo) {
            $businessInfo = [
                'name' => 'POS SOLUTION',
                'location' => 'Your Business Address',
                'phone' => 'Your Phone Number',
                'footer_text' => 'Thank you for your purchase!',
                'printer_port' => 'COM4',
                'vat_inclusive' => 'exclusive',
                'vat_rate' => 15.0
            ];
        }

        // Receipt width configuration: 42 for 80mm, 32 for 58mm
        // Change this variable to switch between printer sizes
        $receiptWidth = 42; // Default: 80mm (change to 32 for 58mm)
        $receiptTruncate = $receiptWidth - 3; // For truncating long text (39 for 80mm, 29 for 58mm)
        
        // Connect to POS database and calculate expected cash using same logic as cash.php
        $dbPos = new PDO('sqlite:pos.db');
        
        // Get business closing time from business_info
        $closingTime = $businessInfo['closing_time'] ?? '22:00'; // Default to 10:00 PM if not set
        
        // Calculate business day boundaries based on closing time
        $closingHour = (int)substr($closingTime, 0, 2);
        $closingMinute = (int)substr($closingTime, 3, 2);
        
        // If closing time is after midnight (e.g., 2:00 AM), we need to consider transactions
        // that happened after midnight but before closing time as part of the previous day
        $isAfterMidnight = $closingHour < 12;
        
        // Get the selected date from order data
        $selectedDate = $orderData['date'];
        $nextBusinessDay = date('Y-m-d', strtotime($selectedDate . ' +1 day'));
        
        // 1. Calculate cash in transactions for the selected date
        $cashInQuery = $dbPos->prepare("
            SELECT COALESCE(SUM(amount), 0) 
            FROM cash_transactions 
            WHERE type='cash-in' AND (
                (DATE(created_at) = :selectedDate AND strftime('%H:%M', created_at) >= :closingTime) OR
                (DATE(created_at) = :nextBusinessDay AND strftime('%H:%M', created_at) < :closingTime AND :isAfterMidnight = 1)
            )
        ");
        $cashInQuery->bindParam(':selectedDate', $selectedDate);
        $cashInQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
        $cashInQuery->bindParam(':closingTime', $closingTime);
        $cashInQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
        $cashInQuery->execute();
        $totalCashIn = $cashInQuery->fetchColumn();
        
        // 2. Calculate cash sales (excluding EFT payments)
        $eftTableExists = false;
        try {
            $checkEftTable = $dbPos->query("SELECT name FROM sqlite_master WHERE type='table' AND name='eft_payments'");
            $eftTableExists = ($checkEftTable->fetchColumn() !== false);
        } catch (PDOException $e) {
            $eftTableExists = false;
        }
        
        if ($eftTableExists) {
            $cashSalesQuery = $dbPos->prepare("
                SELECT COALESCE(SUM(o.total), 0)
                FROM orders o
                LEFT JOIN eft_payments e ON o.id = e.order_id
                WHERE e.order_id IS NULL AND (
                    (DATE(o.created_at) = :selectedDate AND strftime('%H:%M', o.created_at) >= :closingTime) OR
                    (DATE(o.created_at) = :nextBusinessDay AND strftime('%H:%M', o.created_at) < :closingTime AND :isAfterMidnight = 1)
                )
            ");
            $cashSalesQuery->bindParam(':selectedDate', $selectedDate);
            $cashSalesQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
            $cashSalesQuery->bindParam(':closingTime', $closingTime);
            $cashSalesQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
            $cashSalesQuery->execute();
        } else {
            $cashSalesQuery = $dbPos->prepare("
                SELECT COALESCE(SUM(total), 0) 
                FROM orders 
                WHERE (
                    (DATE(created_at) = :selectedDate AND strftime('%H:%M', created_at) >= :closingTime) OR
                    (DATE(created_at) = :nextBusinessDay AND strftime('%H:%M', created_at) < :closingTime AND :isAfterMidnight = 1)
                )
            ");
            $cashSalesQuery->bindParam(':selectedDate', $selectedDate);
            $cashSalesQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
            $cashSalesQuery->bindParam(':closingTime', $closingTime);
            $cashSalesQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
            $cashSalesQuery->execute();
        }
        $totalCashSales = $cashSalesQuery->fetchColumn();
        
        // 3. Calculate credit payments received in cash
        $creditPaymentsQuery = $dbPos->prepare("
            SELECT COALESCE(SUM(p.amount), 0) 
            FROM payments p
            JOIN credit_sales cs ON p.sale_id = cs.id
            WHERE cs.payment_status = 'paid' AND (
                (DATE(p.payment_date) = :selectedDate AND strftime('%H:%M', p.payment_date) >= :closingTime) OR
                (DATE(p.payment_date) = :nextBusinessDay AND strftime('%H:%M', p.payment_date) < :closingTime AND :isAfterMidnight = 1)
            )
        ");
        $creditPaymentsQuery->bindParam(':selectedDate', $selectedDate);
        $creditPaymentsQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
        $creditPaymentsQuery->bindParam(':closingTime', $closingTime);
        $creditPaymentsQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
        $creditPaymentsQuery->execute();
        $totalCreditPayments = $creditPaymentsQuery->fetchColumn();
        
        // 4. Calculate cash out (withdrawals)
        $cashOutQuery = $dbPos->prepare("
            SELECT COALESCE(SUM(amount), 0) 
            FROM cash_transactions 
            WHERE type='cash-out' AND (
                (DATE(created_at) = :selectedDate AND strftime('%H:%M', created_at) >= :closingTime) OR
                (DATE(created_at) = :nextBusinessDay AND strftime('%H:%M', created_at) < :closingTime AND :isAfterMidnight = 1)
            )
        ");
        $cashOutQuery->bindParam(':selectedDate', $selectedDate);
        $cashOutQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
        $cashOutQuery->bindParam(':closingTime', $closingTime);
        $cashOutQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
        $cashOutQuery->execute();
        $totalCashOut = $cashOutQuery->fetchColumn();
        
        // Calculate the correct expected cash amount
        $calculatedExpectedCash = $totalCashIn + $totalCashSales + $totalCreditPayments - $totalCashOut;
        // Printer selection logic (same as before)
        $clientIP = $_SERVER['REMOTE_ADDR'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_CLIENT_IP'] ?? '127.0.0.1';
        $printerName = '';
        $isNetworkPrinter = false;
        if ($clientIP === '127.0.0.1' || $clientIP === '::1' || $clientIP === 'localhost' || $clientIP === $_SERVER['SERVER_ADDR']) {
            $printerName = "XP-58SERIES";
            $isNetworkPrinter = false;
        } else if ($clientIP === '192.168.178.87') {
            $printerName = "XP-58SERIES";
            $isNetworkPrinter = true;
        } else {
            $printerName = "XP-58SERIES";
            $isNetworkPrinter = false;
        }
        // Create printer connection
        if ($isNetworkPrinter) {
            if ($rawMode) {
                $connector = new RawCaptureConnector();
                $rawConnector = $connector;
            } else {
                $connector = new NetworkPrintConnector("192.168.1.7", 9100);
            }
        } else {
            if ($rawMode) {
                $connector = new RawCaptureConnector();
                $rawConnector = $connector;
            } else {
                $connector = new WindowsPrintConnector($printerName);
            }
        }
        $printer = new Printer($connector);
        // Print simplified Z-report receipt (dynamic width format)
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->selectPrintMode(Printer::MODE_DOUBLE_WIDTH | Printer::MODE_DOUBLE_HEIGHT | Printer::MODE_EMPHASIZED);
        $printer->text($businessInfo['name'] . "\n");
        $printer->selectPrintMode();
        $printer->setEmphasis(true);
        $printer->text("Z-REPORT\n");
        $printer->setEmphasis(false);
        $printer->text(str_repeat('-', $receiptWidth) . "\n");
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->text("Date: " . $orderData['date'] . "\n");
        $printer->text("Time: " . date('H:i') . "\n");
        $printer->text("Cashier: " . ($orderData['cashier_username'] ?? 'N/A') . "\n");
        $printer->text(str_repeat('-', $receiptWidth) . "\n");
        $printer->feed();
        
        // Essential totals only
        $cashTotal = ($orderData['cash_sales'] ?? $orderData['total_cash_sales'] ?? 0);
        $eftTotal = ($orderData['eft_sales'] ?? $orderData['total_eft_sales'] ?? 0);
        $grandTotal = ($orderData['grand_total'] ?? $orderData['total_income'] ?? 0);
        
        $printer->text(sprintf("%-20s N$%8.2f\n", "CASH SALES:", $cashTotal));
        $printer->text(sprintf("%-20s N$%8.2f\n", "EFT SALES:", $eftTotal));
        $printer->text(str_repeat('-', $receiptWidth) . "\n");
        $printer->setEmphasis(true);
        $printer->text(sprintf("%-20s N$%8.2f\n", "TOTAL SALES:", $grandTotal));
        $printer->setEmphasis(false);
        $printer->text(str_repeat('=', $receiptWidth) . "\n");
        
        // Shortage/Surplus if provided
        if (isset($orderData['cash_difference'])) {
            $difference = floatval($orderData['cash_difference']);
            if ($difference != 0) {
                // Removed feed() - surplus/shortage line comes directly
                $printer->setEmphasis(true);
                $printer->text(sprintf("%-20s N$%8.2f\n", 
                    $difference > 0 ? "SURPLUS:" : "SHORTAGE:", 
                    abs($difference)));
                $printer->setEmphasis(false);
            }
        }
        
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->text("End of Report\n");
        // Feed enough to ensure footer is fully printed before cut
        $printer->feed(3);
        $printer->cut();
        $printer->pulse();
        // Initialize printer to stop further printing
        $printer->initialize();
        $printer->close();
        // Enrich orderData with business info before returning
        $orderData['business_name'] = $businessInfo['name'] ?? 'POS SOLUTION';
        $orderData['location'] = $businessInfo['location'] ?? '';
        $orderData['phone'] = $businessInfo['phone'] ?? '';
        $orderData['footer_text'] = $businessInfo['footer_text'] ?? 'Thank you for your purchase!';
        $orderData['vat_inclusive'] = $businessInfo['vat_inclusive'] ?? 'exclusive';
        $orderData['vat_rate'] = isset($businessInfo['vat_rate']) ? floatval($businessInfo['vat_rate']) : 15.0;
        
        if ($rawMode) {
            $rawBytes = $rawConnector ? $rawConnector->getData() : '';
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'raw_base64' => base64_encode($rawBytes),
                'order_data' => $orderData
            ]);
            exit;
        }

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true, 
            'message' => 'Cash-up receipt printed',
            'order_data' => $orderData  // Include enriched orderData for Android
        ]);
        exit;
    } catch (Exception $e) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

try {
    // Set timezone to Namibia
    date_default_timezone_set('Africa/Harare');

    // Validate input data
    if (!isset($orderData) || empty($orderData)) {
        throw new Exception('No order data received');
    }

    // Only print if explicitly requested (print_only flag) OR for special receipt types
    // Special receipt types that should always print:
    // - Cash-up reports (is_cashup_report)
    // - Balance receipts (is_balance_receipt, is_tab_balance_receipt)
    // - Payment receipts (is_payment_receipt)
    // - Drawer-only operations (open_drawer_only)
    $shouldPrint = isset($orderData['print_only']) || 
                   isset($orderData['is_cashup_report']) || 
                   isset($orderData['is_balance_receipt']) || 
                   isset($orderData['is_tab_balance_receipt']) || 
                   isset($orderData['is_payment_receipt']) ||
                   isset($orderData['open_drawer_only']);

    // Connect to database and get business info early (before any exit)
    $db = new PDO('sqlite:info.db');
    $businessInfo = $db->query("SELECT * FROM business_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    
    // If no business info found, use defaults
    if (!$businessInfo) {
        $businessInfo = [
            'name' => 'POS SOLUTION',
            'location' => 'Your Business Address',
            'phone' => 'Your Phone Number',
            'footer_text' => 'Thank you for your purchase!',
            'printer_port' => 'COM4',
            'vat_inclusive' => 'exclusive',
            'vat_rate' => 15.0
        ];
    }
    
    // ALWAYS enrich orderData with business info from info.db (reliable source)
    // This ensures business info always matches what's in the database, even if client sent stale data
    $orderData['business_name'] = $businessInfo['name'] ?? 'POS SOLUTION';
    $orderData['location'] = $businessInfo['location'] ?? '';
    $orderData['phone'] = $businessInfo['phone'] ?? '';
    $orderData['footer_text'] = $businessInfo['footer_text'] ?? 'Thank you for your purchase!';
    $orderData['vat_inclusive'] = $businessInfo['vat_inclusive'] ?? 'exclusive';
    $orderData['vat_rate'] = isset($businessInfo['vat_rate']) ? floatval($businessInfo['vat_rate']) : 15.0;
    
    // If printing is not explicitly requested, return success without printing
    if (!$shouldPrint) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => 'Print not requested - receipt skipped',
            'order_data' => $orderData  // Include enriched orderData for Android
        ]);
        exit;
    }

    // Prevent printing regular receipts for tab sales - only print kitchen tickets
    // Exception: Allow tab balance receipts (is_tab_balance_receipt), payment receipts (is_payment_receipt), 
    // and kitchen tickets (print_only with tab_id/table_id) to be printed
    if ((isset($orderData['tab_id']) || isset($orderData['table_id'])) && 
        !isset($orderData['is_tab_balance_receipt']) && 
        !isset($orderData['is_balance_receipt']) &&
        !isset($orderData['is_payment_receipt']) &&
        !isset($orderData['print_only'])) {
        // This is a tab sale - should only print kitchen ticket, not receipt
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => 'Tab sale - kitchen ticket only, no receipt printed',
            'order_data' => $orderData  // Include enriched orderData for Android
        ]);
        exit;
    }
    
    // Get VAT settings (business info already loaded above)
    $vatInclusive = $businessInfo['vat_inclusive'] ?? 'exclusive';
    $vatRate = isset($businessInfo['vat_rate']) ? floatval($businessInfo['vat_rate']) : 15.0;
    
    // Receipt width configuration: 42 for 80mm, 32 for 58mm
    // Change this variable to switch between printer sizes
    $receiptWidth = 32; // Default: 80mm (change to 32 for 58mm)
    $receiptTruncate = $receiptWidth - 3; // For truncating long text (39 for 80mm, 29 for 58mm)
    
    // Detect client IP address to determine which printer to use
    $clientIP = $_SERVER['REMOTE_ADDR'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_CLIENT_IP'] ?? '127.0.0.1';
    
    // Determine printer connection based on client IP
    $printerName = '';
    $isNetworkPrinter = false;
    
    if ($clientIP === '127.0.0.1' || $clientIP === '::1' || $clientIP === 'localhost' || $clientIP === $_SERVER['SERVER_ADDR']) {
        // Local PC - use XP-58SERIES printer
        $printerName = "XP-58SERIES";
        $isNetworkPrinter = false;
    } else if ($clientIP === '192.168.178.87') {
        // Network PC - use POS-80C printer over network
        $printerName = "POSPrinter POS-80C";
        $isNetworkPrinter = true;
    } else {
        // Default to local printer for any other IP
        $printerName = "XP-58SERIES";
        $isNetworkPrinter = false;
    }
    
    // Create printer connection based on type
    try {
        if ($rawMode) {
            $connector = new RawCaptureConnector();
            $rawConnector = $connector;
        } else if ($isNetworkPrinter) {
            // Network printer connection
            $connector = new NetworkPrintConnector("192.168.1.7", 9100);
        } else {
            // Local Windows printer connection
            $connector = new WindowsPrintConnector($printerName);
        }
        $printer = new Printer($connector);
    } catch (Exception $e) {
        throw new Exception("Printer connection failed for $printerName: " . $e->getMessage());
    }

    // Open the cash drawer BEFORE printing for eligible payments
    try {
        if (!isset($orderData['print_only']) && !isset($orderData['creditor_id']) && !isset($orderData['is_cashup_report']) && !isset($orderData['is_balance_receipt']) && !isset($orderData['is_tab_balance_receipt'])) {
            if (!isset($orderData['payment_method']) || ($orderData['payment_method'] !== 'e-wallet' && $orderData['payment_method'] !== 'credit')) {
                $printer->pulse();
            }
        }
    } catch (Exception $e) {
        error_log("Initial drawer open failed: " . $e->getMessage());
    }

    // Store header with emphasis (skip for tab sales/order receipts, but show for payment receipts and kitchen tickets)
    $isTabSale = isset($orderData['table_id']) || isset($orderData['tab_id']);
    $isPaymentReceipt = isset($orderData['is_payment_receipt']) && $orderData['is_payment_receipt'];
    
    // Skip header for kitchen tickets (tab sales) - no header or footer
    if (!$isTabSale || $isPaymentReceipt) {
        try {
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->selectPrintMode(Printer::MODE_DOUBLE_WIDTH | Printer::MODE_DOUBLE_HEIGHT | Printer::MODE_EMPHASIZED);
            $printer->text($businessInfo['name'] . "\n");
            $printer->selectPrintMode(); // Reset print mode
            $printer->setEmphasis(true);
            $printer->text($businessInfo['location'] . "\n");
            $printer->setEmphasis(false);
            $printer->text("Tel: " . $businessInfo['phone'] . "\n");
            $printer->text("Cashier: " . $orderData['cashier_username'] . "\n");
            // Removed unnecessary feed() - separator line comes next
        } catch (Exception $e) {
            error_log("Error printing header: " . $e->getMessage());
            throw new Exception("Failed to print header: " . $e->getMessage());
        }
    }

        // Check if this is a cash-up report or regular receipt
    if (isset($orderData['is_cashup_report']) && $orderData['is_cashup_report']) {
        // CASH-UP REPORT PRINTING
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->text(str_repeat('-', $receiptWidth) . "\n");
        $printer->setEmphasis(true);
        $printer->text("DAILY CASH-UP REPORT\n");
        $printer->setEmphasis(false);
        $printer->text(str_repeat('-', $receiptWidth) . "\n");
        $printer->text("Date: " . $orderData['date'] . "\n");
        $printer->text("Printed: " . date('Y-m-d H:i') . "\n");
        $printer->text("Printed by: " . ($orderData['cashier_username'] ?? 'Admin') . "\n");
        // Removed feed() - separator line comes next
        
        // Print employee breakdown if employees array is provided
        if (isset($orderData['employees']) && is_array($orderData['employees']) && !empty($orderData['employees'])) {
            $printer->text(str_repeat('=', $receiptWidth) . "\n");
            $printer->setEmphasis(true);
            $printer->text("EMPLOYEE BREAKDOWN\n");
            $printer->setEmphasis(false);
            $printer->text(str_repeat('=', $receiptWidth) . "\n");
            
            foreach ($orderData['employees'] as $employee) {
                $empName = $employee['name'] ?? 'Unknown';
                if (strlen($empName) > 20) {
                    $empName = substr($empName, 0, 17) . '...';
                }
                $printer->text(str_repeat('-', $receiptWidth) . "\n");
                $printer->setEmphasis(true);
                $printer->text($empName . "\n");
                $printer->setEmphasis(false);
                $printer->text(sprintf("  Orders: %d\n", $employee['total_orders'] ?? 0));
                $printer->text(sprintf("  Cash:   N$%8.2f\n", $employee['cash_sales'] ?? 0));
                $printer->text(sprintf("  EFT:    N$%8.2f\n", $employee['eft_sales'] ?? 0));
                $printer->text(sprintf("  Total:  N$%8.2f\n", $employee['total_sales'] ?? 0));
            }
            $printer->text(str_repeat('=', $receiptWidth) . "\n");
            // Removed feed() - separator line comes next
        }
        
        $printer->text(str_repeat('=', $receiptWidth) . "\n");
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $cashSales = $orderData['total_cash_sales'] ?? 0;
        $eftSales = $orderData['total_eft_sales'] ?? 0;
        $creditSales = $orderData['credit_sales'] ?? $orderData['credit_sales_total'] ?? 0;
        $tabSales = $orderData['tab_sales'] ?? 0;
        $unpaidCredit = $orderData['credit_unpaid'] ?? $orderData['unpaid_credit'] ?? 0;
        $grandTotal = $orderData['grand_total'] ?? ($cashSales + $eftSales);
        
        $printer->setEmphasis(true);
        $printer->text("GRAND TOTALS\n");
        $printer->setEmphasis(false);
        $printer->text(str_repeat('-', $receiptWidth) . "\n");
        $printer->text(sprintf("%-20s N$%8.2f\n", "TOTAL CASH:", $cashSales));
        $printer->text(sprintf("%-20s N$%8.2f\n", "TOTAL EFT:", $eftSales));
        if ($creditSales > 0) {
            $printer->text(sprintf("%-20s N$%8.2f\n", "CREDIT SALES:", $creditSales));
        }
        if ($tabSales > 0) {
            $printer->text(sprintf("%-20s N$%8.2f\n", "TAB SALES:", $tabSales));
        }
        if ($unpaidCredit > 0) {
            $printer->text(sprintf("%-20s N$%8.2f\n", "UNPAID CREDIT:", $unpaidCredit));
        }
        $printer->text(str_repeat('-', $receiptWidth) . "\n");
        $printer->setEmphasis(true);
        $printer->text(sprintf("%-20s N$%8.2f\n", "GRAND TOTAL:", $grandTotal));
        $printer->setEmphasis(false);
        $printer->text(str_repeat('=', $receiptWidth) . "\n");
        // Removed feed() - cash section comes next
        
        // Use expected_cash if available, otherwise fall back to cash_available_in_till
        $expectedCash = $orderData['expected_cash'] ?? $orderData['cash_available_in_till'] ?? 0;
        $cashOnHand = $orderData['cash_on_hand'] ?? 0;
        
        if ($expectedCash > 0 || $cashOnHand > 0) {
            $printer->text(str_repeat('-', $receiptWidth) . "\n");
            // Expected Cash in Till
            if ($expectedCash > 0) {
                $printer->setEmphasis(true);
                $printer->text(sprintf("%-20s N$%8.2f\n", "EXPECTED CASH:", $expectedCash));
                $printer->setEmphasis(false);
            }
            
            // Cash on Hand
            if ($cashOnHand > 0) {
                $printer->text(sprintf("%-20s N$%8.2f\n", "CASH ON HAND:", $cashOnHand));
            }
            $printer->text(str_repeat('-', $receiptWidth) . "\n");
        }
        
        // Add actual cash and difference if provided
        if (isset($orderData['actual_cash_in_till'])) {
            $actualCash = floatval($orderData['actual_cash_in_till']);
            $printer->setEmphasis(true);
            $printer->text(sprintf("%-20s N$%8.2f\n", "ACTUAL CASH:", $actualCash));
            $printer->setEmphasis(false);
            
            // Calculate difference using the passed expected cash amount
            $difference = $actualCash - $expectedCash;
            if ($difference != 0) {
                $printer->text(sprintf("%-20s N$%8.2f\n", 
                    $difference > 0 ? "SURPLUS:" : "SHORTAGE:", 
                    abs($difference)));
            }
        }
        $printer->text(str_repeat('=', $receiptWidth) . "\n");
        // Removed feed() - signature section comes directly
        // Signature line
        $printer->text(str_repeat('-', $receiptWidth) . "\n");
        $printer->text("Signature: ________________\n");
        // Removed feed() - end message comes next
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->text("*** End of Cash-up Report ***\n");
        // Removed feed() - cut comes immediately
    } else if (isset($orderData['is_refund_receipt']) && $orderData['is_refund_receipt']) {
        // REFUND RECEIPT PRINTING
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->text(str_repeat('-', $receiptWidth) . "\n");
        $printer->setEmphasis(true);
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->text("REFUND RECEIPT\n");
        $printer->setEmphasis(false);
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->text(str_repeat('-', $receiptWidth) . "\n");
        $printer->text("Refund #: " . ($orderData['refund_id'] ?? 'N/A') . "\n");
        $printer->text("Original Order #: " . ($orderData['order_id'] ?? 'N/A') . "\n");
        $printer->text("Date: " . date('Y-m-d H:i') . "\n");
        $printer->text("Cashier: " . ($orderData['cashier_username'] ?? 'Unknown') . "\n");
        if (isset($orderData['reason'])) {
            $printer->text("Reason: " . $orderData['reason'] . "\n");
        }
        $printer->text(str_repeat('-', $receiptWidth) . "\n");
        
        // Print refunded items
        if (isset($orderData['items']) && !empty($orderData['items'])) {
            $printer->setEmphasis(true);
            $printer->text(sprintf("%-20s %3s %9s\n", "Item", "Qty", "Amount"));
            $printer->setEmphasis(false);
            $printer->text(str_repeat('-', $receiptWidth) . "\n");
            
            $subtotal = 0;
            foreach ($orderData['items'] as $item) {
                $name = $item['product_name'] ?? $item['name'] ?? 'Unknown';
                $quantity = intval($item['quantity'] ?? 0);
                $price = floatval($item['price'] ?? 0);
                $amount = $quantity * $price;
                $subtotal += $amount;
                
                // Truncate name if too long
                if (strlen($name) > $receiptWidth) {
                    $name = substr($name, 0, $receiptTruncate) . '...';
                }
                $printer->text($name . "\n");
                
                // Print quantity x price and amount
                $qtyPrice = sprintf("%d x N$%.2f", $quantity, $price);
                $amountText = sprintf("N$%.2f", $amount);
                
                $spaces = $receiptWidth - strlen($qtyPrice) - strlen($amountText);
                if ($spaces < 1) $spaces = 1;
                
                $printer->text($qtyPrice . str_repeat(' ', $spaces) . $amountText . "\n");
                $printer->text(str_repeat('-', $receiptWidth) . "\n");
            }
            
            // Print totals
            $printer->text(str_repeat('=', $receiptWidth) . "\n");
            $printer->setEmphasis(true);
            $printer->text(sprintf("%-20s N$%8.2f\n", "REFUND TOTAL:", $subtotal));
            $printer->setEmphasis(false);
            $printer->text(str_repeat('=', $receiptWidth) . "\n");
        }
        
        // Footer section
        $printer->text(str_repeat('-', $receiptWidth) . "\n");
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->text($businessInfo['footer_text'] . "\n");
        $printer->feed(3); // Feed enough to ensure footer is fully printed before cut
    } else if (isset($orderData['is_balance_receipt']) && $orderData['is_balance_receipt']) {
        // Check if this is a tab balance receipt or credit sale balance receipt
        if (isset($orderData['is_tab_balance_receipt']) && $orderData['is_tab_balance_receipt']) {
            // TAB BALANCE RECEIPT - 48 chars
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->selectPrintMode(Printer::MODE_EMPHASIZED);
            $printer->text("TAB BALANCE RECEIPT\n");
            $printer->selectPrintMode();
            $printer->feed();
            $printer->setJustification(Printer::JUSTIFY_LEFT);
            $printer->text(str_repeat('-', $receiptWidth) . "\n");
            $printer->text(sprintf("%-10s %s\n", "Tab:", $orderData['tab_name'] ?? 'N/A'));
            if (isset($orderData['creditor_name']) && $orderData['creditor_name'] !== 'N/A') {
                $printer->text(sprintf("%-10s %s\n", "Client:", $orderData['creditor_name']));
            }
            $printer->text(str_repeat('-', $receiptWidth) . "\n");
            $printer->feed();
            
            // Print outstanding items
            if (isset($orderData['items']) && !empty($orderData['items'])) {
                $printer->setEmphasis(true);
                $printer->setEmphasis(false);
                $printer->text(str_repeat('-', $receiptWidth) . "\n");
                
                $itemsTotal = 0;
                foreach ($orderData['items'] as $item) {
                    $itemName = $item['name'];
                    if (strlen($itemName) > ($receiptWidth - 4)) {
                        $itemName = substr($itemName, 0, ($receiptWidth - 7)) . '...';
                    }
                    $qty = intval($item['quantity']);
                    $unitPrice = isset($item['unit_price']) ? floatval($item['unit_price']) : (floatval($item['price']) / $qty);
                    $itemTotal = floatval($item['price']);
                    $itemsTotal += $itemTotal;
                    
                    $printer->text($itemName . "\n");
                    $printer->text(sprintf("  %dx N$%.2f = N$%.2f\n", $qty, $unitPrice, $itemTotal));
                }
                $printer->text(str_repeat('-', $receiptWidth) . "\n");
                $printer->setEmphasis(true);
                $printer->text(sprintf("%-20s N$%8.2f\n", "ITEMS TOTAL:", $itemsTotal));
                $printer->setEmphasis(false);
                $printer->text(str_repeat('-', $receiptWidth) . "\n");
                $printer->feed();
            }
            
            // Print total balance
            $printer->setEmphasis(true);
            $printer->text(str_repeat('=', $receiptWidth) . "\n");
            $printer->text(sprintf("%-20s N$%8.2f\n", "OUTSTANDING BALANCE:", $orderData['total_balance']));
            $printer->setEmphasis(false);
            $printer->text(str_repeat('=', $receiptWidth) . "\n");
            // Reduced from feed(2) to feed(1) to save paper
            $printer->feed(1);
        } else {
            // CREDIT SALE BALANCE RECEIPT - 48 chars (original format)
            // Same as credit-book / credit-transactions: amount due = principal × 1.18 (18% interest included)
            $creditBalanceInterestFactor = 1.18;
            $totalBalanceWithInterest = round(floatval($orderData['total_balance'] ?? 0) * $creditBalanceInterestFactor, 2);

            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->selectPrintMode(Printer::MODE_EMPHASIZED);
            $printer->text("BALANCE RECEIPT\n");
            $printer->selectPrintMode();
            $printer->feed();
            $printer->setJustification(Printer::JUSTIFY_LEFT);
            $printer->text(str_repeat('-', $receiptWidth) . "\n");
            $printer->text(sprintf("%-10s %s\n", "Client:", $orderData['creditor_name']));
            $printer->text(sprintf("%-10s N$%8.2f\n", "Balance:", $totalBalanceWithInterest));
            $printer->text(str_repeat('-', $receiptWidth) . "\n");
            $printer->feed();
            
            // Print transaction details
            if (isset($orderData['transactions']) && !empty($orderData['transactions'])) {
                $printer->setEmphasis(true);
                $printer->text("OUTSTANDING ITEMS\n");
                $printer->setEmphasis(false);
                $printer->text(str_repeat('-', $receiptWidth) . "\n");
                
                foreach ($orderData['transactions'] as $transaction) {
                    $printer->text("Date: " . date('Y-m-d', strtotime($transaction['date'])) . "\n");
                    $printer->text(str_repeat('-', $receiptWidth) . "\n");
                    
                    // Split items string into individual items
                    $items = explode(', ', $transaction['items']);
                    foreach ($items as $item) {
                        // Truncate item name if too long
                        if (strlen($item) > $receiptWidth) {
                            $item = substr($item, 0, $receiptTruncate) . '...';
                        }
                        $printer->text($item . "\n");
                    }
                    
                    $txnBalanceWithInterest = round(floatval($transaction['balance'] ?? 0) * $creditBalanceInterestFactor, 2);
                    $printer->text(sprintf("%-20s N$%8.2f\n", "Balance:", $txnBalanceWithInterest));
                    $printer->text(str_repeat('-', $receiptWidth) . "\n");
                    $printer->feed();
                }
            }
            
            // Print total balance again
            $printer->setEmphasis(true);
            $printer->text(sprintf("%-20s N$%8.2f\n", "TOTAL BALANCE:", $totalBalanceWithInterest));
            $printer->setEmphasis(false);
            $printer->text(str_repeat('-', $receiptWidth) . "\n");
            // Reduced from feed(2) to feed(1) to save paper
            $printer->feed(1);
        }
        
        // Footer section
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->text($businessInfo['footer_text'] . "\n");
        // Feed enough to ensure footer is fully printed before cut
        $printer->feed(3);
    } else {
        // REGULAR RECEIPT PRINTING
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->text(str_repeat('-', $receiptWidth) . "\n");
        $printer->setEmphasis(true);
        // Determine receipt number - check for order_id, sale_id, tab_id, or table_id
        $receiptNumber = isset($orderData['order_id']) ? $orderData['order_id'] : 
                        (isset($orderData['sale_id']) ? $orderData['sale_id'] : 
                        (isset($orderData['tab_id']) ? $orderData['tab_id'] : 
                        (isset($orderData['table_id']) ? $orderData['table_id'] : uniqid())));
        
        // For payment receipts, show like normal receipts
        if (isset($orderData['is_payment_receipt']) && $orderData['is_payment_receipt']) {
            // Show receipt type and number like normal receipts
            $printer->text("Payment Bill Receipt #: " . $receiptNumber . "\n");
            $printer->setEmphasis(false);
        } else if (isset($orderData['table_id']) || isset($orderData['tab_id'])) {
            // For tab sales (not payments), show "KITCHEN ORDER RECEIPT" format
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->setEmphasis(true);
            $printer->text("ORDER!!  No: " . $receiptNumber . "\n");
            $printer->setEmphasis(false);
            $printer->feed();
            $printer->setJustification(Printer::JUSTIFY_LEFT);
            // Show table name
            if (isset($orderData['table_name'])) {
                $tableName = $orderData['table_name'];
                $maxTableNameLen = $receiptWidth - 8; // "Table : " is 8 chars
                if (strlen($tableName) > $maxTableNameLen) {
                    $tableName = substr($tableName, 0, $maxTableNameLen - 3) . '...';
                }
                $printer->text("Table : " . $tableName . "\n");
            } else {
                $printer->text("Table : Table " . ($orderData['table_id'] ?? $orderData['tab_id'] ?? 'N/A') . "\n");
            }
            // Show time
            $printer->text("Time : " . date('Y-m-d H:i') . "\n");
            // Show cashier name
            if (isset($orderData['cashier_username'])) {
                $printer->text("By : " . $orderData['cashier_username'] . "\n");
            } else {
                $printer->text("By : Cashier\n");
            }
        } else {
            // Regular receipts
            $receiptType = isset($orderData['sale_id']) ? "Credit Sale" : "Receipt";
        $printer->text($receiptType . " #: " . $receiptNumber . "\n");
        $printer->setEmphasis(false);
        }
        
        // Only show date and order type for non-tab sales
        $isTabSale = isset($orderData['table_id']) || isset($orderData['tab_id']);
        if (!$isTabSale || isset($orderData['is_payment_receipt'])) {
            $printer->text(str_repeat('-', $receiptWidth) . "\n");
            $printer->text("Date: " . date('Y-m-d H:i') . "\n");
            // Optional order type (dine-in vs takeaway) for restaurant POS
            if (isset($orderData['order_type'])) {
                $typeLabel = strtolower($orderData['order_type']) === 'takeaway' ? 'Takeaway' : 'Dine-in';
                $printer->text("Order: " . $typeLabel . "\n");
            }
            // Removed feed() - items header comes next
            
            // Items section header (only for non-tab sales)
            $printer->setEmphasis(true);
            $printer->text(sprintf("%-20s %3s %9s\n", "Item", "Qty", "Amount"));
            $printer->setEmphasis(false);
            $printer->text(str_repeat('-', $receiptWidth) . "\n");
        } else {
            // For tab sales, items come directly after header (no extra spacing)
        }
        
        $subtotal = 0;
        
        // For tab sales, show "ITEMS" header
        if ($isTabSale && !isset($orderData['is_payment_receipt'])) {
            $printer->setEmphasis(true);
            $printer->text("ITEMS\n");
            $printer->setEmphasis(false);
        }
        
        foreach ($orderData['items'] as $item) {
            $name = $item['name'];
            $quantity = $item['quantity'];
            $price = $item['price'] / $quantity;
            $amount = $item['price'];
            $subtotal += $amount;
            
            // For tab sales (kitchen tickets), use simple format: "x1 Ice Tea"
            if ($isTabSale && !isset($orderData['is_payment_receipt'])) {
                // Simple kitchen ticket format
                $maxTabItemLen = $receiptWidth - 5; // "x" + quantity + space is ~5 chars
                if (strlen($name) > $maxTabItemLen) {
                    $name = substr($name, 0, $maxTabItemLen - 3) . '...';
                }
                $printer->text("x" . $quantity . " " . $name . "\n");
            } else {
                // Regular receipt format
                // Print item name (truncate if too long)
                if (strlen($name) > $receiptWidth) {
                    $name = substr($name, 0, $receiptTruncate) . '...';
                }
                $printer->text($name . "\n");
                
                // Print quantity x price and amount on next line
                $qtyPrice = sprintf("%d x N$%.2f", $quantity, $price);
                $amountText = sprintf("N$%.2f", $amount);
                
                // Ensure proper alignment within receipt width
                $spaces = $receiptWidth - strlen($qtyPrice) - strlen($amountText);
                if ($spaces < 1) $spaces = 1;
                
                $printer->text($qtyPrice . str_repeat(' ', $spaces) . $amountText . "\n");
                $printer->text(str_repeat('-', $receiptWidth) . "\n");
            }
        }
        
        // Totals section - simplified for tab sales
        $printer->text(str_repeat('-', $receiptWidth) . "\n");
        
        // For payment receipts, show total and payment info
        if (isset($orderData['is_payment_receipt']) && $orderData['is_payment_receipt']) {
            // Get VAT settings from order data or use defaults
            $paymentVatInclusive = $orderData['vat_inclusive'] ?? $vatInclusive;
            $paymentVatRate = isset($orderData['vat_rate']) ? floatval($orderData['vat_rate']) : $vatRate;
            $tips = floatval($orderData['tips'] ?? 0);
            
            // Calculate VAT for payment receipt
            $vatAmount = 0;
            $displaySubtotal = $subtotal;
            $displayTotal = $subtotal;
            
            if ($paymentVatInclusive === 'exclusive') {
                // Prices exclude VAT, so total is just the subtotal (VAT not included)
                $vatAmount = $subtotal * ($paymentVatRate / 100);
                $displaySubtotal = $subtotal;
                $displayTotal = $subtotal; // Total does not include VAT when VAT is excluded
                
                // Don't show VAT breakdown for exclusive - just show total
            } else {
                // Prices include VAT, so show VAT breakdown
                $vatAmount = $subtotal - ($subtotal / (1 + ($paymentVatRate / 100)));
                $displaySubtotal = $subtotal - $vatAmount;
                $displayTotal = $subtotal;
                
                // Show subtotal (exclusive)
                $printer->text(sprintf("%-20s N$%8.2f\n", "Subtotal (ex VAT):", $displaySubtotal));
                // Show VAT
                $printer->text(sprintf("%-20s N$%8.2f\n", "VAT (" . number_format($paymentVatRate, 2) . "%):", $vatAmount));
                $printer->text(str_repeat('-', $receiptWidth) . "\n");
            }

            // Tips (optional)
            if ($tips > 0) {
                $printer->text(sprintf("%-20s N$%8.2f\n", "Tips:", $tips));
                $printer->text(str_repeat('-', $receiptWidth) . "\n");
                $displayTotal += $tips;
            }
            
            // Show total for payment receipt
            $printer->setEmphasis(true);
            $totalText = sprintf("PAID AMOUNT: N$ %8.2f", $displayTotal);
            $spaces = $receiptWidth - strlen($totalText);
            $printer->text(str_repeat(' ', $spaces) . $totalText . "\n");
            $printer->setEmphasis(false);
            $printer->feed();
            
            // Payment information section for payment receipts
            $printer->text(str_repeat('-', $receiptWidth) . "\n");
            $printer->setEmphasis(true);
            $printer->text("PAYMENT INFORMATION\n");
            $printer->setEmphasis(false);
            $printer->text(str_repeat('-', $receiptWidth) . "\n");
            
            // Display payment method details
            if (isset($orderData['payment_method'])) {
                if ($orderData['payment_method'] === 'cash') {
                    $printer->text("Method: Cash\n");
                    if (isset($orderData['cash_received'])) {
                        $printer->text(sprintf("%-10s N$%8.2f\n", "Paid:", $orderData['cash_received']));
                        $change = max(0, $orderData['cash_received'] - $displayTotal);
                        if ($change > 0) {
                            $printer->text(sprintf("%-10s N$%8.2f\n", "Change:", $change));
                        }
                    }
                } else if ($orderData['payment_method'] === 'eft') {
                    $printer->text("Method: EFT\n");
                    if (isset($orderData['wallet_provider']) && !empty($orderData['wallet_provider'])) {
                        $printer->text(sprintf("%-10s %s\n", "Provider:", $orderData['wallet_provider']));
                    }
                    if (isset($orderData['transaction_ref']) && !empty($orderData['transaction_ref'])) {
                        $ref = $orderData['transaction_ref'];
                        if (strlen($ref) > ($receiptWidth - 12)) {
                            $ref = substr($ref, 0, ($receiptWidth - 15)) . '...';
                        }
                        $printer->text(sprintf("%-10s %s\n", "Ref:", $ref));
                    }
                    $printer->text(sprintf("%-10s N$%8.2f\n", "Paid:", $displayTotal));
                } else if ($orderData['payment_method'] === 'mixed') {
                    $printer->text("Method: Mixed Payment\n");
                    $printer->text(str_repeat('-', $receiptWidth) . "\n");
                    
                    // Cash portion
                    if (isset($orderData['cash_amount']) && $orderData['cash_amount'] > 0) {
                        $printer->text(sprintf("%-10s N$%8.2f\n", "Cash:", $orderData['cash_amount']));
                    }
                    
                    // EFT portion
                    if (isset($orderData['eft_amount']) && $orderData['eft_amount'] > 0) {
                        $printer->text(sprintf("%-10s N$%8.2f\n", "EFT:", $orderData['eft_amount']));
                        if (isset($orderData['eft_wallet_provider']) && !empty($orderData['eft_wallet_provider'])) {
                            $printer->text(sprintf("%-10s %s\n", "Provider:", $orderData['eft_wallet_provider']));
                        }
                        if (isset($orderData['eft_transaction_ref']) && !empty($orderData['eft_transaction_ref'])) {
                            $ref = $orderData['eft_transaction_ref'];
                            if (strlen($ref) > ($receiptWidth - 12)) {
                                $ref = substr($ref, 0, ($receiptWidth - 15)) . '...';
                            }
                            $printer->text(sprintf("%-10s %s\n", "Ref:", $ref));
                        }
                    }
                    
                    $printer->text(str_repeat('-', $receiptWidth) . "\n");
                    $printer->text(sprintf("%-10s N$%8.2f\n", "Total:", $displayTotal));
                }
            }
            $printer->feed();
        } else if (isset($orderData['table_id']) || isset($orderData['tab_id'])) {
            // Kitchen ticket format - show "SEND TO KITCHEN" message
            $printer->feed();
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->setEmphasis(true);
            $printer->setEmphasis(false);
            $printer->setJustification(Printer::JUSTIFY_LEFT);
            // Add extra feed for kitchen tickets to ensure content is fully printed before cut
            $printer->feed(2);
        } else {
            // Regular receipts with VAT breakdown
            // Calculate VAT based on settings
            $vatAmount = 0;
            $displaySubtotal = $subtotal;
            $displayTotal = $subtotal;
            
            if ($vatInclusive === 'exclusive') {
                // Prices exclude VAT, so total is just the subtotal (VAT not included)
                $vatAmount = $subtotal * ($vatRate / 100);
                $displaySubtotal = $subtotal;
                $displayTotal = $subtotal; // Total does not include VAT when VAT is excluded
                
                // Don't show VAT breakdown for exclusive - just show total
            } else {
                // Prices include VAT, so show VAT breakdown
                $vatAmount = $subtotal - ($subtotal / (1 + ($vatRate / 100)));
                $displaySubtotal = $subtotal - $vatAmount;
                $displayTotal = $subtotal;
                
                // Show subtotal (exclusive)
                $printer->text(sprintf("%-20s N$%8.2f\n", "Subtotal (ex VAT):", $displaySubtotal));
                // Show VAT
                $printer->text(sprintf("%-20s N$%8.2f\n", "VAT (" . number_format($vatRate, 2) . "%):", $vatAmount));
                $printer->text(str_repeat('-', $receiptWidth) . "\n");
            }
            
            // Show total
            $printer->setEmphasis(true);
            $totalText = sprintf("TOTAL: N$ %8.2f", $displayTotal);
            $spaces = $receiptWidth - strlen($totalText);
            $printer->text(str_repeat(' ', $spaces) . $totalText . "\n");
            $printer->setEmphasis(false);
            $printer->feed();
            
            // Payment information section (for non-tab sales and payment receipts)
            $printer->text(str_repeat('-', $receiptWidth) . "\n");
            $printer->setEmphasis(true);
            $printer->text("PAYMENT INFORMATION\n");
            $printer->setEmphasis(false);
            $printer->text(str_repeat('-', $receiptWidth) . "\n");
            
            if (isset($orderData['creditor_id'])) {
                // Credit payment
            $printer->text("Method: Credit\n");
            $printer->text(sprintf("%-10s %s\n", "ID:", $orderData['creditor_id']));
            if (isset($orderData['creditor_name'])) {
                $printer->text(sprintf("%-10s %s\n", "Name:", $orderData['creditor_name']));
            }
            if (isset($orderData['due_date'])) {
                $printer->text(sprintf("%-10s %s\n", "Due:", $orderData['due_date']));
            }
            // Show partial payment info if not fully paid
            if (isset($orderData['payment_type']) && $orderData['payment_type'] === 'cash' && isset($orderData['cash_received']) && isset($orderData['total_amount'])) {
                if ($orderData['cash_received'] < $orderData['total_amount']) {
                    $printer->setEmphasis(true);
                    $printer->text("Partial Payment\n");
                    $printer->setEmphasis(false);
                    $printer->text(sprintf("%-10s N$%8.2f\n", "Paid:", $orderData['cash_received']));
                    $printer->text(sprintf("%-10s N$%8.2f\n", "Balance:", $orderData['total_amount'] - $orderData['cash_received']));
                }
            }
            if (isset($orderData['payment_method']) && $orderData['payment_method'] === 'e-wallet' && isset($orderData['payment_amount']) && isset($orderData['total_amount'])) {
                if ($orderData['payment_amount'] < $orderData['total_amount']) {
                    $printer->setEmphasis(true);
                    $printer->text("Partial Payment (EFT)\n");
                    $printer->setEmphasis(false);
                    $printer->text(sprintf("%-10s N$%8.2f\n", "Paid:", $orderData['payment_amount']));
                    $printer->text(sprintf("%-10s N$%8.2f\n", "Balance:", $orderData['total_amount'] - $orderData['payment_amount']));
                }
            }
            $printer->feed();
            // Add barcode for creditor ID ONLY for credit sales - IMPROVED BARCODE LOGIC
            if (isset($orderData['creditor_id'])) {
                $printer->setJustification(Printer::JUSTIFY_CENTER);
                $printer->text("Creditor ID:\n");
                $printer->feed(1);
                
                $barcodePrinted = false;
                $barcodeFormat = 'none';
                
                // Prepare creditor ID for barcode (ensure it's clean and valid)
                $barcodeData = trim(strval($orderData['creditor_id']));
                
                // Ensure we have valid data
                if (empty($barcodeData)) {
                    $barcodeData = "CRED" . $orderData['creditor_id'];
                }
                
                // Initialize printer for barcode printing
                $printer->initialize();
                
                // Use CODE39 barcode (based on the working example)
                try {
                    // Set barcode parameters for better visibility
                    $printer->setBarcodeHeight(80);        // Increased height for better visibility
                    $printer->setBarcodeWidth(3);          // Increased width for better scanning
                    $printer->setBarcodeTextPosition(Printer::BARCODE_TEXT_NONE); // Don't show text below barcode
                    $printer->setJustification(Printer::JUSTIFY_CENTER);
                    
                    // CODE39 format: Add asterisks around the data (as shown in example)
                    // For pure numbers, add a prefix internally for barcode generation only
                    if (is_numeric($barcodeData)) {
                        $code39Data = "*CRED" . $barcodeData . "*";
                    } else {
                        $code39Data = "*" . strtoupper($barcodeData) . "*";
                    }
                    
                    // Print CODE39 barcode (works with both text and numbers)
                    $printer->barcode($code39Data, Printer::BARCODE_CODE39);
                    
                    // Manually print the creditor ID below the barcode
                    $printer->setBarcodeTextPosition(Printer::BARCODE_TEXT_NONE);
                    $printer->text("\n" . $barcodeData . "\n");
                    // Reduced from feed(2) to feed(1) to save paper
                    $printer->feed(1);
                    
                    $barcodePrinted = true;
                    $barcodeFormat = 'CODE39';
                    
                } catch (Exception $e) {
                    error_log("CODE39 barcode failed with error: " . $e->getMessage());
                    
                    // Try CODE128 as fallback
                    try {
                        error_log("Trying CODE128 as fallback...");
                        
                        $printer->initialize();
                        $printer->setBarcodeHeight(80);
                        $printer->setBarcodeWidth(3);
                        $printer->setBarcodeTextPosition(Printer::BARCODE_TEXT_BELOW);
                        $printer->setJustification(Printer::JUSTIFY_CENTER);
                        
                        $printer->barcode($barcodeData, Printer::BARCODE_CODE128);
                        // Reduced from feed(2) to feed(1) to save paper
                        $printer->feed(1);
                        
                        $barcodePrinted = true;
                        $barcodeFormat = 'CODE128';
                        error_log("CODE128 barcode printed successfully for creditor ID: " . $orderData['creditor_id']);
                        
                    } catch (Exception $e2) {
                        error_log("CODE128 fallback also failed: " . $e2->getMessage());
                    }
                }
                
                // If all barcode formats failed, print as large text with manual number display
                if (!$barcodePrinted) {
                    $printer->setJustification(Printer::JUSTIFY_CENTER);
                    $printer->text("*** CREDITOR ID ***\n");
                    $printer->selectPrintMode(Printer::MODE_DOUBLE_WIDTH | Printer::MODE_DOUBLE_HEIGHT | Printer::MODE_EMPHASIZED);
                    $printer->text($orderData['creditor_id'] . "\n");
                    $printer->selectPrintMode();
                    $printer->text("*** SCAN CODE ***\n");
                    
                    $barcodeFormat = 'text_fallback';
                    error_log("All barcode formats failed, using text fallback for creditor ID: " . $orderData['creditor_id']);
                }
                
                $printer->setJustification(Printer::JUSTIFY_LEFT); // Reset alignment
            }
        } else if (isset($orderData['payment_method']) && $orderData['payment_method'] === 'e-wallet') {
            // E-wallet payment
            $printer->text("Method: EFT\n");
            $printer->text(sprintf("%-10s %s\n", "Provider:", $orderData['wallet_provider']));
            $ref = $orderData['transaction_ref'];
            $maxRefLen = $receiptWidth - 5; // "Ref: " prefix is ~5 chars
            if (strlen($ref) > $maxRefLen) {
                $ref = substr($ref, 0, $maxRefLen - 3) . '...';
            }
            $printer->text(sprintf("%-10s %s\n", "Ref:", $ref));
            $printer->text(sprintf("%-10s N$%8.2f\n", "Paid:", $subtotal));
        } else if (isset($orderData['payment_method']) && $orderData['payment_method'] === 'mixed') {
            // Mixed payment (Cash + EFT)
            $printer->text("Method: Mixed Payment\n");
            $printer->text(str_repeat('-', $receiptWidth) . "\n");
            
            // Cash portion
            if (isset($orderData['cash_amount']) && $orderData['cash_amount'] > 0) {
                $printer->text(sprintf("%-10s N$%8.2f\n", "Cash:", $orderData['cash_amount']));
            }
            
            // EFT portion
            if (isset($orderData['eft_amount']) && $orderData['eft_amount'] > 0) {
                $printer->text(sprintf("%-10s N$%8.2f\n", "EFT:", $orderData['eft_amount']));
                if (isset($orderData['wallet_provider'])) {
                    $printer->text(sprintf("%-10s %s\n", "Provider:", $orderData['wallet_provider']));
                }
                    if (isset($orderData['transaction_ref']) && !empty($orderData['transaction_ref'])) {
                        $ref = $orderData['transaction_ref'];
                        if (strlen($ref) > ($receiptWidth - 12)) {
                            $ref = substr($ref, 0, ($receiptWidth - 15)) . '...';
                        }
                        $printer->text(sprintf("%-10s %s\n", "Ref:", $ref));
                    }
                }
                
                $printer->text(str_repeat('-', $receiptWidth) . "\n");
                $printer->text(sprintf("%-10s N$%8.2f\n", "Total:", $subtotal));
            
            // Calculate and show change if cash amount is greater than total
            if (isset($orderData['cash_amount']) && $orderData['cash_amount'] > $subtotal) {
                $change = max(0, $orderData['cash_amount'] - $subtotal);
                $changeText = sprintf("Change: %10s", "N$ " . number_format($change, 2));
                $printer->text($changeText . "\n");
            }
        } else {
            // Cash payment
            $printer->text("Method: Cash\n");
            $paidText = sprintf("Paid: %10s", "N$ " . number_format($orderData['cash_received'], 2));
            $printer->text($paidText . "\n");
            $change = max(0, $orderData['cash_received'] - $subtotal);
            $changeText = sprintf("Change: %8s", "N$ " . number_format($change, 2));
            $printer->text($changeText . "\n");
            }
        }
        
        // Footer section - skip for kitchen tickets (tab sales)
        if (!$isTabSale || $isPaymentReceipt) {
            $printer->text(str_repeat('-', $receiptWidth) . "\n");
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->text($businessInfo['footer_text'] . "\n");
            // Feed enough to ensure footer is fully printed before cut
            $printer->feed(3);
        }
    }

    // Send final commands to stop printer and prevent further printing
    try {
        // Ensure all content including footer is fully printed before cutting
        // For kitchen tickets (tab sales), add extra feed since footer is skipped
        $isKitchenTicket = (isset($orderData['table_id']) || isset($orderData['tab_id'])) && !isset($orderData['is_payment_receipt']);
        if ($isKitchenTicket) {
            $printer->feed(4); // Extra feed for kitchen tickets to prevent premature cutting
        } else {
            $printer->feed(1);
        }
        
        // Partial cut command (ESC m)
        $connector->write(chr(27).chr(109));
        
        // Full cut
        $printer->cut();
        
        // Initialize printer (ESC @) - resets printer and clears buffer to prevent further printing
        $printer->initialize();
        
        // Close the printer connection
        $printer->close();
        
        error_log("Printing completed - printer stopped and connection closed");
    } catch (\Throwable $e) {
        error_log("Error stopping printer: " . $e->getMessage());
        // Try to close anyway
        try {
            $printer->close();
        } catch (\Throwable $closeError) {
            // Ignore close errors
        }
        throw new Exception("Failed to complete printing: " . $e->getMessage());
    }

    if ($rawMode) {
        $rawBytes = '';
        if ($rawConnector) {
            $rawBytes = $rawConnector->getData();
        } elseif (isset($connector) && $connector instanceof RawCaptureConnector) {
            $rawBytes = $connector->getData();
        }

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'raw_base64' => base64_encode($rawBytes),
            'order_data' => $orderData
        ]);
        exit;
    }

    // Return success response with enriched orderData for Android interceptor
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true, 
        'message' => 'Receipt printed successfully',
        'printer_used' => $printerName,
        'client_ip' => $clientIP,
        'connection_type' => $isNetworkPrinter ? 'network' : 'local',
        'order_data' => $orderData  // Include enriched orderData for Android
    ]);

} catch (\Throwable $e) {
    // Enrich orderData even on error for Android compatibility
    if (isset($orderData) && is_array($orderData)) {
        try {
            $db = new PDO('sqlite:info.db');
            $businessInfo = $db->query("SELECT * FROM business_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if ($businessInfo) {
                $orderData['business_name'] = $businessInfo['name'] ?? 'POS SOLUTION';
                $orderData['location'] = $businessInfo['location'] ?? '';
                $orderData['phone'] = $businessInfo['phone'] ?? '';
                $orderData['footer_text'] = $businessInfo['footer_text'] ?? 'Thank you for your purchase!';
                $orderData['vat_inclusive'] = $businessInfo['vat_inclusive'] ?? 'exclusive';
                $orderData['vat_rate'] = isset($businessInfo['vat_rate']) ? floatval($businessInfo['vat_rate']) : 15.0;
            }
        } catch (Exception $dbError) {
            // Ignore database errors in error handler
        }
    }
    
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => $e->getMessage(),
        'printer_attempted' => $printerName ?? 'unknown',
        'client_ip' => $clientIP ?? 'unknown',
        'connection_type' => ($isNetworkPrinter ?? false) ? 'network' : 'local',
        'details' => 'Please check if the printer is connected and powered on.',
        'order_data' => $orderData ?? []  // Include orderData even on error for Android
    ]);
}
exit;
?>