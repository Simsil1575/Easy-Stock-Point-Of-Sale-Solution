<?php
require_once __DIR__ . '/../config.php';

$username = $_SESSION['username'] ?? null;
if (isset($_SESSION['user_id']) && $username !== null && $username !== '') {
    try {
        $pos_db_file = realpath(__DIR__ . '/../pos.db');
        if ($pos_db_file !== false) {
            $db = new PDO("sqlite:$pos_db_file");
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $logStmt = $db->prepare("INSERT INTO user_log (user_id, action_type) VALUES (:username, 'logout')");
            $logStmt->execute([':username' => $username]);
        }
    } catch (PDOException $e) {
        error_log('Hubbly logout logging failed: ' . $e->getMessage());
    }
}

session_unset();
session_destroy();

header('Location: /');
exit;
