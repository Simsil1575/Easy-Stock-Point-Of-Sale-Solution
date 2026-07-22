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
 * 4b. Tab Copy / Guest Check (is_tab_copy_receipt: true)
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
    var isLaybyeBalanceReceipt = receiptData.is_laybye_balance_receipt === true;
    var isTabCopyReceipt = receiptData.is_tab_copy_receipt === true;
    if (!receiptData.print_only && !isCashupReport && !isBalanceReceipt && !isTabBalanceReceipt && !isTabCopyReceipt && !isLaybyeBalanceReceipt && !isPaymentReceipt && !isRefundReceipt && !isDrawerOnly) {
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
        logo_path: receiptData.logo_path || (businessInfoSource ? businessInfoSource.logo_path : null),
        vat_inclusive: receiptData.vat_inclusive || (businessInfoSource ? businessInfoSource.vat_inclusive : null),
        vat_rate: receiptData.vat_rate || (businessInfoSource ? businessInfoSource.vat_rate : null)
    });
    
    // Kitchen network printer: server opens TCP to configured IP (Admin → Settings). Bypass QZ Tray and Android local print.
    if (dataWithBusiness.print_to_kitchen_printer === true) {
        var receiptTypeKitchen = 'Kitchen (network printer)';
        var receiptUrlKitchen = (window.location && window.location.origin ? window.location.origin : '') + '/receipt.php';
        return fetch(receiptUrlKitchen, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(dataWithBusiness)
        }).then(function(r) {
            return r.json().then(function(result) {
                result.receipt_type = receiptTypeKitchen;
                return result;
            });
        }).catch(function(error) {
            console.error('[sendToPrinter] Kitchen network print error:', error);
            return { success: false, message: 'Kitchen print failed: ' + error.message, receipt_type: receiptTypeKitchen };
        });
    }
    
    // Log receipt type for debugging
    var receiptType = 'Unknown';
    if (isCashupReport) receiptType = 'Cash-up Report';
    else if (receiptData.is_tab_copy_receipt === true) receiptType = 'Tab Copy Receipt';
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
    
    // #region agent log
    if (isPaymentReceipt) {
        fetch('http://127.0.0.1:7918/ingest/543ece8e-e9a4-4ceb-9f09-b26f1ebce51b',{method:'POST',headers:{'Content-Type':'application/json','X-Debug-Session-Id':'833f8f'},body:JSON.stringify({sessionId:'833f8f',location:'receipt.php:sendToPrinter',message:'Payment receipt client payload',data:{order_id:receiptData.order_id,tab_id:receiptData.tab_id,gratuity_amount:receiptData.gratuity_amount,gratuity:receiptData.gratuity,gratuity_percent_applied:receiptData.gratuity_percent_applied,gratuity_included_in_total:receiptData.gratuity_included_in_total,tips:receiptData.tips,total:receiptData.total},timestamp:Date.now(),hypothesisId:'B',runId:'post-fix'})}).catch(function(){});
    } else if (isTabCopyReceipt) {
        fetch('http://127.0.0.1:7918/ingest/543ece8e-e9a4-4ceb-9f09-b26f1ebce51b',{method:'POST',headers:{'Content-Type':'application/json','X-Debug-Session-Id':'833f8f'},body:JSON.stringify({sessionId:'833f8f',location:'receipt.php:sendToPrinter',message:'Copy receipt client payload',data:{tab_id:receiptData.tab_id,gratuity:receiptData.gratuity,gratuity_percent_applied:receiptData.gratuity_percent_applied,gratuity_included_in_total:receiptData.gratuity_included_in_total,total_balance:receiptData.total_balance},timestamp:Date.now(),hypothesisId:'B',runId:'post-fix'})}).catch(function(){});
    }
    // #endregion
    
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
                var qzStorageKey = 'qz_print_' + Date.now() + '_' + Math.random().toString(36).slice(2);
                try {
                    sessionStorage.setItem(qzStorageKey, JSON.stringify(dataWithBusiness));
                } catch (storageErr) {
                    console.warn('[sendToPrinter] sessionStorage unavailable for QZ payload, using URL data param', storageErr);
                    qzStorageKey = null;
                }
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
                var qzBase = (window.location && window.location.origin ? window.location.origin : '') + '/qzreceipt.php';
                iframe.src = qzStorageKey
                    ? (qzBase + '?key=' + encodeURIComponent(qzStorageKey))
                    : (qzBase + '?data=' + encoded);
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

require_once __DIR__ . '/receipt_payment_helper.php';

use Mike42\Escpos\Printer;
use Mike42\Escpos\PrintConnectors\WindowsPrintConnector;
use Mike42\Escpos\PrintConnectors\NetworkPrintConnector;
use Mike42\Escpos\PrintConnectors\PrintConnector;
use Mike42\Escpos\EscposImage;

// #region agent log
function debug833f8f_log($location, $message, $data, $hypothesisId) {
    $logPath = __DIR__ . '/debug-833f8f.log';
    @file_put_contents($logPath, json_encode([
        'sessionId' => '833f8f',
        'timestamp' => (int) round(microtime(true) * 1000),
        'location' => $location,
        'message' => $message,
        'data' => $data,
        'hypothesisId' => $hypothesisId,
        'runId' => 'post-fix',
    ]) . "\n", FILE_APPEND);
}
// #endregion

function receipt_format_gratuity_label(array $orderData): string
{
    $gPctRaw = $orderData['gratuity_percent_applied'] ?? null;
    $gLab = 'Gratuity';
    if ($gPctRaw !== null && $gPctRaw !== '') {
        $gPctStr = rtrim(rtrim(number_format((float) $gPctRaw, 2, '.', ''), '0'), '.');
        if ($gPctStr !== '' && $gPctStr !== '.') {
            $gLab .= " ({$gPctStr}%)";
        }
    }
    return $gLab;
}

function receipt_resolve_tab_gratuity_for_print(array $orderData, float $gratuityFromItemLines = 0.0): float
{
    $tips = round(floatval($orderData['tips'] ?? 0), 2);
    $amt = round(floatval($orderData['gratuity'] ?? 0), 2);
    if ($amt <= 0) {
        $combined = round(floatval($orderData['gratuity_amount'] ?? 0), 2);
        if ($combined > 0 && $tips > 0.005 && $combined + 0.001 >= $tips && !empty($orderData['gratuity_percent_applied'])) {
            $amt = round(max(0.0, $combined - $tips), 2);
        } elseif ($combined > 0) {
            $amt = $combined;
        }
    }
    if ($amt <= 0 && $gratuityFromItemLines > 0) {
        $amt = round($gratuityFromItemLines, 2);
    }
    return $amt;
}

/**
 * Ensure tab copy / payment receipts include gratuity when enabled on the tab.
 * Fills missing client fields (e.g. QZ URL truncation) from pos.db and order rows.
 */
function receipt_enrich_tab_gratuity_for_print(array &$orderData): void
{
    $tabId = (int) ($orderData['tab_id'] ?? 0);
    $isCopy = !empty($orderData['is_tab_copy_receipt']);
    $isPayment = !empty($orderData['is_payment_receipt']);
    if (!$isCopy && !$isPayment) {
        return;
    }

    require_once __DIR__ . '/tab_balance_helper.php';
    require_once __DIR__ . '/receipt_payment_helper.php';
    try {
        $posDb = new PDO('sqlite:' . __DIR__ . '/pos.db');
        $posDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (PDOException $e) {
        return;
    }

    ensureTabGratuityColumns($posDb);
    $settings = tab_gratuity_settings($posDb);
    $defaultPct = $settings['feature_enabled'] ? $settings['percent'] : null;

    $tabRow = null;
    if ($tabId > 0) {
        $tabStmt = $posDb->prepare('SELECT gratuity_enabled, COALESCE(gratuity_paid, 0) AS gratuity_paid FROM tabs WHERE id = ?');
        $tabStmt->execute([$tabId]);
        $tabRow = $tabStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if ($isPayment && !empty($orderData['order_id'])) {
        $orderStmt = $posDb->prepare(
            'SELECT gratuity_amount, gratuity_percent_applied, gratuity_included_in_total FROM orders WHERE id = ? LIMIT 1'
        );
        $orderStmt->execute([(int) $orderData['order_id']]);
        $orderRow = $orderStmt->fetch(PDO::FETCH_ASSOC);
        if ($orderRow) {
            if (!isset($orderData['gratuity_amount']) || floatval($orderData['gratuity_amount']) <= 0.005) {
                $orderData['gratuity_amount'] = floatval($orderRow['gratuity_amount'] ?? 0);
            }
            if (($orderData['gratuity_percent_applied'] ?? null) === null && $orderRow['gratuity_percent_applied'] !== null) {
                $orderData['gratuity_percent_applied'] = $orderRow['gratuity_percent_applied'];
            }
            if (!isset($orderData['gratuity_included_in_total'])) {
                $orderData['gratuity_included_in_total'] = (int) ($orderRow['gratuity_included_in_total'] ?? 1);
            }
        }
    }

    $tips = round(floatval($orderData['tips'] ?? 0), 2);
    $resolved = receipt_resolve_tab_gratuity_for_print($orderData, 0.0);
    if ($resolved > 0.005) {
        if (round(floatval($orderData['gratuity'] ?? 0), 2) <= 0.005) {
            $orderData['gratuity'] = $resolved;
        }
        if (($orderData['gratuity_percent_applied'] ?? null) === null && $defaultPct !== null) {
            $orderData['gratuity_percent_applied'] = $defaultPct;
        }
        $orderData['gratuity_included_in_total'] = 1;
        return;
    }

    if ($tabId <= 0 || $tabRow === null || !tab_is_gratuity_enabled_for_tab($tabRow) || $defaultPct === null) {
        return;
    }

    $computed = tab_compute_gratuity_amount($posDb, $tabId, $tabRow);

    if ($isCopy) {
        $remaining = tab_gratuity_remaining($posDb, $tabId, $tabRow);
        $amt = $computed > 0.005 ? round($remaining > 0.001 ? $remaining : $computed, 2) : 0.0;
        if ($amt <= 0.005 && $defaultPct !== null && !empty($orderData['items']) && is_array($orderData['items'])) {
            $lineSum = 0.0;
            foreach ($orderData['items'] as $item) {
                if (trim((string) ($item['name'] ?? '')) === 'Gratuity') {
                    continue;
                }
                $lineSum += floatval($item['price'] ?? 0);
            }
            if ($lineSum > 0.005) {
                $amt = round($lineSum * ($defaultPct / 100), 2);
            }
        }
        if ($amt > 0.005) {
            $orderData['gratuity'] = $amt;
            $orderData['gratuity_percent_applied'] = $orderData['gratuity_percent_applied'] ?? $defaultPct;
            $orderData['gratuity_included_in_total'] = 1;
        }
        return;
    }

    if ($computed <= 0.005) {
        return;
    }

    // Payment bill: only backfill when this payment recorded tab gratuity on the order.
    $combined = round(floatval($orderData['gratuity_amount'] ?? 0), 2);
    $fromOrder = round(max(0.0, $combined - $tips), 2);
    if ($fromOrder > 0.005) {
        $orderData['gratuity'] = $fromOrder;
        $orderData['gratuity_percent_applied'] = $orderData['gratuity_percent_applied'] ?? $defaultPct;
        $orderData['gratuity_included_in_total'] = 1;
    }
}

function resolveBusinessLogoFilePath(array $businessInfo): ?string
{
    $relative = trim((string)($businessInfo['logo_path'] ?? ''));
    if ($relative === '') {
        return null;
    }
    $full = __DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
    return is_file($full) ? $full : null;
}

function receiptCharsToPaperMm(int $receiptWidth): int
{
    return ($receiptWidth >= 42) ? 80 : 58;
}

function loadLogoImageResource(string $logoFile)
{
    $ext = strtolower(pathinfo($logoFile, PATHINFO_EXTENSION));
    if ($ext === 'jpg' || $ext === 'jpeg') {
        return @imagecreatefromjpeg($logoFile);
    }
    if ($ext === 'gif') {
        return @imagecreatefromgif($logoFile);
    }
    if ($ext === 'webp' && function_exists('imagecreatefromwebp')) {
        return @imagecreatefromwebp($logoFile);
    }
    return @imagecreatefrompng($logoFile);
}

function prepareBusinessLogoForPrinter(string $logoFile, int $maxWidthPx): string
{
    if (!function_exists('getimagesize') || !function_exists('imagecreatetruecolor')) {
        return $logoFile;
    }
    $size = @getimagesize($logoFile);
    if (!$size || $size[0] <= 0 || $size[1] <= 0) {
        return $logoFile;
    }

    $src = loadLogoImageResource($logoFile);
    if (!$src) {
        return $logoFile;
    }

    $srcW = $size[0];
    $srcH = $size[1];
    $dstW = min($srcW, $maxWidthPx);
    $dstH = max(1, (int)round($srcH * ($dstW / $srcW)));

    $dst = imagecreatetruecolor($dstW, $dstH);
    if ($dst === false) {
        imagedestroy($src);
        return $logoFile;
    }
    // Flatten onto white — thermal printers render PNG alpha as noise or solid black.
    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefilledrectangle($dst, 0, 0, $dstW, $dstH, $white);
    imagealphablending($dst, true);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
    imagedestroy($src);

    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pos_business_logo_' . md5($logoFile . '|' . $maxWidthPx) . '.png';
    if (!@imagepng($dst, $tmp)) {
        imagedestroy($dst);
        return $logoFile;
    }
    imagedestroy($dst);
    return $tmp;
}

function printBusinessLogo(Printer $printer, array $businessInfo, int $paperWidthMm = 58, bool $embedInStream = true): void
{
    if (!$embedInStream) {
        return;
    }
    $logoFile = resolveBusinessLogoFilePath($businessInfo);
    if ($logoFile === null) {
        return;
    }

    $maxWidthPx = ($paperWidthMm === 80) ? 576 : 384;
    $printFile = prepareBusinessLogoForPrinter($logoFile, $maxWidthPx);
    try {
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $img = EscposImage::load($printFile, false);
        $printer->graphics($img);
        $printer->feed(1);
    } catch (Exception $e) {
        error_log('Business logo print error: ' . $e->getMessage());
    }
}

function getBusinessLogoPngBase64(array $businessInfo, int $paperWidthMm = 58): ?string
{
    $logoFile = resolveBusinessLogoFilePath($businessInfo);
    if ($logoFile === null) {
        return null;
    }
    $maxWidthPx = ($paperWidthMm === 80) ? 576 : 384;
    $printFile = prepareBusinessLogoForPrinter($logoFile, $maxWidthPx);
    $bytes = @file_get_contents($printFile);
    if ($bytes === false || $bytes === '') {
        return null;
    }
    return base64_encode($bytes);
}

function getBusinessLogoEscposBase64(array $businessInfo, int $paperWidthMm = 58): ?string
{
    $logoFile = resolveBusinessLogoFilePath($businessInfo);
    if ($logoFile === null) {
        return null;
    }
    $maxWidthPx = ($paperWidthMm === 80) ? 576 : 384;
    $printFile = prepareBusinessLogoForPrinter($logoFile, $maxWidthPx);
    try {
        $connector = new RawCaptureConnector();
        $printer = new Printer($connector);
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $img = EscposImage::load($printFile, false);
        // bitImage (GS v 0) is supported on more 80mm ESC/POS printers than graphics().
        $printer->bitImage($img);
        $printer->feed(1);
        $printer->close();
        $bytes = $connector->getData();
        return $bytes !== '' ? base64_encode($bytes) : null;
    } catch (Exception $e) {
        error_log('Business logo ESC/POS capture error: ' . $e->getMessage());
        return null;
    }
}

function emitRawModeJson(array $orderData, string $rawBytes, array $businessInfo, int $paperWidthMm = 58, bool $includeLogo = true): void
{
    if (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    $payload = [
        'success' => true,
        'raw_base64' => base64_encode($rawBytes),
        'order_data' => $orderData,
    ];
    if ($includeLogo) {
        $logoEscposB64 = getBusinessLogoEscposBase64($businessInfo, $paperWidthMm);
        if ($logoEscposB64 !== null) {
            $payload['logo_escpos_base64'] = $logoEscposB64;
        }
        $logoPngB64 = getBusinessLogoPngBase64($businessInfo, $paperWidthMm);
        if ($logoPngB64 !== null) {
            $payload['logo_png_base64'] = $logoPngB64;
        }
    }
    $payload['receipt_paper_width_mm'] = ($paperWidthMm === 80) ? 80 : 58;
    echo json_encode($payload);
    exit;
}

function enrichOrderDataWithBusinessInfo(array &$orderData, array $businessInfo): void
{
    $orderData['business_name'] = $businessInfo['name'] ?? 'POS SOLUTION';
    $orderData['location'] = $businessInfo['location'] ?? '';
    $orderData['phone'] = $businessInfo['phone'] ?? '';
    $orderData['footer_text'] = $businessInfo['footer_text'] ?? 'Thank you for your purchase!';
    $orderData['vat_inclusive'] = $businessInfo['vat_inclusive'] ?? 'exclusive';
    $orderData['vat_rate'] = isset($businessInfo['vat_rate']) ? floatval($businessInfo['vat_rate']) : 15.0;
    $logoPath = trim((string)($businessInfo['logo_path'] ?? ''));
    if ($logoPath !== '') {
        $orderData['logo_path'] = $logoPath;
    } else {
        unset($orderData['logo_path']);
    }
}

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

receipt_enrich_tab_gratuity_for_print($orderData);

// #region agent log
if (!empty($orderData['is_payment_receipt']) || !empty($orderData['is_tab_copy_receipt'])) {
    debug833f8f_log('receipt.php:orderDataReceived', 'Tab receipt data received', [
        'receipt_type' => !empty($orderData['is_payment_receipt']) ? 'payment' : 'copy',
        'order_id' => $orderData['order_id'] ?? null,
        'tab_id' => $orderData['tab_id'] ?? null,
        'gratuity_amount' => $orderData['gratuity_amount'] ?? null,
        'gratuity' => $orderData['gratuity'] ?? null,
        'gratuity_percent_applied' => $orderData['gratuity_percent_applied'] ?? null,
        'gratuity_included_in_total' => $orderData['gratuity_included_in_total'] ?? null,
        'tips' => $orderData['tips'] ?? null,
        'total' => $orderData['total'] ?? null,
        'item_count' => is_array($orderData['items'] ?? null) ? count($orderData['items']) : 0,
        'raw_mode' => !empty($_GET['raw']),
    ], 'B');
}
// #endregion

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
        
        enrichOrderDataWithBusinessInfo($orderData, $businessInfo);

        if ($rawMode) {
            $rawBytes = $rawConnector ? $rawConnector->getData() : '';
            emitRawModeJson($orderData, $rawBytes, $businessInfo, 58);
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
        $tips = floatval($orderData['tips'] ?? 0);
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
        $damages = floatval($orderData['damages'] ?? 0);
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
        $separatorLine = function($style = 'thin') use ($receiptWidth) {
            return str_repeat($style === 'heavy' ? '=' : '-', $receiptWidth) . "\n";
        };
        
        // Print header
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        printBusinessLogo($printer, $businessInfo, receiptCharsToPaperMm($receiptWidth), !$rawMode);
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
        $headerCustomText = trim((string)($businessInfo['header_custom_text'] ?? ''));
        if ($headerCustomText !== '') {
            $printer->text($headerCustomText . "\n");
        }
        
        $printer->text($businessInfo['location'] . "\n");

        if (!empty(trim((string)($businessInfo['phone'] ?? '')))) {
            $printer->text("Tel: " . $businessInfo['phone'] . "\n");
        }
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
        $printer->text($separatorLine('thin'));
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
        $printer->text($separatorLine('thin'));
        $printer->text($formatLine("Card Sales (Expected)", $cardSalesExpected));
        $printer->text($formatLine("EFT on Hand", $eftOnHand));
        $printer->text($formatLine("EFT Over / Short", $eftOverShort));
        $printer->text($formatLine("Unpaid Credit Sales", $unpaidCreditSales));
        $printer->text($formatLine("Open Tabs Balance", $openTabsBalance));
        $printer->text($formatLine("Credit Returns", $creditReturns));
        $printer->text($separatorLine('thin'));
        
        // DEDUCTIONS SECTION
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->setEmphasis(true);
        $printer->text("DEDUCTIONS\n");
        $printer->setEmphasis(false);
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->text(str_repeat('-', $receiptWidth) . "\n");
        $printer->text($formatLine("Expenses", $expenses));
        $printer->text($formatLine("Tips", $tips));
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
        $printer->text($formatLine("Damages", $damages));
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
        
        enrichOrderDataWithBusinessInfo($orderData, $businessInfo);
        
        // Ensure no output before JSON
        if (ob_get_level()) ob_end_clean();
        
        if ($rawMode) {
            $rawBytes = $rawConnector ? $rawConnector->getData() : '';
            emitRawModeJson($orderData, $rawBytes, $businessInfo, receiptCharsToPaperMm($receiptWidth));
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
        printBusinessLogo($printer, $businessInfo, receiptCharsToPaperMm($receiptWidth), !$rawMode);
        $printer->selectPrintMode(Printer::MODE_DOUBLE_WIDTH | Printer::MODE_DOUBLE_HEIGHT | Printer::MODE_EMPHASIZED);
        $printer->text($businessInfo['name'] . "\n");
        $printer->selectPrintMode();
        $printer->setEmphasis(true);
        $printer->text("INVENTORY REPORT\n");

        $headerCustomText = trim((string)($businessInfo['header_custom_text'] ?? ''));
        if ($headerCustomText !== '') {
            $printer->text($headerCustomText . "\n");
        }

        $printer->setEmphasis(false);
        $printer->text($businessInfo['location'] . "\n");
        if (!empty(trim((string)($businessInfo['phone'] ?? '')))) {
            $printer->text("Tel: " . $businessInfo['phone'] . "\n");
        }
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
        
        enrichOrderDataWithBusinessInfo($orderData, $businessInfo);
        
        // Ensure no output before JSON
        if (ob_get_level()) ob_end_clean();
        
        if ($rawMode) {
            $rawBytes = $rawConnector ? $rawConnector->getData() : '';
            emitRawModeJson($orderData, $rawBytes, $businessInfo, receiptCharsToPaperMm($receiptWidth));
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
        
        // Amounts come from orderData (get_cashup_data.php / cashier Z-report); no server-side recalculation here.
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

        $cashSalesExpected = floatval($orderData['cash_sales_expected'] ?? $orderData['cash_sales'] ?? 0);
        $cashInTill = floatval($orderData['cash_in_till'] ?? $orderData['expected_cash'] ?? 0);
        $cashOnHand = floatval($orderData['cash_on_hand'] ?? $cashInTill);
        $cardSalesExpected = floatval($orderData['card_sales_expected'] ?? $orderData['eft_sales'] ?? $orderData['total_eft_sales'] ?? 0);
        $eftOnHand = floatval($orderData['eft_on_hand'] ?? $cardSalesExpected);
        $eftOverShort = floatval($orderData['eft_over_short'] ?? 0);
        $unpaidCreditSales = floatval($orderData['unpaid_credit_sales'] ?? $orderData['credit_unpaid'] ?? 0);
        $openTabsBalance = floatval($orderData['open_tabs_balance'] ?? $orderData['open_tabs'] ?? 0);
        $creditReturns = floatval($orderData['credit_returns'] ?? 0);
        $unpaidTabs = floatval($orderData['unpaid_tabs'] ?? 0);
        $expenses = floatval($orderData['expenses'] ?? $orderData['total_expense'] ?? 0);
        $tips = floatval($orderData['tips'] ?? $orderData['tips_system'] ?? 0);
        $voids = floatval($orderData['voids'] ?? 0);
        $voidsCount = intval($orderData['voids_count'] ?? 0);
        $refunds = floatval($orderData['refunds'] ?? 0);
        $refundsCount = intval($orderData['refunds_count'] ?? 0);
        $totalItemsSold = floatval($orderData['total_items_sold'] ?? 0);
        $damages = floatval($orderData['damages'] ?? 0);
        $cashIn = floatval($orderData['cash_in'] ?? 0);
        $cashOut = floatval($orderData['cash_out'] ?? 0);
        $totalCashSalesOrders = floatval($orderData['total_cash_sales'] ?? $orderData['cash_sales'] ?? 0);
        $grandTotal = floatval($orderData['grand_total'] ?? $orderData['total_income'] ?? ($totalCashSalesOrders + $cardSalesExpected));

        $overShort = $cashOnHand - $cashSalesExpected;
        $isIndividual = !empty($orderData['is_individual_cashout']);
        $staffName = (string)($orderData['filter_cashier_name'] ?? $orderData['staff_name'] ?? $orderData['cashier_name'] ?? '');

        $formatLine = function ($label, $amount) use ($receiptWidth) {
            $amountStr = 'N$ ' . number_format(floatval($amount), 2);
            $spaces = $receiptWidth - strlen($label) - strlen($amountStr);
            if ($spaces < 1) {
                $spaces = 1;
            }
            return $label . str_repeat(' ', $spaces) . $amountStr . "\n";
        };

        $wrapText = function ($prefix, $text) use ($receiptWidth) {
            $text = (string)$text;
            $lines = [];
            $first = $prefix . $text;
            $chunk = $receiptWidth;
            for ($i = 0; $i < strlen($first); $i += $chunk) {
                $lines[] = substr($first, $i, $chunk);
            }
            return $lines;
        };

        $printer->setJustification(Printer::JUSTIFY_CENTER);
        printBusinessLogo($printer, $businessInfo, receiptCharsToPaperMm($receiptWidth), !$rawMode);
        $printer->selectPrintMode(Printer::MODE_DOUBLE_WIDTH | Printer::MODE_DOUBLE_HEIGHT | Printer::MODE_EMPHASIZED);
        $printer->text($businessInfo['name'] . "\n");
        $printer->selectPrintMode();
        $printer->setEmphasis(true);
        if ($isIndividual) {
            $printer->text("CASHOUT / Z-REPORT\n");
        } else {
            $printer->text("Z-REPORT\n");
        }
        $printer->setEmphasis(false);
        $printer->text($businessInfo['location'] . "\n");
        $headerCustomText = trim((string)($businessInfo['header_custom_text'] ?? ''));
        if ($headerCustomText !== '') {
            $printer->text($headerCustomText . "\n");
        }
        if (!empty(trim((string)($businessInfo['phone'] ?? '')))) {
            $printer->text("Tel: " . $businessInfo['phone'] . "\n");
        }
        $printer->text(str_repeat('-', $receiptWidth) . "\n");
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->text("Date: " . ($orderData['date'] ?? date('Y-m-d')) . "\n");
        if (!empty($orderData['date_range'])) {
            foreach ($wrapText('Period: ', $orderData['date_range']) as $ln) {
                $printer->text($ln . "\n");
            }
        }
        $printer->text("Time: " . date('H:i') . "\n");
        if ($staffName !== '') {
            $printer->setEmphasis(true);
            $printer->text("Staff: " . $staffName . "\n");
            $printer->setEmphasis(false);
        }
        $printer->text("Printed by: " . ($orderData['cashier_username'] ?? 'N/A') . "\n");
        if (!empty($orderData['generated_at'])) {
            $printer->text("Generated: " . $orderData['generated_at'] . "\n");
        }

        $printer->text(str_repeat('-', $receiptWidth) . "\n");
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->setEmphasis(true);
        $printer->text("CASH\n");
        $printer->setEmphasis(false);
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->text(str_repeat('-', $receiptWidth) . "\n");
        $printer->text($formatLine('Cash In', $cashIn));
        $printer->text($formatLine('Cash Out', $cashOut));
        $printer->text($formatLine('Cash Sales (Orders)', $totalCashSalesOrders));
        $printer->text($formatLine('Cash Sales (Expected)', $cashSalesExpected));
        $printer->text($formatLine('Cash in Till', $cashInTill));
        $printer->text($formatLine('Cash on Hand', $cashOnHand));
        $printer->text($formatLine('Over / Short', $overShort));

        $printer->text(str_repeat('-', $receiptWidth) . "\n");
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->setEmphasis(true);
        $printer->text("CARD & CREDIT\n");
        $printer->setEmphasis(false);
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->text(str_repeat('-', $receiptWidth) . "\n");
        $printer->text($formatLine('Card Sales (Expected)', $cardSalesExpected));
        $printer->text($formatLine('EFT on Hand', $eftOnHand));
        $printer->text($formatLine('EFT Over / Short', $eftOverShort));
        $printer->text($formatLine('Unpaid Credit Sales', $unpaidCreditSales));
        $printer->text($formatLine('Open Tabs Balance', $openTabsBalance));
        if ($unpaidTabs > 0) {
            $printer->text($formatLine('Unpaid Tabs (total)', $unpaidTabs));
        }
        $printer->text($formatLine('Credit Returns', $creditReturns));

        $printer->text(str_repeat('-', $receiptWidth) . "\n");
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->setEmphasis(true);
        $printer->text("DEDUCTIONS\n");
        $printer->setEmphasis(false);
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->text(str_repeat('-', $receiptWidth) . "\n");
        $printer->text($formatLine('Expenses', $expenses));
        $printer->text($formatLine('Tips', $tips));

        $printer->text(str_repeat('-', $receiptWidth) . "\n");
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->setEmphasis(true);
        $printer->text("ADJUSTMENTS\n");
        $printer->setEmphasis(false);
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->text(str_repeat('-', $receiptWidth) . "\n");
        $voidsLabel = $voidsCount > 0 ? 'Voids (' . $voidsCount . ')' : 'Voids';
        $printer->text($formatLine($voidsLabel, $voids));
        $refundsLabel = $refundsCount > 0 ? 'Refunds (' . $refundsCount . ')' : 'Refunds';
        $printer->text($formatLine($refundsLabel, $refunds));
        $printer->text($formatLine('Damages', $damages));

        $printer->text(str_repeat('-', $receiptWidth) . "\n");
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->setEmphasis(true);
        $printer->text("TOTALS\n");
        $printer->setEmphasis(false);
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->text(str_repeat('-', $receiptWidth) . "\n");
        $printer->text($formatLine('Total Items Sold Value', $totalItemsSold));
        $printer->setEmphasis(true);
        $printer->text($formatLine('TOTAL SALES', $grandTotal));
        $printer->setEmphasis(false);

        if (isset($orderData['cash_difference'])) {
            $difference = floatval($orderData['cash_difference']);
            if ($difference != 0) {
                $printer->text(str_repeat('-', $receiptWidth) . "\n");
                $printer->setEmphasis(true);
                $printer->text($formatLine($difference > 0 ? 'SURPLUS' : 'SHORTAGE', abs($difference)));
                $printer->setEmphasis(false);
            }
        }

        $printer->text(str_repeat('=', $receiptWidth) . "\n");
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        if ($isIndividual) {
            $printer->text("*Cashout for " . ($staffName !== '' ? $staffName : 'staff') . "*\n");
        } else {
            $printer->text("*End of Z-Report*\n");
        }
        // Feed enough to ensure footer is fully printed before cut
        $printer->feed(3);
        $printer->cut();
        $printer->pulse();
        // Initialize printer to stop further printing
        $printer->initialize();
        $printer->close();
        enrichOrderDataWithBusinessInfo($orderData, $businessInfo);
        
        if ($rawMode) {
            $rawBytes = $rawConnector ? $rawConnector->getData() : '';
            emitRawModeJson($orderData, $rawBytes, $businessInfo, receiptCharsToPaperMm($receiptWidth));
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
                   !empty($orderData['is_tab_copy_receipt']) ||
                   isset($orderData['is_laybye_balance_receipt']) ||
                   isset($orderData['is_payment_receipt']) ||
                   isset($orderData['open_drawer_only']) ||
                   !empty($orderData['print_to_kitchen_printer']);

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
    $allowedVatModes = ['exclusive', 'inclusive', 'none'];
    $vMode = $businessInfo['vat_inclusive'] ?? 'exclusive';
    if (!in_array($vMode, $allowedVatModes, true)) {
        $vMode = 'exclusive';
    }
    $businessInfo['vat_inclusive'] = $vMode;
    
    // ALWAYS enrich orderData with business info from info.db (reliable source)
    enrichOrderDataWithBusinessInfo($orderData, $businessInfo);
    
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

    // Kitchen printer (ESC/POS over TCP) — IP/port in product_settings
    $kitchenPrinterIp = '';
    $kitchenPrinterPort = 9100;
    $configuredReceiptWidthMm = 58;
    try {
        $posKitchenDb = new PDO('sqlite:' . __DIR__ . '/pos.db');
        $posKitchenDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        try {
            $posKitchenDb->exec("ALTER TABLE product_settings ADD COLUMN kitchen_printer_ip TEXT");
        } catch (PDOException $e) { /* exists */
        }
        try {
            $posKitchenDb->exec("ALTER TABLE product_settings ADD COLUMN kitchen_printer_port INTEGER NOT NULL DEFAULT 9100");
        } catch (PDOException $e) { /* exists */
        }
        try {
            $posKitchenDb->exec("ALTER TABLE product_settings ADD COLUMN receipt_paper_width_mm INTEGER NOT NULL DEFAULT 58");
        } catch (PDOException $e) { /* exists */
        }
        $krow = $posKitchenDb->query("SELECT kitchen_printer_ip, kitchen_printer_port, receipt_paper_width_mm FROM product_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($krow) {
            $kitchenPrinterIp = trim((string)($krow['kitchen_printer_ip'] ?? ''));
            $kp = (int)($krow['kitchen_printer_port'] ?? 9100);
            $kitchenPrinterPort = ($kp > 0 && $kp <= 65535) ? $kp : 9100;
            $dbPaperWidth = (int)($krow['receipt_paper_width_mm'] ?? 58);
            $configuredReceiptWidthMm = ($dbPaperWidth === 80) ? 80 : 58;
        }
    } catch (Exception $e) {
        error_log('Kitchen printer settings: ' . $e->getMessage());
    }

    if (!empty($orderData['print_to_kitchen_printer']) && $kitchenPrinterIp === '') {
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Kitchen printer IP is not configured. Set it under Admin → Settings (kitchen printer).'
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
        !isset($orderData['print_only']) &&
        empty($orderData['is_tab_copy_receipt'])) {
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
    
    // Receipt width: 42 chars ≈ 80mm, 32 ≈ 58mm.
    $explicitPaperWidthMm = null;
    if (isset($orderData['receipt_paper_width_mm'])) {
        $explicitPaperWidthMm = (int)$orderData['receipt_paper_width_mm'];
    } elseif (isset($orderData['paper_width_mm'])) {
        $explicitPaperWidthMm = (int)$orderData['paper_width_mm'];
    }
    if ($explicitPaperWidthMm !== 58 && $explicitPaperWidthMm !== 80) {
        $explicitPaperWidthMm = null;
    }
    $receiptWidthMm = $explicitPaperWidthMm ?? $configuredReceiptWidthMm;
    if (!empty($orderData['print_to_kitchen_printer']) && $explicitPaperWidthMm === null) {
        $receiptWidthMm = 80;
    }
    $receiptWidth = ($receiptWidthMm === 80) ? 42 : 32;
    $receiptTruncate = $receiptWidth - 3;

    $separatorLine = function ($style = 'thin') use ($receiptWidth) {
        if ($style === 'none') {
            return '';
        }
        $ch = ($style === 'heavy') ? '=' : '-';
        return str_repeat($ch, $receiptWidth) . "\n";
    };
    $formatMoney = function ($amount) {
        return 'N$ ' . number_format((float)$amount, 2, '.', '');
    };
    $fitLeft = function ($text, $maxLen) {
        $text = trim((string)$text);
        if ($maxLen <= 0) {
            return '';
        }
        if (strlen($text) <= $maxLen) {
            return $text;
        }
        return substr($text, 0, max(0, $maxLen - 3)) . '...';
    };
    $wrapText = function ($text, $width) use ($fitLeft) {
        $text = trim((string)$text);
        if ($text === '') {
            return [''];
        }
        if ($width < 4) {
            return [$fitLeft($text, max(1, $width))];
        }
        $words = preg_split('/\s+/', $text);
        $lines = [];
        $current = '';
        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }
            if ($current === '') {
                if (strlen($word) <= $width) {
                    $current = $word;
                } else {
                    $lines[] = $fitLeft($word, $width);
                }
                continue;
            }
            $candidate = $current . ' ' . $word;
            if (strlen($candidate) <= $width) {
                $current = $candidate;
            } else {
                $lines[] = $current;
                $current = (strlen($word) <= $width) ? $word : $fitLeft($word, $width);
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }
        return empty($lines) ? [''] : $lines;
    };
    $lineLeftRight = function ($left, $right) use ($receiptWidth, $fitLeft) {
        $left = trim((string)$left);
        $right = trim((string)$right);
        $rightLen = strlen($right);
        $leftMax = max(1, $receiptWidth - $rightLen - 1);
        $left = $fitLeft($left, $leftMax);
        $pad = max(1, $receiptWidth - strlen($left) - $rightLen);
        return $left . str_repeat(' ', $pad) . $right . "\n";
    };
    $lineLabelAmount = function ($label, $amount) use ($lineLeftRight, $formatMoney) {
        return $lineLeftRight($label, $formatMoney($amount));
    };
    $qtyColWidth = 3;
    $amountColWidth = ($receiptWidth >= 42) ? 12 : 10;
    $descColWidth = max(8, $receiptWidth - $qtyColWidth - 1 - $amountColWidth);
    $printItemHeader = function ($printer) use ($qtyColWidth, $descColWidth, $amountColWidth) {
        $printer->text(str_pad('Qty', $qtyColWidth) . ' ' . str_pad('Description', $descColWidth) . str_pad('Amount', $amountColWidth, ' ', STR_PAD_LEFT) . "\n");
    };
    $printItemRow = function ($printer, $qty, $description, $amount) use ($qtyColWidth, $descColWidth, $amountColWidth, $wrapText, $formatMoney) {
        $qtyText = str_pad((string)max(1, (int)$qty), $qtyColWidth);
        $amountText = str_pad($formatMoney($amount), $amountColWidth, ' ', STR_PAD_LEFT);
        $descLines = $wrapText((string)$description, $descColWidth);
        foreach ($descLines as $idx => $line) {
            $leftQty = ($idx === 0) ? $qtyText : str_repeat(' ', $qtyColWidth);
            $rightAmount = ($idx === 0) ? $amountText : str_repeat(' ', $amountColWidth);
            $printer->text($leftQty . ' ' . str_pad($line, $descColWidth) . $rightAmount . "\n");
        }
    };
    $formatReceiptTime = function ($value) {
        if ($value === null || $value === '') {
            return '';
        }
        if (is_numeric($value)) {
            $ts = intval($value);
        } else {
            $ts = strtotime((string) $value);
        }
        if ($ts === false || $ts <= 0) {
            $raw = trim((string) $value);
            return $raw;
        }
        return date('g:i A', $ts);
    };
    $tabTimingFallback = [
        'opened_at' => '',
        'closed_at' => ''
    ];
    $tabIdForTiming = intval($orderData['tab_id'] ?? 0);
    if ($tabIdForTiming > 0) {
        try {
            $posTimingDb = new PDO('sqlite:' . __DIR__ . '/pos.db');
            $posTimingDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $tabTimingStmt = $posTimingDb->prepare("SELECT opened_at, closed_at FROM tabs WHERE id = :id LIMIT 1");
            $tabTimingStmt->bindValue(':id', $tabIdForTiming, PDO::PARAM_INT);
            $tabTimingStmt->execute();
            $tabTimingRow = $tabTimingStmt->fetch(PDO::FETCH_ASSOC);
            if ($tabTimingRow) {
                $tabTimingFallback['opened_at'] = $tabTimingRow['opened_at'] ?? '';
                $tabTimingFallback['closed_at'] = $tabTimingRow['closed_at'] ?? '';
            }
        } catch (Exception $e) {
            error_log('Tab timing fallback lookup failed: ' . $e->getMessage());
        }
    }
    $resolveOpenedTime = function ($orderData) use ($formatReceiptTime, $tabTimingFallback) {
        $openedCandidates = [
            $orderData['order_started'] ?? '',
            $orderData['opened_at'] ?? '',
            $orderData['created_at'] ?? '',
            $orderData['tab_opened_at'] ?? '',
            $orderData['tab_started_at'] ?? '',
            $tabTimingFallback['opened_at'] ?? ''
        ];
        foreach ($openedCandidates as $candidate) {
            $fmt = $formatReceiptTime($candidate);
            if ($fmt !== '') {
                return $fmt;
            }
        }
        return '';
    };
    $resolveClosedPaidTime = function ($orderData) use ($formatReceiptTime, $tabTimingFallback) {
        $maxPaymentTs = null;
        if (!empty($orderData['payments']) && is_array($orderData['payments'])) {
            foreach ($orderData['payments'] as $p) {
                $paymentDateRaw = $p['payment_date'] ?? '';
                if ($paymentDateRaw === null || $paymentDateRaw === '') {
                    continue;
                }
                $ts = strtotime((string) $paymentDateRaw);
                if ($ts === false) {
                    continue;
                }
                if ($maxPaymentTs === null || $ts > $maxPaymentTs) {
                    $maxPaymentTs = $ts;
                }
            }
        }
        if ($maxPaymentTs !== null) {
            return date('g:i A', $maxPaymentTs);
        }
        $fallbackCandidates = [
            $orderData['order_ended'] ?? '',
            $orderData['closed_at'] ?? '',
            $orderData['payment_date'] ?? '',
            $orderData['tab_closed_at'] ?? '',
            $orderData['closed_time'] ?? '',
            $tabTimingFallback['closed_at'] ?? ''
        ];
        foreach ($fallbackCandidates as $candidate) {
            $fmt = $formatReceiptTime($candidate);
            if ($fmt !== '') {
                return $fmt;
            }
        }
        return '';
    };
    
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
        } else if (!empty($orderData['print_to_kitchen_printer']) && $kitchenPrinterIp !== '') {
            $connector = new NetworkPrintConnector($kitchenPrinterIp, $kitchenPrinterPort);
        } else if ($isNetworkPrinter) {
            // Network printer connection
            $connector = new NetworkPrintConnector("192.168.1.7", 9100);
        } else {
            // Local Windows printer connection
            $connector = new WindowsPrintConnector($printerName);
        }
        $printer = new Printer($connector);
    } catch (Exception $e) {
        $connLabel = (!empty($orderData['print_to_kitchen_printer']) && $kitchenPrinterIp !== '')
            ? ('kitchen ' . $kitchenPrinterIp . ':' . $kitchenPrinterPort)
            : $printerName;
        throw new Exception("Printer connection failed for $connLabel: " . $e->getMessage());
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
    
    // Skip header for kitchen tickets (tab sales) - no header or footer; show for tab copy (guest check)
    if (!$isTabSale || $isPaymentReceipt || !empty($orderData['is_tab_copy_receipt'])) {
        try {
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            printBusinessLogo($printer, $businessInfo, $receiptWidthMm, !$rawMode);
            // Highlight business name with bold double-size text (readable on thermal paper).
            $printer->selectPrintMode(Printer::MODE_DOUBLE_WIDTH | Printer::MODE_DOUBLE_HEIGHT | Printer::MODE_EMPHASIZED);
            $printer->text($businessInfo['name'] . "\n");
            $printer->selectPrintMode(); // Reset print mode
            $printer->text("\n");

            $printer->setEmphasis(false);
            $headerCustomText = trim((string)($businessInfo['header_custom_text'] ?? ''));
            if ($headerCustomText !== '') {
                $printer->text($headerCustomText . "\n");
            }
            $printer->setEmphasis(true);
            $printer->text($businessInfo['location'] . "\n");
            if (!empty(trim((string)($businessInfo['phone'] ?? '')))) {
                $printer->text("Tel: " . $businessInfo['phone'] . "\n");
            }
            $shouldShowCashierInHeader =
                empty($orderData['is_tab_copy_receipt']) &&
                !$isPaymentReceipt &&
                empty($orderData['is_cashup_report']) &&
                empty($orderData['is_cashup_master_report']) &&
                empty($orderData['is_balance_receipt']) &&
                empty($orderData['is_tab_balance_receipt']) &&
                empty($orderData['is_laybye_balance_receipt']);
            if ($shouldShowCashierInHeader) {
                $cashierHeaderName = trim((string)($orderData['cashier_username'] ?? $orderData['cashier_name'] ?? ''));
                if ($cashierHeaderName !== '') {
                    $printer->text("Cashier: " . $cashierHeaderName . "\n");
                }
            }
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
            $printer->text($separatorLine('thin'));
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
            $printer->text($separatorLine('thin'));
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
            $printItemHeader($printer);
            $printer->setEmphasis(false);
            $printer->text($separatorLine('thin'));
            
            $subtotal = 0;
            foreach ($orderData['items'] as $item) {
                $name = $item['product_name'] ?? $item['name'] ?? 'Unknown';
                $quantity = intval($item['quantity'] ?? 0);
                $price = floatval($item['price'] ?? 0);
                $amount = $quantity * $price;
                $subtotal += $amount;
                
                $printItemRow($printer, $quantity, $name, $amount);
            }
            
            // Print totals
            $printer->text($separatorLine('heavy'));
            $printer->setEmphasis(true);
            $printer->text($lineLabelAmount("REFUND TOTAL:", $subtotal));
            $printer->setEmphasis(false);
            $printer->text($separatorLine('heavy'));
        }
        
        // Footer section
        $printer->text($separatorLine('thin'));
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->text($businessInfo['footer_text'] . "\n");
        $printer->feed(3); // Feed enough to ensure footer is fully printed before cut
    } else if (!empty($orderData['is_tab_copy_receipt'])) {
        // TAB COPY / GUEST CHECK (itemized, VAT, gratuity, balance due) — N$
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->setEmphasis(true);
        $printer->text("COPY RECEIPT\n");
        $printer->setEmphasis(false);
        $printer->feed(1);
        $printer->setJustification(Printer::JUSTIFY_LEFT);
     
        $printer->text($separatorLine('thin'));
        $printer->setEmphasis(true);
        $printItemHeader($printer);
        $printer->setEmphasis(false);
        $printer->text($separatorLine('thin'));

        $lineSum = 0.0;
        $gratuityFromTabLines = 0.0;
        if (isset($orderData['items']) && is_array($orderData['items'])) {
            foreach ($orderData['items'] as $item) {
                $name = $item['name'] ?? 'Item';
                $trimName = trim((string) $name);
                $quantity = intval($item['quantity'] ?? 1);
                if ($quantity < 1) {
                    $quantity = 1;
                }
                $amount = floatval($item['price'] ?? 0);
                if ($trimName === 'Gratuity') {
                    $gratuityFromTabLines += $amount;
                    continue;
                }
                $lineSum += $amount;
                $unitPrice = $amount / $quantity;
                $printItemRow($printer, $quantity, $name, $amount);
            }
        }
        $subtotal = $lineSum;
        $copyVatMode = isset($orderData['vat_inclusive']) ? $orderData['vat_inclusive'] : $vatInclusive;
        $copyVatRate = isset($orderData['vat_rate']) ? floatval($orderData['vat_rate']) : $vatRate;
        $vatAmount = 0.0;
        $regularVatLineAmount = 0.0;
        $totalWithVat = $subtotal;
        if ($copyVatMode === 'exclusive' || $copyVatMode === '') {
            $vatAmount = $subtotal * ($copyVatRate / 100);
            $regularVatLineAmount = $vatAmount;
            $totalWithVat = $subtotal + $vatAmount;
        } elseif ($copyVatMode === 'none') {
            $regularVatLineAmount = 0.0;
            $totalWithVat = $subtotal;
        } elseif ($copyVatMode === 'inclusive') {
            $regularVatLineAmount = $subtotal - ($subtotal / (1 + ($copyVatRate / 100)));
            if ($regularVatLineAmount < 0) {
                $regularVatLineAmount = 0.0;
            }
            $regularVatLineAmount = round($regularVatLineAmount, 2);
            $totalWithVat = $subtotal;
        } else {
            $vatAmount = $subtotal * ($copyVatRate / 100);
            $regularVatLineAmount = $vatAmount;
            $totalWithVat = $subtotal + $vatAmount;
        }
        $rPct = rtrim(rtrim(number_format($copyVatRate, 2, '.', ''), '0'), '.');
        if ($rPct === '' || $rPct === '.') {
            $rPct = '0';
        }
        $printer->text($separatorLine('thin'));
        // Subtotal (maintain inline N$ always)
        $subtotalLabel = "Subtotal:";
        $subtotalVal = sprintf("N$%8.2f", $subtotal);
        $pad = max(1, $receiptWidth - strlen($subtotalLabel) - strlen($subtotalVal));
        $printer->text($subtotalLabel . str_repeat(' ', $pad) . $subtotalVal . "\n");

        $copyVatPrinted = strtolower((string) $copyVatMode) !== 'none';
        if ($copyVatPrinted) {
            if ($copyVatMode === 'inclusive') {
                $regularVatLabel = 'VAT: ' . $rPct . '% (incl.):';
            } else {
                $regularVatLabel = 'VAT: ' . $rPct . '% (excl.):';
            }
            $vatVal = sprintf("N$%8.2f", $regularVatLineAmount);
            $vatPad = max(1, $receiptWidth - strlen($regularVatLabel) - strlen($vatVal));
            $printer->text($regularVatLabel . str_repeat(' ', $vatPad) . $vatVal . "\n");
        }

        // Make "Total" line slightly bigger and bold (only double height, not double width)
        $printer->setEmphasis(true);
        $printer->selectPrintMode(
            Printer::MODE_DOUBLE_HEIGHT | Printer::MODE_EMPHASIZED
        );
        // Align the N$ in "Total:" in same position as in Subtotal
        $totLblLeft = "Total:";
        $totLblAmt = number_format($totalWithVat, 2, '.', '');
        $totLblRight = sprintf("N$%8s", $totLblAmt);
        $pad = max(1, $receiptWidth - strlen($totLblLeft) - strlen($totLblRight));
        $printer->text($totLblLeft . str_repeat(' ', $pad) . $totLblRight . "\n");
        $printer->selectPrintMode(); // Reset to normal after
        $printer->setEmphasis(false);

        $gratuity = receipt_resolve_tab_gratuity_for_print($orderData, $gratuityFromTabLines);
        if ($gratuity <= 0.005 && !empty($orderData['gratuity_percent_applied']) && $lineSum > 0.005) {
            $gratuity = round($lineSum * (floatval($orderData['gratuity_percent_applied']) / 100), 2);
        }
        if ($gratuity <= 0.0 && empty($orderData['gratuity_percent_applied']) && !empty($orderData['payments']) && is_array($orderData['payments'])) {
            foreach ($orderData['payments'] as $p) {
                $gratuity += floatval($p['tip_amount'] ?? 0);
            }
        }
        $gratuity = round($gratuity, 2);
        // #region agent log
        debug833f8f_log('receipt.php:copyGratuityDecision', 'Copy receipt gratuity totals computed', [
            'gratuity' => $gratuity,
            'gratuityFromTabLines' => $gratuityFromTabLines,
            'gratuity_percent_applied' => $orderData['gratuity_percent_applied'] ?? null,
            'gratuity_included_in_total' => $orderData['gratuity_included_in_total'] ?? null,
            'willPrintGratuityLine' => ($gratuity > 0.005),
            'totalWithVat' => $totalWithVat,
        ], 'C');
        // #endregion
        if ($gratuity > 0.005) {
            $gLab = receipt_format_gratuity_label($orderData);
            $printer->text($lineLabelAmount($gLab . ':', $gratuity));
        }
        $grandTotal = $totalWithVat + ($gratuity > 0.005 ? $gratuity : 0);
        // Highlight grand total as the final monetary line (bold + double-height).
        $printer->setEmphasis(true);
        $printer->selectPrintMode(
            Printer::MODE_DOUBLE_HEIGHT | Printer::MODE_EMPHASIZED
        );
        $gLeft = 'Grand total:';
        $gAmt = number_format($grandTotal, 2, '.', '');
        $gRight = sprintf("N$%8s", $gAmt);
        $gPad = max(1, $receiptWidth - strlen($gLeft) - strlen($gRight));
        $printer->text($gLeft . str_repeat(' ', $gPad) . $gRight . "\n");
        $printer->selectPrintMode(); // Reset to normal after
        $printer->setEmphasis(false);
        $printer->text($separatorLine('thin'));
        $printer->feed(1);

        $cashierU = $orderData['cashier_username'] ?? 'Unknown';
        $printer->text("Server: " . $cashierU . "\n");
        $printer->text("Cashier: " . $cashierU . "\n");
        $printer->selectPrintMode(Printer::MODE_DOUBLE_HEIGHT | Printer::MODE_EMPHASIZED);
        $printer->text("Table: " . ($orderData['tab_name'] ?? 'N/A') . "\n");
        $printer->selectPrintMode(Printer::MODE_EMPHASIZED);
        $printer->text("Order #: " . ($orderData['tab_id'] ?? 'N/A') . "\n");
        $printer->selectPrintMode();
        $orderOpenedTime = $resolveOpenedTime($orderData);
        if ($orderOpenedTime !== '') {
            $printer->text("Order started: " . $orderOpenedTime . "\n");
        }
        $printer->text($separatorLine('thin'));
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->text(date('j F Y g:i A') . "\n");
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $rnum = (string)($orderData['receipt_number'] ?? ($orderData['tab_id'] ?? ''));
        $rcopy = (string)($orderData['receipt_copy_number'] ?? '1');
        $lab = 'Receipt number:';
        $rv = $rnum;
        $pad = max(1, $receiptWidth - strlen($lab) - strlen($rv));
        $printer->text($lab . str_repeat(' ', $pad) . $rv . "\n");
        $lab2 = 'Receipt copy #:';
        $pad2 = max(1, $receiptWidth - strlen($lab2) - strlen($rcopy));
        $printer->text($lab2 . str_repeat(' ', $pad2) . $rcopy . "\n");
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->text(str_repeat('-', $receiptWidth) . "\n");
        $printer->text($businessInfo['footer_text'] . "\n");
        $printer->feed(3);
    } else if (isset($orderData['is_balance_receipt']) && $orderData['is_balance_receipt']) {
        // Check if this is a lay-bye statement, tab balance receipt, or credit sale balance receipt
        if (isset($orderData['is_laybye_balance_receipt']) && $orderData['is_laybye_balance_receipt']) {
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->selectPrintMode(Printer::MODE_EMPHASIZED);
            $printer->text("LAY-BYE STATEMENT\n");
            $printer->selectPrintMode();
            $printer->feed();
            $printer->setJustification(Printer::JUSTIFY_LEFT);
            $printer->text(str_repeat('-', $receiptWidth) . "\n");
            $printer->text(sprintf("%-12s %s\n", "Reference:", $orderData['laybye_reference'] ?? 'N/A'));
            if (!empty($orderData['creditor_name']) && ($orderData['creditor_name'] ?? '') !== 'N/A') {
                $printer->text(sprintf("%-12s %s\n", "Customer:", $orderData['creditor_name']));
            }
            $printer->text(sprintf("%-12s %s\n", "Status:", $orderData['laybye_status'] ?? 'N/A'));
            $printer->text(sprintf("%-12s %s\n", "Plan:", $orderData['laybye_plan_frequency'] ?? 'N/A'));
            if (!empty($orderData['laybye_next_due_date'])) {
                $printer->text(sprintf("%-12s %s\n", "Next due:", $orderData['laybye_next_due_date']));
            }
            $printer->text(sprintf("%-12s N$%8.2f\n", "Goods total:", floatval($orderData['laybye_goods_total'] ?? 0)));
            $printer->text(sprintf("%-12s N$%8.2f\n", "Deposit:", floatval($orderData['laybye_deposit_amount'] ?? 0)));
            $printer->text(str_repeat('-', $receiptWidth) . "\n");
            $printer->feed();

            if (isset($orderData['items']) && !empty($orderData['items'])) {
                $printer->setEmphasis(true);
                $printer->text("GOODS ON LAY-BYE\n");
                $printer->setEmphasis(false);
                $printer->text($separatorLine('thin'));
                $itemsTotal = 0;
                foreach ($orderData['items'] as $item) {
                    $itemName = $item['name'] ?? 'Item';
                    $qty = intval($item['quantity'] ?? 1);
                    $unitPrice = isset($item['unit_price']) ? floatval($item['unit_price']) : (floatval($item['price']) / max(1, $qty));
                    $itemTotal = floatval($item['price']);
                    $itemsTotal += $itemTotal;
                    $printItemRow($printer, $qty, $itemName, $itemTotal);
                }
                $printer->text($separatorLine('thin'));
                $printer->setEmphasis(true);
                $printer->text($lineLabelAmount("ITEMS TOTAL:", $itemsTotal));
                $printer->setEmphasis(false);
                $printer->text($separatorLine('thin'));
                $printer->feed();
            }

            if (!empty($orderData['laybye_payment_lines']) && is_array($orderData['laybye_payment_lines'])) {
                $printer->setEmphasis(true);
                $printer->text("PAYMENT HISTORY\n");
                $printer->setEmphasis(false);
                $printer->text($separatorLine('thin'));
                foreach ($orderData['laybye_payment_lines'] as $line) {
                    $ln = $line;
                    if (strlen($ln) > $receiptWidth) {
                        $ln = substr($ln, 0, $receiptTruncate) . '...';
                    }
                    $printer->text($ln . "\n");
                }
                $printer->text($separatorLine('thin'));
                $printer->feed();
            }

            $printer->setEmphasis(true);
            $printer->text($separatorLine('heavy'));
            $printer->text($lineLabelAmount("BALANCE DUE:", floatval($orderData['total_balance'] ?? 0)));
            $printer->setEmphasis(false);
            $printer->text($separatorLine('heavy'));
            $printer->feed(1);
        } else if (isset($orderData['is_tab_balance_receipt']) && $orderData['is_tab_balance_receipt']) {
            // TAB BALANCE RECEIPT - 48 chars
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->selectPrintMode(Printer::MODE_EMPHASIZED);
            $printer->text("TAB BALANCE RECEIPT\n");
            $printer->selectPrintMode();
            $printer->feed();
            $printer->setJustification(Printer::JUSTIFY_LEFT);
            $printer->text($separatorLine('thin'));
            $printer->text(sprintf("%-10s %s\n", "Tab:", $orderData['tab_name'] ?? 'N/A'));
            if (isset($orderData['creditor_name']) && $orderData['creditor_name'] !== 'N/A') {
                $printer->text(sprintf("%-10s %s\n", "Client:", $orderData['creditor_name']));
            }
            $printer->text($separatorLine('thin'));
            $printer->feed();
            
            // Print outstanding items
            if (isset($orderData['items']) && !empty($orderData['items'])) {
                $printer->setEmphasis(true);
                $printItemHeader($printer);
                $printer->setEmphasis(false);
                $printer->text($separatorLine('thin'));
                
                $itemsTotal = 0;
                $balanceReceiptGratuity = 0.0;
                foreach ($orderData['items'] as $item) {
                    $itemName = isset($item['name']) ? trim((string) $item['name']) : '';
                    $qty = intval($item['quantity']);
                    $unitPrice = isset($item['unit_price']) ? floatval($item['unit_price']) : (floatval($item['price']) / max(1, $qty));
                    $itemTotal = floatval($item['price']);
                    if ($itemName === 'Gratuity') {
                        $balanceReceiptGratuity += $itemTotal;
                        continue;
                    }
                    $itemsTotal += $itemTotal;
                    $displayName = $item['name'] ?? '';
                    $printItemRow($printer, $qty, $displayName, $itemTotal);
                }
                if ($balanceReceiptGratuity > 0.0001) {
                    $printer->text($separatorLine('thin'));
                    $gPctRaw = $orderData['gratuity_percent_applied'] ?? null;
                    $gLab = 'Gratuity';
                    if ($gPctRaw !== null && $gPctRaw !== '') {
                        $gPctStr = rtrim(rtrim(number_format((float) $gPctRaw, 2, '.', ''), '0'), '.');
                        if ($gPctStr !== '' && $gPctStr !== '.') {
                            $gLab .= " ({$gPctStr}%)";
                        }
                    }
                    $printer->text($lineLabelAmount($gLab . ':', round($balanceReceiptGratuity, 2)));
                    $itemsTotal += round($balanceReceiptGratuity, 2);
                } else {
                    $tabGratuityField = round(floatval($orderData['gratuity'] ?? 0), 2);
                    if ($tabGratuityField > 0.0001) {
                        $printer->text($separatorLine('thin'));
                        $gPctRaw = $orderData['gratuity_percent_applied'] ?? null;
                        $gLab = 'Gratuity';
                        if ($gPctRaw !== null && $gPctRaw !== '') {
                            $gPctStr = rtrim(rtrim(number_format((float) $gPctRaw, 2, '.', ''), '0'), '.');
                            if ($gPctStr !== '' && $gPctStr !== '.') {
                                $gLab .= " ({$gPctStr}%)";
                            }
                        }
                        $printer->text($lineLabelAmount($gLab . ':', $tabGratuityField));
                        $itemsTotal += $tabGratuityField;
                    }
                }
                $printer->text($separatorLine('thin'));
                $printer->setEmphasis(true);
                $printer->text($lineLabelAmount("ITEMS TOTAL:", $itemsTotal));
                $printer->setEmphasis(false);
                $printer->text($separatorLine('thin'));
                $printer->feed();
            }
            
            // Print total balance
            $printer->setEmphasis(true);
            $printer->text($separatorLine('heavy'));
            $printer->text($lineLabelAmount("OUTSTANDING BALANCE:", $orderData['total_balance']));
            $printer->setEmphasis(false);
            $printer->text($separatorLine('heavy'));
            // Reduced from feed(2) to feed(1) to save paper
            $printer->feed(1);
        } else {
            // CREDIT SALE BALANCE RECEIPT - 48 chars (original format)
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->selectPrintMode(Printer::MODE_EMPHASIZED);
            $printer->text("BALANCE RECEIPT\n");
            $printer->selectPrintMode();
            $printer->feed();
            $printer->setJustification(Printer::JUSTIFY_LEFT);
            $printer->text(str_repeat('-', $receiptWidth) . "\n");
            $printer->text(sprintf("%-10s %s\n", "Client:", $orderData['creditor_name']));
            $printer->text(sprintf("%-10s N$%8.2f\n", "Balance:", $orderData['total_balance']));
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
                    
                    $printer->text(sprintf("%-20s N$%8.2f\n", "Balance:", $transaction['balance']));
                    $printer->text(str_repeat('-', $receiptWidth) . "\n");
                    $printer->feed();
                }
            }
            
            // Print total balance again
            $printer->setEmphasis(true);
            $printer->text(sprintf("%-20s N$%8.2f\n", "TOTAL BALANCE:", $orderData['total_balance']));
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
            $printer->text($separatorLine('thin'));
            $printer->text("Date: " . date('Y-m-d H:i') . "\n");
            // Optional order type (dine-in vs takeaway) for restaurant POS
            if (isset($orderData['order_type'])) {
                $typeLabel = strtolower($orderData['order_type']) === 'takeaway' ? 'Takeaway' : 'Dine-in';
                $printer->text("Order: " . $typeLabel . "\n");
            }
            // Removed feed() - items header comes next
            
            // Items section header (only for non-tab sales)
            $printer->setEmphasis(true);
            $printItemHeader($printer);
            $printer->setEmphasis(false);
            $printer->text($separatorLine('thin'));
        } else {
            // For tab sales, items come directly after header (no extra spacing)
        }
        
        $isPaymentReceiptNow = !empty($orderData['is_payment_receipt']);
        $gratuityFromItemLines = 0.0;
        $rawLinesTotal = 0.0;
        foreach (($orderData['items'] ?? []) as $_posItem) {
            $lineAmt = floatval($_posItem['price'] ?? 0);
            if (trim((string) ($_posItem['name'] ?? '')) === 'Gratuity') {
                $gratuityFromItemLines += $lineAmt;
                if ($isPaymentReceiptNow) {
                    continue;
                }
            }
            $rawLinesTotal += $lineAmt;
        }
        $posGratuityAmt = round(floatval($orderData['gratuity_amount'] ?? 0), 2);
        $posGratuityIncluded = isset($orderData['gratuity_included_in_total']) ? ((int) $orderData['gratuity_included_in_total'] !== 0) : true;
        if ($isPaymentReceiptNow) {
            $paymentTipsField = round(floatval($orderData['tips'] ?? 0), 2);
            $posGratuityAmt = receipt_resolve_tab_gratuity_for_print($orderData, $gratuityFromItemLines);
            $vatBaseSubtotal = $rawLinesTotal;
        } else {
            $stripGratuityFromVatBase = ($posGratuityAmt > 0.0001 && $posGratuityIncluded);
            $vatBaseSubtotal = $stripGratuityFromVatBase ? max(0.0, round($rawLinesTotal - $posGratuityAmt, 2)) : $rawLinesTotal;
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

            $isKitchenTicketItems = $isTabSale && !isset($orderData['is_payment_receipt']);
            $skipGratuityLinePrint = trim((string) $name) === 'Gratuity' && (
                $isPaymentReceiptNow
                || (!$isKitchenTicketItems && $posGratuityAmt > 0.0001)
            );
            
            // For tab sales (kitchen tickets), use simple format: "x1 Ice Tea"
            if ($isTabSale && !isset($orderData['is_payment_receipt'])) {
                // Simple kitchen ticket format
                $maxTabItemLen = $receiptWidth - 5; // "x" + quantity + space is ~5 chars
                if (strlen($name) > $maxTabItemLen) {
                    $name = substr($name, 0, $maxTabItemLen - 3) . '...';
                }
                $printer->text("x" . $quantity . " " . $name . "\n");
            } elseif (!$skipGratuityLinePrint) {
                // Regular receipt format with fixed-width columns and wrapped descriptions
                $printItemRow($printer, $quantity, $name, $amount);
            }
        }
        
        // Separate totals block from items with one divider line
        $printer->text($separatorLine('thin'));
        
        // For payment receipts, show total and payment info
        if (isset($orderData['is_payment_receipt']) && $orderData['is_payment_receipt']) {
            // Get VAT settings from order data or use defaults
            $paymentVatInclusive = $orderData['vat_inclusive'] ?? $vatInclusive;
            $paymentVatRate = isset($orderData['vat_rate']) ? floatval($orderData['vat_rate']) : $vatRate;
            $tips = floatval($orderData['tips'] ?? 0);
            
            // Calculate VAT for payment receipt; line shows VAT: RATE% (excl.)/(incl.) + amount (omitted when no VAT)
            $vatAmount = 0;
            $displaySubtotal = $vatBaseSubtotal;
            $paymentTotalWithVat = $vatBaseSubtotal;
            $paymentVatLineAmount = 0.0;
            
            if ($paymentVatInclusive === 'exclusive' || $paymentVatInclusive === '') {
                $vatAmount = $vatBaseSubtotal * ($paymentVatRate / 100);
                $displaySubtotal = $vatBaseSubtotal;
                $paymentTotalWithVat = $vatBaseSubtotal + $vatAmount;
                $paymentVatLineAmount = $vatAmount;
            } elseif ($paymentVatInclusive === 'none') {
                $displaySubtotal = $vatBaseSubtotal;
                $paymentTotalWithVat = $vatBaseSubtotal;
                $paymentVatLineAmount = 0.0;
            } elseif ($paymentVatInclusive === 'inclusive') {
                $displaySubtotal = $vatBaseSubtotal;
                $paymentTotalWithVat = $vatBaseSubtotal;
                $paymentVatLineAmount = $vatBaseSubtotal - ($vatBaseSubtotal / (1 + ($paymentVatRate / 100)));
                if ($paymentVatLineAmount < 0) {
                    $paymentVatLineAmount = 0.0;
                }
                $paymentVatLineAmount = round($paymentVatLineAmount, 2);
            } else {
                $vatAmount = $vatBaseSubtotal * ($paymentVatRate / 100);
                $displaySubtotal = $vatBaseSubtotal;
                $paymentTotalWithVat = $vatBaseSubtotal + $vatAmount;
                $paymentVatLineAmount = $vatAmount;
            }

            // Match copy-receipt totals presentation.
            $subtotalLabel = "Subtotal:";
            $subtotalVal = sprintf("N$%8.2f", $displaySubtotal);
            $subtotalPad = max(1, $receiptWidth - strlen($subtotalLabel) - strlen($subtotalVal));
            $printer->text($subtotalLabel . str_repeat(' ', $subtotalPad) . $subtotalVal . "\n");

            $paymentVatPrinted = strtolower((string) $paymentVatInclusive) !== 'none';
            if ($paymentVatPrinted) {
                $pPct = rtrim(rtrim(number_format($paymentVatRate, 2, '.', ''), '0'), '.');
                if ($pPct === '' || $pPct === '.') {
                    $pPct = '0';
                }
                if ($paymentVatInclusive === 'inclusive') {
                    $paymentVatLabel = 'VAT: ' . $pPct . '% (incl.):';
                } else {
                    $paymentVatLabel = 'VAT: ' . $pPct . '% (excl.):';
                }
                $vatVal = sprintf("N$%8.2f", $paymentVatLineAmount);
                $vatPad = max(1, $receiptWidth - strlen($paymentVatLabel) - strlen($vatVal));
                $printer->text($paymentVatLabel . str_repeat(' ', $vatPad) . $vatVal . "\n");
            }

            $printer->setEmphasis(true);
            $printer->selectPrintMode(
                Printer::MODE_DOUBLE_HEIGHT | Printer::MODE_EMPHASIZED
            );
            $totLblLeft = "Total:";
            $totLblAmt = number_format($paymentTotalWithVat, 2, '.', '');
            $totLblRight = sprintf("N$%8s", $totLblAmt);
            $totPad = max(1, $receiptWidth - strlen($totLblLeft) - strlen($totLblRight));
            $printer->text($totLblLeft . str_repeat(' ', $totPad) . $totLblRight . "\n");
            $printer->selectPrintMode();
            $printer->setEmphasis(false);

            $paymentGrandTotal = $paymentTotalWithVat;
            // #region agent log
            debug833f8f_log('receipt.php:paymentGratuityDecision', 'Payment receipt gratuity totals computed', [
                'posGratuityAmt' => $posGratuityAmt,
                'gratuityFromItemLines' => $gratuityFromItemLines,
                'paymentTipsField' => $paymentTipsField ?? null,
                'paymentTotalWithVat' => $paymentTotalWithVat,
                'gratuity_percent_applied' => $orderData['gratuity_percent_applied'] ?? null,
                'gratuity_included_in_total' => $orderData['gratuity_included_in_total'] ?? null,
                'willPrintGratuityLine' => ($posGratuityAmt > 0.005),
                'tips' => $tips,
            ], 'C');
            // #endregion
            if ($posGratuityAmt > 0.005) {
                $gLab = receipt_format_gratuity_label($orderData);
                $printer->text($lineLabelAmount($gLab . ':', $posGratuityAmt));
                $paymentGrandTotal = round($paymentGrandTotal + $posGratuityAmt, 2);
            }

            if ($paymentTipsField > 0.005) {
                $printer->text($lineLabelAmount('Tips:', $paymentTipsField));
                $paymentGrandTotal = round($paymentGrandTotal + $paymentTipsField, 2);
            }
            
            // Final highlighted grand total (bold + double-height).
            $printer->setEmphasis(true);
            $printer->selectPrintMode(
                Printer::MODE_DOUBLE_HEIGHT | Printer::MODE_EMPHASIZED
            );
            $gLeft = 'Grand total:';
            $gAmt = number_format($paymentGrandTotal, 2, '.', '');
            $gRight = sprintf("N$%8s", $gAmt);
            $gPad = max(1, $receiptWidth - strlen($gLeft) - strlen($gRight));
            $printer->text($gLeft . str_repeat(' ', $gPad) . $gRight . "\n");
            $printer->selectPrintMode();
            $printer->setEmphasis(false);
            $printer->text($separatorLine('thin'));
            $printer->feed();

            // Copy-receipt identity block for payment bill receipts.
            $cashierU = $orderData['cashier_username'] ?? 'Unknown';
            $printer->text("Server: " . $cashierU . "\n");
            $printer->text("Cashier: " . $cashierU . "\n");
            $printer->selectPrintMode(Printer::MODE_DOUBLE_HEIGHT | Printer::MODE_EMPHASIZED);
            $tableLabel = $orderData['tab_name'] ?? $orderData['table_name'] ?? ('Table ' . ($orderData['table_id'] ?? $orderData['tab_id'] ?? 'N/A'));
            $printer->text("Table: " . $tableLabel . "\n");
            $printer->selectPrintMode(Printer::MODE_EMPHASIZED);
            $orderNo = $orderData['tab_id'] ?? $orderData['order_id'] ?? $orderData['table_id'] ?? 'N/A';
            $printer->text("Order #: " . $orderNo . "\n");
            $printer->selectPrintMode();
            $paymentOpenedTime = $resolveOpenedTime($orderData);
            if ($paymentOpenedTime !== '') {
                $printer->text("Order started: " . $paymentOpenedTime . "\n");
            }
            $paymentClosedTime = $resolveClosedPaidTime($orderData);
            if ($paymentClosedTime === '') {
                $paymentClosedTime = date('g:i A');
            }
            if ($paymentClosedTime !== '') {
                $printer->text("Order closed: " . $paymentClosedTime . "\n");
            }
            $printer->text($separatorLine('thin'));
            
            // Payment information section for payment receipts
            $printer->text($separatorLine('thin'));
            $printer->setEmphasis(true);
            $printer->text("PAYMENT INFORMATION\n");
            $printer->setEmphasis(false);
            $printer->text($separatorLine('thin'));

            $tenderTotal = isset($orderData['total']) ? round(floatval($orderData['total']), 2) : $paymentGrandTotal;
            
            // Display payment method details
            if (isset($orderData['payment_method'])) {
                if ($orderData['payment_method'] === 'cash') {
                    $printer->text("Method: Cash\n");
                    if (isset($orderData['cash_received'])) {
                        $printer->text(sprintf("%-10s N$%8.2f\n", "Paid:", $orderData['cash_received']));
                        $change = max(0, $orderData['cash_received'] - $tenderTotal);
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
                    $printer->text(sprintf("%-10s N$%8.2f\n", "Paid:", $tenderTotal));
                } else if ($orderData['payment_method'] === 'mixed') {
                    $printer->text("Method: Mixed Payment\n");
                    $printer->text($separatorLine('thin'));
                    
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
                    
                    $printer->text($separatorLine('thin'));
                    $printer->text($lineLabelAmount("Total:", $tenderTotal));
                    $mixedChange = receipt_mixed_payment_change($orderData, $tenderTotal);
                    if ($mixedChange > 0.004) {
                        $printer->text(sprintf("%-10s N$%8.2f\n", "Change:", $mixedChange));
                    }
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
            // Regular receipts: print VAT line when business uses VAT (exclusive or inclusive)
            $receiptVatMode = isset($orderData['vat_inclusive']) ? $orderData['vat_inclusive'] : $vatInclusive;
            $vatAmount = 0;
            $displaySubtotal = $vatBaseSubtotal;
            $displayTotal = $vatBaseSubtotal;
            $regularVatLineAmount = 0.0;
            $tips = floatval($orderData['tips'] ?? 0);
            
            if ($receiptVatMode === 'exclusive' || $receiptVatMode === '') {
                $vatAmount = $vatBaseSubtotal * ($vatRate / 100);
                $displaySubtotal = $vatBaseSubtotal;
                $displayTotal = $vatBaseSubtotal;
                $regularVatLineAmount = $vatAmount;
            } elseif ($receiptVatMode === 'none') {
                $displaySubtotal = $vatBaseSubtotal;
                $displayTotal = $vatBaseSubtotal;
                $regularVatLineAmount = 0.0;
            } elseif ($receiptVatMode === 'inclusive') {
                $displaySubtotal = $vatBaseSubtotal;
                $displayTotal = $vatBaseSubtotal;
                $regularVatLineAmount = $vatBaseSubtotal - ($vatBaseSubtotal / (1 + ($vatRate / 100)));
                if ($regularVatLineAmount < 0) {
                    $regularVatLineAmount = 0.0;
                }
                $regularVatLineAmount = round($regularVatLineAmount, 2);
            } else {
                $vatAmount = $vatBaseSubtotal * ($vatRate / 100);
                $displaySubtotal = $vatBaseSubtotal;
                $displayTotal = $vatBaseSubtotal;
                $regularVatLineAmount = $vatAmount;
            }

            $subtotalLabel = "Subtotal:";
            $subtotalVal = sprintf("N$%8.2f", $displaySubtotal);
            $subtotalPad = max(1, $receiptWidth - strlen($subtotalLabel) - strlen($subtotalVal));
            $printer->text($subtotalLabel . str_repeat(' ', $subtotalPad) . $subtotalVal . "\n");

            $regularVatPrinted = strtolower((string) $receiptVatMode) !== 'none';
            if ($regularVatPrinted) {
                $rPct = rtrim(rtrim(number_format($vatRate, 2, '.', ''), '0'), '.');
                if ($rPct === '' || $rPct === '.') {
                    $rPct = '0';
                }
                if ($receiptVatMode === 'inclusive') {
                    $regularVatLabel = 'VAT: ' . $rPct . '% (incl.):';
                } else {
                    $regularVatLabel = 'VAT: ' . $rPct . '% (excl.):';
                }
                $vatVal = sprintf("N$%8.2f", $regularVatLineAmount);
                $vatPad = max(1, $receiptWidth - strlen($regularVatLabel) - strlen($vatVal));
                $printer->text($regularVatLabel . str_repeat(' ', $vatPad) . $vatVal . "\n");
            }

            if ($posGratuityAmt > 0.005) {
                $gPctRaw = $orderData['gratuity_percent_applied'] ?? null;
                $gPctStr = '';
                if ($gPctRaw !== null && $gPctRaw !== '') {
                    $gPctStr = rtrim(rtrim(number_format((float) $gPctRaw, 2, '.', ''), '0'), '.');
                }
                $gLab = 'Gratuity' . ($gPctStr !== '' && $gPctStr !== '.' ? " ({$gPctStr}%)" : '');
                $gLab .= $posGratuityIncluded ? ', incl. in total' : ', not incl. in due';
                $printer->text($lineLabelAmount($gLab . ':', $posGratuityAmt));
                $printer->text($separatorLine('thin'));
                if ($posGratuityIncluded) {
                    $displayTotal = round($displayTotal + $posGratuityAmt, 2);
                }
            }

            $printer->setEmphasis(true);
            $printer->selectPrintMode(
                Printer::MODE_DOUBLE_HEIGHT | Printer::MODE_EMPHASIZED
            );
            $totLblLeft = "Total:";
            $totLblAmt = number_format($displayTotal, 2, '.', '');
            $totLblRight = sprintf("N$%8s", $totLblAmt);
            $totPad = max(1, $receiptWidth - strlen($totLblLeft) - strlen($totLblRight));
            $printer->text($totLblLeft . str_repeat(' ', $totPad) . $totLblRight . "\n");
            $printer->selectPrintMode();
            $printer->setEmphasis(false);

            $regularGrandTotal = $displayTotal;
            if ($tips > 0) {
                $printer->text($lineLabelAmount("Tips:", $tips));
                $regularGrandTotal = round($regularGrandTotal + $tips, 2);
            }

            $printer->setEmphasis(true);
            $printer->selectPrintMode(
                Printer::MODE_DOUBLE_HEIGHT | Printer::MODE_EMPHASIZED
            );
            $gLeft = 'Grand total:';
            $gAmt = number_format($regularGrandTotal, 2, '.', '');
            $gRight = sprintf("N$%8s", $gAmt);
            $gPad = max(1, $receiptWidth - strlen($gLeft) - strlen($gRight));
            $printer->text($gLeft . str_repeat(' ', $gPad) . $gRight . "\n");
            $printer->selectPrintMode();
            $printer->setEmphasis(false);
            $printer->text($separatorLine('thin'));
            $printer->feed();
            
            // Payment information section (for non-tab sales and payment receipts)
            $printer->text($separatorLine('thin'));
            $printer->setEmphasis(true);
            $printer->text("PAYMENT INFORMATION\n");
            $printer->setEmphasis(false);
            $printer->text($separatorLine('thin'));

            $tenderTotal = isset($orderData['total']) ? round(floatval($orderData['total']), 2) : $regularGrandTotal;
            
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
            $printer->text(sprintf("%-10s N$%8.2f\n", "Paid:", $tenderTotal));
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
            $printer->text(sprintf("%-10s N$%8.2f\n", "Total:", $tenderTotal));
            
            $mixedChange = receipt_mixed_payment_change($orderData, $tenderTotal);
            if ($mixedChange > 0.004) {
                $changeText = sprintf("Change: %10s", "N$ " . number_format($mixedChange, 2));
                $printer->text($changeText . "\n");
            }
        } else {
            // Cash payment
            $printer->text("Method: Cash\n");
            $paidText = sprintf("Paid: %10s", "N$ " . number_format($orderData['cash_received'], 2));
            $printer->text($paidText . "\n");
            $change = max(0, $orderData['cash_received'] - $tenderTotal);
            $changeText = sprintf("Change: %8s", "N$ " . number_format($change, 2));
            $printer->text($changeText . "\n");
        }
        
        }
        
        // Footer section - skip for kitchen tickets (tab sales)
        if (!$isTabSale || $isPaymentReceipt || !empty($orderData['is_tab_copy_receipt'])) {
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
        $isKitchenTicket = (isset($orderData['table_id']) || isset($orderData['tab_id'])) && !isset($orderData['is_payment_receipt']) && empty($orderData['is_tab_copy_receipt']);
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

        $includeLogoInRaw = !$isTabSale || $isPaymentReceipt || !empty($orderData['is_tab_copy_receipt']);
        emitRawModeJson($orderData, $rawBytes, $businessInfo, $receiptWidthMm, $includeLogoInRaw);
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
                enrichOrderDataWithBusinessInfo($orderData, $businessInfo);
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