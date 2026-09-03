<?php
// Quick check of user_log table structure
try {
    $db = new PDO('sqlite:pos.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h2>User Log Table Schema:</h2>";
    $schema = $db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='user_log'")->fetchColumn();
    echo "<pre>" . htmlspecialchars($schema) . "</pre>";
    
    echo "<h2>Sample Data (Last 5 records):</h2>";
    $sample = $db->query("SELECT * FROM user_log ORDER BY rowid DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    echo "<table border='1' cellpadding='5'>";
    if (count($sample) > 0) {
        echo "<tr>";
        foreach (array_keys($sample[0]) as $col) {
            echo "<th>" . htmlspecialchars($col) . "</th>";
        }
        echo "</tr>";
        foreach ($sample as $row) {
            echo "<tr>";
            foreach ($row as $val) {
                echo "<td>" . htmlspecialchars($val) . "</td>";
            }
            echo "</tr>";
        }
    }
    echo "</table>";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
