<?php
/**
 * Database Initialization Script
 * This script initializes the pos.db database from pos.db.sql
 */

// Set timezone
date_default_timezone_set('Africa/Harare');

$dbFile = 'pos.db';
$sqlFile = 'pos.db.sql';

echo "<h2>Database Initialization</h2>";

try {
    // Connect to SQLite database (creates file if it doesn't exist)
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Check if SQL file exists
    if (!file_exists($sqlFile)) {
        die("<p style='color: red;'>Error: SQL file '$sqlFile' not found!</p>");
    }
    
    // Read SQL file
    $sql = file_get_contents($sqlFile);
    
    if ($sql === false) {
        die("<p style='color: red;'>Error: Could not read SQL file!</p>");
    }
    
    // Remove transaction statements (we'll handle transactions ourselves)
    $sql = preg_replace('/BEGIN\s+TRANSACTION\s*;/i', '', $sql);
    $sql = preg_replace('/COMMIT\s*;/i', '', $sql);
    
    // Begin transaction
    $db->beginTransaction();
    
    $successCount = 0;
    $errorCount = 0;
    
    // Split SQL into statements (simple approach - split by semicolon)
    // Note: This works because the SQL file uses proper quoting
    $statements = explode(';', $sql);
    
    // Execute each statement
    foreach ($statements as $statement) {
        $statement = trim($statement);
        
        // Skip empty statements and comments
        if (empty($statement) || preg_match('/^\s*--/', $statement)) {
            continue;
        }
        
        try {
            $db->exec($statement);
            $successCount++;
        } catch (PDOException $e) {
            // Ignore "already exists" errors for CREATE TABLE IF NOT EXISTS and CREATE INDEX IF NOT EXISTS
            $errorMsg = $e->getMessage();
            if (strpos($errorMsg, 'already exists') !== false || 
                strpos($errorMsg, 'duplicate column') !== false ||
                strpos($errorMsg, 'duplicate name') !== false ||
                strpos($errorMsg, 'UNIQUE constraint failed') !== false) {
                // This is expected for IF NOT EXISTS statements that already exist
                $successCount++;
            } else {
                // Only show real errors
                echo "<p style='color: orange;'>Warning: " . htmlspecialchars(substr($errorMsg, 0, 200)) . "</p>";
                $errorCount++;
            }
        }
    }
    
    // Commit transaction
    $db->commit();
    
    echo "<p style='color: green;'>✅ Database initialized successfully!</p>";
    echo "<p>Successfully executed: $successCount statements</p>";
    if ($errorCount > 0) {
        echo "<p style='color: orange;'>Warnings: $errorCount</p>";
    }
    
    // Verify cash_transactions table exists
    $stmt = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='cash_transactions'");
    if ($stmt->fetch()) {
        echo "<p style='color: green;'>✅ cash_transactions table exists</p>";
    } else {
        echo "<p style='color: red;'>❌ cash_transactions table was not created</p>";
    }
    
    echo "<p><a href='cash.php'>Go to Cash Management</a></p>";
    
} catch (PDOException $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    echo "<p style='color: red;'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>File: " . htmlspecialchars($e->getFile()) . "</p>";
    echo "<p>Line: " . $e->getLine() . "</p>";
}
?>
