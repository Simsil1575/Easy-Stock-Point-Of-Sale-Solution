<?php
/**
 * App update API (admin only).
 * Actions: status | check | update
 */

session_start();
date_default_timezone_set('Africa/Harare');
header('Content-Type: application/json; charset=utf-8');

function appUpdateApiRespond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit();
}

if (!isset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['role'])) {
    appUpdateApiRespond(['ok' => false, 'error' => 'Not authenticated.'], 401);
}

if (strtolower((string) $_SESSION['role']) !== 'admin') {
    appUpdateApiRespond(['ok' => false, 'error' => 'Admin access required.'], 403);
}

require_once __DIR__ . '/../app_updater_helper.php';

$action = '';
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = isset($_GET['action']) ? (string) $_GET['action'] : 'status';
} else {
    $raw = file_get_contents('php://input');
    $json = is_string($raw) ? json_decode($raw, true) : null;
    if (is_array($json) && isset($json['action'])) {
        $action = (string) $json['action'];
    } elseif (isset($_POST['action'])) {
        $action = (string) $_POST['action'];
    }
}

$action = strtolower(trim($action));

try {
    if ($action === 'status') {
        appUpdateApiRespond(appUpdaterStatusPayload());
    }

    if ($action === 'check') {
        $remote = appUpdaterCheckRemote();
        if (!$remote['ok']) {
            appUpdateApiRespond([
                'ok' => false,
                'error' => $remote['error'],
                'status' => appUpdaterStatusPayload(),
            ], 502);
        }

        $status = appUpdaterStatusPayload();
        appUpdateApiRespond([
            'ok' => true,
            'remote' => [
                'source' => $remote['source'],
                'sha' => $remote['sha'],
                'tag' => $remote['tag'],
                'name' => $remote['name'],
                'published_at' => $remote['published_at'],
            ],
            'update_available' => $status['update_available'],
            'status' => $status,
        ]);
    }

    if ($action === 'update') {
        $remote = appUpdaterCheckRemote();
        if (!$remote['ok']) {
            appUpdateApiRespond([
                'ok' => false,
                'error' => $remote['error'],
            ], 502);
        }

        $result = appUpdaterApplyUpdate($remote);
        if (!$result['ok']) {
            appUpdateApiRespond([
                'ok' => false,
                'error' => $result['error'],
                'mode' => $result['mode'] ?? null,
                'copied' => $result['copied'] ?? 0,
                'deleted' => $result['deleted'] ?? 0,
                'skipped' => $result['skipped'] ?? 0,
                'failed' => $result['failed'] ?? 0,
                'errors' => $result['errors'] ?? [],
            ], 500);
        }

        $mode = $result['mode'] ?? 'full';
        $modeLabel = $mode === 'incremental'
            ? 'Changed files only (fast update)'
            : 'Full package download';

        appUpdateApiRespond([
            'ok' => true,
            'message' => 'Update applied successfully.',
            'mode' => $mode,
            'mode_label' => $modeLabel,
            'copied' => $result['copied'],
            'deleted' => $result['deleted'] ?? 0,
            'skipped' => $result['skipped'],
            'failed' => $result['failed'],
            'errors' => $result['errors'],
            'remote' => [
                'source' => $remote['source'],
                'sha' => $remote['sha'],
                'tag' => $remote['tag'],
                'name' => $remote['name'],
            ],
            'status' => appUpdaterStatusPayload(),
        ]);
    }

    appUpdateApiRespond(['ok' => false, 'error' => 'Unknown action. Use status, check, or update.'], 400);
} catch (Throwable $e) {
    appUpdateApiRespond([
        'ok' => false,
        'error' => 'Updater error: ' . $e->getMessage(),
    ], 500);
}
