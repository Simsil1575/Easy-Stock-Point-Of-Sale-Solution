<?php
header('Content-Type: application/json');

if (isset($_GET['name'])) {
    $db = new SQLite3('../pos.db');
    $name = $_GET['name'];
    
    if (isset($_GET['id'])) {
        // Edit mode - check for duplicate excluding current product
        $id = $_GET['id'];
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM products WHERE name = :name AND id != :id");
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    } else {
        // Add mode - check for any duplicate
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM products WHERE name = :name");
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
    }
    
    $result = $stmt->execute()->fetchArray();
    echo json_encode(['exists' => $result['count'] > 0]);
} else {
    echo json_encode(['exists' => false]);
}
?> 