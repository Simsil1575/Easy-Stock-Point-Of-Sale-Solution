<?php
session_start();
header('Content-Type: application/json');

// Set timezone to Central Africa Time (CAT)
date_default_timezone_set('Africa/Harare');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

try {
    $db = new PDO('sqlite:pos.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    require_once __DIR__ . '/tab_balance_helper.php';
    
    // Create tabs table if it doesn't exist
    $db->exec("
        CREATE TABLE IF NOT EXISTS tabs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            creditor_id INTEGER,
            tab_name TEXT NOT NULL,
            opening_balance DECIMAL(10,2) DEFAULT 0.00,
            current_balance DECIMAL(10,2) DEFAULT 0.00,
            status TEXT NOT NULL DEFAULT 'open' CHECK(status IN ('open', 'closed')),
            opened_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            closed_at DATETIME,
            closed_by INTEGER,
            notes TEXT,
            cashier_id TEXT,
            FOREIGN KEY(creditor_id) REFERENCES creditors(id)
        )
    ");
    
    // Try to add cashier_id as TEXT if it doesn't exist
    try {
        $db->exec("ALTER TABLE tabs ADD COLUMN cashier_id TEXT");
    } catch (PDOException $e) {
        // Column already exists, ignore
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    $tabName = trim($data['name'] ?? '');
    
    if (empty($tabName)) {
        echo json_encode(['success' => false, 'message' => 'Tab name is required']);
        exit();
    }
    
    // Check if tab with same name already exists and is open
    $checkStmt = $db->prepare("SELECT id FROM tabs WHERE LOWER(tab_name) = LOWER(?) AND status = 'open'");
    $checkStmt->execute([$tabName]);
    if ($checkStmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'An open tab with this name already exists']);
        exit();
    }
    
    // Insert new tab
    ensureTabGratuityColumns($db);
    $defaultGratuityOn = tab_default_gratuity_enabled_on_create($db);
    $cashierUsername = $_SESSION['username'] ?? 'Unknown';
    $stmt = $db->prepare("INSERT INTO tabs (tab_name, opening_balance, current_balance, status, cashier_id, gratuity_enabled) VALUES (?, 0, 0, 'open', ?, ?)");
    $stmt->execute([$tabName, $cashierUsername, $defaultGratuityOn]);
    
    $tabId = $db->lastInsertId();
    
    // Fetch the newly created tab
    $newTab = $db->prepare("
        SELECT 
            id,
            tab_name as name,
            current_balance as balance,
            status,
            opened_at,
            creditor_id,
            cashier_id
        FROM tabs
        WHERE id = ?
    ");
    $newTab->execute([$tabId]);
    $tab = $newTab->fetch(PDO::FETCH_ASSOC);
    
    // Format for response (similar to get_tables_with_balances.php format)
    $tableNumber = null;
    if (preg_match('/Table\s+(\d+)/i', $tab['name'], $matches)) {
        $tableNumber = intval($matches[1]);
    } else {
        $tableNumber = intval($tab['id']);
    }
    
    $formattedTab = [
        'id' => intval($tab['id']),
        'name' => $tab['name'],
        'number' => $tableNumber,
        'balance' => floatval($tab['balance']),
        'tab_id' => intval($tab['id']),
        'has_tab' => true,
        'opened_at' => $tab['opened_at'],
        'creditor_id' => $tab['creditor_id'] ? intval($tab['creditor_id']) : null,
        'cashier_id' => $tab['cashier_id'] ?? null
    ];
    
    echo json_encode(['success' => true, 'message' => 'Tab created successfully', 'tab' => $formattedTab]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error creating tab: ' . $e->getMessage()]);
}








