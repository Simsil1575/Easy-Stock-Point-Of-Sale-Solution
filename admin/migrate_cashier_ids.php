<?php
/**
 * Cashier ID Migration Script
 * 
 * This script normalizes all cashier_id columns to store usernames consistently.
 * Run this ONCE to fix existing data.
 * 
 * IMPORTANT: Backup your database before running!
 */

session_start();

// Check if user is logged in and is admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die('<h1>Access Denied</h1><p>Only administrators can run this migration.</p>');
}

// Set timezone
date_default_timezone_set('Africa/Harare');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cashier ID Migration</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen p-8">
    <div class="max-w-4xl mx-auto">
        <div class="bg-white rounded-lg shadow-lg p-6">
            <h1 class="text-2xl font-bold text-gray-800 mb-6">Cashier ID Migration Tool</h1>
            
            <?php
            // Connect to databases
            try {
                $posDb = new PDO('sqlite:../pos.db');
                $posDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                
                $userDb = new PDO('sqlite:../user.db');
                $userDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            } catch (PDOException $e) {
                echo '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">';
                echo '<strong>Database Connection Error:</strong> ' . htmlspecialchars($e->getMessage());
                echo '</div>';
                exit;
            }
            
            // Get user mapping from user.db
            $users = [];
            try {
                $userStmt = $userDb->query("SELECT id, username FROM users");
                while ($user = $userStmt->fetch(PDO::FETCH_ASSOC)) {
                    $users[$user['id']] = $user['username'];
                }
            } catch (PDOException $e) {
                echo '<div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded mb-4">';
                echo '<strong>Warning:</strong> Could not read user.db - ' . htmlspecialchars($e->getMessage());
                echo '</div>';
            }
            
            // Tables with cashier_id columns
            $tablesWithCashierId = [
                'orders' => 'cashier_id',
                'cash_transactions' => 'cashier_id',
                'eft_payments' => 'cashier_id',
                'credit_sales' => 'cashier_id',
                'payments' => 'cashier_id',
                'refunds' => 'cashier_id',
                'tabs' => 'cashier_id',
                'tab_payments' => 'cashier_id',
                'credit_returns' => 'cashier_id',
                'mixed_payments' => 'cashier_id',
                'void_transactions' => 'cashier_id'
            ];
            
            $tablesWithAddedBy = [
                'tab_items' => 'added_by'
            ];
            
            // Handle form submission
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_migration'])) {
                echo '<div class="mb-6">';
                echo '<h2 class="text-xl font-semibold text-gray-700 mb-4">Migration Results</h2>';
                
                $totalUpdated = 0;
                $errors = [];
                
                try {
                    $posDb->beginTransaction();
                    
                    // Process each table
                    foreach ($tablesWithCashierId as $table => $column) {
                        $updated = 0;
                        
                        // Check if table exists
                        $checkTable = $posDb->query("SELECT name FROM sqlite_master WHERE type='table' AND name='$table'");
                        if (!$checkTable->fetch()) {
                            echo "<p class='text-gray-500'>Table <strong>$table</strong> does not exist, skipping...</p>";
                            continue;
                        }
                        
                        // Update numeric IDs to usernames
                        foreach ($users as $id => $username) {
                            $stmt = $posDb->prepare("UPDATE $table SET $column = ? WHERE CAST($column AS INTEGER) = ? AND $column != ?");
                            $stmt->execute([$username, $id, $username]);
                            $updated += $stmt->rowCount();
                        }
                        
                        // Set 'Unknown' for NULL or empty values
                        $stmt = $posDb->prepare("UPDATE $table SET $column = 'Unknown' WHERE $column IS NULL OR $column = ''");
                        $stmt->execute();
                        $updated += $stmt->rowCount();
                        
                        $totalUpdated += $updated;
                        
                        if ($updated > 0) {
                            echo "<p class='text-green-600'>✓ Table <strong>$table</strong>: Updated $updated records</p>";
                        } else {
                            echo "<p class='text-gray-500'>Table <strong>$table</strong>: No updates needed</p>";
                        }
                    }
                    
                    // Process added_by columns
                    foreach ($tablesWithAddedBy as $table => $column) {
                        $updated = 0;
                        
                        // Check if table exists
                        $checkTable = $posDb->query("SELECT name FROM sqlite_master WHERE type='table' AND name='$table'");
                        if (!$checkTable->fetch()) {
                            echo "<p class='text-gray-500'>Table <strong>$table</strong> does not exist, skipping...</p>";
                            continue;
                        }
                        
                        // Update numeric IDs to usernames
                        foreach ($users as $id => $username) {
                            $stmt = $posDb->prepare("UPDATE $table SET $column = ? WHERE CAST($column AS INTEGER) = ? AND $column != ?");
                            $stmt->execute([$username, $id, $username]);
                            $updated += $stmt->rowCount();
                        }
                        
                        // Set 'Unknown' for NULL or empty values
                        $stmt = $posDb->prepare("UPDATE $table SET $column = 'Unknown' WHERE $column IS NULL OR $column = ''");
                        $stmt->execute();
                        $updated += $stmt->rowCount();
                        
                        $totalUpdated += $updated;
                        
                        if ($updated > 0) {
                            echo "<p class='text-green-600'>✓ Table <strong>$table.$column</strong>: Updated $updated records</p>";
                        } else {
                            echo "<p class='text-gray-500'>Table <strong>$table.$column</strong>: No updates needed</p>";
                        }
                    }
                    
                    $posDb->commit();
                    
                    echo '<div class="mt-4 p-4 bg-green-100 border border-green-400 text-green-700 rounded">';
                    echo "<strong>Migration Complete!</strong> Total records updated: $totalUpdated";
                    echo '</div>';
                    
                } catch (PDOException $e) {
                    $posDb->rollBack();
                    echo '<div class="mt-4 p-4 bg-red-100 border border-red-400 text-red-700 rounded">';
                    echo '<strong>Migration Failed:</strong> ' . htmlspecialchars($e->getMessage());
                    echo '</div>';
                }
                
                echo '</div>';
            }
            
            // Show current state analysis
            echo '<div class="mb-6">';
            echo '<h2 class="text-xl font-semibold text-gray-700 mb-4">Current Database Analysis</h2>';
            echo '<div class="overflow-x-auto">';
            echo '<table class="min-w-full bg-white border border-gray-300">';
            echo '<thead class="bg-gray-100">';
            echo '<tr>';
            echo '<th class="px-4 py-2 border">Table</th>';
            echo '<th class="px-4 py-2 border">Column</th>';
            echo '<th class="px-4 py-2 border">Total Records</th>';
            echo '<th class="px-4 py-2 border">Numeric IDs</th>';
            echo '<th class="px-4 py-2 border">Usernames</th>';
            echo '<th class="px-4 py-2 border">NULL/Empty</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';
            
            $allTables = array_merge($tablesWithCashierId, $tablesWithAddedBy);
            
            foreach ($allTables as $table => $column) {
                // Check if table exists
                $checkTable = $posDb->query("SELECT name FROM sqlite_master WHERE type='table' AND name='$table'");
                if (!$checkTable->fetch()) {
                    continue;
                }
                
                try {
                    // Total records
                    $total = $posDb->query("SELECT COUNT(*) FROM $table")->fetchColumn();
                    
                    // Numeric IDs (pure numbers)
                    $numericStmt = $posDb->query("SELECT COUNT(*) FROM $table WHERE $column IS NOT NULL AND $column != '' AND CAST($column AS INTEGER) > 0 AND CAST(CAST($column AS INTEGER) AS TEXT) = $column");
                    $numeric = $numericStmt->fetchColumn();
                    
                    // NULL or empty
                    $nullEmpty = $posDb->query("SELECT COUNT(*) FROM $table WHERE $column IS NULL OR $column = ''")->fetchColumn();
                    
                    // Usernames (non-numeric)
                    $usernames = $total - $numeric - $nullEmpty;
                    
                    $rowClass = ($numeric > 0 || $nullEmpty > 0) ? 'bg-yellow-50' : 'bg-green-50';
                    
                    echo "<tr class='$rowClass'>";
                    echo "<td class='px-4 py-2 border font-medium'>$table</td>";
                    echo "<td class='px-4 py-2 border'>$column</td>";
                    echo "<td class='px-4 py-2 border text-center'>$total</td>";
                    echo "<td class='px-4 py-2 border text-center'>" . ($numeric > 0 ? "<span class='text-orange-600 font-bold'>$numeric</span>" : '0') . "</td>";
                    echo "<td class='px-4 py-2 border text-center'>" . ($usernames > 0 ? "<span class='text-green-600'>$usernames</span>" : '0') . "</td>";
                    echo "<td class='px-4 py-2 border text-center'>" . ($nullEmpty > 0 ? "<span class='text-red-600 font-bold'>$nullEmpty</span>" : '0') . "</td>";
                    echo "</tr>";
                } catch (PDOException $e) {
                    echo "<tr class='bg-red-50'>";
                    echo "<td class='px-4 py-2 border'>$table</td>";
                    echo "<td class='px-4 py-2 border' colspan='5'>Error: " . htmlspecialchars($e->getMessage()) . "</td>";
                    echo "</tr>";
                }
            }
            
            echo '</tbody>';
            echo '</table>';
            echo '</div>';
            echo '</div>';
            
            // User mapping display
            echo '<div class="mb-6">';
            echo '<h2 class="text-xl font-semibold text-gray-700 mb-4">User Mapping (from user.db)</h2>';
            echo '<div class="grid grid-cols-2 md:grid-cols-4 gap-2">';
            foreach ($users as $id => $username) {
                echo "<div class='bg-gray-100 px-3 py-2 rounded'><span class='text-gray-500'>ID $id:</span> <strong>$username</strong></div>";
            }
            echo '</div>';
            echo '</div>';
            
            // Migration form
            echo '<div class="bg-yellow-50 border border-yellow-400 rounded p-4 mb-6">';
            echo '<h3 class="font-semibold text-yellow-800 mb-2">⚠️ Before Running Migration</h3>';
            echo '<ul class="list-disc list-inside text-yellow-700 mb-4">';
            echo '<li>Make sure to backup your database first</li>';
            echo '<li>This will convert all numeric cashier_id values to usernames</li>';
            echo '<li>NULL/empty values will be set to "Unknown"</li>';
            echo '</ul>';
            echo '<form method="POST" onsubmit="return confirm(\'Are you sure you want to run the migration? This cannot be undone.\')">';
            echo '<button type="submit" name="run_migration" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">';
            echo 'Run Migration';
            echo '</button>';
            echo '</form>';
            echo '</div>';
            ?>
            
            <div class="mt-6">
                <a href="settings.php" class="text-blue-600 hover:underline">← Back to Settings</a>
            </div>
        </div>
    </div>
</body>
</html>
