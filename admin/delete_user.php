<?php
session_start();

if (!isset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['role'])) {
    header('Location: ../');
    exit;
}

try {
    // Open database connection
    $db = new PDO('sqlite:../user.db');
    
    // Get user ID from query parameter
    $id = $_GET['id'] ?? null;

    // Validate ID
    if (!$id || !is_numeric($id)) {
        throw new Exception('Invalid User ID');
    }

    $id = (int) $id;
    if ($id === (int) $_SESSION['user_id']) {
        throw new Exception('You cannot delete your own account');
    }

    $roleStmt = $db->prepare('SELECT role FROM users WHERE id = :id');
    $roleStmt->bindValue(':id', $id, PDO::PARAM_INT);
    $roleStmt->execute();
    $role = $roleStmt->fetchColumn();
    if ($role === false) {
        throw new Exception('User not found');
    }
    if (!in_array($role, ['admin', 'cashier', 'manager', 'waitress', 'hubbly'], true)) {
        throw new Exception('This account cannot be deleted here');
    }

    // Prepare and execute delete statement
    $stmt = $db->prepare('DELETE FROM users WHERE id = :id AND role IN (\'admin\', \'cashier\', \'manager\', \'waitress\', \'hubbly\')');
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
            header('Location: users?delete=success');
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
