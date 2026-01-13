<?php
// Connect to the database
$db = new PDO('sqlite:pos.db');

// Get chatbot-related tables
$tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('credit_sales', 'credit_sale_items', 'creditors')")->fetchAll(PDO::FETCH_COLUMN);

echo "<div class='chatbot-data-container'>";

foreach ($tables as $table) {
    echo "<div class='table-container'>";
    echo "<h3 class='table-title'>Table: $table</h3>";
    
    // Get table columns
    $columns = $db->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC);
    
    // Get table data
    $data = $db->query("SELECT * FROM $table")->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($data) > 0) {
        echo "<div class='table-responsive'>";
        echo "<table class='chatbot-table'>";
        
        // Display table headers
        echo "<thead><tr>";
        foreach ($columns as $col) {
            echo "<th>{$col['name']}</th>";
        }
        echo "</tr></thead>";
        
        // Display table rows
        echo "<tbody>";
        foreach ($data as $row) {
            echo "<tr>";
            foreach ($row as $value) {
                echo "<td>" . htmlspecialchars($value) . "</td>";
            }
            echo "</tr>";
        }
        echo "</tbody>";
        
        echo "</table>";
        echo "</div>";
    } else {
        echo "<p class='no-data'>No data found in this table.</p>";
    }
    
    echo "</div>";
}

echo "</div>";
?>

<style>
.chatbot-data-container {
    max-width: 1000px;
    margin: 20px auto;
    padding: 20px;
    background: #f9f9f9;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.table-container {
    margin-bottom: 30px;
}

.table-title {
    color: #2c3e50;
    margin-bottom: 15px;
}

.chatbot-table {
    width: 100%;
    border-collapse: collapse;
    background: white;
}

.chatbot-table th,
.chatbot-table td {
    padding: 12px;
    border: 1px solid #ddd;
    text-align: left;
}

.chatbot-table th {
    background-color: #3498db;
    color: white;
    font-weight: bold;
}

.chatbot-table tr:nth-child(even) {
    background-color: #f2f2f2;
}

.chatbot-table tr:hover {
    background-color: #e6f7ff;
}

.no-data {
    color: #666;
    font-style: italic;
}

.table-responsive {
    overflow-x: auto;
}
</style>
