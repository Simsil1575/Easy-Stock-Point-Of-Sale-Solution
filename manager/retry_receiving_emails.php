<?php
/**
 * Retry Failed Receiving Emails
 * 
 * This script provides endpoints to:
 * 1. List all failed/pending emails
 * 2. Retry individual failed emails
 * 3. Retry all failed emails
 * 
 * Usage:
 * GET  - List failed emails
 * POST - Retry specific email (record_id) or all (action: retry_all)
 */

session_start();

// Set timezone
date_default_timezone_set('Africa/Harare');

// Set JSON response header
header('Content-Type: application/json');

// Check if user is logged in and has admin/manager role
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Only allow admin and manager to retry emails
if (!in_array($_SESSION['role'], ['admin', 'Admin', 'manager', 'Manager'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. Admin or Manager role required.']);
    exit();
}

try {
    // Connect to database
    $db = new PDO('sqlite:../pos.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // List failed/pending emails
        $status = $_GET['status'] ?? 'failed';
        $limit = intval($_GET['limit'] ?? 50);
        $offset = intval($_GET['offset'] ?? 0);
        
        $validStatuses = ['pending', 'failed', 'sent', 'all'];
        if (!in_array($status, $validStatuses)) {
            $status = 'failed';
        }
        
        if ($status === 'all') {
            $stmt = $db->prepare("
                SELECT 
                    id, user_id, username, receiving_date, 
                    total_items, total_quantity, total_value, total_cost,
                    email_status, email_attempts, email_error, email_sent_at, created_at
                FROM receiving_records 
                ORDER BY created_at DESC 
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$limit, $offset]);
        } else {
            $stmt = $db->prepare("
                SELECT 
                    id, user_id, username, receiving_date, 
                    total_items, total_quantity, total_value, total_cost,
                    email_status, email_attempts, email_error, email_sent_at, created_at
                FROM receiving_records 
                WHERE email_status = ?
                ORDER BY created_at DESC 
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$status, $limit, $offset]);
        }
        
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get counts by status
        $countStmt = $db->query("
            SELECT 
                email_status,
                COUNT(*) as count
            FROM receiving_records
            GROUP BY email_status
        ");
        $counts = [];
        while ($row = $countStmt->fetch(PDO::FETCH_ASSOC)) {
            $counts[$row['email_status']] = intval($row['count']);
        }
        
        echo json_encode([
            'success' => true,
            'records' => $records,
            'counts' => $counts,
            'filter' => $status,
            'limit' => $limit,
            'offset' => $offset
        ]);
        
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Retry email(s)
        $requestData = json_decode(file_get_contents('php://input'), true);
        $action = $requestData['action'] ?? 'retry';
        $recordId = $requestData['record_id'] ?? null;
        $maxRetries = intval($requestData['max_retries'] ?? 3);
        
        if ($action === 'retry_all') {
            // Retry all failed emails with less than max retries
            $stmt = $db->prepare("
                SELECT id FROM receiving_records 
                WHERE email_status IN ('failed', 'pending') 
                AND email_attempts < ?
                ORDER BY created_at ASC
                LIMIT 50
            ");
            $stmt->execute([$maxRetries]);
            $recordIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (empty($recordIds)) {
                echo json_encode([
                    'success' => true, 
                    'message' => 'No emails to retry',
                    'retried' => 0
                ]);
                exit();
            }
            
            $results = [];
            foreach ($recordIds as $id) {
                $result = retryEmail($id);
                $results[] = ['record_id' => $id, 'success' => $result['success'], 'message' => $result['message']];
                
                // Small delay between emails to avoid rate limiting
                usleep(500000); // 0.5 second
            }
            
            $successCount = count(array_filter($results, fn($r) => $r['success']));
            
            echo json_encode([
                'success' => true,
                'message' => "Retried $successCount of " . count($results) . " emails",
                'retried' => count($results),
                'successful' => $successCount,
                'results' => $results
            ]);
            
        } elseif ($action === 'skip') {
            // Mark email as skipped
            if (!$recordId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'record_id required']);
                exit();
            }
            
            $stmt = $db->prepare("
                UPDATE receiving_records 
                SET email_status = 'skipped', email_error = 'Manually skipped by admin'
                WHERE id = ?
            ");
            $stmt->execute([$recordId]);
            
            echo json_encode(['success' => true, 'message' => 'Email marked as skipped']);
            
        } else {
            // Retry single email
            if (!$recordId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'record_id required']);
                exit();
            }
            
            // Check if record exists and hasn't been sent
            $checkStmt = $db->prepare("SELECT email_status, email_attempts FROM receiving_records WHERE id = ?");
            $checkStmt->execute([$recordId]);
            $record = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$record) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Record not found']);
                exit();
            }
            
            if ($record['email_status'] === 'sent') {
                echo json_encode(['success' => true, 'message' => 'Email already sent', 'already_sent' => true]);
                exit();
            }
            
            if ($record['email_attempts'] >= $maxRetries) {
                http_response_code(400);
                echo json_encode([
                    'success' => false, 
                    'message' => 'Maximum retry attempts reached (' . $record['email_attempts'] . ')',
                    'attempts' => $record['email_attempts']
                ]);
                exit();
            }
            
            $result = retryEmail($recordId);
            echo json_encode($result);
        }
        
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }
    
} catch (PDOException $e) {
    error_log("Database error in retry_receiving_emails: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log("General error in retry_receiving_emails: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

/**
 * Retry sending email for a specific record
 */
function retryEmail($recordId) {
    // Use curl to call the send_receiving_email.php endpoint
    $url = 'http' . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/send_receiving_email.php';
    
    // Copy session cookie for authentication
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['record_id' => $recordId]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Cookie: ' . session_name() . '=' . session_id()
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['success' => false, 'message' => 'Curl error: ' . $error];
    }
    
    $result = json_decode($response, true);
    if ($result === null) {
        return ['success' => false, 'message' => 'Invalid response from email service'];
    }
    
    return $result;
}
?>
