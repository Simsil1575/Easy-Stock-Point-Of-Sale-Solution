<?php
/**
 * Migration script to update the CHECK constraint on the users table
 * to include 'waitress' role
 */

session_start();

// Set timezone to Central Africa Time (CAT)
date_default_timezone_set('Africa/Harare');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    // Redirect to login page if not logged in
    header("Location: ../");
    exit();
}

// Only allow admin to run this migration
if ($_SESSION['role'] !== 'admin') {
    die('Access denied. Only administrators can run this migration.');
}

try {
    $db = new PDO('sqlite:../user.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Start transaction
    $db->beginTransaction();
    
    // Step 1: Create new table with updated constraint
    $db->exec("
        CREATE TABLE IF NOT EXISTS users_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL CHECK(role IN ('cashier', 'manager', 'admin', 'waitress', 'hubbly')),
            email TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    // Step 2: Copy data from old table to new table
    $db->exec("
        INSERT INTO users_new (id, username, password_hash, role, email, created_at)
        SELECT id, username, password_hash, role, email, created_at
        FROM users
    ");
    
    // Step 3: Drop old table
    $db->exec("DROP TABLE users");
    
    // Step 4: Rename new table to original name
    $db->exec("ALTER TABLE users_new RENAME TO users");
    
    // Commit transaction
    $db->commit();
    
    echo "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Migration Success</title>
    <link href='../src/output.css' rel='stylesheet'>
</head>
<body class='bg-gray-50'>
    <div class='max-w-2xl mx-auto mt-20 p-8 bg-white rounded-lg shadow-lg'>
        <div class='text-center'>
            <div class='mb-4'>
                <svg class='w-16 h-16 text-green-500 mx-auto' fill='none' stroke='currentColor' viewBox='0 0 24 24'>
                    <path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'></path>
                </svg>
            </div>
            <h1 class='text-2xl font-bold text-gray-800 mb-4'>Migration Successful!</h1>
            <p class='text-gray-600 mb-6'>The database constraint has been updated to include the 'waitress' role.</p>
            <a href='users' class='inline-flex items-center px-4 py-2 bg-teal-600 text-white rounded-md hover:bg-teal-700'>
                Go to User Management
            </a>
        </div>
    </div>
</body>
</html>";
    
} catch (PDOException $e) {
    // Rollback on error
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    
    echo "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Migration Error</title>
    <link href='../src/output.css' rel='stylesheet'>
</head>
<body class='bg-gray-50'>
    <div class='max-w-2xl mx-auto mt-20 p-8 bg-white rounded-lg shadow-lg'>
        <div class='text-center'>
            <div class='mb-4'>
                <svg class='w-16 h-16 text-red-500 mx-auto' fill='none' stroke='currentColor' viewBox='0 0 24 24'>
                    <path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z'></path>
                </svg>
            </div>
            <h1 class='text-2xl font-bold text-gray-800 mb-4'>Migration Failed</h1>
            <p class='text-gray-600 mb-2'>Error: " . htmlspecialchars($e->getMessage()) . "</p>
            <p class='text-sm text-gray-500 mb-6'>Please check your database backup and try again.</p>
            <a href='users' class='inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-md hover:bg-gray-700'>
                Go Back
            </a>
        </div>
    </div>
</body>
</html>";
}

