<?php
session_start();
require_once 'cashier_helper.php';

// Set timezone
date_default_timezone_set('Africa/Harare');

// Check if user is logged in
if (!validateCashierSession()) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit();
}

$tipType = $input['tip_type'] ?? 'cash_card';
$notes = trim($input['notes'] ?? '');
$tipDate = isset($input['date']) ? preg_replace('/[^0-9\-]/', '', $input['date']) : '';
if ($tipDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tipDate)) {
    $tipDate = date('Y-m-d');
}
$timestamp = $tipDate . ' 10:00:00';

try {
    $db = new PDO('sqlite:pos.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $cashier = getCashierInfo();
    
    // Inventory tip: product given as tip (e.g. drink) – deduct from stock
    if ($tipType === 'inventory') {
        $productId = (int)($input['product_id'] ?? 0);
        $quantity = (int)($input['quantity'] ?? 1);
        if ($productId <= 0 || $quantity <= 0) {
            echo json_encode(['success' => false, 'message' => 'Select a product and quantity.']);
            exit();
        }
        
        $product = $db->prepare("SELECT id, name, price, quantity FROM products WHERE id = ?");
        $product->execute([$productId]);
        $product = $product->fetch(PDO::FETCH_ASSOC);
        if (!$product) {
            echo json_encode(['success' => false, 'message' => 'Product not found.']);
            exit();
        }
        $available = (int)$product['quantity'];
        if ($quantity > $available) {
            echo json_encode(['success' => false, 'message' => "Not enough stock. Available: $available."]);
            exit();
        }
        
        $amount = (float)$product['price'] * $quantity;
        $paymentMethod = 'inventory';
        $description = 'Tips - ' . $product['name'] . ($quantity > 1 ? " x$quantity" : '');
        if ($notes !== '') {
            $notes = $description . ' | ' . $notes;
        } else {
            $notes = $description;
        }
        
        $db->beginTransaction();
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS tips (id INTEGER PRIMARY KEY AUTOINCREMENT, amount REAL NOT NULL, payment_method TEXT NOT NULL, cashier_id TEXT NOT NULL, notes TEXT, created_at TEXT NOT NULL)");
            $stmt = $db->prepare("INSERT INTO tips (amount, payment_method, cashier_id, notes, created_at) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$amount, $paymentMethod, $cashier['username'], $notes, $timestamp]);
            
            $db->prepare("UPDATE products SET quantity = quantity - ? WHERE id = ?")->execute([$quantity, $productId]);
            
            $oldQty = (int)$product['quantity'];
            $newQty = $oldQty - $quantity;
            $hasStockChanges = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='stock_changes'")->fetch();
            if ($hasStockChanges) {
                $db->prepare("INSERT INTO stock_changes (product_id, action, quantity_change, old_quantity, new_quantity) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$productId, 'tip', -$quantity, $oldQty, $newQty]);
            }
            
            $hasCashTx = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='cash_transactions'")->fetch();
            if ($hasCashTx) {
                $cols = $db->query("PRAGMA table_info(cash_transactions)")->fetchAll(PDO::FETCH_ASSOC);
                $hasCashierId = false;
                foreach ($cols as $c) {
                    if ($c['name'] === 'cashier_id') { $hasCashierId = true; break; }
                }
                if ($hasCashierId) {
                    $db->prepare("INSERT INTO cash_transactions (type, amount, description, cashier_id, created_at) VALUES (?, ?, ?, ?, ?)")
                        ->execute(['cash-out', $amount, $description, $cashier['username'], $timestamp]);
                } else {
                    $db->prepare("INSERT INTO cash_transactions (type, amount, description, created_at) VALUES (?, ?, ?, ?)")
                        ->execute(['cash-out', $amount, $description, $timestamp]);
                }
            }
            
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
        
        echo json_encode(['success' => true, 'message' => 'Tip (inventory) recorded and stock updated.', 'amount' => $amount]);
        exit();
    }
    
    // Cash/Card tip
    if (!isset($input['amount']) || !isset($input['payment_method'])) {
        echo json_encode(['success' => false, 'message' => 'Amount and payment method required.']);
        exit();
    }
    
    $amount = floatval($input['amount']);
    $paymentMethod = $input['payment_method'];
    
    if ($amount <= 0) {
        echo json_encode(['success' => false, 'message' => 'Amount must be greater than zero']);
        exit();
    }
    
    $db->exec("CREATE TABLE IF NOT EXISTS tips (id INTEGER PRIMARY KEY AUTOINCREMENT, amount REAL NOT NULL, payment_method TEXT NOT NULL, cashier_id TEXT NOT NULL, notes TEXT, created_at TEXT NOT NULL)");
    $stmt = $db->prepare("INSERT INTO tips (amount, payment_method, cashier_id, notes, created_at) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$amount, $paymentMethod, $cashier['username'], $notes, $timestamp]);
    
    // Record in cash_transactions so cash-up/reports see tips
    $hasCashTx = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='cash_transactions'")->fetch();
    if ($hasCashTx) {
        $cols = $db->query("PRAGMA table_info(cash_transactions)")->fetchAll(PDO::FETCH_ASSOC);
        $hasCashierId = false;
        foreach ($cols as $c) {
            if ($c['name'] === 'cashier_id') { $hasCashierId = true; break; }
        }
        $desc = 'Tips' . ($notes !== '' ? ' - ' . $notes : '');
        if ($hasCashierId) {
            $db->prepare("INSERT INTO cash_transactions (type, amount, description, cashier_id, created_at) VALUES (?, ?, ?, ?, ?)")
                ->execute(['cash-out', $amount, $desc, $cashier['username'], $timestamp]);
        } else {
            $db->prepare("INSERT INTO cash_transactions (type, amount, description, created_at) VALUES (?, ?, ?, ?)")
                ->execute(['cash-out', $amount, $desc, $timestamp]);
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Tip recorded successfully',
        'amount' => $amount,
        'payment_method' => $paymentMethod
    ]);
    
} catch (PDOException $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
