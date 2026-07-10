<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['role'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
$ids = $data['ids'] ?? null;

if (!is_array($ids) || $ids === []) {
    echo json_encode(['success' => false, 'message' => 'No users selected']);
    exit;
}

$ids = array_values(array_unique(array_map('intval', $ids)));
$ids = array_values(array_filter($ids, static function ($x) {
    return $x > 0;
}));

if ($ids === []) {
    echo json_encode(['success' => false, 'message' => 'Invalid user ids']);
    exit;
}

$currentId = (int) $_SESSION['user_id'];
$ids = array_values(array_filter($ids, static function ($id) use ($currentId) {
    return $id !== $currentId;
}));

if ($ids === []) {
    echo json_encode(['success' => false, 'message' => 'You cannot delete your own account']);
    exit;
}

$allowedRoles = ['admin', 'cashier', 'manager', 'waitress'];

try {
    $db = new PDO('sqlite:../user.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT id, role FROM users WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $toDelete = [];
    foreach ($rows as $r) {
        $role = $r['role'] ?? '';
        if (in_array($role, $allowedRoles, true)) {
            $toDelete[] = (int) $r['id'];
        }
    }

    if ($toDelete === []) {
        echo json_encode(['success' => false, 'message' => 'No deletable users in selection']);
        exit;
    }

    $db->beginTransaction();
    $ph = implode(',', array_fill(0, count($toDelete), '?'));
    $del = $db->prepare(
        "DELETE FROM users WHERE id IN ($ph) AND role IN ('admin', 'cashier', 'manager', 'waitress')"
    );
    $del->execute($toDelete);
    $db->commit();

    echo json_encode([
        'success' => true,
        'deleted' => count($toDelete),
    ]);
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
