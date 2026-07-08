<?php
/**
 * Debug Activation Issues
 * This will show exactly what's happening during activation
 */

session_start();
date_default_timezone_set('Africa/Harare');

// Include the activation helper to test it
require_once 'activation_helper.php';

echo "<h2>Activation Debug Tool</h2>";
echo "<pre style='background:#f5f5f5; padding:15px; border-radius:5px; font-size:14px;'>";

// Step 1: Check which database file is being used
echo "=== DATABASE PATH CHECK ===\n";

$possiblePaths = [
    __DIR__ . '/active.db',
    __DIR__ . '/../active.db',
    'active.db',
    '../active.db'
];

$foundPath = null;
foreach ($possiblePaths as $path) {
    $exists = file_exists($path);
    $realPath = $exists ? realpath($path) : 'N/A';
    $writable = $exists ? (is_writable($path) ? 'YES' : 'NO') : 'N/A';
    
    echo "Path: $path\n";
    echo "  Exists: " . ($exists ? 'YES' : 'NO') . "\n";
    echo "  Real path: $realPath\n";
    echo "  Writable: $writable\n";
    
    if ($exists && !$foundPath) {
        $foundPath = $path;
    }
    echo "\n";
}

if (!$foundPath) {
    echo "ERROR: No active.db found!\n";
    echo "</pre>";
    exit;
}

echo "USING DATABASE: " . realpath($foundPath) . "\n\n";

// Step 2: Connect and check current state
echo "=== CURRENT DATABASE STATE ===\n";

try {
    $pdo = new PDO('sqlite:' . $foundPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Check schema
    $result = $pdo->query("PRAGMA table_info(software_keys)");
    $columns = $result->fetchAll(PDO::FETCH_ASSOC);
    echo "Columns: " . implode(', ', array_column($columns, 'name')) . "\n\n";
    
    // Show all keys
    $stmt = $pdo->query("SELECT * FROM software_keys ORDER BY id");
    $keys = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Total keys: " . count($keys) . "\n\n";
    
    $availableKeys = [];
    $now = new DateTime();
    
    foreach ($keys as $key) {
        $statusText = '';
        $canUse = true;
        
        if ($key['is_used'] == 1) {
            $statusText = '*** CURRENTLY ACTIVE ***';
            $canUse = false;
        } elseif (!empty($key['expires_at'])) {
            $exp = new DateTime($key['expires_at']);
            if ($now > $exp) {
                $statusText = 'EXPIRED on ' . $key['expires_at'];
                $canUse = false;
            } else {
                $statusText = 'Available (pre-set expiry)';
            }
        } elseif (!empty($key['activated_at'])) {
            $statusText = 'Previously used (now deactivated)';
            // This key was used before but marked as unused - might be reusable
            $canUse = true;
        } else {
            $statusText = '✓ AVAILABLE (never used)';
        }
        
        if ($canUse && $key['is_used'] == 0) {
            $availableKeys[] = $key;
        }
        
        echo "ID {$key['id']}: {$key['key']}\n";
        echo "  is_used: {$key['is_used']} | Status: $statusText\n";
        echo "  activated_at: " . ($key['activated_at'] ?? 'NULL') . "\n";
        echo "  expires_at: " . ($key['expires_at'] ?? 'NULL') . "\n";
        echo "\n";
    }
    
    echo "\n=== KEYS YOU CAN USE RIGHT NOW ===\n";
    if (count($availableKeys) > 0) {
        echo "These keys should work for activation:\n\n";
        foreach (array_slice($availableKeys, 0, 5) as $ak) {
            echo "  " . $ak['key'] . " (ID: {$ak['id']})\n";
        }
        if (count($availableKeys) > 5) {
            echo "  ... and " . (count($availableKeys) - 5) . " more\n";
        }
    } else {
        echo "NO AVAILABLE KEYS! All keys are either used or expired.\n";
    }
    
} catch (PDOException $e) {
    echo "DATABASE ERROR: " . $e->getMessage() . "\n";
}

echo "</pre>";

// Step 3: Test activation form
echo "<hr><h3>Test Activation</h3>";
echo "<form method='POST' style='margin:20px 0;'>";
echo "<input type='hidden' name='test_activate' value='1'>";
echo "<input type='text' name='test_key' placeholder='Enter key to test' style='padding:10px; width:300px;'>";
echo "<button type='submit' style='padding:10px 20px; margin-left:10px;'>Test Direct DB</button>";
echo "</form>";

echo "<form method='POST' style='margin:20px 0;'>";
echo "<input type='hidden' name='test_helper' value='1'>";
echo "<input type='text' name='helper_key' placeholder='Enter key to test with helper' style='padding:10px; width:300px;'>";
echo "<button type='submit' style='padding:10px 20px; margin-left:10px; background:#4CAF50; color:white;'>Test via activateKey()</button>";
echo "</form>";

// Process test activation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_activate'])) {
    $testKey = trim($_POST['test_key']);
    
    echo "<pre style='background:#ffe; padding:15px; border:2px solid #cc0;'>";
    echo "=== TESTING ACTIVATION FOR: $testKey ===\n\n";
    
    try {
        $pdo = new PDO('sqlite:' . $foundPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Step 1: Check if key exists
        echo "Step 1: Checking if key exists...\n";
        $stmt = $pdo->prepare("SELECT * FROM software_keys WHERE key = ?");
        $stmt->execute([$testKey]);
        $key = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$key) {
            echo "  RESULT: Key NOT FOUND in database!\n";
            echo "  This key does not exist.\n";
        } else {
            echo "  RESULT: Key found! ID = {$key['id']}\n";
            echo "  Current is_used = {$key['is_used']}\n\n";
            
            // Step 2: Check if already used
            echo "Step 2: Checking if already used...\n";
            if ($key['is_used'] == 1) {
                echo "  RESULT: Key is ALREADY USED!\n";
            } else {
                echo "  RESULT: Key is available for activation.\n\n";
                
                // Step 3: Try to activate
                echo "Step 3: Attempting activation...\n";
                
                $activatedAt = (new DateTime())->format('Y-m-d H:i:s');
                $expiresAt = (new DateTime())->modify('+30 days')->format('Y-m-d H:i:s');
                
                // First deactivate all
                $deactivate = $pdo->prepare("UPDATE software_keys SET is_used = 0");
                $result1 = $deactivate->execute();
                echo "  Deactivated all keys: " . ($result1 ? 'SUCCESS' : 'FAILED') . "\n";
                echo "  Rows affected: " . $deactivate->rowCount() . "\n";
                
                // Now activate this key
                $activate = $pdo->prepare("UPDATE software_keys SET is_used = 1, activated_at = ?, expires_at = ? WHERE id = ?");
                $result2 = $activate->execute([$activatedAt, $expiresAt, $key['id']]);
                echo "  Activated key {$key['id']}: " . ($result2 ? 'SUCCESS' : 'FAILED') . "\n";
                echo "  Rows affected: " . $activate->rowCount() . "\n\n";
                
                // Step 4: Verify
                echo "Step 4: Verifying...\n";
                $verify = $pdo->prepare("SELECT * FROM software_keys WHERE id = ?");
                $verify->execute([$key['id']]);
                $updated = $verify->fetch(PDO::FETCH_ASSOC);
                
                echo "  is_used: {$updated['is_used']}\n";
                echo "  activated_at: {$updated['activated_at']}\n";
                echo "  expires_at: {$updated['expires_at']}\n";
                
                if ($updated['is_used'] == 1) {
                    echo "\n*** ACTIVATION SUCCESSFUL! ***\n";
                } else {
                    echo "\n*** ACTIVATION FAILED - Database not updated ***\n";
                }
            }
        }
        
    } catch (PDOException $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
    }
    
    echo "</pre>";
}

// Process test via helper function
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_helper'])) {
    $testKey = trim($_POST['helper_key']);
    
    echo "<pre style='background:#efe; padding:15px; border:2px solid #0c0;'>";
    echo "=== TESTING VIA activateKey() HELPER ===\n";
    echo "Key entered: $testKey\n";
    echo "Key after sanitize: " . sanitizeActivationKey($testKey) . "\n\n";
    
    // First show what the DB has for this key
    echo "--- Database Record for this Key ---\n";
    try {
        $debugPdo = new PDO('sqlite:' . $foundPath);
        $debugStmt = $debugPdo->prepare("SELECT * FROM software_keys WHERE key = ?");
        $debugStmt->execute([sanitizeActivationKey($testKey)]);
        $dbRecord = $debugStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($dbRecord) {
            echo "  id: " . $dbRecord['id'] . "\n";
            echo "  key: " . $dbRecord['key'] . "\n";
            echo "  is_used: " . $dbRecord['is_used'] . " (" . ($dbRecord['is_used'] == 1 ? 'USED' : 'NOT USED') . ")\n";
            echo "  activated_at: " . ($dbRecord['activated_at'] ?? 'NULL') . "\n";
            echo "  expires_at: " . ($dbRecord['expires_at'] ?? 'NULL') . "\n";
            
            // Check if expired
            if (!empty($dbRecord['expires_at'])) {
                $exp = new DateTime($dbRecord['expires_at']);
                $now = new DateTime();
                if ($now > $exp) {
                    echo "  STATUS: *** EXPIRED *** (expired " . $now->diff($exp)->days . " days ago)\n";
                } else {
                    echo "  STATUS: Valid (" . $now->diff($exp)->days . " days remaining)\n";
                }
            }
        } else {
            echo "  KEY NOT FOUND IN DATABASE!\n";
        }
    } catch (Exception $e) {
        echo "  Error: " . $e->getMessage() . "\n";
    }
    
    echo "\n--- Calling activateKey() ---\n";
    
    // Test without CSRF (pass null)
    $result = activateKey($testKey, null);
    
    echo "Result:\n";
    echo "  success: " . ($result['success'] ? 'TRUE' : 'FALSE') . "\n";
    echo "  message: " . $result['message'] . "\n";
    
    if (isset($result['expires_at'])) {
        echo "  expires_at: " . $result['expires_at'] . "\n";
    }
    
    echo "\n--- Current Status After ---\n";
    $status = checkActivationStatus();
    echo "  status: " . $status['status'] . "\n";
    echo "  message: " . $status['message'] . "\n";
    if (isset($status['days_remaining'])) {
        echo "  days_remaining: " . $status['days_remaining'] . "\n";
    }
    if (isset($status['expires_at'])) {
        echo "  expires_at: " . $status['expires_at'] . "\n";
    }
    
    echo "</pre>";
}

// Show PHP error log location
echo "<hr><h3>Check for Errors</h3>";
echo "<p>PHP Error Log: " . ini_get('error_log') . "</p>";
echo "<p>Display Errors: " . (ini_get('display_errors') ? 'ON' : 'OFF') . "</p>";

echo "<p><a href='settings.php'>← Back to Settings</a></p>";
?>
