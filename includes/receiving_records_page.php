<?php
/**
 * Receiving records hub — set $receivingRecordsRoleFolder = 'admin'|'manager' before include.
 */
if (!isset($receivingRecordsRoleFolder)) {
    $receivingRecordsRoleFolder = isset($roleFolder) ? $roleFolder : 'admin';
}

session_start();
date_default_timezone_set('Africa/Harare');

if (!isset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['role'])) {
    header('Location: ../');
    exit();
}

require_once __DIR__ . '/../purchase_order_lib.php';
poRequireAdminOrManager();

$pdo = new PDO('sqlite:../active.db');
if ((int) $pdo->query('SELECT COUNT(*) FROM software_keys WHERE is_used = 1')->fetchColumn() === 0) {
    header('Location: settings');
    exit();
}

$db = new PDO('sqlite:../pos.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA foreign_keys = ON');

require_once __DIR__ . '/receiving_records_lib.php';
rrEnsureTables($db);

$backHref = $receivingRecordsRoleFolder === 'manager' ? 'manager-center' : 'admin-center';
$flash = (string) ($_SESSION['rr_flash'] ?? '');
$flashErr = (string) ($_SESSION['rr_flash_err'] ?? '');
unset($_SESSION['rr_flash'], $_SESSION['rr_flash_err']);

$filters = [
    'date_from' => trim((string) ($_GET['date_from'] ?? '')),
    'date_to' => trim((string) ($_GET['date_to'] ?? '')),
    'supplier_id' => (int) ($_GET['supplier_id'] ?? 0) ?: null,
    'search' => trim((string) ($_GET['search'] ?? '')),
    'limit' => 200,
];

$rrListQuery = static function (array $extra = []) use ($filters): string {
    return http_build_query(array_filter(array_merge([
        'date_from' => $filters['date_from'],
        'date_to' => $filters['date_to'],
        'supplier_id' => $filters['supplier_id'] ?? '',
        'search' => $filters['search'],
    ], $extra), static fn($v) => $v !== '' && $v !== null));
};

if (isset($_GET['action']) && $_GET['action'] === 'pdf') {
    try {
        rrOutputPdf($db, (int) ($_GET['id'] ?? 0));
    } catch (Throwable $e) {
        $_SESSION['rr_flash_err'] = $e->getMessage();
        header('Location: receiving_records.php' . ($rrListQuery() !== '' ? '?' . $rrListQuery() : ''));
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $recordId = (int) ($_POST['record_id'] ?? 0);
    $listQs = $rrListQuery();
    try {
        if ($action === 'delete' && $recordId > 0) {
            rrDeleteRecord($db, $recordId);
            $_SESSION['rr_flash'] = 'Receiving record deleted and stock reversed.';
            header('Location: receiving_records.php' . ($listQs !== '' ? '?' . $listQs : ''));
            exit();
        }
        if ($action === 'update' && $recordId > 0) {
            $lines = [];
            foreach ((array) ($_POST['line_id'] ?? []) as $i => $lineId) {
                $lines[] = [
                    'id' => (int) $lineId,
                    'quantity_added' => (float) ($_POST['line_qty'][$i] ?? 0),
                    'buying_price' => (float) ($_POST['line_cost'][$i] ?? 0),
                ];
            }
            $header = [
                'receiving_date' => (string) ($_POST['receiving_date'] ?? ''),
                'supplier_id' => $_POST['supplier_id'] ?? '',
            ];
            if (array_key_exists('purchase_order_id', $_POST)) {
                $header['purchase_order_id'] = $_POST['purchase_order_id'];
            }
            rrUpdateRecord($db, $recordId, $header, $lines);
            $_SESSION['rr_flash'] = 'Receiving record updated.';
            header('Location: receiving_records.php?' . $rrListQuery(['id' => $recordId]));
            exit();
        }
        throw new RuntimeException('Invalid action.');
    } catch (Throwable $e) {
        $_SESSION['rr_flash_err'] = $e->getMessage();
        if ($recordId > 0 && $action === 'update') {
            header('Location: receiving_records.php?' . $rrListQuery(['id' => $recordId, 'edit' => 1]));
        } else {
            header('Location: receiving_records.php' . ($listQs !== '' ? '?' . $listQs : ''));
        }
        exit();
    }
}

$recordId = (int) ($_GET['id'] ?? 0);
$editMode = isset($_GET['edit']) && (int) $_GET['edit'] === 1;
$detailBundle = $recordId > 0 ? rrGetRecord($db, $recordId) : null;
if ($recordId > 0 && !$detailBundle) {
    $_SESSION['rr_flash_err'] = 'Receiving record not found.';
    header('Location: receiving_records.php' . ($rrListQuery() !== '' ? '?' . $rrListQuery() : ''));
    exit();
}

$list = rrListRecords($db, $filters);
$records = $list['rows'];
$suppliers = poListActiveSuppliers($db);
$listHref = 'receiving_records.php' . ($rrListQuery() !== '' ? '?' . $rrListQuery() : '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $detailBundle ? 'Receiving #' . (int) $detailBundle['record']['id'] : 'Receiving Records' ?> - POS</title>
    <script src="../navigation.js" async></script>
    <link href="../src/output.css" rel="stylesheet">
    <link rel="icon" href="../favicon.ico" type="image/png">
    <link rel="stylesheet" href="../src/font-awesome/css/all.min.css">
    <style>
        .rr-selectable-row { cursor: pointer; transition: background-color 0.15s ease; }
        .rr-selectable-row:hover { background-color: #f9fafb; }
        .rr-selected-row { background-color: #f0fdfa !important; box-shadow: inset 3px 0 0 #0d9488; }
        .bulk-actions-bar { transition: all 0.2s ease; }
        .hamburger span { display: block; width: 22px; height: 2px; background: #374151; margin: 5px 0; }
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
                    <?php if ($detailBundle): ?>
                        <h1 class="text-xl sm:text-2xl font-bold text-gray-900">Receiving #<?= (int) $detailBundle['record']['id'] ?></h1>
                        <p class="text-gray-600 text-sm hidden sm:block"><?= htmlspecialchars(date('Y-m-d H:i', strtotime($detailBundle['record']['receiving_date']))) ?> · <?= htmlspecialchars($detailBundle['record']['username']) ?></p>
                    <?php else: ?>
                        <h1 class="text-xl sm:text-2xl font-bold text-gray-900">Receiving Records</h1>
                        <p class="text-gray-600 text-sm hidden sm:block">View, edit, delete, and download receiving reports</p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <?php if ($detailBundle && !$editMode): ?>
                    <a href="?action=pdf&amp;id=<?= (int) $detailBundle['record']['id'] ?>" class="inline-flex items-center px-3 py-2 text-sm border border-gray-300 rounded-lg font-medium text-gray-700 bg-white hover:bg-gray-50">
                        <i class="fas fa-file-pdf mr-2 text-teal-600"></i> PDF
                    </a>
                    <a href="?<?= htmlspecialchars($rrListQuery(['id' => $recordId, 'edit' => 1])) ?>" class="inline-flex items-center px-3 py-2 text-sm border border-gray-300 rounded-lg font-medium text-gray-700 bg-white hover:bg-gray-50">
                        <i class="fas fa-edit mr-2 text-teal-600"></i> Edit
                    </a>
                <?php elseif (!$detailBundle): ?>
                   
                <?php endif; ?>
                <a href="<?= $detailBundle ? htmlspecialchars($listHref) : htmlspecialchars($backHref) ?>" class="inline-flex items-center px-3 py-2 text-sm border border-gray-300 rounded-lg font-medium text-gray-700 bg-white hover:bg-gray-50">
                    <i class="fas fa-arrow-left mr-2"></i> <?= $detailBundle ? 'Back to list' : 'Back' ?>
                </a>
            </div>
        </div>
    </div>

    <main class="p-4 lg:p-6">
        <?php if ($flash !== ''): ?>
            <div class="mb-4 p-3 rounded-lg bg-teal-50 text-teal-800 text-sm"><?= htmlspecialchars($flash) ?></div>
        <?php endif; ?>
        <?php if ($flashErr !== ''): ?>
            <div class="mb-4 p-3 rounded-lg bg-red-50 text-red-800 text-sm"><?= htmlspecialchars($flashErr) ?></div>
        <?php endif; ?>

        <?php if ($detailBundle): ?>
            <?php $rec = $detailBundle['record']; ?>
            <?php if ($editMode): ?>
            <div class="bg-white rounded-xl shadow border border-gray-100 overflow-hidden">
                <div class="px-4 py-4 bg-gradient-to-r from-teal-50 to-cyan-50 border-b border-gray-100">
                    <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2"><i class="fas fa-edit text-teal-600"></i> Edit receiving #<?= (int) $rec['id'] ?></h2>
                </div>
                <form method="post" class="p-4 lg:p-6">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="record_id" value="<?= (int) $rec['id'] ?>">
                    <input type="hidden" name="filter_date_from" value="<?= htmlspecialchars($filters['date_from']) ?>">
                    <input type="hidden" name="filter_date_to" value="<?= htmlspecialchars($filters['date_to']) ?>">
                    <input type="hidden" name="filter_supplier_id" value="<?= htmlspecialchars((string) ($filters['supplier_id'] ?? '')) ?>">
                    <input type="hidden" name="filter_search" value="<?= htmlspecialchars($filters['search']) ?>">
                    <?php if (!empty($rec['purchase_order_id'])): ?>
                    <input type="hidden" name="purchase_order_id" value="<?= (int) $rec['purchase_order_id'] ?>">
                    <?php endif; ?>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
                        <div>
                            <label class="block text-xs font-medium text-gray-500 uppercase mb-1">Receiving date</label>
                            <input type="datetime-local" name="receiving_date" value="<?= htmlspecialchars(date('Y-m-d\TH:i', strtotime($rec['receiving_date']))) ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" required>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 uppercase mb-1">Supplier</label>
                            <select name="supplier_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                                <option value="">— None —</option>
                                <?php foreach ($suppliers as $s): ?>
                                    <option value="<?= (int) $s['id'] ?>" <?= (int) ($rec['supplier_id'] ?? 0) === (int) $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="overflow-x-auto border border-gray-200 rounded-lg mb-4">
                        <table class="min-w-full text-sm divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Product</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Qty added</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Cost price</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                            <?php foreach ($detailBundle['items'] as $li): ?>
                                <tr>
                                    <td class="px-4 py-3 text-gray-900">
                                        <?= htmlspecialchars($li['product_name']) ?>
                                        <input type="hidden" name="line_id[]" value="<?= (int) $li['id'] ?>">
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <input type="number" name="line_qty[]" value="<?= (int) $li['quantity_added'] ?>" min="0" step="1" class="w-24 border border-gray-300 rounded-lg px-2 py-1.5 text-right text-sm">
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <input type="number" name="line_cost[]" value="<?= htmlspecialchars((string) $li['buying_price']) ?>" min="0" step="0.01" class="w-28 border border-gray-300 rounded-lg px-2 py-1.5 text-right text-sm">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="text-xs text-gray-600 mb-4">Changing quantities adjusts product stock automatically.</p>
                    <div class="flex flex-wrap gap-2 justify-end">
                        <a href="?<?= htmlspecialchars($rrListQuery(['id' => $recordId])) ?>" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 bg-white hover:bg-gray-50">Cancel</a>
                        <button type="submit" class="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white rounded-lg text-sm font-medium">Save changes</button>
                    </div>
                </form>
            </div>
            <?php else: ?>
            <div class="bg-white rounded-xl shadow border border-gray-100 overflow-hidden mb-6">
                <div class="px-4 py-4 bg-gradient-to-r from-teal-50 to-cyan-50 border-b border-gray-100">
                    <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2"><i class="fas fa-dolly text-teal-600"></i> Receiving batch</h2>
                    <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 text-sm">
                        <div>
                            <div class="text-xs font-medium text-gray-500 uppercase">Date</div>
                            <div class="text-gray-800"><?= htmlspecialchars(date('Y-m-d H:i', strtotime($rec['receiving_date']))) ?></div>
                        </div>
                        <div>
                            <div class="text-xs font-medium text-gray-500 uppercase">Received by</div>
                            <div class="text-gray-800"><?= htmlspecialchars($rec['username']) ?></div>
                        </div>
                        <div>
                            <div class="text-xs font-medium text-gray-500 uppercase">Supplier</div>
                            <div class="text-gray-800"><?= htmlspecialchars($rec['supplier_name'] ?? '—') ?></div>
                        </div>
                        <div>
                            <div class="text-xs font-medium text-gray-500 uppercase">Purchase order</div>
                            <div class="text-gray-800">
                                <?php if (!empty($rec['po_number'])): ?>
                                    <a href="purchase_orders.php?id=<?= (int) ($rec['purchase_order_id'] ?? 0) ?>" class="text-teal-700 hover:text-teal-900 font-medium"><?= htmlspecialchars($rec['po_number']) ?></a>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </div>
                        </div>
                        <div>
                            <div class="text-xs font-medium text-gray-500 uppercase">Items</div>
                            <div class="text-gray-800"><?= (int) $rec['total_items'] ?></div>
                        </div>
                        <div>
                            <div class="text-xs font-medium text-gray-500 uppercase">Total qty</div>
                            <div class="text-gray-800"><?= (int) $rec['total_quantity'] ?></div>
                        </div>
                        <div>
                            <div class="text-xs font-medium text-gray-500 uppercase">Restock value</div>
                            <div class="text-gray-800">N$<?= number_format((float) $rec['total_value'], 2) ?></div>
                        </div>
                        <div>
                            <div class="text-xs font-medium text-gray-500 uppercase">Total cost</div>
                            <div class="text-gray-900 font-semibold">N$<?= number_format((float) $rec['total_cost'], 2) ?></div>
                        </div>
                        <div>
                            <div class="text-xs font-medium text-gray-500 uppercase">Email status</div>
                            <div class="text-gray-800"><?= htmlspecialchars($rec['email_status'] ?? 'pending') ?></div>
                        </div>
                    </div>
                </div>
                <div class="px-4 py-3 border-b border-gray-100 flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-lg font-semibold text-gray-800">Line items</h3>
                    <form method="post" class="inline" onsubmit="return confirm('Delete this receiving record? Stock quantities will be reversed.');">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="record_id" value="<?= (int) $rec['id'] ?>">
                        <input type="hidden" name="filter_date_from" value="<?= htmlspecialchars($filters['date_from']) ?>">
                        <input type="hidden" name="filter_date_to" value="<?= htmlspecialchars($filters['date_to']) ?>">
                        <input type="hidden" name="filter_supplier_id" value="<?= htmlspecialchars((string) ($filters['supplier_id'] ?? '')) ?>">
                        <input type="hidden" name="filter_search" value="<?= htmlspecialchars($filters['search']) ?>">
                        <button type="submit" class="inline-flex items-center px-3 py-1.5 bg-red-600 hover:bg-red-700 text-white rounded-lg text-sm font-medium">
                            <i class="fas fa-trash mr-2"></i> Delete record
                        </button>
                    </form>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Product</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Added</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Old → New</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Sell price</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Cost price</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Line value</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Line cost</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                        <?php foreach ($detailBundle['items'] as $li): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 font-medium text-gray-900"><?= htmlspecialchars($li['product_name']) ?></td>
                                <td class="px-4 py-3 text-right text-teal-700 font-medium">+<?= (int) $li['quantity_added'] ?></td>
                                <td class="px-4 py-3 text-right text-gray-600"><?= (int) $li['old_quantity'] ?> → <?= (int) $li['new_quantity'] ?></td>
                                <td class="px-4 py-3 text-right text-gray-700">N$<?= number_format((float) $li['unit_price'], 2) ?></td>
                                <td class="px-4 py-3 text-right text-gray-700">N$<?= number_format((float) $li['buying_price'], 2) ?></td>
                                <td class="px-4 py-3 text-right text-gray-800">N$<?= number_format((float) $li['total_value'], 2) ?></td>
                                <td class="px-4 py-3 text-right text-gray-800">N$<?= number_format((float) $li['total_cost'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot class="bg-gray-50">
                            <tr>
                                <td colspan="5" class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Totals</td>
                                <td class="px-4 py-3 text-right font-semibold text-gray-900">N$<?= number_format((float) $rec['total_value'], 2) ?></td>
                                <td class="px-4 py-3 text-right font-semibold text-gray-900">N$<?= number_format((float) $rec['total_cost'], 2) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <?php endif; ?>

        <?php else: ?>
        <form method="get" class="bg-white rounded-xl shadow border border-gray-100 p-4 mb-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
            <div>
                <label class="block text-xs font-medium text-gray-500 uppercase mb-1">From</label>
                <input type="date" name="date_from" value="<?= htmlspecialchars($filters['date_from']) ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 uppercase mb-1">To</label>
                <input type="date" name="date_to" value="<?= htmlspecialchars($filters['date_to']) ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 uppercase mb-1">Supplier</label>
                <select name="supplier_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    <option value="">All suppliers</option>
                    <?php foreach ($suppliers as $s): ?>
                        <option value="<?= (int) $s['id'] ?>" <?= ($filters['supplier_id'] ?? 0) === (int) $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 uppercase mb-1">Search</label>
                <input type="search" name="search" value="<?= htmlspecialchars($filters['search']) ?>" placeholder="User or product" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div class="flex items-end gap-2">
                <button type="submit" class="flex-1 px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white rounded-lg text-sm font-medium">Filter</button>
                <a href="receiving_records.php" class="px-3 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 bg-white hover:bg-gray-50">Reset</a>
            </div>
        </form>

        <div class="bg-white rounded-xl shadow border border-gray-100 overflow-hidden">
            <div class="px-4 py-3 bg-gradient-to-r from-teal-50 to-cyan-50 border-b border-gray-100 flex justify-between items-center">
                <h2 class="font-semibold text-gray-800 flex items-center gap-2"><i class="fas fa-clipboard-list text-teal-600"></i> <?= (int) $list['total'] ?> record(s)</h2>
            </div>
            <div id="rrBulkBar" class="bulk-actions-bar hidden px-4 py-3 bg-teal-50 border-b border-teal-100 flex flex-wrap items-center justify-between gap-3">
                <span id="rrSelectedCount" class="text-sm font-medium text-gray-700">0 selected</span>
                <div class="flex flex-wrap gap-2">
                    <button type="button" id="rrViewBtn" class="inline-flex items-center px-3 py-1.5 bg-teal-600 hover:bg-teal-700 text-white rounded-lg text-sm font-medium" onclick="viewSelectedRecord()">
                        <i class="fas fa-eye mr-2"></i> View
                    </button>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left w-10">
                                <input type="checkbox" id="rrSelectAll" class="rr-row-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500" title="Select all" onclick="event.stopPropagation()">
                            </th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">By</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Supplier</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">PO</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Qty</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Value</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Cost</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                    <?php if (empty($records)): ?>
                        <tr><td colspan="11" class="px-4 py-8 text-center text-gray-500">No receiving records found.</td></tr>
                    <?php else: foreach ($records as $rec): ?>
                        <tr class="rr-selectable-row" data-record-id="<?= (int) $rec['id'] ?>" onclick="handleRecordRowClick(event, <?= (int) $rec['id'] ?>)">
                            <td class="px-4 py-3" onclick="event.stopPropagation()">
                                <input type="checkbox" value="<?= (int) $rec['id'] ?>" class="rr-row-checkbox rr-record-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500">
                            </td>
                            <td class="px-4 py-3 font-medium text-gray-900">#<?= (int) $rec['id'] ?></td>
                            <td class="px-4 py-3 whitespace-nowrap text-gray-700"><?= htmlspecialchars(date('Y-m-d H:i', strtotime($rec['receiving_date']))) ?></td>
                            <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars($rec['username']) ?></td>
                            <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars($rec['supplier_name'] ?? '—') ?></td>
                            <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars($rec['po_number'] ?: '—') ?></td>
                            <td class="px-4 py-3 text-right text-gray-800"><?= (int) $rec['total_quantity'] ?></td>
                            <td class="px-4 py-3 text-right text-gray-800">N$<?= number_format((float) $rec['total_value'], 2) ?></td>
                            <td class="px-4 py-3 text-right text-gray-800">N$<?= number_format((float) $rec['total_cost'], 2) ?></td>
                            <td class="px-4 py-3"><span class="text-xs px-2 py-0.5 rounded-full bg-gray-100 text-gray-700"><?= htmlspecialchars($rec['email_status'] ?? 'pending') ?></span></td>
                            <td class="px-4 py-3 text-right whitespace-nowrap" onclick="event.stopPropagation()">
                                <a href="?<?= htmlspecialchars($rrListQuery(['id' => (int) $rec['id']])) ?>" class="text-teal-700 hover:text-teal-900 font-medium mr-3">View</a>
                                <a href="?<?= htmlspecialchars($rrListQuery(['id' => (int) $rec['id'], 'edit' => 1])) ?>" class="text-gray-700 hover:text-gray-900 font-medium mr-3">Edit</a>
                                <a href="?action=pdf&amp;id=<?= (int) $rec['id'] ?>" class="text-gray-700 hover:text-gray-900 font-medium mr-3">PDF</a>
                                <form method="post" class="inline" onsubmit="return confirm('Delete this receiving record? Stock quantities will be reversed.');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="record_id" value="<?= (int) $rec['id'] ?>">
                                    <input type="hidden" name="filter_date_from" value="<?= htmlspecialchars($filters['date_from']) ?>">
                                    <input type="hidden" name="filter_date_to" value="<?= htmlspecialchars($filters['date_to']) ?>">
                                    <input type="hidden" name="filter_supplier_id" value="<?= htmlspecialchars((string) ($filters['supplier_id'] ?? '')) ?>">
                                    <input type="hidden" name="filter_search" value="<?= htmlspecialchars($filters['search']) ?>">
                                    <button type="submit" class="text-red-600 hover:text-red-800 font-medium">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </main>
</div>

<?php if (!$detailBundle): ?>
<script>
const RR_LIST_QUERY = <?= json_encode($rrListQuery()) ?>;

function recordViewUrl(id) {
    const base = 'receiving_records.php?id=' + encodeURIComponent(id);
    return RR_LIST_QUERY ? base + '&' + RR_LIST_QUERY : base;
}

function handleRecordRowClick(event, id) {
    if (event.target.closest('a, button, input, select, textarea, label, .rr-row-checkbox')) return;
    window.location.href = recordViewUrl(id);
}

function viewSelectedRecord() {
    const checked = document.querySelectorAll('.rr-record-checkbox:checked');
    if (checked.length !== 1) {
        alert('Select exactly one record to view.');
        return;
    }
    window.location.href = recordViewUrl(checked[0].value);
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
        if (countEl) countEl.textContent = checked.length + ' selected';
        boxes.forEach(cb => {
            const row = cb.closest('tr');
            if (row) row.classList.toggle('rr-selected-row', cb.checked);
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
        if (e.target.classList.contains(checkboxClass)) updateBulkUI();
    });
}

document.addEventListener('DOMContentLoaded', () => {
    initBulkTable('rrSelectAll', 'rr-record-checkbox', 'rrBulkBar', 'rrSelectedCount');
});
</script>
<?php endif; ?>
</body>
</html>
