<?php
header('Content-Type: application/json');

try {
    // Open database connection
    $db = new SQLite3('../pos.db');
    if (!$db) {
        throw new Exception('Failed to connect to database');
    }

    // Handle both GET and POST requests
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $id = $data['id'] ?? null;
    } else {
        $id = $_GET['id'] ?? null;
    }

    // Validate ID
    if (!$id || !is_numeric($id)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid Product ID']);
        exit;
    }

    // First, get the image URL to delete the file
    $stmt = $db->prepare('SELECT image_url FROM products WHERE id = :id');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    
    if ($row && !empty($row['image_url'])) {
        $imagePath = '../products/' . $row['image_url'];
        // Protect default.png from deletion
        if (file_exists($imagePath) && basename($imagePath) !== 'default.png' && strpos($imagePath, 'default.png') === false) {
            unlink($imagePath);
        }
    }

    // Prepare and execute delete statement
    $stmt = $db->prepare('DELETE FROM products WHERE id = :id');
    if (!$stmt) {
        throw new Exception('Failed to prepare statement');
    }

    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $result = $stmt->execute();

    // Verify deletion
    if ($result) {
        // Check if row was actually deleted
        $checkStmt = $db->prepare('SELECT COUNT(*) FROM products WHERE id = :id');
        $checkStmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $checkResult = $checkStmt->execute()->fetchArray(SQLITE3_NUM);
        
        if ($checkResult[0] === 0) {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                echo json_encode(['success' => true]);
                exit;
            } else {
                header('Location: inventory?delete=success');
                exit;
            }
        } else {
            throw new Exception('Product still exists in database');
        }
    } else {
        throw new Exception('Delete operation failed');
    }
} catch (Exception $e) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    } else {
        header('Location: inventory?delete=error');
        exit;
    }
}
?>