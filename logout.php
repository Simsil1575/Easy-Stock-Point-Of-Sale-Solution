<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database_backup_helper.php';

$username = $_SESSION['username'] ?? null;
$backupOnLogout = false;

try {
    $settings = loadDatabaseBackupSettings();
    $backupOnLogout = !empty($settings['backup_on_logout']);
} catch (Throwable $e) {
    error_log('Logout backup settings check failed: ' . $e->getMessage());
}

if ($backupOnLogout) {
    header('Content-Type: text/html; charset=UTF-8');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logging out</title>
    <style>
        body {
            margin: 0;
            font-family: system-ui, -apple-system, sans-serif;
            background: #f9fafb;
            color: #374151;
        }
        .wrap {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            box-sizing: border-box;
        }
        .box {
            text-align: center;
            max-width: 280px;
        }
        .spinner {
            width: 36px;
            height: 36px;
            margin: 0 auto 16px;
            border: 3px solid #e5e7eb;
            border-top-color: #6b7280;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .title {
            margin: 0;
            font-size: 15px;
            font-weight: 500;
        }
        .hint {
            margin: 8px 0 0;
            font-size: 13px;
            color: #9ca3af;
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="box">
        <div class="spinner" aria-hidden="true"></div>
        <p class="title">Backing up database</p>
        <p class="hint">Logging out, please wait…</p>
    </div>
</div>
    <?php
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();

    try {
        createDatabaseBackup('logout', $username);
    } catch (Throwable $e) {
        error_log('Logout backup failed: ' . $e->getMessage());
    }
}

if (isset($_SESSION['user_id']) && $username !== null && $username !== '') {
    try {
        $pos_db_file = realpath(__DIR__ . '/pos.db');
        if ($pos_db_file !== false) {
            $db = new PDO("sqlite:$pos_db_file");
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $logStmt = $db->prepare("INSERT INTO user_log (user_id, action_type) VALUES (:username, 'logout')");
            $logStmt->execute([':username' => $username]);
        }
    } catch (PDOException $e) {
        error_log('Logout logging failed: ' . $e->getMessage());
    }
}

session_unset();
session_destroy();

if ($backupOnLogout) {
    ?>
<script>
    try { sessionStorage.removeItem('pos_browser_session'); } catch (e) {}
    window.location.replace('/');
</script>
</body>
</html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <script>
        try { sessionStorage.removeItem('pos_browser_session'); } catch (e) {}
        window.location.replace('/');
    </script>
</head>
<body></body>
</html>
<?php
exit();
