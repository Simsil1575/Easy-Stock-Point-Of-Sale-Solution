<?php
/**
 * Fix Active.db Tracking Issues
 * Run this once to fix the currently active key that's missing timestamps
 */

date_default_timezone_set('Africa/Harare');

echo "<h2>Fixing Active.db Tracking Issues</h2>";
echo "<pre style='background:#f5f5f5; padding:15px; border-radius:5px;'>";

try {
    // Find the database
    $dbPath = file_exists('active.db') ? 'active.db' : '../active.db';
    
    if (!file_exists($dbPath)) {
        die("ERROR: Cannot find active.db at '$dbPath'\n");
    }
    
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "Connected to: $dbPath\n\n";
    
    // Step 1: Check current schema
    echo "=== CURRENT SCHEMA ===\n";
    $result = $pdo->query("PRAGMA table_info(software_keys)");
    $columns = $result->fetchAll(PDO::FETCH_ASSOC);
    $columnNames = array_column($columns, 'name');
    
    foreach ($columns as $col) {
        echo "  - {$col['name']} ({$col['type']})\n";
    }
    echo "\n";
    
    // Step 2: Add machine_id column if missing
    if (!in_array('machine_id', $columnNames)) {
        echo "Adding missing 'machine_id' column... ";
        $pdo->exec("ALTER TABLE software_keys ADD COLUMN machine_id TEXT DEFAULT NULL");
        echo "DONE\n\n";
    } else {
        echo "'machine_id' column already exists\n\n";
    }
    
    // Step 3: Show current state
    echo "=== CURRENT KEYS ===\n";
    $stmt = $pdo->query("SELECT id, key, is_used, activated_at, expires_at FROM software_keys ORDER BY id");
    $keys = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($keys as $key) {
        $status = $key['is_used'] ? '✓ ACTIVE' : '○ unused';
        $keyShort = substr($key['key'], 0, 5) . '...';
        echo sprintf("  ID %2d: %s [%s] activated: %s, expires: %s\n", 
            $key['id'], 
            $keyShort, 
            $status,
            $key['activated_at'] ?? 'NULL',
            $key['expires_at'] ?? 'NULL'
        );
    }
    echo "\n";
    
    // Step 4: Find keys that are active but missing timestamps
    $stmt = $pdo->query("SELECT * FROM software_keys WHERE is_used = 1 AND (activated_at IS NULL OR expires_at IS NULL)");
    $brokenKeys = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($brokenKeys) > 0) {
        echo "=== FIXING BROKEN KEYS ===\n";
        
        $activatedAt = (new DateTime())->format('Y-m-d H:i:s');
        $expiresAt = (new DateTime())->modify('+30 days')->format('Y-m-d H:i:s');
        
        foreach ($brokenKeys as $key) {
            echo "Fixing key ID {$key['id']} ({$key['key']})...\n";
            echo "  Setting activated_at = $activatedAt\n";
            echo "  Setting expires_at = $expiresAt\n";
            
            $update = $pdo->prepare("UPDATE software_keys SET activated_at = ?, expires_at = ? WHERE id = ?");
            $update->execute([$activatedAt, $expiresAt, $key['id']]);
            
            echo "  FIXED!\n\n";
        }
    } else {
        echo "=== NO BROKEN KEYS FOUND ===\n";
        echo "All active keys have proper timestamps.\n\n";
    }
    
    // Step 5: Show final state
    echo "=== FINAL STATE ===\n";
    $stmt = $pdo->query("SELECT id, key, is_used, activated_at, expires_at FROM software_keys WHERE is_used = 1");
    $activeKeys = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($activeKeys) > 0) {
        foreach ($activeKeys as $key) {
            echo "Active Key: {$key['key']}\n";
            echo "  Activated: {$key['activated_at']}\n";
            echo "  Expires:   {$key['expires_at']}\n";
            
            $expires = new DateTime($key['expires_at']);
            $now = new DateTime();
            $daysLeft = $now->diff($expires)->days;
            $isExpired = $now > $expires;
            
            if ($isExpired) {
                echo "  Status:    EXPIRED\n";
            } else {
                echo "  Status:    Valid ({$daysLeft} days remaining)\n";
            }
        }
    } else {
        echo "No active keys found.\n";
    }
    
    echo "\n✓ Fix complete!\n";
    
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "</pre>";
echo "<p><a href='settings.php'>← Back to Settings</a></p>";
?>
