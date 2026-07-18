<?php
session_start();
date_default_timezone_set('Africa/Harare');

if (!isset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['role'])) {
    header('Location: ../');
    exit;
}

require_once __DIR__ . '/../purchase_order_lib.php';
poRequireAdminOrManager();

$pdo = new PDO('sqlite:' . __DIR__ . '/../active.db');
if ((int) $pdo->query('SELECT COUNT(*) FROM software_keys WHERE is_used = 1')->fetchColumn() === 0) {
    header('Location: settings');
    exit;
}

$db = new PDO('sqlite:' . __DIR__ . '/../pos.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA foreign_keys = ON');
require_once __DIR__ . '/../ensure_purchase_order_schema.php';
ensurePurchaseOrderSchema($db);

$backHref = 'admin-center';
$currentUserLabel = (string) ($_SESSION['user_id'] ?? '') . ' · ' . (string) ($_SESSION['username'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'add_supplier') {
            $name = trim((string) ($_POST['name'] ?? ''));
            if ($name === '') {
                throw new RuntimeException('Supplier name is required.');
            }
            $st = $db->prepare('INSERT INTO suppliers (name, phone, email, notes, active) VALUES (?,?,?,?,1)');
            $st->execute([
                $name,
                trim((string) ($_POST['phone'] ?? '')) ?: null,
                trim((string) ($_POST['email'] ?? '')) ?: null,
                trim((string) ($_POST['notes'] ?? '')) ?: null,
            ]);
            $_SESSION['po_flash'] = 'Supplier added.';
            header('Location: purchase_orders.php?tab=suppliers');
            exit;
        }
        if ($action === 'edit_supplier') {
            $sid = (int) ($_POST['supplier_id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));
            if ($sid < 1 || $name === '') {
                throw new RuntimeException('Invalid supplier.');
            }
            $st = $db->prepare('UPDATE suppliers SET name = ?, phone = ?, email = ?, notes = ? WHERE id = ?');
            $st->execute([
                $name,
                trim((string) ($_POST['phone'] ?? '')) ?: null,
                trim((string) ($_POST['email'] ?? '')) ?: null,
                trim((string) ($_POST['notes'] ?? '')) ?: null,
                $sid,
            ]);
            $_SESSION['po_flash'] = 'Supplier updated.';
            header('Location: purchase_orders.php?tab=suppliers');
            exit;
        }
        if ($action === 'toggle_supplier_active') {
            $sid = (int) ($_POST['supplier_id'] ?? 0);
            if ($sid < 1) {
                throw new RuntimeException('Invalid supplier.');
            }
            $db->prepare('UPDATE suppliers SET active = CASE WHEN active = 1 THEN 0 ELSE 1 END WHERE id = ?')->execute([$sid]);
            $_SESSION['po_flash'] = 'Supplier status updated.';
            header('Location: purchase_orders.php?tab=suppliers');
            exit;
        }
        if ($action === 'delete_supplier') {
            $sid = (int) ($_POST['supplier_id'] ?? 0);
            $poCount = poDeleteSupplier($db, $sid);
            $_SESSION['po_flash'] = 'Supplier deleted' . ($poCount > 0 ? ' with ' . $poCount . ' purchase order(s).' : '.');
            header('Location: purchase_orders.php?tab=suppliers');
            exit;
        }
        if ($action === 'link_receiving_to_supplier') {
            $sid = (int) ($_POST['supplier_id'] ?? 0);
            $recordIds = array_values(array_filter(array_map('intval', (array) ($_POST['receiving_ids'] ?? []))));
            $linked = poLinkReceivingToSupplier($db, $sid, $recordIds);
            $_SESSION['po_flash'] = $linked . ' receiving record(s) linked to supplier.';
            header('Location: purchase_orders.php?tab=suppliers&supplier_id=' . $sid);
            exit;
        }
        if ($action === 'unlink_receiving_from_supplier') {
            $sid = (int) ($_POST['supplier_id'] ?? 0);
            $recordIds = array_values(array_filter(array_map('intval', (array) ($_POST['receiving_ids'] ?? []))));
            $unlinked = poUnlinkReceivingFromSupplier($db, $sid, $recordIds);
            $_SESSION['po_flash'] = $unlinked . ' receiving record(s) unlinked from supplier.';
            header('Location: purchase_orders.php?tab=suppliers&supplier_id=' . $sid);
            exit;
        }
        if ($action === 'bulk_toggle_supplier_active') {
            $ids = array_values(array_filter(array_map('intval', (array) ($_POST['supplier_ids'] ?? []))));
            if ($ids === []) {
                throw new RuntimeException('Select at least one supplier.');
            }
            $st = $db->prepare('UPDATE suppliers SET active = CASE WHEN active = 1 THEN 0 ELSE 1 END WHERE id = ?');
            foreach ($ids as $sid) {
                if ($sid > 0) {
                    $st->execute([$sid]);
                }
            }
            $_SESSION['po_flash'] = count($ids) . ' supplier(s) updated.';
            header('Location: purchase_orders.php?tab=suppliers');
            exit;
        }
        if ($action === 'bulk_delete_supplier') {
            $ids = array_values(array_filter(array_map('intval', (array) ($_POST['supplier_ids'] ?? []))));
            if ($ids === []) {
                throw new RuntimeException('Select at least one supplier.');
            }
            $deleted = 0;
            $poDeleted = 0;
            foreach ($ids as $sid) {
                if ($sid < 1) {
                    continue;
                }
                $poDeleted += poDeleteSupplier($db, $sid);
                $deleted++;
            }
            $_SESSION['po_flash'] = $deleted . ' supplier(s) deleted' . ($poDeleted > 0 ? ' with ' . $poDeleted . ' purchase order(s).' : '.');
            header('Location: purchase_orders.php?tab=suppliers');
            exit;
        }
        if ($action === 'create_po') {
            $sid = (int) ($_POST['supplier_id'] ?? 0);
            if ($sid < 1) {
                throw new RuntimeException('Select a supplier.');
            }
            $chk = $db->prepare('SELECT id FROM suppliers WHERE id = ? AND active = 1');
            $chk->execute([$sid]);
            if (!$chk->fetchColumn()) {
                throw new RuntimeException('Supplier not found or inactive.');
            }
            $orderDate = trim((string) ($_POST['order_date'] ?? ''));
            if ($orderDate === '') {
                $orderDate = date('Y-m-d');
            }
            $st = $db->prepare('INSERT INTO purchase_orders (supplier_id, status, order_date, notes, total_amount, created_by) VALUES (?,?,?,?,0,?)');
            $st->execute([$sid, 'draft', $orderDate, trim((string) ($_POST['notes'] ?? '')) ?: null, $currentUserLabel]);
            $newId = (int) $db->lastInsertId();
            $_SESSION['po_flash'] = 'Purchase order created.';
            header('Location: purchase_orders.php?id=' . $newId);
            exit;
        }
        if ($action === 'save_po_draft') {
            $poId = (int) ($_POST['po_id'] ?? 0);
            $bundle = poLoadWithDetails($db, $poId);
            if (!$bundle || $bundle['po']['status'] !== 'draft') {
                throw new RuntimeException('Cannot edit this purchase order.');
            }
            $sid = (int) ($_POST['supplier_id'] ?? 0);
            if ($sid < 1) {
                throw new RuntimeException('Select a supplier.');
            }
            $orderDate = trim((string) ($_POST['order_date'] ?? '')) ?: date('Y-m-d');
            $expected = trim((string) ($_POST['expected_date'] ?? ''));
            $expected = $expected !== '' ? $expected : null;
            $notes = trim((string) ($_POST['notes'] ?? '')) ?: null;

            $pids = $_POST['line_product_id'] ?? [];
            $qtys = $_POST['line_quantity'] ?? [];
            $costs = $_POST['line_unit_cost'] ?? [];
            if (!is_array($pids) || !is_array($qtys) || !is_array($costs)) {
                throw new RuntimeException('Invalid line data.');
            }
            $db->beginTransaction();
            $db->prepare('UPDATE purchase_orders SET supplier_id = ?, order_date = ?, expected_date = ?, notes = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
                ->execute([$sid, $orderDate, $expected, $notes, $poId]);
            $db->prepare('DELETE FROM purchase_order_items WHERE purchase_order_id = ?')->execute([$poId]);

            $prodStmt = $db->prepare('SELECT name, buying_price, price FROM products WHERE id = ?');
            $ins = $db->prepare('INSERT INTO purchase_order_items (purchase_order_id, product_id, product_name, quantity, unit_cost, line_total) VALUES (?,?,?,?,?,?)');
            $n = max(count($pids), count($qtys), count($costs));
            for ($i = 0; $i < $n; $i++) {
                $pid = isset($pids[$i]) ? (int) $pids[$i] : 0;
                $q = isset($qtys[$i]) ? (int) $qtys[$i] : 0;
                $uc = isset($costs[$i]) ? (float) $costs[$i] : 0;
                if ($pid < 1 || $q < 1) {
                    continue;
                }
                $prodStmt->execute([$pid]);
                $p = $prodStmt->fetch(PDO::FETCH_ASSOC);
                if (!$p) {
                    continue;
                }
                $pname = (string) $p['name'];
                $lineTotal = round($q * $uc, 2);
                $ins->execute([$poId, $pid, $pname, $q, $uc, $lineTotal]);
            }
            poRecalculateTotal($db, $poId);
            $db->commit();
            $_SESSION['po_flash'] = 'Purchase order saved.';
            header('Location: purchase_orders.php?id=' . $poId);
            exit;
        }
        if ($action === 'mark_ordered') {
            $poId = (int) ($_POST['po_id'] ?? 0);
            $bundle = poLoadWithDetails($db, $poId);
            if (!$bundle || $bundle['po']['status'] !== 'draft') {
                throw new RuntimeException('Only draft orders can be marked ordered.');
            }
            if (count($bundle['items']) < 1) {
                throw new RuntimeException('Add at least one line item first.');
            }
            $db->prepare("UPDATE purchase_orders SET status = 'ordered', updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$poId]);
            $_SESSION['po_flash'] = 'Purchase order marked as ordered.';
            header('Location: purchase_orders.php?id=' . $poId);
            exit;
        }
        if ($action === 'cancel_po') {
            $poId = (int) ($_POST['po_id'] ?? 0);
            $bundle = poLoadWithDetails($db, $poId);
            if (!$bundle || !in_array($bundle['po']['status'], ['draft', 'ordered'], true)) {
                throw new RuntimeException('Cannot cancel this purchase order.');
            }
            $db->prepare("UPDATE purchase_orders SET status = 'cancelled', updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$poId]);
            $_SESSION['po_flash'] = 'Purchase order cancelled.';
            header('Location: purchase_orders.php?id=' . $poId);
            exit;
        }
        if ($action === 'delete_po') {
            $poId = (int) ($_POST['po_id'] ?? 0);
            poDeletePurchaseOrder($db, $poId);
            $_SESSION['po_flash'] = 'Purchase order deleted.';
            header('Location: purchase_orders.php?tab=orders');
            exit;
        }
        if ($action === 'bulk_cancel_po') {
            $ids = array_values(array_filter(array_map('intval', (array) ($_POST['po_ids'] ?? []))));
            if ($ids === []) {
                throw new RuntimeException('Select at least one purchase order.');
            }
            $st = $db->prepare("UPDATE purchase_orders SET status = 'cancelled', updated_at = CURRENT_TIMESTAMP WHERE id = ? AND status IN ('draft', 'ordered')");
            $cancelled = 0;
            $skipped = 0;
            foreach ($ids as $poId) {
                if ($poId < 1) {
                    continue;
                }
                $st->execute([$poId]);
                if ($st->rowCount() > 0) {
                    $cancelled++;
                } else {
                    $skipped++;
                }
            }
            $msg = $cancelled . ' purchase order(s) cancelled.';
            if ($skipped > 0) {
                $msg .= ' ' . $skipped . ' could not be cancelled.';
            }
            $_SESSION['po_flash'] = $msg;
            header('Location: purchase_orders.php?tab=orders');
            exit;
        }
        if ($action === 'bulk_delete_po') {
            $ids = array_values(array_filter(array_map('intval', (array) ($_POST['po_ids'] ?? []))));
            if ($ids === []) {
                throw new RuntimeException('Select at least one purchase order.');
            }
            $deleted = 0;
            foreach ($ids as $poId) {
                if ($poId < 1) {
                    continue;
                }
                poDeletePurchaseOrder($db, $poId);
                $deleted++;
            }
            $_SESSION['po_flash'] = $deleted . ' purchase order(s) deleted.';
            header('Location: purchase_orders.php?tab=orders');
            exit;
        }
    } catch (Throwable $e) {
        $_SESSION['po_flash_err'] = $e->getMessage();
        $supplierActions = ['add_supplier', 'edit_supplier', 'toggle_supplier_active', 'delete_supplier', 'bulk_toggle_supplier_active', 'bulk_delete_supplier', 'link_receiving_to_supplier', 'unlink_receiving_from_supplier'];
        $poListActions = ['bulk_cancel_po', 'bulk_delete_po', 'delete_po'];
        if (in_array($action, ['link_receiving_to_supplier', 'unlink_receiving_from_supplier'], true) && !empty($_POST['supplier_id'])) {
            $redir = 'purchase_orders.php?tab=suppliers&supplier_id=' . (int) $_POST['supplier_id'];
        } elseif (in_array($action, $supplierActions, true)) {
            $redir = 'purchase_orders.php?tab=suppliers';
        } elseif (in_array($action, $poListActions, true)) {
            $redir = 'purchase_orders.php?tab=orders';
        } elseif (!empty($_POST['po_id'])) {
            $redir = 'purchase_orders.php?id=' . (int) $_POST['po_id'];
        } else {
            $redir = 'purchase_orders.php';
        }
        header('Location: ' . $redir);
        exit;
    }
}

$flash = $_SESSION['po_flash'] ?? '';
unset($_SESSION['po_flash']);
$flashErr = $_SESSION['po_flash_err'] ?? '';
unset($_SESSION['po_flash_err']);

$suppliers = $db->query('SELECT * FROM suppliers ORDER BY active DESC, name COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);
$products = $db->query('SELECT id, name, buying_price, price FROM products ORDER BY name COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);
$productsPayload = [];
foreach ($products as $p) {
    $bp = $p['buying_price'];
    $def = ($bp !== null && $bp !== '') ? (float) $bp : (float) $p['price'];
    $productsPayload[] = ['id' => (int) $p['id'], 'name' => $p['name'], 'unit_default' => $def];
}
$productsJson = json_encode($productsPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE);

$viewId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$poBundle = null;
if ($viewId > 0) {
    $poBundle = poLoadWithDetails($db, $viewId);
}

$viewSupplierId = isset($_GET['supplier_id']) ? (int) $_GET['supplier_id'] : 0;
$supplierBundle = null;
if (!$poBundle && $viewSupplierId > 0) {
    $supplierBundle = poLoadSupplierBundle($db, $viewSupplierId);
}

$statusFilter = (string) ($_GET['status'] ?? 'all');
$poListSql = 'SELECT po.id, po.status, po.order_date, po.total_amount, po.created_at, s.name AS supplier_name
    FROM purchase_orders po
    JOIN suppliers s ON s.id = po.supplier_id';
$args = [];
if (in_array($statusFilter, ['draft', 'ordered', 'cancelled', 'partially_received', 'received'], true)) {
    $poListSql .= ' WHERE po.status = ?';
    $args[] = $statusFilter;
}
$poListSql .= ' ORDER BY po.created_at DESC';
$stList = $db->prepare($poListSql);
$stList->execute($args);
$poRows = $stList->fetchAll(PDO::FETCH_ASSOC);

$activeTab = (string) ($_GET['tab'] ?? 'suppliers');
if (!in_array($activeTab, ['suppliers', 'orders'], true)) {
    $activeTab = 'suppliers';
}

$poReceivingHistory = [];
if ($poBundle) {
    $poReceivingHistory = poReceivingRecordsForPo($db, (int) $poBundle['po']['id']);
}

$supReceivingHistory = [];
$unlinkedReceivings = [];
if ($supplierBundle) {
    $supReceivingHistory = poReceivingHistoryForSupplier($db, (int) $supplierBundle['supplier']['id']);
    $unlinkedReceivings = poListUnlinkedReceivingRecords($db);
}

function poStatusBadgeClass(string $status): string
{
    $map = [
        'draft' => 'bg-amber-100 text-amber-900',
        'ordered' => 'bg-teal-100 text-teal-800',
        'partially_received' => 'bg-orange-100 text-orange-800',
        'received' => 'bg-green-100 text-green-800',
        'cancelled' => 'bg-gray-100 text-gray-600',
    ];
    return $map[$status] ?? 'bg-gray-100 text-gray-600';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase orders</title>
    <script src="../navigation.js" async></script>
    <link href="../src/output.css" rel="stylesheet">
    <link rel="icon" href="../favicon.ico" type="image/png">
    <link rel="stylesheet" href="../src/font-awesome/css/all.min.css">
    <link rel="stylesheet" href="../src/font-awesome/css/all.min.css">
    <script src="../sweetalert2@11.js"></script>
    <style>
        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 9999; display: flex; align-items: center; justify-content: center; opacity: 0; visibility: hidden; transition: all 0.3s ease; }
        .modal-overlay.active { opacity: 1; visibility: visible; }
        .modal-content { background: white; border-radius: 1rem; max-width: 28rem; width: 90%; transform: scale(0.95); transition: all 0.3s ease; }
        .modal-content.modal-wide { max-width: 42rem; }
        .modal-overlay.active .modal-content { transform: scale(1); }
        .po-tab { transition: all 0.2s ease; }
        .po-tab.active { background: #0d9488; color: white; border-color: #0d9488; }
        .custom-scrollbar { scrollbar-width: thin; scrollbar-color: #14b8a6 #E5E7EB; }
        .hamburger span { display: block; width: 22px; height: 2px; background: #374151; margin: 5px 0; }
        .po-selectable-row { cursor: pointer; transition: background-color 0.15s ease; }
        .po-selectable-row:hover { background-color: #f9fafb; }
        .po-selected-row { background-color: #f0fdfa !important; box-shadow: inset 3px 0 0 #0d9488; }
        .bulk-actions-bar { transition: all 0.2s ease; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">
    <?php include 'sidebar.php'; ?>
    <div class="mobile-overlay lg:hidden fixed inset-0 bg-black/50 z-[80] hidden" id="mobileOverlay" onclick="closeSidebar()"></div>
    <div class="content flex-1 lg:ml-64">
        <div class="sticky top-0 z-50 bg-gray-100 py-3 sm:py-4 px-4 lg:px-6 shadow-sm border-b border-gray-100">
            <div class="w-full flex flex-col sm:flex-row sm:items-center gap-3">
                <div class="flex items-center gap-3 min-w-0 flex-1">
                    <button type="button" class="hamburger lg:hidden p-2 rounded bg-gray-100" onclick="toggleSidebar()" aria-label="Open menu"><span></span><span></span><span></span></button>
                    <div class="min-w-0">
                        <h1 class="text-xl sm:text-2xl lg:text-3xl font-bold text-gray-900">Purchase Orders</h1>
                        <p class="text-gray-600 text-sm hidden sm:block">Suppliers, orders, and receiving tracking</p>
                    </div>
                </div>
                <a href="<?= htmlspecialchars($backHref) ?>" class="inline-flex items-center px-3 py-2 text-sm border border-gray-300 rounded-lg shadow-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-teal-500">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                    Back
                </a>
            </div>
        </div>
        <main class="p-4 lg:p-6">
        <?php if ($flash !== ''): ?>
            <div class="mb-4 p-3 rounded-lg bg-teal-50 text-teal-800 text-sm"><?= htmlspecialchars($flash) ?></div>
        <?php endif; ?>
        <?php if ($flashErr !== ''): ?>
            <div class="mb-4 p-3 rounded-lg bg-red-50 text-red-800 text-sm"><?= htmlspecialchars($flashErr) ?></div>
        <?php endif; ?>

        <?php if (!$poBundle && !$supplierBundle): ?>
        <div class="flex flex-wrap gap-2 mb-6">
            <a href="purchase_orders.php?tab=suppliers" class="po-tab inline-flex items-center px-4 py-2 rounded-lg text-sm font-medium border border-gray-200 <?= $activeTab === 'suppliers' ? 'active' : 'bg-white text-gray-700 hover:bg-gray-50' ?>">
                <i class="fas fa-truck mr-2"></i> Suppliers
            </a>
            <a href="purchase_orders.php?tab=orders" class="po-tab inline-flex items-center px-4 py-2 rounded-lg text-sm font-medium border border-gray-200 <?= $activeTab === 'orders' ? 'active' : 'bg-white text-gray-700 hover:bg-gray-50' ?>">
                <i class="fas fa-file-invoice mr-2"></i> Purchase Orders
            </a>
        </div>

        <?php if ($activeTab === 'suppliers'): ?>
        <div class="bg-white rounded-xl shadow border border-gray-100 overflow-hidden mb-6">
            <div class="px-4 py-3 bg-gradient-to-r from-teal-50 to-cyan-50 border-b border-gray-100 flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-lg font-semibold text-gray-800 flex items-center gap-2"><i class="fas fa-truck text-teal-600"></i> Suppliers</h2>
                <button type="button" onclick="openModal('modalAddSupplier')" class="inline-flex items-center px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white rounded-lg text-sm font-medium focus:outline-none focus:ring-2 focus:ring-teal-500">
                    <i class="fas fa-plus mr-2"></i> Add supplier
                </button>
            </div>
            <form method="post" id="supplierListForm">
                <div id="supplierBulkBar" class="bulk-actions-bar hidden px-4 py-3 bg-teal-50 border-b border-teal-100 flex flex-wrap items-center justify-between gap-3">
                    <span id="supplierSelectedCount" class="text-sm font-medium text-gray-700">0 selected</span>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" id="supplierViewBtn" class="inline-flex items-center px-3 py-1.5 bg-teal-600 hover:bg-teal-700 text-white rounded-lg text-sm font-medium" onclick="viewSelectedSupplier()">
                            <i class="fas fa-eye mr-2"></i> View
                        </button>
                        <button type="submit" name="action" value="bulk_toggle_supplier_active" class="inline-flex items-center px-3 py-1.5 bg-white border border-gray-300 hover:bg-gray-50 text-gray-800 rounded-lg text-sm font-medium" onclick="return confirmSupplierBulkAction('toggle active status for')">
                            <i class="fas fa-toggle-on mr-2 text-gray-500"></i> Toggle active
                        </button>
                        <button type="submit" name="action" value="bulk_delete_supplier" class="inline-flex items-center px-3 py-1.5 bg-red-600 hover:bg-red-700 text-white rounded-lg text-sm font-medium" onclick="return confirmSupplierBulkDelete()">
                            <i class="fas fa-trash mr-2"></i> Delete
                        </button>
                    </div>
                </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left w-10">
                                <input type="checkbox" id="supplierSelectAll" class="po-row-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500" title="Select all" onclick="event.stopPropagation()">
                            </th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Phone</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Active</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($suppliers as $s): ?>
                            <tr class="po-selectable-row po-supplier-row" data-supplier-id="<?= (int) $s['id'] ?>" data-supplier="<?= htmlspecialchars(json_encode($s), ENT_QUOTES, 'UTF-8') ?>" onclick="handleSupplierRowClick(event)">
                                <td class="px-4 py-3" onclick="event.stopPropagation()">
                                    <input type="checkbox" name="supplier_ids[]" value="<?= (int) $s['id'] ?>" class="po-row-checkbox supplier-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500">
                                </td>
                                <td class="px-4 py-3 text-sm font-medium text-gray-900"><?= htmlspecialchars($s['name']) ?></td>
                                <td class="px-4 py-3 text-sm text-gray-600"><?= htmlspecialchars((string) ($s['phone'] ?? '')) ?></td>
                                <td class="px-4 py-3 text-sm text-gray-600"><?= htmlspecialchars((string) ($s['email'] ?? '')) ?></td>
                                <td class="px-4 py-3 text-sm"><?= (int) $s['active'] === 1 ? '<span class="text-teal-600">Yes</span>' : '<span class="text-gray-400">No</span>' ?></td>
                                <td class="px-4 py-3 text-sm">
                                    <a href="purchase_orders.php?tab=suppliers&amp;supplier_id=<?= (int) $s['id'] ?>" class="text-teal-700 hover:text-teal-900 font-medium mr-3" onclick="event.stopPropagation()">View</a>
                                    <button type="button" class="po-edit-supplier text-teal-700 hover:text-teal-900 font-medium mr-3" data-supplier="<?= htmlspecialchars(json_encode($s), ENT_QUOTES, 'UTF-8') ?>">Edit</button>
                                    <form method="post" class="inline mr-3" onsubmit="return confirm('Toggle active status?');" onclick="event.stopPropagation()">
                                        <input type="hidden" name="action" value="toggle_supplier_active">
                                        <input type="hidden" name="supplier_id" value="<?= (int) $s['id'] ?>">
                                        <button type="submit" class="text-gray-600 hover:text-gray-900 font-medium">Toggle</button>
                                    </form>
                                    <form method="post" class="inline" onsubmit="return confirm('Delete this supplier and all of their purchase orders?');" onclick="event.stopPropagation()">
                                        <input type="hidden" name="action" value="delete_supplier">
                                        <input type="hidden" name="supplier_id" value="<?= (int) $s['id'] ?>">
                                        <button type="submit" class="text-red-600 hover:text-red-800 font-medium">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($suppliers)): ?>
                            <tr><td colspan="6" class="px-4 py-8 text-center text-gray-500 text-sm">No suppliers yet. Add one to create purchase orders.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            </form>
        </div>
        <?php else: ?>

        <!-- New PO + list -->
        <div class="bg-white rounded-xl shadow border border-gray-100 overflow-hidden mb-6">
            <div class="px-4 py-3 bg-gradient-to-r from-teal-50 to-cyan-50 border-b border-gray-100">
                <h2 class="text-lg font-semibold text-gray-800 flex items-center gap-2 mb-3"><i class="fas fa-file-invoice text-teal-600"></i> Create purchase order</h2>
                <form method="post" class="flex flex-wrap items-end gap-3">
                    <input type="hidden" name="action" value="create_po">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Supplier</label>
                        <select name="supplier_id" required class="px-3 py-2 border border-gray-300 rounded-lg text-sm min-w-[200px] focus:outline-none focus:ring-2 focus:ring-teal-500">
                            <option value="">Select…</option>
                            <?php foreach ($suppliers as $s): ?>
                                <?php if ((int) $s['active'] !== 1) { continue; } ?>
                                <option value="<?= (int) $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Order date</label>
                        <input type="date" name="order_date" value="<?= htmlspecialchars(date('Y-m-d')) ?>" class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                    </div>
                    <div class="flex-1 min-w-[200px]">
                        <label class="block text-xs font-medium text-gray-600 mb-1">Notes (optional)</label>
                        <input type="text" name="notes" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-teal-500" placeholder="Internal notes">
                    </div>
                    <button type="submit" class="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white rounded-lg text-sm font-medium focus:outline-none focus:ring-2 focus:ring-teal-500">Create draft</button>
                </form>
            </div>
            <div class="p-4 border-b border-gray-100 flex flex-wrap gap-2 items-center">
                <span class="text-sm text-gray-600 mr-1">Filter:</span>
                <?php
                $filters = ['all' => 'All', 'draft' => 'Draft', 'ordered' => 'Ordered', 'partially_received' => 'Partial', 'received' => 'Received', 'cancelled' => 'Cancelled'];
                foreach ($filters as $key => $label):
                    $href = $key === 'all' ? 'purchase_orders.php?tab=orders' : 'purchase_orders.php?tab=orders&status=' . $key;
                    $active = ($key === 'all' && $statusFilter === 'all') || $statusFilter === $key;
                ?>
                <a href="<?= htmlspecialchars($href) ?>" class="text-sm px-3 py-1 rounded-full <?= $active ? 'bg-teal-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>"><?= htmlspecialchars($label) ?></a>
                <?php endforeach; ?>
            </div>
            <form method="post" id="poListForm">
                <div id="poBulkBar" class="bulk-actions-bar hidden px-4 py-3 bg-teal-50 border-b border-teal-100 flex flex-wrap items-center justify-between gap-3">
                    <span id="poSelectedCount" class="text-sm font-medium text-gray-700">0 selected</span>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" id="poViewBtn" class="inline-flex items-center px-3 py-1.5 bg-teal-600 hover:bg-teal-700 text-white rounded-lg text-sm font-medium" onclick="viewSelectedPo()">
                            <i class="fas fa-eye mr-2"></i> View
                        </button>
                        <button type="submit" name="action" value="bulk_cancel_po" class="inline-flex items-center px-3 py-1.5 bg-white border border-gray-300 hover:bg-gray-50 text-gray-800 rounded-lg text-sm font-medium" onclick="return confirmPoBulkAction('cancel')">
                            <i class="fas fa-ban mr-2 text-gray-500"></i> Cancel
                        </button>
                        <button type="submit" name="action" value="bulk_delete_po" class="inline-flex items-center px-3 py-1.5 bg-red-600 hover:bg-red-700 text-white rounded-lg text-sm font-medium" onclick="return confirmPoBulkDelete()">
                            <i class="fas fa-trash mr-2"></i> Delete
                        </button>
                    </div>
                </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left w-10">
                                <input type="checkbox" id="poSelectAll" class="po-row-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500" title="Select all" onclick="event.stopPropagation()">
                            </th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">PO #</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Supplier</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($poRows as $row): ?>
                            <tr class="po-selectable-row" data-po-id="<?= (int) $row['id'] ?>" data-po-status="<?= htmlspecialchars($row['status']) ?>" onclick="handlePoRowClick(event, <?= (int) $row['id'] ?>)">
                                <td class="px-4 py-3" onclick="event.stopPropagation()">
                                    <input type="checkbox" name="po_ids[]" value="<?= (int) $row['id'] ?>" class="po-row-checkbox po-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500">
                                </td>
                                <td class="px-4 py-3 text-sm font-mono font-medium text-gray-900"><?= htmlspecialchars(poFormatNumber((int) $row['id'])) ?></td>
                                <td class="px-4 py-3 text-sm text-gray-700"><?= htmlspecialchars($row['supplier_name']) ?></td>
                                <td class="px-4 py-3 text-sm text-gray-600"><?= htmlspecialchars($row['order_date']) ?></td>
                                <td class="px-4 py-3 text-sm">
                                    <?php $st = $row['status']; ?>
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium <?= poStatusBadgeClass($st) ?>"><?= htmlspecialchars(str_replace('_', ' ', $st)) ?></span>
                                </td>
                                <td class="px-4 py-3 text-sm text-right">N$<?= number_format((float) $row['total_amount'], 2) ?></td>
                                <td class="px-4 py-3 text-sm" onclick="event.stopPropagation()">
                                    <a href="purchase_orders.php?id=<?= (int) $row['id'] ?>" class="text-teal-700 hover:text-teal-900 font-medium mr-2">View</a>
                                    <a href="purchase_order_pdf.php?id=<?= (int) $row['id'] ?>" class="text-gray-700 hover:text-gray-900 font-medium mr-2" target="_blank" rel="noopener">PDF</a>
                                    <form method="post" class="inline" onsubmit="return confirm('Delete this purchase order?');">
                                        <input type="hidden" name="action" value="delete_po">
                                        <input type="hidden" name="po_id" value="<?= (int) $row['id'] ?>">
                                        <button type="submit" class="text-red-600 hover:text-red-800 font-medium">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($poRows)): ?>
                            <tr><td colspan="7" class="px-4 py-8 text-center text-gray-500 text-sm">No purchase orders for this filter.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            </form>
        </div>
        <?php endif; ?>

        <?php elseif ($supplierBundle): ?>
            <?php
            $supView = $supplierBundle['supplier'];
            $supOrders = $supplierBundle['orders'];
            ?>
            <div class="mb-4 flex flex-wrap gap-2">
                <a href="purchase_orders.php?tab=suppliers" class="inline-flex items-center px-3 py-2 border border-gray-300 bg-white hover:bg-gray-50 text-gray-800 rounded-lg text-sm font-medium focus:outline-none focus:ring-2 focus:ring-teal-500">← All suppliers</a>
                <button type="button" class="po-edit-supplier inline-flex items-center px-3 py-2 bg-teal-600 hover:bg-teal-700 text-white rounded-lg text-sm font-medium focus:outline-none focus:ring-2 focus:ring-teal-500" data-supplier="<?= htmlspecialchars(json_encode($supView), ENT_QUOTES, 'UTF-8') ?>">
                    <i class="fas fa-pen mr-2"></i> Edit supplier
                </button>
                <button type="button" onclick="openModal('modalLinkReceiving')" class="inline-flex items-center px-3 py-2 bg-white border border-gray-300 hover:bg-gray-50 text-gray-800 rounded-lg text-sm font-medium focus:outline-none focus:ring-2 focus:ring-teal-500">
                    <i class="fas fa-link mr-2 text-teal-600"></i> Link receiving
                </button>
                <a href="receiving.php?supplier_id=<?= (int) $supView['id'] ?>" class="inline-flex items-center px-3 py-2 bg-white border border-gray-300 hover:bg-gray-50 text-gray-800 rounded-lg text-sm font-medium focus:outline-none focus:ring-2 focus:ring-teal-500">
                    <i class="fas fa-dolly mr-2 text-teal-600"></i> Receive stock
                </a>
                <form method="post" class="inline" onsubmit="return confirm('Delete this supplier and all of their purchase orders?');">
                    <input type="hidden" name="action" value="delete_supplier">
                    <input type="hidden" name="supplier_id" value="<?= (int) $supView['id'] ?>">
                    <button type="submit" class="inline-flex items-center px-3 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg text-sm font-medium focus:outline-none focus:ring-2 focus:ring-red-500">
                        <i class="fas fa-trash mr-2"></i> Delete supplier
                    </button>
                </form>
            </div>

            <div class="bg-white rounded-xl shadow border border-gray-100 overflow-hidden mb-6">
                <div class="px-4 py-4 bg-gradient-to-r from-teal-50 to-cyan-50 border-b border-gray-100">
                    <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2"><i class="fas fa-truck text-teal-600"></i> <?= htmlspecialchars($supView['name']) ?></h2>
                    <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 text-sm">
                        <div>
                            <div class="text-xs font-medium text-gray-500 uppercase">Phone</div>
                            <div class="text-gray-800"><?= htmlspecialchars((string) ($supView['phone'] ?? '—')) ?></div>
                        </div>
                        <div>
                            <div class="text-xs font-medium text-gray-500 uppercase">Email</div>
                            <div class="text-gray-800"><?= htmlspecialchars((string) ($supView['email'] ?? '—')) ?></div>
                        </div>
                        <div>
                            <div class="text-xs font-medium text-gray-500 uppercase">Active</div>
                            <div class="<?= (int) $supView['active'] === 1 ? 'text-teal-600' : 'text-gray-400' ?>"><?= (int) $supView['active'] === 1 ? 'Yes' : 'No' ?></div>
                        </div>
                        <div>
                            <div class="text-xs font-medium text-gray-500 uppercase">Purchase orders</div>
                            <div class="text-gray-800"><?= count($supOrders) ?></div>
                        </div>
                        <div>
                            <div class="text-xs font-medium text-gray-500 uppercase">Receivings</div>
                            <div class="text-gray-800"><?= count($supReceivingHistory) ?></div>
                        </div>
                    </div>
                    <?php if (!empty($supView['notes'])): ?>
                    <div class="mt-4 text-sm">
                        <div class="text-xs font-medium text-gray-500 uppercase mb-1">Notes</div>
                        <div class="text-gray-700 whitespace-pre-wrap"><?= htmlspecialchars((string) $supView['notes']) ?></div>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="px-4 py-3 border-b border-gray-100">
                    <h3 class="text-lg font-semibold text-gray-800">Purchase orders</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">PO #</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($supOrders as $row): ?>
                            <tr class="po-selectable-row" onclick="handlePoRowClick(event, <?= (int) $row['id'] ?>)">
                                <td class="px-4 py-3 text-sm font-mono font-medium text-gray-900"><?= htmlspecialchars(poFormatNumber((int) $row['id'])) ?></td>
                                <td class="px-4 py-3 text-sm text-gray-600"><?= htmlspecialchars($row['order_date']) ?></td>
                                <td class="px-4 py-3 text-sm">
                                    <?php $st = $row['status']; ?>
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium <?= poStatusBadgeClass($st) ?>"><?= htmlspecialchars(str_replace('_', ' ', $st)) ?></span>
                                </td>
                                <td class="px-4 py-3 text-sm text-right">N$<?= number_format((float) $row['total_amount'], 2) ?></td>
                                <td class="px-4 py-3 text-sm" onclick="event.stopPropagation()">
                                    <a href="purchase_orders.php?id=<?= (int) $row['id'] ?>" class="text-teal-700 hover:text-teal-900 font-medium mr-2">View</a>
                                    <a href="purchase_order_pdf.php?id=<?= (int) $row['id'] ?>" class="text-gray-700 hover:text-gray-900 font-medium" target="_blank" rel="noopener">PDF</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($supOrders)): ?>
                            <tr><td colspan="5" class="px-4 py-8 text-center text-gray-500 text-sm">No purchase orders for this supplier yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow border border-gray-100 overflow-hidden mb-6">
                <div class="px-4 py-3 border-b border-gray-100 flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-lg font-semibold text-gray-800 flex items-center gap-2">
                        <i class="fas fa-dolly text-teal-600"></i> Receiving history
                    </h3>
                    <div class="flex flex-wrap items-center gap-2">
                    <?php if (!empty($supReceivingHistory)): ?>
                    <span class="text-sm text-gray-500"><?= count($supReceivingHistory) ?> batch<?= count($supReceivingHistory) === 1 ? '' : 'es' ?></span>
                    <?php endif; ?>
                    <?php
                    $unlinkableReceiving = array_filter($supReceivingHistory, static fn($r) => !empty($r['can_unlink']));
                    if (!empty($unlinkableReceiving)):
                    ?>
                    <button type="submit" form="unlinkReceivingBulkForm" class="inline-flex items-center px-3 py-1.5 bg-white border border-amber-300 hover:bg-amber-50 text-amber-800 rounded-lg text-sm font-medium" onclick="return confirmUnlinkReceivingBulk();">
                        <i class="fas fa-unlink mr-2"></i> Unlink selected
                    </button>
                    <?php endif; ?>
                    </div>
                </div>
                <?php if (empty($supReceivingHistory)): ?>
                <div class="px-4 py-8 text-center text-gray-500 text-sm">No stock received from this supplier yet.</div>
                <?php else: ?>
                <?php if (!empty($unlinkableReceiving)): ?>
                <form method="post" id="unlinkReceivingBulkForm" class="hidden" aria-hidden="true">
                    <input type="hidden" name="action" value="unlink_receiving_from_supplier">
                    <input type="hidden" name="supplier_id" value="<?= (int) $supView['id'] ?>">
                </form>
                <?php endif; ?>
                <div class="divide-y divide-gray-200">
                    <?php foreach ($supReceivingHistory as $rec): ?>
                    <div class="px-4 py-4">
                        <div class="flex flex-wrap items-start justify-between gap-3 mb-3">
                            <div class="flex items-start gap-3 min-w-0">
                                <?php if (!empty($rec['can_unlink'])): ?>
                                <input type="checkbox" form="unlinkReceivingBulkForm" name="receiving_ids[]" value="<?= (int) $rec['id'] ?>" class="unlink-receiving-checkbox mt-1 rounded border-gray-300 text-amber-600 focus:ring-amber-500" title="Select to unlink">
                                <?php endif; ?>
                                <div>
                                <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($rec['receiving_date']) ?></div>
                                <div class="text-xs text-gray-500 mt-0.5">Received by <?= htmlspecialchars($rec['username']) ?></div>
                                </div>
                            </div>
                            <div class="text-right text-sm">
                                <?php if (!empty($rec['purchase_order_id'])): ?>
                                <a href="purchase_orders.php?id=<?= (int) $rec['purchase_order_id'] ?>" class="text-teal-700 hover:text-teal-900 font-medium"><?= htmlspecialchars(poFormatNumber((int) $rec['purchase_order_id'])) ?></a>
                                <?php else: ?>
                                <span class="text-gray-400">No PO linked</span>
                                <?php endif; ?>
                                <div class="text-gray-600 mt-1">
                                    <?= (int) $rec['total_items'] ?> item<?= (int) $rec['total_items'] === 1 ? '' : 's' ?> ·
                                    <?= (int) $rec['total_quantity'] ?> qty ·
                                    <strong class="text-gray-900">N$<?= number_format((float) $rec['total_cost'], 2) ?></strong>
                                </div>
                                <?php if (!empty($rec['can_unlink'])): ?>
                                <form method="post" class="inline mt-2" onsubmit="return confirm('Unlink this receiving batch from this supplier?');">
                                    <input type="hidden" name="action" value="unlink_receiving_from_supplier">
                                    <input type="hidden" name="supplier_id" value="<?= (int) $supView['id'] ?>">
                                    <input type="hidden" name="receiving_ids[]" value="<?= (int) $rec['id'] ?>">
                                    <button type="submit" class="text-amber-700 hover:text-amber-900 font-medium text-xs">
                                        <i class="fas fa-unlink mr-1"></i> Unlink
                                    </button>
                                </form>
                                <?php elseif (!empty($rec['purchase_order_id'])): ?>
                                <div class="text-xs text-gray-400 mt-2">Linked via purchase order only</div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if (!empty($rec['items'])): ?>
                        <div class="overflow-x-auto border border-gray-100 rounded-lg">
                            <table class="min-w-full divide-y divide-gray-100">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Product</th>
                                        <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Qty</th>
                                        <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Unit cost</th>
                                        <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Line total</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <?php foreach ($rec['items'] as $line): ?>
                                    <tr>
                                        <td class="px-3 py-2 text-sm text-gray-800"><?= htmlspecialchars($line['product_name']) ?></td>
                                        <td class="px-3 py-2 text-sm text-right text-gray-700"><?= (int) $line['quantity_added'] ?></td>
                                        <td class="px-3 py-2 text-sm text-right text-gray-600">N$<?= number_format((float) $line['buying_price'], 2) ?></td>
                                        <td class="px-3 py-2 text-sm text-right text-gray-800">N$<?= number_format((float) $line['line_cost'], 2) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

        <?php elseif ($poBundle): ?>
            <?php
            $po = $poBundle['po'];
            $sup = $poBundle['supplier'];
            $items = $poBundle['items'];
            $isDraft = $po['status'] === 'draft';
            $isOrdered = in_array($po['status'], ['ordered', 'partially_received'], true);
            $canReceive = in_array($po['status'], ['ordered', 'partially_received'], true);
            ?>
            <div class="mb-4 flex flex-wrap gap-2">
                <a href="purchase_orders.php?tab=orders" class="inline-flex items-center px-3 py-2 border border-gray-300 bg-white hover:bg-gray-50 text-gray-800 rounded-lg text-sm font-medium focus:outline-none focus:ring-2 focus:ring-teal-500">← All orders</a>
                <?php if ($canReceive): ?>
                <a href="receiving.php?po_id=<?= (int) $po['id'] ?>" class="inline-flex items-center px-3 py-2 bg-teal-600 hover:bg-teal-700 text-white rounded-lg text-sm font-medium focus:outline-none focus:ring-2 focus:ring-teal-500"><i class="fas fa-dolly mr-2"></i> Receive stock</a>
                <?php endif; ?>
                <a href="purchase_order_pdf.php?id=<?= (int) $po['id'] ?>" class="inline-flex items-center px-3 py-2 bg-gray-800 hover:bg-gray-900 text-white rounded-lg text-sm font-medium" target="_blank" rel="noopener"><i class="fas fa-file-pdf mr-2"></i> Download PDF</a>
            </div>

            <div class="bg-white rounded-xl shadow border border-gray-100 overflow-hidden mb-6">
                <div class="px-4 py-3 bg-gradient-to-r from-teal-50 to-cyan-50 border-b border-gray-100">
                    <div class="flex flex-wrap justify-between gap-3">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900"><?= htmlspecialchars(poFormatNumber((int) $po['id'])) ?></h2>
                            <p class="text-sm text-gray-600"><?= htmlspecialchars((string) $sup['name']) ?></p>
                            <span class="inline-flex mt-2 px-2 py-0.5 rounded-full text-xs font-medium <?= poStatusBadgeClass((string) $po['status']) ?>"><?= htmlspecialchars(str_replace('_', ' ', (string) $po['status'])) ?></span>
                        </div>
                        <div class="text-right text-sm text-gray-600">
                            <div>Total: <strong class="text-gray-900">N$<?= number_format((float) $po['total_amount'], 2) ?></strong></div>
                            <div class="text-xs text-gray-500 mt-1">Created <?= htmlspecialchars((string) $po['created_at']) ?></div>
                        </div>
                    </div>
                </div>

                <?php if ($isDraft): ?>
                <form method="post" id="poForm" class="p-4 space-y-4">
                    <input type="hidden" name="action" value="save_po_draft">
                    <input type="hidden" name="po_id" value="<?= (int) $po['id'] ?>">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Supplier</label>
                            <select name="supplier_id" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                                <?php foreach ($suppliers as $s): ?>
                                    <?php if ((int) $s['active'] !== 1 && (int) $s['id'] !== (int) $po['supplier_id']) { continue; } ?>
                                    <option value="<?= (int) $s['id'] ?>" <?= (int) $po['supplier_id'] === (int) $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Order date</label>
                                <input type="date" name="order_date" required value="<?= htmlspecialchars((string) $po['order_date']) ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Expected (optional)</label>
                                <input type="date" name="expected_date" value="<?= $po['expected_date'] ? htmlspecialchars((string) $po['expected_date']) : '' ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                            </div>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Notes</label>
                        <textarea name="notes" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"><?= htmlspecialchars((string) ($po['notes'] ?? '')) ?></textarea>
                    </div>

                    <div class="border border-gray-200 rounded-lg overflow-hidden">
                        <div class="bg-gray-50 px-3 py-2 flex justify-between items-center">
                            <span class="text-sm font-medium text-gray-700">Line items</span>
                            <button type="button" onclick="addPoLine()" class="text-sm text-teal-700 hover:text-teal-900 font-medium">+ Add line</button>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200" id="linesTable">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500">Product</th>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 w-24">Qty</th>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 w-28">Unit cost</th>
                                        <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 w-28">Line</th>
                                        <th class="px-3 py-2 w-10"></th>
                                    </tr>
                                </thead>
                                <tbody id="linesBody">
                                    <?php foreach ($items as $it): ?>
                                        <tr class="po-line po-selectable-row" onclick="handleLineRowClick(event)">
                                            <td class="px-3 py-2">
                                                <select name="line_product_id[]" class="line-product w-full px-2 py-1.5 border border-gray-300 rounded text-sm" onchange="onProductChange(this)">
                                                    <option value="">—</option>
                                                    <?php foreach ($products as $p): ?>
                                                        <option value="<?= (int) $p['id'] ?>" <?= (int) $it['product_id'] === (int) $p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td class="px-3 py-2"><input type="number" name="line_quantity[]" min="1" value="<?= (int) $it['quantity'] ?>" class="line-qty w-full px-2 py-1.5 border border-gray-300 rounded text-sm" oninput="recalcLine(this)"></td>
                                            <td class="px-3 py-2"><input type="number" name="line_unit_cost[]" step="0.01" min="0" value="<?= htmlspecialchars((string) $it['unit_cost']) ?>" class="line-cost w-full px-2 py-1.5 border border-gray-300 rounded text-sm" oninput="recalcLine(this)"></td>
                                            <td class="px-3 py-2 text-right text-sm line-total">N$<?= number_format((float) $it['line_total'], 2) ?></td>
                                            <td class="px-3 py-2"><button type="button" onclick="this.closest('tr').remove(); recalcGrand();" class="text-red-600 hover:text-red-800 text-sm">&times;</button></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-3">
                        <button type="submit" class="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white rounded-lg text-sm font-medium focus:outline-none focus:ring-2 focus:ring-teal-500">Save draft</button>
                    </div>
                </form>
                <div class="px-4 pb-4 flex flex-wrap gap-3 border-t border-gray-100 pt-4">
                    <form method="post" class="inline" onsubmit="return confirm('Mark this PO as ordered? Save any line changes first.');">
                        <input type="hidden" name="action" value="mark_ordered">
                        <input type="hidden" name="po_id" value="<?= (int) $po['id'] ?>">
                        <button type="submit" class="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white rounded-lg text-sm font-medium">Mark ordered</button>
                    </form>
                    <form method="post" class="inline" onsubmit="return confirm('Cancel this draft? It will remain in the list as cancelled.');">
                        <input type="hidden" name="action" value="cancel_po">
                        <input type="hidden" name="po_id" value="<?= (int) $po['id'] ?>">
                        <button type="submit" class="px-4 py-2 border border-gray-300 text-gray-800 hover:bg-gray-50 rounded-lg text-sm font-medium">Cancel draft</button>
                    </form>
                    <form method="post" onsubmit="return confirm('Delete this purchase order permanently?');">
                        <input type="hidden" name="action" value="delete_po">
                        <input type="hidden" name="po_id" value="<?= (int) $po['id'] ?>">
                        <button type="submit" class="px-4 py-2 border border-red-300 text-red-700 hover:bg-red-50 rounded-lg text-sm font-medium">Delete</button>
                    </form>
                </div>
                <?php else: ?>
                <div class="p-4 space-y-3">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-2 text-sm text-gray-700">
                        <div><span class="text-gray-500">Order date:</span> <?= htmlspecialchars((string) $po['order_date']) ?></div>
                        <div><span class="text-gray-500">Expected:</span> <?= $po['expected_date'] ? htmlspecialchars((string) $po['expected_date']) : '—' ?></div>
                        <div class="md:col-span-2"><span class="text-gray-500">Notes:</span> <?= $po['notes'] !== '' && $po['notes'] !== null ? nl2br(htmlspecialchars((string) $po['notes'])) : '—' ?></div>
                    </div>
                    <table class="min-w-full divide-y divide-gray-200 border border-gray-200 rounded-lg overflow-hidden">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500">Product</th>
                                <th class="px-3 py-2 text-right text-xs font-medium text-gray-500">Ordered</th>
                                <th class="px-3 py-2 text-right text-xs font-medium text-gray-500">Received</th>
                                <th class="px-3 py-2 text-right text-xs font-medium text-gray-500">Remaining</th>
                                <th class="px-3 py-2 text-right text-xs font-medium text-gray-500">Unit</th>
                                <th class="px-3 py-2 text-right text-xs font-medium text-gray-500">Line</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($items as $it): ?>
                                <?php
                                $ordered = (int) $it['quantity'];
                                $received = (int) ($it['quantity_received'] ?? 0);
                                $remaining = max(0, $ordered - $received);
                                ?>
                                <tr class="po-selectable-row" onclick="handleLineRowClick(event)">
                                    <td class="px-3 py-2 text-sm"><?= htmlspecialchars($it['product_name']) ?></td>
                                    <td class="px-3 py-2 text-sm text-right"><?= $ordered ?></td>
                                    <td class="px-3 py-2 text-sm text-right text-teal-700"><?= $received ?></td>
                                    <td class="px-3 py-2 text-sm text-right <?= $remaining > 0 ? 'text-amber-700 font-medium' : 'text-gray-500' ?>"><?= $remaining ?></td>
                                    <td class="px-3 py-2 text-sm text-right">N$<?= number_format((float) $it['unit_cost'], 2) ?></td>
                                    <td class="px-3 py-2 text-sm text-right">N$<?= number_format((float) $it['line_total'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($items)): ?>
                                <tr><td colspan="6" class="px-3 py-4 text-center text-gray-500 text-sm">No lines</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <?php if ($isOrdered): ?>
                    <form method="post" onsubmit="return confirm('Cancel this purchase order?');" class="inline">
                        <input type="hidden" name="action" value="cancel_po">
                        <input type="hidden" name="po_id" value="<?= (int) $po['id'] ?>">
                        <button type="submit" class="px-4 py-2 border border-orange-300 text-orange-800 hover:bg-orange-50 rounded-lg text-sm font-medium">Cancel order</button>
                    </form>
                    <?php endif; ?>

                    <?php if (!empty($poReceivingHistory)): ?>
                    <div class="mt-6 border border-gray-200 rounded-lg overflow-hidden">
                        <div class="px-3 py-2 bg-gray-50 text-sm font-medium text-gray-700">Related receiving records</div>
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500">Date</th>
                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500">By</th>
                                    <th class="px-3 py-2 text-right text-xs font-medium text-gray-500">Items</th>
                                    <th class="px-3 py-2 text-right text-xs font-medium text-gray-500">Qty</th>
                                    <th class="px-3 py-2 text-right text-xs font-medium text-gray-500">Cost</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php foreach ($poReceivingHistory as $rec): ?>
                                <tr class="po-selectable-row" onclick="handleLineRowClick(event)">
                                    <td class="px-3 py-2 text-sm"><?= htmlspecialchars((string) $rec['receiving_date']) ?></td>
                                    <td class="px-3 py-2 text-sm"><?= htmlspecialchars((string) $rec['username']) ?></td>
                                    <td class="px-3 py-2 text-sm text-right"><?= (int) $rec['total_items'] ?></td>
                                    <td class="px-3 py-2 text-sm text-right"><?= (int) $rec['total_quantity'] ?></td>
                                    <td class="px-3 py-2 text-sm text-right">N$<?= number_format((float) $rec['total_cost'], 2) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Add supplier modal -->
        <div id="modalAddSupplier" class="modal-overlay" onclick="if(event.target===this)closeModal('modalAddSupplier')">
            <div class="modal-content p-6 m-4">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Add supplier</h3>
                <form method="post" class="space-y-3">
                    <input type="hidden" name="action" value="add_supplier">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Name *</label>
                        <input name="name" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Phone</label>
                        <input name="phone" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Email</label>
                        <input name="email" type="text" inputmode="email" autocomplete="email" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Notes</label>
                        <textarea name="notes" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-teal-500"></textarea>
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" onclick="closeModal('modalAddSupplier')" class="px-4 py-2 text-gray-700 hover:bg-gray-100 rounded-lg text-sm">Close</button>
                        <button type="submit" class="px-4 py-2 bg-teal-600 text-white rounded-lg text-sm font-medium">Save</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Edit supplier modal -->
        <div id="modalEditSupplier" class="modal-overlay" onclick="if(event.target===this)closeModal('modalEditSupplier')">
            <div class="modal-content p-6 m-4">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Edit supplier</h3>
                <form method="post" class="space-y-3">
                    <input type="hidden" name="action" value="edit_supplier">
                    <input type="hidden" name="supplier_id" id="edit_supplier_id">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Name *</label>
                        <input name="name" id="edit_supplier_name" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Phone</label>
                        <input name="phone" id="edit_supplier_phone" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Email</label>
                        <input name="email" id="edit_supplier_email" type="text" inputmode="email" autocomplete="email" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Notes</label>
                        <textarea name="notes" id="edit_supplier_notes" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-teal-500"></textarea>
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" onclick="closeModal('modalEditSupplier')" class="px-4 py-2 text-gray-700 hover:bg-gray-100 rounded-lg text-sm">Close</button>
                        <button type="submit" class="px-4 py-2 bg-teal-600 text-white rounded-lg text-sm font-medium">Save</button>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($supplierBundle): ?>
        <!-- Link receiving modal -->
        <div id="modalLinkReceiving" class="modal-overlay" onclick="if(event.target===this)closeModal('modalLinkReceiving')">
            <div class="modal-content modal-wide p-6 m-4 max-h-[85vh] overflow-y-auto">
                <h3 class="text-lg font-semibold text-gray-900 mb-1">Link receiving to <?= htmlspecialchars($supView['name'] ?? 'supplier') ?></h3>
                <p class="text-sm text-gray-600 mb-4">Assign past receiving batches that have no supplier linked.</p>
                <?php if (empty($unlinkedReceivings)): ?>
                <p class="text-sm text-gray-500 py-6 text-center">No unlinked receiving records found.</p>
                <div class="flex justify-end pt-2">
                    <button type="button" onclick="closeModal('modalLinkReceiving')" class="px-4 py-2 text-gray-700 hover:bg-gray-100 rounded-lg text-sm">Close</button>
                </div>
                <?php else: ?>
                <form method="post">
                    <input type="hidden" name="action" value="link_receiving_to_supplier">
                    <input type="hidden" name="supplier_id" value="<?= (int) ($supView['id'] ?? 0) ?>">
                    <div class="overflow-x-auto border border-gray-200 rounded-lg mb-4">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-3 py-2 w-10"><input type="checkbox" id="linkReceivingSelectAll" class="rounded border-gray-300 text-teal-600 focus:ring-teal-500" title="Select all"></th>
                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Items</th>
                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">PO</th>
                                    <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Cost</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php foreach ($unlinkedReceivings as $ur): ?>
                                <tr>
                                    <td class="px-3 py-2">
                                        <input type="checkbox" name="receiving_ids[]" value="<?= (int) $ur['id'] ?>" class="link-receiving-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500">
                                    </td>
                                    <td class="px-3 py-2 text-gray-800 whitespace-nowrap"><?= htmlspecialchars($ur['receiving_date']) ?></td>
                                    <td class="px-3 py-2 text-gray-600">
                                        <div><?= (int) $ur['total_quantity'] ?> qty · <?= htmlspecialchars($ur['item_summary'] ?: '—') ?></div>
                                        <div class="text-xs text-gray-400">by <?= htmlspecialchars($ur['username']) ?></div>
                                    </td>
                                    <td class="px-3 py-2 text-gray-600 whitespace-nowrap">
                                        <?php if (!empty($ur['purchase_order_id'])): ?>
                                            <?= htmlspecialchars(poFormatNumber((int) $ur['purchase_order_id'])) ?>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-3 py-2 text-right text-gray-800 whitespace-nowrap">N$<?= number_format((float) $ur['total_cost'], 2) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button type="button" onclick="closeModal('modalLinkReceiving')" class="px-4 py-2 text-gray-700 hover:bg-gray-100 rounded-lg text-sm">Cancel</button>
                        <button type="submit" class="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white rounded-lg text-sm font-medium">Link selected</button>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <script>
            function openModal(id) {
                document.getElementById(id).classList.add('active');
            }
            function confirmUnlinkReceivingBulk() {
                const n = document.querySelectorAll('.unlink-receiving-checkbox:checked').length;
                if (!n) {
                    alert('Select at least one receiving batch to unlink.');
                    return false;
                }
                return confirm('Unlink ' + n + ' receiving batch(es) from this supplier?');
            }
            function closeModal(id) {
                document.getElementById(id).classList.remove('active');
            }

            window.PO_PRODUCTS = <?= $productsJson ?>;

            function productDefaultCost(productId) {
                const p = window.PO_PRODUCTS.find(x => String(x.id) === String(productId));
                return p ? p.unit_default : 0;
            }

            function handlePoRowClick(event, id) {
                if (event.target.closest('a, button, input, select, textarea, label, .po-row-checkbox')) return;
                window.location.href = 'purchase_orders.php?id=' + id;
            }

            function handleSupplierRowClick(event) {
                if (event.target.closest('a, button, input, label, .po-row-checkbox')) return;
                const row = event.currentTarget;
                const id = row.dataset.supplierId;
                if (!id) return;
                window.location.href = 'purchase_orders.php?tab=suppliers&supplier_id=' + encodeURIComponent(id);
            }

            function viewSelectedSupplier() {
                const checked = document.querySelectorAll('.supplier-checkbox:checked');
                if (checked.length !== 1) {
                    alert('Select exactly one supplier to view.');
                    return;
                }
                window.location.href = 'purchase_orders.php?tab=suppliers&supplier_id=' + encodeURIComponent(checked[0].value);
            }

            function viewSelectedPo() {
                const checked = document.querySelectorAll('.po-checkbox:checked');
                if (checked.length !== 1) {
                    alert('Select exactly one purchase order to view.');
                    return;
                }
                window.location.href = 'purchase_orders.php?id=' + encodeURIComponent(checked[0].value);
            }

            function confirmPoBulkDelete() {
                const n = document.querySelectorAll('.po-checkbox:checked').length;
                if (!n) {
                    alert('Select at least one purchase order.');
                    return false;
                }
                return confirm('Delete ' + n + ' purchase order(s)? This cannot be undone.');
            }

            document.addEventListener('click', function(e) {
                const btn = e.target.closest('.po-edit-supplier');
                if (!btn) return;
                e.stopPropagation();
                if (!btn.dataset.supplier) return;
                openEditSupplier(JSON.parse(btn.dataset.supplier));
            });

            function handleLineRowClick(event) {
                if (event.target.closest('button, input, select, textarea')) return;
                event.currentTarget.classList.toggle('po-selected-row');
            }

            function openEditSupplier(s) {
                document.getElementById('edit_supplier_id').value = s.id;
                document.getElementById('edit_supplier_name').value = s.name ?? '';
                document.getElementById('edit_supplier_phone').value = s.phone ?? '';
                document.getElementById('edit_supplier_email').value = s.email ?? '';
                document.getElementById('edit_supplier_notes').value = s.notes ?? '';
                openModal('modalEditSupplier');
            }

            function addPoLine() {
                const tbody = document.getElementById('linesBody');
                if (!tbody) return;
                const tr = document.createElement('tr');
                tr.className = 'po-line po-selectable-row';
                tr.onclick = function(e) { handleLineRowClick(e); };
                let opts = '<option value="">—</option>';
                window.PO_PRODUCTS.forEach(p => {
                    opts += '<option value="' + p.id + '">' + escapeHtml(p.name) + '</option>';
                });
                tr.innerHTML = '<td class="px-3 py-2"><select name="line_product_id[]" class="line-product w-full px-2 py-1.5 border border-gray-300 rounded text-sm" onchange="onProductChange(this)">' + opts + '</select></td>' +
                    '<td class="px-3 py-2"><input type="number" name="line_quantity[]" min="1" value="1" class="line-qty w-full px-2 py-1.5 border border-gray-300 rounded text-sm" oninput="recalcLine(this)"></td>' +
                    '<td class="px-3 py-2"><input type="number" name="line_unit_cost[]" step="0.01" min="0" value="0" class="line-cost w-full px-2 py-1.5 border border-gray-300 rounded text-sm" oninput="recalcLine(this)"></td>' +
                    '<td class="px-3 py-2 text-right text-sm line-total">N$0.00</td>' +
                    '<td class="px-3 py-2"><button type="button" onclick="this.closest(\'tr\').remove(); recalcGrand();" class="text-red-600 hover:text-red-800 text-sm">&times;</button></td>';
                tbody.appendChild(tr);
            }

            function escapeHtml(str) {
                return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
            }

            function onProductChange(sel) {
                const tr = sel.closest('tr');
                const cost = tr.querySelector('.line-cost');
                const pid = sel.value;
                if (cost && pid) {
                    cost.value = Number(productDefaultCost(pid)).toFixed(2);
                }
                recalcLine(sel);
            }

            function recalcLine(el) {
                const tr = el.closest('tr');
                const qty = parseInt(tr.querySelector('.line-qty').value, 10) || 0;
                const cost = parseFloat(tr.querySelector('.line-cost').value) || 0;
                const lt = tr.querySelector('.line-total');
                if (lt) lt.textContent = 'N$' + (qty * cost).toFixed(2);
                recalcGrand();
            }

            function recalcGrand() {
                /* visual only — server recomputes */
            }

            function initBulkTable(selectAllId, checkboxClass, bulkBarId, countId) {
                const selectAll = document.getElementById(selectAllId);
                const bulkBar = document.getElementById(bulkBarId);
                const countEl = document.getElementById(countId);
                if (!selectAll || !bulkBar) return;

                function getCheckboxes() {
                    return Array.from(document.querySelectorAll('.' + checkboxClass));
                }

                function updateBulkUI() {
                    const boxes = getCheckboxes();
                    const checked = boxes.filter(cb => cb.checked);
                    bulkBar.classList.toggle('hidden', checked.length === 0);
                    if (countEl) {
                        countEl.textContent = checked.length + ' selected';
                    }
                    boxes.forEach(cb => {
                        const row = cb.closest('tr');
                        if (row) {
                            row.classList.toggle('po-selected-row', cb.checked);
                        }
                    });
                    if (boxes.length) {
                        selectAll.checked = checked.length === boxes.length;
                        selectAll.indeterminate = checked.length > 0 && checked.length < boxes.length;
                    } else {
                        selectAll.checked = false;
                        selectAll.indeterminate = false;
                    }
                }

                selectAll.addEventListener('change', () => {
                    getCheckboxes().forEach(cb => { cb.checked = selectAll.checked; });
                    updateBulkUI();
                });

                document.addEventListener('change', (e) => {
                    if (e.target.classList.contains(checkboxClass)) {
                        updateBulkUI();
                    }
                });
            }

            function confirmSupplierBulkAction(verb) {
                const n = document.querySelectorAll('.supplier-checkbox:checked').length;
                if (!n) {
                    alert('Select at least one supplier.');
                    return false;
                }
                return confirm('Are you sure you want to ' + verb + ' ' + n + ' supplier(s)?');
            }

            function confirmSupplierBulkDelete() {
                const n = document.querySelectorAll('.supplier-checkbox:checked').length;
                if (!n) {
                    alert('Select at least one supplier.');
                    return false;
                }
                return confirm('Delete ' + n + ' supplier(s) and all of their purchase orders? This cannot be undone.');
            }

            function confirmPoBulkAction(verb) {
                const n = document.querySelectorAll('.po-checkbox:checked').length;
                if (!n) {
                    alert('Select at least one purchase order.');
                    return false;
                }
                return confirm('Are you sure you want to ' + verb + ' ' + n + ' purchase order(s)?');
            }

            document.addEventListener('DOMContentLoaded', () => {
                initBulkTable('supplierSelectAll', 'supplier-checkbox', 'supplierBulkBar', 'supplierSelectedCount');
                initBulkTable('poSelectAll', 'po-checkbox', 'poBulkBar', 'poSelectedCount');

                const linkSelectAll = document.getElementById('linkReceivingSelectAll');
                if (linkSelectAll) {
                    linkSelectAll.addEventListener('change', () => {
                        document.querySelectorAll('.link-receiving-checkbox').forEach(cb => {
                            cb.checked = linkSelectAll.checked;
                        });
                    });
                }
            });

        </script>
        </main>
    </div>
</body>
</html>
