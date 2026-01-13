<?php
try {
    // Open database connection
    $db = new PDO('sqlite:../user.db');
    
    // Get user ID from query parameter
    $id = $_GET['id'] ?? null;

    // Validate ID
    if (!$id || !is_numeric($id)) {
        throw new Exception('Invalid User ID');
    }

    // Prepare and execute delete statement
    $stmt = $db->prepare('DELETE FROM users WHERE id = :id');
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $result = $stmt->execute();

    // Verify deletion
    if ($result) {
        // Check if row was actually deleted
        $checkStmt = $db->prepare('SELECT COUNT(*) FROM users WHERE id = :id');
        $checkStmt->bindValue(':id', $id, PDO::PARAM_INT);
        $checkStmt->execute();
        $rowCount = $checkStmt->fetchColumn();
        
        if ($rowCount === 0) {
            header('Location: users.php?delete=success');
            exit;
        } else {
            throw new Exception('User still exists in database');
        }
    } else {
        throw new Exception('Delete operation failed');
    }
} catch (Exception $e) {
    header('Location: users?delete=error&message=' . urlencode($e->getMessage()));
    exit;
}
?>
