<?php
// Start session to track conversation history
session_start();


// Define the API endpoint and your API key
$apiUrl = "https://generativelanguage.googleapis.com/v1/models/gemini-2.0-flash:generateContent";
$apiKey = "AIzaSyBff_jPxEmh1CcS019p1fRbdKrDrriQko0";

// Initialize conversation history if it doesn't exist
if (!isset($_SESSION['conversation'])) {
    $_SESSION['conversation'] = [];
}

// Initialize used prompts tracking if it doesn't exist
if (!isset($_SESSION['used_prompts'])) {
    $_SESSION['used_prompts'] = [];
}

// Handle clear chat request
if (isset($_GET['clear']) && $_GET['clear'] == 1) {
    $_SESSION['conversation'] = [];
    $_SESSION['used_prompts'] = []; // Also clear used prompts
    echo json_encode(['status' => 'success', 'message' => 'Chat history cleared']);
    exit;
}

// Handle inbox fetch request
if (isset($_GET['inbox']) && $_GET['inbox'] == 1) {
    try {
        $infoDb = new PDO('sqlite:../info.db');
        $infoDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $infoDb->query("CREATE TABLE IF NOT EXISTS inbox_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, message TEXT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, is_read INTEGER DEFAULT 0)");
        
        // Get messages
        $stmt = $infoDb->query("SELECT * FROM inbox_messages ORDER BY created_at DESC LIMIT 50");
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Ensure is_read is properly cast to integer
        foreach ($messages as &$message) {
            $message['is_read'] = (int)$message['is_read'];
        }
        
        echo json_encode(['status' => 'success', 'messages' => $messages]);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Handle unread count request
if (isset($_GET['unread_count']) && $_GET['unread_count'] == 1) {
    try {
        $infoDb = new PDO('sqlite:../info.db');
        $infoDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $infoDb->query("SELECT COUNT(*) as count FROM inbox_messages WHERE is_read = 0");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'count' => $result['count']]);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Handle mark as read
if (isset($_GET['mark_read'])) {
    $id = intval($_GET['mark_read']);
    try {
        $infoDb = new PDO('sqlite:../info.db');
        $infoDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $infoDb->prepare("UPDATE inbox_messages SET is_read = 1 WHERE id = ?");
        $result = $stmt->execute([$id]);
        if ($result) {
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to mark message as read']);
        }
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Handle create inbox table request
if (isset($_GET['create_inbox_table']) && $_GET['create_inbox_table'] == 1) {
    try {
        $infoDb = new PDO('sqlite:../info.db');
        $infoDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $infoDb->query("CREATE TABLE IF NOT EXISTS inbox_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, message TEXT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, is_read INTEGER DEFAULT 0)");
        echo json_encode(['status' => 'success', 'message' => 'Inbox table created or already exists.']);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Handle debug insert test message
if (isset($_GET['debug_insert']) && $_GET['debug_insert'] == 1) {
    try {
        $infoDb = new PDO('sqlite:../info.db');
        $infoDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $infoDb->prepare("INSERT INTO inbox_messages (message) VALUES (?)");
        $stmt->execute(['Test message inserted at ' . date('Y-m-d H:i:s')]);
        echo json_encode(['status' => 'success', 'message' => 'Test message inserted.']);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Handle debug trigger AI message
if (isset($_GET['debug_trigger_ai']) && $_GET['debug_trigger_ai'] == 1) {
    try {
        $infoDb = new PDO('sqlite:../info.db');
        $infoDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $infoDb->prepare("INSERT INTO inbox_messages (message) VALUES (?)");
        $stmt->execute(['AI message triggered at ' . date('Y-m-d H:i:s')]);
        echo json_encode(['status' => 'success', 'message' => 'AI message triggered.']);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Handle generate notification
if (isset($_GET['generate_notification']) && $_GET['generate_notification'] == 1) {
    try {
        $infoDb = new PDO('sqlite:../info.db');
        $infoDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Get all database data
        $allData = [];
        
        // Connect to POS database
        try {
            $posDb = new PDO('sqlite:../pos.db');
            $posDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Get list of all tables from POS database
            $posTables = $posDb->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($posTables as $table) {
                $stmt = $posDb->query("SELECT * FROM $table");
                $allData[$table] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // Get yearly sales data
            $startDate = date('Y-01-01');
            $endDate = date('Y-12-31');
            
            // Get cash sales total for the year
            $cashSalesTotal = getCashSales($posDb, $startDate, $endDate);
            $allData['yearly_cash_sales'] = [['total' => $cashSalesTotal]];

            // Get credit sales data for the year
            $creditSalesTotal = getCreditSales($posDb, $startDate, $endDate);
            $allData['yearly_credit_sales'] = [['total_unpaid' => $creditSalesTotal]];

            // Get cost of goods sold
            $costOfGoodsSold = getCostOfGoodsSold($posDb, $startDate, $endDate);
            $allData['cost_of_goods_sold'] = [['total' => $costOfGoodsSold]];

            // Get total cash in
            $totalCashIn = getTotalCashIn($posDb, $startDate, $endDate);
            $allData['total_cash_in'] = [['amount' => $totalCashIn]];

            // Get total cash out
            $totalCashOut = getTotalCashOut($posDb, $startDate, $endDate);
            $allData['total_cash_out'] = [['amount' => $totalCashOut]];

            // Calculate net profit
            $totalRevenue = $cashSalesTotal + $creditSalesTotal;
            $grossProfit = calculateGrossProfit($totalRevenue, $costOfGoodsSold);
            $netProfit = calculateNetProfit($grossProfit, $totalCashIn, $totalCashOut);
            $allData['net_profit'] = [['amount' => $netProfit]];

        } catch (PDOException $e) {
            error_log('POS database connection failed: ' . $e->getMessage());
        }

        // Get admin username
        try {
            $userDb = new PDO('sqlite:../user.db');
            $userDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $stmt = $userDb->prepare("SELECT username FROM users WHERE role = 'admin'");
            $stmt->execute();
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);
            $adminName = $admin ? $admin['username'] : 'Admin';
        } catch (PDOException $e) {
            error_log('Error fetching admin username: ' . $e->getMessage());
            $adminName = 'Admin';
        }

        // Get last login time for the admin
        try {
            $posDb = new PDO('sqlite:../pos.db');
            $posDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $stmt = $posDb->prepare("SELECT action_time FROM user_log WHERE user_id = ? AND action_type = 'login' ORDER BY action_time DESC LIMIT 1");
            $stmt->execute([$adminName]);
            $lastLogin = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Determine greeting based on time
            $greeting = "Hello";
            if ($lastLogin) {
                $hour = date('G', strtotime($lastLogin['action_time']));
                if ($hour >= 5 && $hour < 12) {
                    $greeting = "Good morning";
                } elseif ($hour >= 12 && $hour < 17) {
                    $greeting = "Good afternoon";
                } else {
                    $greeting = "Good evening";
                }
            }
        } catch (PDOException $e) {
            error_log('Error fetching last login time: ' . $e->getMessage());
            $greeting = "Hello";
        }

        // Format all data in a more readable way
        $prompts = [
            "Analyze employee sales patterns and suggest one specific way to improve their performance in 1-2 sentences.",
            "Identify the most profitable time slots and recommend an optimal staffing schedule in 1-2 sentences.",
            "Compare employee performance metrics and highlight one actionable improvement area in 1-2 sentences.",
            "Analyze customer purchase patterns and suggest one targeted upselling opportunity in 1-2 sentences.",
            "Review inventory turnover rates and recommend one specific stock adjustment in 1-2 sentences.",
            "Examine peak hours sales data and suggest one operational improvement in 1-2 sentences.",
            "Analyze product pairing trends and recommend one promotional strategy in 1-2 sentences.",
            "Review employee-customer interaction patterns and suggest one service enhancement in 1-2 sentences.",
            "Compare weekday vs weekend performance and recommend one scheduling optimization in 1-2 sentences.",
            "Analyze payment method preferences and suggest one transaction efficiency improvement in 1-2 sentences.",
            "Identify the least selling items and suggest one specific action to improve their performance in 1-2 sentences.",
            "Analyze profit margins across all products and recommend one pricing strategy adjustment in 1-2 sentences.",
            "Compare buying prices with selling prices and suggest one inventory optimization strategy in 1-2 sentences.",
            "Review low-margin products and recommend one specific improvement to increase profitability in 1-2 sentences.",
            "Analyze slow-moving inventory and suggest one specific action to reduce stock holding costs in 1-2 sentences.",
            "Review credit sales patterns and suggest one specific strategy to improve collection rates in 1-2 sentences.",
            "Analyze customer credit history and recommend one approach to reduce outstanding balances in 1-2 sentences.",
            "Compare credit vs cash sales and suggest one way to optimize the credit policy in 1-2 sentences.",
            "Review late payment patterns and recommend one specific action to improve payment timeliness in 1-2 sentences.",
            "Analyze credit limit utilization and suggest one adjustment to the credit management strategy in 1-2 sentences."
        ];
        
        // Get available prompts (those not recently used)
        $availablePrompts = array_diff($prompts, array_slice($_SESSION['used_prompts'], -5));
        
        // If all prompts have been used recently, reset the tracking
        if (empty($availablePrompts)) {
            $_SESSION['used_prompts'] = [];
            $availablePrompts = $prompts;
        }
        
        // Select a random prompt from available ones
        $randomPrompt = $availablePrompts[array_rand($availablePrompts)];
        
        // Add the selected prompt to used prompts
        $_SESSION['used_prompts'][] = $randomPrompt;
        
        // Keep only the last 10 used prompts
        $_SESSION['used_prompts'] = array_slice($_SESSION['used_prompts'], -10);
        
        $context = $randomPrompt . "You are a friendly and smart tuckshop sales assistant. " . $greeting . " " . $adminName . "! Write a message using simple casual English. start writing already ,Use this data to make your point, remember that `oders` table is supposed to be `cash sales` table :\n";
        
        foreach ($allData as $table => $rows) {
            $context .= "Table: $table\n";
            foreach ($rows as $row) {
                $context .= "- " . json_encode($row) . "\n";
            }
            $context .= "\n";
        }

        // Use Gemini API to generate a context-based notification
        $apiData = [
            "contents" => [
                "parts" => [
                    ["text" => $context]
                ]
            ],
            "generationConfig" => [
                "temperature" => 0.2,
                "topP" => 0.8,
                "topK" => 40,
                "maxOutputTokens" => 100  // Limit response length
            ]
        ];

        $ch = curl_init($apiUrl . "?key=" . $apiKey);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($apiData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);

        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            throw new Exception('API request failed: ' . curl_error($ch));
        }
        curl_close($ch);

        $responseData = json_decode($response, true);
        $notificationMessage = $responseData['candidates'][0]['content']['parts'][0]['text'] ?? "Default notification message.";

        // Format the notification message
        $notificationMessage = formatBotResponse($notificationMessage);

        $stmt = $infoDb->prepare("INSERT INTO inbox_messages (message) VALUES (?)");
        $stmt->execute([$notificationMessage]);
        echo json_encode(['status' => 'success', 'message' => 'Notification generated and inserted with context.']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Handle clear inbox messages
if (isset($_GET['clear_inbox']) && $_GET['clear_inbox'] == 1) {
    try {
        $infoDb = new PDO('sqlite:../info.db');
        $infoDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $infoDb->query("DELETE FROM inbox_messages");
        echo json_encode(['status' => 'success', 'message' => 'Inbox cleared.']);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Handle delete single message
if (isset($_GET['delete_message'])) {
    $id = intval($_GET['delete_message']);
    try {
        $infoDb = new PDO('sqlite:../info.db');
        $infoDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $infoDb->prepare("DELETE FROM inbox_messages WHERE id = ?");
        $result = $stmt->execute([$id]);
        if ($result) {
            echo json_encode(['status' => 'success', 'message' => 'Message deleted.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to delete message']);
        }
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}


// Process incoming message
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get the JSON data from the request
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    
    $userMessage = $data['message'] ?? '';
    
    if (empty($userMessage)) {
        echo json_encode(['status' => 'error', 'message' => 'No message provided']);
        exit;
    }
    
    // All database data
    $allData = [];
    
    // Connect to POS database
    try {
        $posDb = new PDO('sqlite:../pos.db');
        $posDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Get list of all tables from POS database
        $posTables = $posDb->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($posTables as $table) {
            $stmt = $posDb->query("SELECT * FROM $table");
            $allData[$table] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Get yearly sales data
        $startDate = date('Y-01-01');
        $endDate = date('Y-12-31');
        
        // Get cash sales total for the year
        $cashSalesTotal = getCashSales($posDb, $startDate, $endDate);
        $allData['yearly_cash_sales'] = [['total' => $cashSalesTotal]];

        // Get credit sales data for the year
        $creditSalesTotal = getCreditSales($posDb, $startDate, $endDate);
        $allData['yearly_credit_sales'] = [['total_unpaid' => $creditSalesTotal]];

        // Get cost of goods sold
        $costOfGoodsSold = getCostOfGoodsSold($posDb, $startDate, $endDate);
        $allData['cost_of_goods_sold'] = [['total' => $costOfGoodsSold]];

        // Get total cash in
        $totalCashIn = getTotalCashIn($posDb, $startDate, $endDate);
        $allData['total_cash_in'] = [['amount' => $totalCashIn]];

        // Get total cash out
        $totalCashOut = getTotalCashOut($posDb, $startDate, $endDate);
        $allData['total_cash_out'] = [['amount' => $totalCashOut]];

        // Calculate net profit
        $totalRevenue = $cashSalesTotal + $creditSalesTotal;
        $grossProfit = calculateGrossProfit($totalRevenue, $costOfGoodsSold);
        $netProfit = calculateNetProfit($grossProfit, $totalCashIn, $totalCashOut);
        $allData['net_profit'] = [['amount' => $netProfit]];

    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'POS database connection failed: ' . $e->getMessage()]);
        exit;
    }
    
    // Connect to INFO database
    try {
        $infoDb = new PDO('sqlite:../info.db');
        $infoDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Get list of all tables from INFO database
        $infoTables = $infoDb->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($infoTables as $table) {
            $stmt = $infoDb->query("SELECT * FROM $table");
            $allData['info_' . $table] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        // Log error but continue
        error_log('INFO database connection failed: ' . $e->getMessage());
    }
    
    // Connect to USER database
    try {
        $userDb = new PDO('sqlite:../user.db');
        $userDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Get list of all tables from USER database
        $userTables = $userDb->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($userTables as $table) {
            $stmt = $userDb->query("SELECT * FROM $table");
            $allData['user_' . $table] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        // Log error but continue
        error_log('USER database connection failed: ' . $e->getMessage());
    }
    
    // Format all data in a more readable way
    $context = "act as a natural simple worded sales assistant for a business owner, use namibian dollars for currency and if anything about sales use this database:\n";
    foreach ($allData as $table => $rows) {
        $context .= "Table: $table\n";
        foreach ($rows as $row) {
            $context .= "- " . json_encode($row) . "\n";
        }
        $context .= "\n";
    }
    
    // Include conversation history for context
    $conversationContext = "";
    if (!empty($_SESSION['conversation'])) {
        foreach ($_SESSION['conversation'] as $exchange) {
            $conversationContext .= "User: " . $exchange['user'] . "\n";
            $conversationContext .= "Assistant: " . $exchange['bot'] . "\n\n";
        }
    }
    
    $fullMessage = $context . "\n\nConversation history:\n" . $conversationContext . "\nUser question: " . $userMessage;

    $apiData = [
        "contents" => [
            "parts" => [
                ["text" => $fullMessage]
            ]
        ],
        "generationConfig" => [
            "temperature" => 0.2,
            "topP" => 0.8,
            "topK" => 40
        ]
    ];

    // Send request to Gemini API
    $ch = curl_init($apiUrl . "?key=" . $apiKey);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($apiData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);

    $response = curl_exec($ch);
    
    if (curl_errno($ch)) {
        echo json_encode(['status' => 'error', 'message' => 'API request failed: ' . curl_error($ch)]);
        exit;
    }
    
    curl_close($ch);

    $responseData = json_decode($response, true);
    $reply = $responseData['candidates'][0]['content']['parts'][0]['text'] ?? "I'm having trouble processing your request right now. Please try again.";
    
    // Format the reply text
    $reply = formatBotResponse($reply);
    
    // Store the exchange in session
    $_SESSION['conversation'][] = [
        'user' => $userMessage,
        'bot' => $reply
    ];

    // Example: send an AI notification every 5th message
    if (count($_SESSION['conversation']) % 5 == 0) {
        addInboxMessage("Here's an automated insight: " . $reply);
    }
    
    // Return response as JSON
    echo json_encode([
        'status' => 'success',
        'message' => $reply,
        'conversation' => $_SESSION['conversation']
    ]);
    exit;
}

// If not a POST request, return conversation history
echo json_encode([
    'status' => 'success',
    'conversation' => $_SESSION['conversation'] ?? []
]);

// Include the financial calculation functions from test.php
function getCashSales($db, $startDate, $endDate) {
    try {
        $stmt = $db->prepare("
            SELECT 
                (SELECT COALESCE(SUM(total), 0) 
                 FROM orders 
                 WHERE created_at BETWEEN :start_date AND :end_date)
                +
                (SELECT COALESCE(SUM(amount), 0) 
                 FROM payments 
                 WHERE payment_date BETWEEN :start_date AND :end_date)
        ");
        $stmt->execute([
            ':start_date' => $startDate . ' 00:00:00',
            ':end_date' => $endDate . ' 23:59:59'
        ]);
        return $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Error in getCashSales: " . $e->getMessage());
        return 0;
    }
}

// Function to get total credit sales
function getCreditSales($db, $startDate, $endDate) {
    try {
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(total_amount - paid_amount), 0)
            FROM credit_sales 
            WHERE created_at BETWEEN :start_date AND :end_date
        ");
        $stmt->execute([
            ':start_date' => $startDate . ' 00:00:00',
            ':end_date' => $endDate . ' 23:59:59'
        ]);
        return $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Error in getCreditSales: " . $e->getMessage());
        return 0;
    }
}

// Function to get cost of goods sold
function getCostOfGoodsSold($db, $startDate, $endDate) {
    try {
        $stmt = $db->prepare("
            SELECT 
                (SELECT COALESCE(SUM(oi.quantity * p.buying_price), 0)
                 FROM order_items oi
                 JOIN orders o ON oi.order_id = o.id
                 JOIN products p ON oi.product_name = p.name
                 WHERE o.created_at BETWEEN :start_date AND :end_date)
                +
                (SELECT COALESCE(SUM(csi.quantity * p.buying_price), 0)
                 FROM credit_sale_items csi
                 JOIN credit_sales cs ON csi.sale_id = cs.id
                 JOIN products p ON csi.product_name = p.name
                 WHERE cs.created_at BETWEEN :start_date AND :end_date)
        ");
        $stmt->execute([
            ':start_date' => $startDate . ' 00:00:00',
            ':end_date' => $endDate . ' 23:59:59'
        ]);
        return $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Error in getCostOfGoodsSold: " . $e->getMessage());
        return 0;
    }
}

// Function to calculate gross profit
function calculateGrossProfit($totalSales, $costOfGoodsSold) {
    return floatval($totalSales) - floatval($costOfGoodsSold);
}

// Function to get total cash in
function getTotalCashIn($db, $startDate, $endDate) {
    try {
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_transactions WHERE type = 'cash-in' AND created_at BETWEEN :start_date AND :end_date");
        $stmt->execute([':start_date' => $startDate . ' 00:00:00', ':end_date' => $endDate . ' 23:59:59']);
        return $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Error in getTotalCashIn: " . $e->getMessage());
        return 0;
    }
}

// Function to get total cash out
function getTotalCashOut($db, $startDate, $endDate) {
    try {
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_transactions WHERE type = 'cash-out' AND created_at BETWEEN :start_date AND :end_date");
        $stmt->execute([':start_date' => $startDate . ' 00:00:00', ':end_date' => $endDate . ' 23:59:59']);
        return $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Error in getTotalCashOut: " . $e->getMessage());
        return 0;
    }
}

// Function to calculate net profit
function calculateNetProfit($grossProfit, $totalCashIn, $totalCashOut) {
    return $grossProfit + $totalCashIn - $totalCashOut;
}

// Add this new function at the end of the file
function formatBotResponse($text) {
    // Remove Markdown formatting
    $text = str_replace(['*', '**', '__', '~~'], '', $text);
    
    // Format lists with proper bullet points
    $text = preg_replace('/^(\d+)[\.\)]\s*/m', '• ', $text); // Convert numbered lists to bullet points
    $text = preg_replace('/^[-*]\s*/m', '• ', $text); // Standardize existing bullet points
    
    // Ensure proper list formatting
    $lines = explode("\n", $text);
    $formattedLines = [];
    $inList = false;
    
    foreach ($lines as $line) {
        $trimmedLine = trim($line);
        
        // Check if line is a list item
        if (strpos($trimmedLine, '•') === 0) {
            if (!$inList) {
                $formattedLines[] = ''; // Add extra line before list starts
                $inList = true;
            }
            $formattedLines[] = $trimmedLine;
        } else {
            if ($inList && !empty($trimmedLine)) {
                $formattedLines[] = ''; // Add extra line after list ends
                $inList = false;
            }
            $formattedLines[] = $trimmedLine;
        }
    }
    
    $text = implode("\n", $formattedLines);
    
    // Replace multiple newlines with a single one
    $text = preg_replace('/\n\s*\n/', "\n\n", $text);
    
    // Ensure proper spacing after periods
    $text = preg_replace('/\.(?=[A-Z])/', '. ', $text);
    
    // Add proper spacing after commas
    $text = preg_replace('/,(?=[A-Za-z])/', ', ', $text);
    
    // Add proper spacing for numbers with dots
    $text = preg_replace('/(\d+)\.(?=[A-Za-z])/', '$1. ', $text);
    
    // Ensure proper spacing for currency values
    $text = preg_replace('/N\$(?=\d)/', 'N$ ', $text);
    
    // Trim extra spaces
    $text = trim($text);
    
    return $text;
}

// Add this function at the end of the file
function addInboxMessage($message) {
    try {
        $infoDb = new PDO('sqlite:../info.db');
        $infoDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $infoDb->query("CREATE TABLE IF NOT EXISTS inbox_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, message TEXT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, is_read INTEGER DEFAULT 0)");
        $stmt = $infoDb->prepare("INSERT INTO inbox_messages (message) VALUES (?)");
        $stmt->execute([$message]);
    } catch (PDOException $e) {
        error_log("Error adding inbox message: " . $e->getMessage());
    }
}