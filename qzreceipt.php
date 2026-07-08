<?php
// Lightweight GET endpoint to provide business info for iframe-based printing
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['business_info'])) {
    try {
        $db = new PDO('sqlite:info.db');
        $businessInfo = $db->query("SELECT * FROM business_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$businessInfo) {
            $businessInfo = [
                'name' => 'POS SOLUTION',
                'location' => 'Your Business Address',
                'phone' => 'Your Phone Number',
                'footer_text' => 'Thank you for your purchase!'
            ];
        }
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'businessInfo' => [
            'business_name' => $businessInfo['name'] ?? 'POS SOLUTION',
            'business_location' => $businessInfo['location'] ?? 'Your Business Address',
            'business_phone' => $businessInfo['phone'] ?? 'Your Phone Number',
            'footer_text' => $businessInfo['footer_text'] ?? 'Thank you for your purchase!'
        ]]);
        exit;
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'businessInfo' => [
            'business_name' => 'POS SOLUTION',
            'business_location' => 'Your Business Address',
            'business_phone' => 'Your Phone Number',
            'footer_text' => 'Thank you for your purchase!'
        ]]);
        exit;
    }
}

// Handle POST requests for printing
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
$orderData = json_decode(file_get_contents('php://input'), true);

if (!$orderData) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No order data received']);
    exit;
}

    // Fetch business info
    try {
        $db = new PDO('sqlite:info.db');
        $businessInfo = $db->query("SELECT * FROM business_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);

        if (!$businessInfo) {
            $businessInfo = [
                'name' => 'POS SOLUTION',
                'location' => 'Your Business Address',
                'phone' => 'Your Phone Number',
                'footer_text' => 'Thank you for your purchase!'
            ];
        }
        
        // Merge business info with order data
        $orderData = array_merge($orderData, [
            'business_name' => $businessInfo['name'],
            'business_location' => $businessInfo['location'],
            'business_phone' => $businessInfo['phone'],
            'footer_text' => $businessInfo['footer_text']
        ]);
        
    } catch (Exception $e) {
        // Use defaults if database fails
        $orderData = array_merge($orderData, [
            'business_name' => 'POS SOLUTION',
            'business_location' => 'Your Business Address',
            'business_phone' => 'Your Phone Number',
            'footer_text' => 'Thank you for your purchase!'
        ]);
    }
    
    // Return the enhanced order data for client-side printing
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'orderData' => $orderData]);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt Printer - QZ Tray</title>
    <!-- QZ Tray Scripts -->
    <script type="text/javascript" src="receipt/js/qz-tray.js"></script>
    <script type="text/javascript" src="receipt/js/sample/promise-polyfill-8.1.3.min.js"></script>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .status {
            padding: 10px;
            margin: 10px 0;
            border-radius: 4px;
            text-align: center;
        }
        .success { background-color: #d4edda; color: #155724; }
        .error { background-color: #f8d7da; color: #721c24; }
        .info { background-color: #d1ecf1; color: #0c5460; }
        .print-button {
            background-color: #007bff;
            color: white;
            border: none;
            padding: 10px 20px;
            font-size: 16px;
            cursor: pointer;
            border-radius: 4px;
            margin: 5px;
        }
        .print-button:hover {
            background-color: #0056b3;
        }
        .print-button:disabled {
            background-color: #6c757d;
            cursor: not-allowed;
        }
    </style>
</head>
<body>
    <div class="container" id="mainContainer">
        <h2>Receipt Printer</h2>
        <div id="status" class="status info">Initializing QZ Tray...</div>
        <div id="buttons" style="display: none;">
            <button id="printBtn" class="print-button" onclick="printReceipt()">Print Receipt</button>
            <button id="testBtn" class="print-button" onclick="testPrint()">Test Print</button>
        </div>
    </div>

    <script>
    // Global variables
    let orderData = null;
    let qzConnected = false;
    let hasPrinted = false;

    // Receipt width helpers for 58mm paper (printable ~48mm)
    const CHAR_WIDTH = 48; // increased columns for 58mm with Font A (if printer supports)
    function line(char = '-') {
        return char.repeat(CHAR_WIDTH) + '\n';
    }
    function truncate(text, max = CHAR_WIDTH) {
        text = String(text ?? '');
        return text.length > max ? text.substring(0, Math.max(0, max - 3)) + '...' : text;
    }
    function formatLine(left, right) {
        left = String(left ?? '');
        right = String(right ?? '');
        
        // Ensure we have at least 1 character for right side
        const maxRight = Math.max(1, CHAR_WIDTH - left.length - 1);
        
        if (right.length > maxRight) {
            const cut = Math.max(1, maxRight - 3);
            right = (cut > 0 ? right.substring(0, cut) + '...' : right.substring(0, maxRight));
        }
        
        const spaces = CHAR_WIDTH - left.length - right.length;
        return left + ' '.repeat(Math.max(1, spaces)) + right + '\n';
    }

    // Function to format long item names with proper line breaks
    function formatLongItemName(itemName, maxWidth = CHAR_WIDTH) {
        const name = String(itemName || '');
        if (name.length <= maxWidth) {
            return name + '\n';
        }
        
        // Split long item names at word boundaries when possible
        const words = name.split(' ');
        let result = '';
        let currentLine = '';
        
        words.forEach(word => {
            if ((currentLine + ' ' + word).length > maxWidth && currentLine.length > 0) {
                result += truncate(currentLine, maxWidth) + '\n';
                currentLine = word;
            } else {
                currentLine += (currentLine.length > 0 ? ' ' : '') + word;
            }
        });
        
        if (currentLine.length > 0) {
            result += truncate(currentLine, maxWidth) + '\n';
        }
        
        return result;
    }

    // Split long ESC/POS data into manageable chunks for QZ/WebSocket
    function chunkString(text, chunkSize = 2048) {
        const chunks = [];
        for (let i = 0; i < text.length; i += chunkSize) {
            chunks.push(text.substring(i, i + chunkSize));
        }
        return chunks;
    }

    // Prefer chunking by line boundaries to avoid splitting ESC/POS sequences mid-line
    function chunkByLines(text, maxLen = 1536) {
        const lines = text.split('\n');
        const chunks = [];
        let current = '';
        for (let i = 0; i < lines.length; i++) {
            const lineWithNl = (i < lines.length - 1) ? (lines[i] + '\n') : lines[i];
            if ((current.length + lineWithNl.length) > maxLen && current.length > 0) {
                chunks.push(current);
                current = '';
            }
            current += lineWithNl;
        }
        if (current.length > 0) chunks.push(current);
        return chunks;
    }

    // Send chunks sequentially to the printer to avoid buffer overflows
    function printChunksSequentially(config, chunks, interDelayMs = 75) {
        let chain = Promise.resolve();
        chunks.forEach(function(part, idx) {
            chain = chain.then(function() {
                const data = [{ type: 'raw', format: 'command', flavor: 'plain', data: part }];
                return qz.print(config, data).then(function() {
                    if (interDelayMs > 0 && idx < chunks.length - 1) {
                        return new Promise(function(resolve) { setTimeout(resolve, interDelayMs); });
                    }
                });
            });
        });
        return chain;
    }

    // Break a large balance receipt into multiple smaller pages for reliability
    function paginateBalanceData(data, pageSize = 25) {
        try {
            const txns = Array.isArray(data.transactions) ? data.transactions.filter(t => Number(t.balance || 0) > 0) : [];
            if (txns.length <= pageSize) return [Object.assign({}, data, { page_index: 0, total_pages: 1, page_size: pageSize })];
            const pages = [];
            const totalPages = Math.ceil(txns.length / pageSize);
            for (let i = 0; i < totalPages; i++) {
                const start = i * pageSize;
                const end = start + pageSize;
                const slice = txns.slice(start, end);
                const pageData = Object.assign({}, data, {
                    transactions: slice,
                    page_index: i,
                    total_pages: totalPages,
                    page_size: pageSize
                });
                pages.push(pageData);
            }
            return pages;
        } catch (e) {
            return [data];
        }
    }

    // Break a large regular receipt (many items) into multiple smaller pages
    function paginateRegularData(data, itemsPerPage = 25) {
        try {
            const items = Array.isArray(data.items) ? data.items : [];
            if (items.length <= itemsPerPage) return [Object.assign({}, data, { page_index: 0, total_pages: 1, items_per_page: itemsPerPage })];
            const pages = [];
            const totalPages = Math.ceil(items.length / itemsPerPage);
            for (let i = 0; i < totalPages; i++) {
                const start = i * itemsPerPage;
                const end = start + itemsPerPage;
                const slice = items.slice(start, end);
                const pageData = Object.assign({}, data, {
                    items: slice,
                    page_index: i,
                    total_pages: totalPages,
                    items_per_page: itemsPerPage
                });
                pages.push(pageData);
            }
            return pages;
        } catch (e) {
            return [data];
        }
    }

    // Check if we're in an iframe (background printing)
    function isInIframe() {
        try {
            return window.self !== window.top;
        } catch (e) {
            return true;
        }
    }

    // Initialize QZ Tray when page loads
    document.addEventListener('DOMContentLoaded', function() {
        // Hide UI if in iframe (background printing)
        if (isInIframe()) {
            document.getElementById('mainContainer').style.display = 'none';
            document.body.style.margin = '0';
            document.body.style.padding = '0';
        }
        
        initializeQZ();
        loadOrderData();
    });

    // Load order data from PHP or query and auto-print
    function loadOrderData() {
        // Get order data from URL parameters or POST data
        const urlParams = new URLSearchParams(window.location.search);
        const orderDataParam = urlParams.get('data');
        
        if (orderDataParam) {
            try {
                orderData = JSON.parse(decodeURIComponent(orderDataParam));
                // Merge business info from server before printing
                fetch(window.location.pathname + '?business_info=1', { cache: 'no-store' })
                    .then(r => r.json())
                    .then(info => {
                        if (info && info.success && info.businessInfo) {
                            orderData = Object.assign({}, orderData, info.businessInfo);
                        }
                        updateStatus('Order data loaded successfully', 'success');
                        document.getElementById('buttons').style.display = 'block';
                        attemptAutoPrint();
                    })
                    .catch(() => {
                        updateStatus('Order data loaded (without business info fallback)', 'info');
                        document.getElementById('buttons').style.display = 'block';
                        attemptAutoPrint();
                    });
            } catch (e) {
                updateStatus('Error parsing order data: ' + e.message, 'error');
            }
        } else {
            // Try to get data from POST request (if this page was called via fetch)
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({})
            })
            .then(response => response.json())
            .then(data => {
                if (data.orderData) {
                    orderData = data.orderData;
                    // Merge business info from server before printing
                    fetch(window.location.pathname + '?business_info=1', { cache: 'no-store' })
                        .then(r => r.json())
                        .then(info => {
                            if (info && info.success && info.businessInfo) {
                                orderData = Object.assign({}, orderData, info.businessInfo);
                            }
                            updateStatus('Order data loaded successfully', 'success');
                            document.getElementById('buttons').style.display = 'block';
                            attemptAutoPrint();
                        })
                        .catch(() => {
                            updateStatus('Order data loaded (without business info fallback)', 'info');
                            document.getElementById('buttons').style.display = 'block';
                            attemptAutoPrint();
                        });
                } else {
                    updateStatus('No order data available', 'error');
                }
            })
            .catch(error => {
                updateStatus('Error loading order data: ' + error.message, 'error');
            });
        }
    }

    // Update status message
    function updateStatus(message, type) {
        const statusDiv = document.getElementById('status');
        statusDiv.textContent = message;
        statusDiv.className = 'status ' + type;
    }

    // Initialize QZ Tray
    function initializeQZ() {
        // Set up QZ Tray security
qz.security.setCertificatePromise(function(resolve, reject) {
            // Try to load certificate from server
            fetch("receipt/digital-certificate.txt", {cache: 'no-store', headers: {'Content-Type': 'text/plain'}})
              .then(function(data) { 
                  data.ok ? resolve(data.text()) : reject(data.text()); 
              })
              .catch(function() {
                  // Fallback to anonymous mode for testing
                  resolve();
              });
        });

        qz.security.setSignatureAlgorithm("SHA512");
qz.security.setSignaturePromise(function(toSign) {
    return function(resolve, reject) {
                // Try to sign with server
                fetch("receipt/assets/signing/sign-message.php?request=" + toSign, {cache: 'no-store', headers: {'Content-Type': 'text/plain'}})
                  .then(function(data) { 
                      data.ok ? resolve(data.text()) : reject(data.text()); 
                  })
                  .catch(function() {
                      // Fallback to unsigned for testing
                      resolve();
                  });
    };
});

        // Connect to QZ Tray
                qz.websocket.connect().then(function() {
            qzConnected = true;
            updateStatus('QZ Tray connected successfully', 'success');
            document.getElementById('buttons').style.display = 'block';
            // If orderData already loaded, auto-print now
            attemptAutoPrint();
        }).catch(function(error) {
            updateStatus('Failed to connect to QZ Tray: ' + error, 'error');
        });
    }

    // Attempt auto print once when both data and connection are ready
    function attemptAutoPrint() {
        if (hasPrinted) return;
        if (!orderData) return;
        if (!qzConnected) {
            let waitTime = 0;
            const maxWaitTime = 10000; // Maximum 10 seconds wait for connection
            const waitConn = setInterval(function() {
                waitTime += 200;
                if (qzConnected) {
                    clearInterval(waitConn);
                    attemptAutoPrint();
                } else if (waitTime >= maxWaitTime) {
                    clearInterval(waitConn);
                    updateStatus('QZ Tray connection timeout. Please try printing manually.', 'error');
                }
            }, 200);
            return;
        }
        hasPrinted = true;
        printReceipt();
    }

    // Test print function
    function testPrint() {
        if (!qzConnected) {
            updateStatus('QZ Tray not connected', 'error');
            return;
        }

        const config = qz.configs.create(null, { altPrinting: true, encoding: 'CP437', jobName: 'POS Receipt' });
        qz.printers.getDefault().then(function(printer) {
            config.setPrinter(printer);
            
            const testData = [
                { type: 'raw', format: 'command', flavor: 'plain', data: generateTestReceipt() }
            ];
            
            return qz.print(config, testData);
        }).then(function() {
            updateStatus('Test print sent successfully', 'success');
        }).catch(function(error) {
            updateStatus('Test print failed: ' + error, 'error');
        });
    }

    // Print receipt function
    function printReceipt() {
        if (!qzConnected) {
            updateStatus('QZ Tray not connected', 'error');
            return;
        }

        if (!orderData) {
            updateStatus('No order data available', 'error');
            return;
        }

        // Show processing status for longer receipts
        updateStatus('Preparing receipt for printing...', 'info');
        
        const config = qz.configs.create(null, { altPrinting: true, encoding: 'CP437', rasterize: false, scaleContent: false, copies: 1 });

        // Hoist for scope access in handlers
        let receiptDataText = '';
        let receiptLength = 0;
        let rawReceiptText = null;
        let rawReceiptPromise = null;

        // Get the exact ESC/POS bytes from the server (so formatting matches receipt.php).
        function ensureRawReceiptText() {
            if (rawReceiptText) return Promise.resolve(rawReceiptText);
            if (rawReceiptPromise) return rawReceiptPromise;

            rawReceiptPromise = fetch('receipt.php?raw=1', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(orderData)
            })
            .then(async function(r) {
                const text = await r.text();
                let json = null;
                try { json = text ? JSON.parse(text) : null; } catch (e) { /* non-JSON */ }
                if (!r.ok) {
                    const msg = (json && json.message) ? json.message : (text || ('HTTP ' + r.status));
                    throw new Error(msg);
                }
                return json;
            })
            .then(function(resp) {
                if (!resp || !resp.success) {
                    throw new Error((resp && resp.message) ? resp.message : 'Failed to generate raw receipt');
                }
                if (!resp.raw_base64) {
                    throw new Error('Missing raw_base64 in receipt.php raw response');
                }
                // atob returns a binary string where each character code corresponds to the byte (0-255)
                rawReceiptText = atob(resp.raw_base64);
                return rawReceiptText;
            });

            return rawReceiptPromise;
        }

        function performPrintForData(dataForPrint) {
            return qz.printers.getDefault().then(function(printer) {
                config.setPrinter(printer);

                // Use the server-generated raw ESC/POS stream
                receiptDataText = rawReceiptText || '';

                // Show printing status for longer receipts
                receiptLength = receiptDataText ? receiptDataText.length : 0;
                if (receiptLength > 500) {
                    updateStatus('Printing longer receipt, please wait...', 'info');
                }

                // Prepare primary and fallback strategies (smaller chunks first for reliability)
                const primaryChunks = chunkByLines(receiptDataText, 1024);
                const fallbackChunks = chunkByLines(receiptDataText, 768);
                const lastResortChunks = chunkByLines(receiptDataText, 512);

                function printWithStrategy(chunks, delay) {
                    return printChunksSequentially(config, chunks, delay);
                }

                function printAsSingleJob(chunks) {
                    const dataArr = chunks.map(function(part) {
                        return { type: 'raw', format: 'command', flavor: 'plain', data: part };
                    });
                    return qz.print(config, dataArr);
                }

                // Calculate dynamic timeout based on receipt length and type
                const isBalanceReceipt = dataForPrint.is_balance_receipt;
                const baseTimeout = isBalanceReceipt ? 120000 : 60000; // 120s for balance, 60s for others
                const lengthTimeout = Math.floor(receiptLength / 5); // 200ms per 10 characters
                const totalTimeout = Math.min(baseTimeout + lengthTimeout, 600000); // Max 10 minutes

                // Try strategies in sequence with retries
                let currentStrategy = 0;
                const strategies = [
                    { chunks: primaryChunks, delay: 75, name: 'Primary (1024b chunks)' },
                    { chunks: fallbackChunks, delay: 125, name: 'Fallback (768b chunks)' },
                    { chunks: lastResortChunks, delay: 175, name: 'Last resort (512b chunks)' },
                    { chunks: lastResortChunks, delay: 0, name: 'Single job', single: true }
                ];

                function tryNextStrategy() {
                    if (currentStrategy >= strategies.length) {
                        throw new Error('All print strategies failed');
                    }

                    const strategy = strategies[currentStrategy];
                    currentStrategy++;

                    const strategyTimeout = Math.min(totalTimeout, 60000 + (strategy.chunks.length * 5000)); // 5s per chunk
                    updateStatus(`Trying ${strategy.name}... (${strategy.chunks.length} chunks)`, 'info');

                    const attemptOnce = () => {
                        const printPromise = strategy.single ?
                            printAsSingleJob(strategy.chunks) :
                            printWithStrategy(strategy.chunks, strategy.delay);
                        const timeoutPromise = new Promise((_, reject) => {
                            setTimeout(() => reject(new Error(`Strategy timeout: ${strategy.name}`)), strategyTimeout);
                        });
                        return Promise.race([printPromise, timeoutPromise]);
                    };

                    let attempts = 0;
                    const maxAttempts = 2; // one retry per strategy
                    function attemptWithRetry() {
                        return attemptOnce().catch(function(err) {
                            attempts++;
                            if (attempts <= maxAttempts) {
                                console.warn(`${strategy.name} attempt ${attempts} failed, retrying...`, err);
                                return new Promise(res => setTimeout(res, 300)).then(attemptWithRetry);
                            }
                            if (currentStrategy < strategies.length) {
                                return tryNextStrategy();
                            }
                            throw err;
                        });
                    }

                    return attemptWithRetry();
                }

                return tryNextStrategy();
            });
        }

        // Print once (chunked internally) using the captured ESC/POS stream.
        // Drawer-only mode: pulse drawer via QZ raw ESC/POS command.
        const isDrawerOnly = !!orderData.open_drawer_only;
        const printFlow = isDrawerOnly
            ? qz.printers.getDefault().then(function(printer) {
                config.setPrinter(printer);
                // ESC p m t1 t2 -> pulse drawer pin 2
                const drawerPulse = '\x1B\x70\x00\x32\x32';
                return qz.print(config, [{ type: 'raw', format: 'command', flavor: 'plain', data: drawerPulse }]);
            })
            : ensureRawReceiptText().then(function() {
                return performPrintForData(orderData);
            });

        printFlow
            .then(function() {
            updateStatus('Receipt printed successfully', 'success');
            
            // Notify parent window that printing is complete (if in iframe)
            if (isInIframe()) {
                try {
                    // Send message to parent window indicating print completion
                    window.parent.postMessage({
                        type: 'printComplete',
                        success: true,
                        message: 'Receipt printed successfully'
                    }, '*');
                } catch (e) {
                    console.log('Could not notify parent window:', e);
                }
            }
            
            // If in iframe, close after successful print
            if (isInIframe()) {
                // Calculate timeout based on receipt length for longer receipts
                const baseTimeout = 3000; // Base 3 seconds
                const additionalTimeout = Math.max(0, Math.floor(receiptLength / 100) * 1000); // 1s per 100 chars
                const totalTimeout = Math.min(baseTimeout + additionalTimeout, 15000); // Max 15 seconds
                
                setTimeout(() => {
                    window.close();
                }, totalTimeout);
            }
        }).catch(function(error) {
            updateStatus('Print failed: ' + error, 'error');
            
            // Notify parent window that printing failed (if in iframe)
            if (isInIframe()) {
                try {
                    // Send message to parent window indicating print failure
                    window.parent.postMessage({
                        type: 'printComplete',
                        success: false,
                        message: 'Print failed: ' + error
                    }, '*');
                } catch (e) {
                    console.log('Could not notify parent window:', e);
                }
            }
            
            // If in iframe, close even on error after delay
            if (isInIframe()) {
                // Calculate timeout based on receipt length for longer receipts
                const baseTimeout = 5000; // Base 5 seconds for errors
                const additionalTimeout = Math.max(0, Math.floor(receiptLength / 100) * 1000); // 1s per 100 chars
                const totalTimeout = Math.min(baseTimeout + additionalTimeout, 20000); // Max 20 seconds
                
                setTimeout(() => {
                    window.close();
                }, totalTimeout);
            }
        });
    }

    // Generate test receipt
    function generateTestReceipt() {
        let receipt = '';
        receipt += '\x1B\x40'; // Initialize printer
        receipt += '\x1B\x4D\x00'; // Font A (32 cols)
        receipt += '\x1B\x61\x01'; // Center align
        receipt += '\x1B\x21\x30'; // Double height, double width
        receipt += 'TEST RECEIPT\n';
        receipt += '\x1B\x21\x00'; // Normal size
        receipt += '\x1B\x61\x00'; // Left align
        receipt += 'QZ Tray Test\n';
        receipt += formatLine('Date:', new Date().toLocaleString());
        receipt += 'This is a test print\n';
        receipt += 'from QZ Tray integration\n';
        receipt += line();
        receipt += '\x1B\x61\x01'; // Center align
        receipt += 'Test Successful!\n';
        receipt += '\x1B\x61\x00'; // Left align
        receipt += '\n\n\n';
        receipt += '\x1D\x56\x00'; // Cut paper
        return receipt;
    }
    
    // Generate regular receipt
    function generateRegularReceipt(data) {
        let receipt = '';
        receipt += '\x1B\x40'; // Initialize printer
        receipt += '\x1B\x4D\x00'; // Font A (32 cols)
        receipt += '\x1B\x61\x01'; // Center align
        const businessName = (data.business_name || 'POS SOLUTION');
        if (businessName.length <= 16) {
            receipt += '\x1B\x21\x30'; // Double height, double width
            receipt += businessName + '\n';
            receipt += '\x1B\x21\x00'; // Normal size
        } else {
            receipt += truncate(businessName) + '\n';
        }
        // Center the location
        receipt += truncate((data.business_location || 'Your Business Address')) + '\n';
        receipt += '\x1B\x61\x00'; // Left align

        // Tel and Cashier on the left
        receipt += '\x1B\x61\x01'; // Center align
        receipt += '\x1B\x45\x01'; // Bold
        receipt += 'Tel: ' + (data.business_phone || 'Your Phone Number') + '\n';
        receipt += 'Cashier: ' + (data.cashier_username || 'Unknown') + '\n';
        receipt += '\x1B\x45\x00'; // Normal
        receipt += '\x1B\x61\x00'; // Left align
        receipt += '\n';
        receipt += line();

        // Receipt type and number (centered)
        receipt += '\x1B\x61\x01'; // Center align
        receipt += '\x1B\x45\x01'; // Bold
        const receiptNumber = data.order_id || data.sale_id || 'UNKNOWN';
        const receiptType = data.sale_id ? 'Credit Sale' : 'Receipt';
        receipt += truncate(receiptType + ' #: ' + receiptNumber) + '\n';
        receipt += '\x1B\x45\x00'; // Normal
        receipt += '\x1B\x61\x00'; // Left align
        receipt += line();
        receipt += 'Date: ' + new Date().toISOString().replace('T', ' ').substring(0, 16) + '\n';

            // Items section (left aligned)
            receipt += '\x1B\x61\x00'; // Left align
            receipt += '\x1B\x45\x01'; // Bold
            receipt += formatLine('Item', 'Qty   Amount');
            receipt += '\x1B\x45\x00'; // Normal
            receipt += line();

        let subtotal = 0;
        if (data.items && data.items.length > 0) {
            data.items.forEach(item => {
                const name = String(item.name || '');
                const quantity = Number(item.quantity || 0);
                const amount = Number(item.price || 0);
                const price = quantity ? amount / quantity : 0;
                subtotal += amount;

                // Item name
                receipt += truncate(name, CHAR_WIDTH) + '\n';
                // qty x price ...... amount
                const qtyPrice = quantity + ' x N$' + price.toFixed(2);
                const amountText = 'N$' + amount.toFixed(2);
                receipt += formatLine(qtyPrice, amountText);
                receipt += line();
            });
        }

        // Totals section
        receipt += '\x1B\x45\x01'; // Bold
        receipt += formatLine('TOTAL', 'N$ ' + subtotal.toFixed(2));
        receipt += '\x1B\x45\x00'; // Normal

        // Payment information (left aligned)
        receipt += line();
        receipt += '\x1B\x45\x01'; // Bold
        receipt += 'PAYMENT INFO\n';
        receipt += '\x1B\x45\x00'; // Normal
        receipt += line();

        if (data.creditor_id) {
            // Credit payment
            receipt += 'Method: Credit\n';
            receipt += 'ID: ' + String(data.creditor_id) + '\n';
            if (data.creditor_name) {
                receipt += 'Name: ' + String(data.creditor_name) + '\n';
            }
            if (data.due_date) {
                receipt += 'Due: ' + String(data.due_date) + '\n';
            }

            // Transaction ID emphasized
            receipt += '\n';
            receipt += '\x1B\x61\x01'; // Center align
            receipt += 'Transaction ID:\n';
            if (String(receiptNumber).length <= 16) {
                receipt += '\x1B\x21\x30'; // Double size
                receipt += String(receiptNumber) + '\n';
                receipt += '\x1B\x21\x00'; // Back to normal
            } else {
                receipt += truncate(String(receiptNumber)) + '\n';
            }
            receipt += '\x1B\x61\x00'; // Left align
        } else if (data.payment_method === 'e-wallet') {
            // E-wallet payment
            receipt += 'Method: EFT\n';
            receipt += 'Provider: ' + (data.wallet_provider || 'Unknown') + '\n';
            const ref = data.transaction_ref || '';
            receipt += 'Ref: ' + ref + '\n';
            receipt += 'Paid: N$' + subtotal.toFixed(2) + '\n';
        } else {
            // Cash payment
            receipt += 'Method: Cash\n';
            const paid = Number(data.cash_received || 0);
            receipt += 'Paid: N$' + paid.toFixed(2) + '\n';
            const change = paid - subtotal;
            receipt += 'Change: N$' + change.toFixed(2) + '\n';
        }

        receipt += line();

        // Footer
        receipt += '\x1B\x61\x01'; // Center align
        receipt += truncate((data.footer_text || 'Thank you for your purchase!')) + '\n';
        receipt += '\x1B\x61\x00'; // Left align
        receipt += '\n\n\n\n'; // Feed

        // Open cash drawer only for cash payments
        if (!data.creditor_id && data.payment_method !== 'e-wallet') {
            receipt += '\x1B\x70\x00\x32\x32'; // Pulse (open cash drawer)
        }

        receipt += '\x1D\x56\x00'; // Cut paper
        return receipt;
    }
    
    // Generate cash-up receipt
    function generateCashUpReceipt(data) {
        let receipt = '';
        receipt += '\x1B\x40'; // Initialize printer
        receipt += '\x1B\x4D\x00'; // Font A (32 cols)
        receipt += '\x1B\x61\x01'; // Center align
        const businessName = (data.business_name || 'POS SOLUTION');
        if (businessName.length <= 16) {
            receipt += '\x1B\x21\x30'; // Double height, double width
            receipt += businessName + '\n';
            receipt += '\x1B\x21\x00'; // Normal size
        } else {
            receipt += truncate(businessName) + '\n';
        }
        // Center the location
        receipt += truncate((data.business_location || 'Your Business Address')) + '\n';
        receipt += '\x1B\x61\x00'; // Left align

        // Tel and Cashier on the left
        receipt += '\x1B\x61\x01'; // Center align
        receipt += '\x1B\x45\x01'; // Bold
        receipt += 'Tel: ' + (data.business_phone || 'Your Phone Number') + '\n';
        receipt += 'Cashier: ' + (data.cashier_username || 'Unknown') + '\n';
        receipt += '\x1B\x45\x00'; // Normal
        receipt += '\x1B\x61\x00'; // Left align
        receipt += '\n';
        receipt += line();

        // Report type and date (centered)
        receipt += '\x1B\x61\x01'; // Center align
        receipt += '\x1B\x45\x01'; // Bold
        receipt += 'DAILY CASH-UP REPORT\n';
        receipt += '\x1B\x45\x00'; // Normal
        receipt += '\x1B\x61\x00'; // Left align
        receipt += line();
        receipt += 'Date: ' + (data.date || new Date().toISOString().split('T')[0]) + '\n';
        receipt += 'By: ' + (data.cashier_username || 'Unknown') + '\n';
        receipt += line('=');

        receipt += '\x1B\x45\x01'; // Bold
        receipt += formatLine('EXPECTED CASH:', 'N$' + Number(data.expected_cash || 0).toFixed(2));
        receipt += '\x1B\x45\x00'; // Normal

        if (data.actual_cash_in_till) {
            receipt += formatLine('ACTUAL CASH:', 'N$' + parseFloat(data.actual_cash_in_till).toFixed(2));
            if (data.cash_difference) {
                const difference = parseFloat(data.cash_difference);
                if (!isNaN(difference) && difference !== 0) {
                    const label = difference > 0 ? 'SURPLUS:' : 'SHORTAGE:';
                    receipt += formatLine(label, 'N$' + Math.abs(difference).toFixed(2));
                }
            }
        }

        receipt += line('=');

        if (data.total_income) {
            receipt += '\x1B\x45\x01'; // Bold
            receipt += 'INCOME & EXPENSES\n';
            receipt += '\x1B\x45\x00'; // Normal
            receipt += line();
            receipt += formatLine('CASH:', 'N$' + (((data.cash_sales || 0) + (data.credit_cash || 0))).toFixed(2));
            receipt += formatLine('EFT:', 'N$' + (((data.credit_eft || 0) + (data.eft_sales || 0))).toFixed(2));
            receipt += formatLine('CREDIT:', 'N$' + Number(data.credit_unpaid || 0).toFixed(2));
            receipt += line();
            receipt += '\x1B\x45\x01'; // Bold
            receipt += formatLine('TOTAL INCOME:', 'N$' + Number(data.total_income || 0).toFixed(2));
            receipt += '\x1B\x45\x00'; // Normal
            receipt += formatLine('TOTAL EXPENSES:', 'N$' + Number(data.total_expense || 0).toFixed(2));
            receipt += line();
            receipt += '\x1B\x45\x01'; // Bold
            receipt += formatLine('NET AMOUNT:', 'N$' + Number(data.net_amount || 0).toFixed(2));
            receipt += '\x1B\x45\x00'; // Normal
        }

        receipt += line();
        receipt += '\x1B\x61\x01'; // Center align
        receipt += truncate((data.footer_text || 'Thank you for your purchase!')) + '\n';
        receipt += 'Cashier: ________________\n';
        receipt += 'Manager: ________________\n';
        receipt += '\x1B\x61\x00'; // Left align
        receipt += '\x1B\x70\x00\x32\x32'; // Pulse (open cash drawer)
        receipt += '\n\n\n\n'; // Feed
        receipt += '\x1D\x56\x00'; // Cut paper
        return receipt;
    }
    
    // Generate balance receipt
    function generateBalanceReceipt(data) {
        let receipt = '';
        receipt += '\x1B\x40'; // Initialize printer
        receipt += '\x1B\x4D\x00'; // Font A (32 cols)
        receipt += '\x1B\x61\x01'; // Center align
        const businessName = (data.business_name || 'POS SOLUTION');
        if (businessName.length <= 16) {
            receipt += '\x1B\x21\x30'; // Double height, double width
            receipt += businessName + '\n';
            receipt += '\x1B\x21\x00'; // Normal size
        } else {
            receipt += truncate(businessName) + '\n';
        }
        // Center the location
        receipt += truncate((data.business_location || 'Your Business Address')) + '\n';
        receipt += '\x1B\x61\x00'; // Left align

        // Tel and Cashier on the left
        receipt += '\x1B\x61\x01'; // Center align
        receipt += '\x1B\x45\x01'; // Bold
        receipt += 'Tel: ' + (data.business_phone || 'Your Phone Number') + '\n';
        receipt += 'Cashier: ' + (data.cashier_username || 'Unknown') + '\n';
        receipt += '\x1B\x45\x00'; // Normal
        receipt += '\x1B\x61\x00'; // Left align
        receipt += '\n';
        receipt += line();

        // Receipt type and client info (centered)
        receipt += '\x1B\x61\x01'; // Center align
        receipt += '\x1B\x45\x01'; // Bold
        receipt += 'BALANCE RECEIPT\n';
        receipt += '\x1B\x45\x00'; // Normal
        receipt += '\x1B\x61\x00'; // Left align
        receipt += line();
        receipt += 'Client: ' + (data.creditor_name || 'Unknown') + '\n';
        receipt += 'Balance: N$' + Number(data.total_balance || 0).toFixed(2) + '\n';
        if (typeof data.total_pages === 'number' && data.total_pages > 1 && typeof data.page_index === 'number') {
            receipt += 'Page ' + (data.page_index + 1) + ' of ' + data.total_pages + '\n';
        }
        receipt += line();

        receipt += '\x1B\x45\x01'; // Bold
        receipt += 'OUTSTANDING ITEMS\n';
        receipt += '\x1B\x45\x00'; // Normal
        receipt += line();

        if (data.transactions && data.transactions.length > 0) {
            // If paginated, we already sliced the array upstream
            const transactionsToShow = data.transactions;
            transactionsToShow.forEach(transaction => {
                receipt += 'Date: ' + new Date(transaction.date).toISOString().split('T')[0] + '\n';
                receipt += line();

                if (transaction.items) {
                    const items = String(transaction.items).split(', ');
                    // Limit items per transaction to prevent extremely long lines
                    const maxItems = 15;
                    const itemsToShow = items.slice(0, maxItems);
                    const hasMoreItems = items.length > maxItems;
                    
                    itemsToShow.forEach(item => {
                        // Use the new function to handle long item names properly
                        receipt += formatLongItemName(item);
                    });
                    
                    if (hasMoreItems) {
                        receipt += '... and ' + (items.length - maxItems) + ' more items\n';
                    }
                }

                // Ensure balance is always displayed properly
                const bal = Number(transaction.balance || 0).toFixed(2);
                receipt += '\x1B\x45\x01'; // Bold
                receipt += formatLine('Balance:', 'N$' + bal);
                receipt += '\x1B\x45\x00'; // Normal
                receipt += line();
            });
        }

        receipt += '\x1B\x45\x01'; // Bold
        receipt += formatLine('TOTAL BALANCE:', 'N$' + Number(data.total_balance || 0).toFixed(2));
        receipt += '\x1B\x45\x00'; // Normal
        receipt += line();

        receipt += '\x1B\x61\x01'; // Center align
        receipt += truncate((data.footer_text || 'Thank you for your purchase!')) + '\n';
        receipt += line();
        receipt += '\x1B\x61\x00'; // Left align
        receipt += '\n\n\n\n'; // Feed
        receipt += '\x1D\x56\x00'; // Cut paper
        return receipt;
    }

    // Handle POST requests for order data
    <?php
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $orderData = json_decode(file_get_contents('php://input'), true);
        if ($orderData) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'orderData' => $orderData]);
            exit;
        }
    }
    ?>
</script>
</body>
</html>