<?php
session_start();
header('Content-Type: application/json');

// Set timezone
date_default_timezone_set('Africa/Harare');

$db = new PDO('sqlite:pos.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    // Fetch all open tabs from the database
    $tabsStmt = $db->prepare("
        SELECT 
            id,
            tab_name,
            current_balance,
            status,
            opened_at,
            creditor_id,
            cashier_id
        FROM tabs 
        ORDER BY opened_at DESC
    ");
    $tabsStmt->execute();
    $tabs = $tabsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format tabs for the modal
    $tables = [];
    foreach ($tabs as $tab) {
        // Extract table number from tab_name if it's in "Table X" format, otherwise use tab_id
        $tabName = $tab['tab_name'];
        $tableNumber = null;
        
        // Try to extract number from "Table X" format
        if (preg_match('/Table\s+(\d+)/i', $tabName, $matches)) {
            $tableNumber = intval($matches[1]);
        } else {
            // If not in "Table X" format, use tab id as number
            $tableNumber = intval($tab['id']);
        }
        
        $tables[] = [
            'id' => intval($tab['id']),
            'name' => $tabName,
            'number' => $tableNumber,
            'balance' => floatval($tab['current_balance']),
            'tab_id' => intval($tab['id']),
            'has_tab' => true,
            'opened_at' => $tab['opened_at'],
            'creditor_id' => $tab['creditor_id'] ? intval($tab['creditor_id']) : null,
            'cashier_id' => $tab['cashier_id'] ?? null
        ];
    }
    
    echo json_encode([
        'success' => true,
        'tables' => $tables
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

