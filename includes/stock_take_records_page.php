<?php
/**
 * Stock take records hub — set $stockTakeRecordsRoleFolder = 'admin'|'manager' before include.
 */
if (!isset($stockTakeRecordsRoleFolder)) {
    $stockTakeRecordsRoleFolder = isset($roleFolder) ? $roleFolder : 'admin';
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

require_once __DIR__ . '/stock_take_records_lib.php';
strEnsureTables($db);

$backHref = $stockTakeRecordsRoleFolder === 'manager' ? 'manager-center' : 'admin-center';
$flash = (string) ($_SESSION['str_flash'] ?? '');
$flashErr = (string) ($_SESSION['str_flash_err'] ?? '');
unset($_SESSION['str_flash'], $_SESSION['str_flash_err']);

$filters = [
    'date_from' => trim((string) ($_GET['date_from'] ?? '')),
    'date_to' => trim((string) ($_GET['date_to'] ?? '')),
    'stock_type' => trim((string) ($_GET['stock_type'] ?? '')),
    'search' => trim((string) ($_GET['search'] ?? '')),
    'limit' => 200,
];

$strListQuery = static function (array $extra = []) use ($filters): string {
    return http_build_query(array_filter(array_merge([
        'date_from' => $filters['date_from'],
        'date_to' => $filters['date_to'],
        'stock_type' => $filters['stock_type'],
        'search' => $filters['search'],
    ], $extra), static fn($v) => $v !== '' && $v !== null));
};

if (isset($_GET['action']) && $_GET['action'] === 'pdf') {
    try {
        strOutputPdf($db, (int) ($_GET['id'] ?? 0));
    } catch (Throwable $e) {
        $_SESSION['str_flash_err'] = $e->getMessage();
        header('Location: stock_take_records.php' . ($strListQuery() !== '' ? '?' . $strListQuery() : ''));
        exit();
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'stock_take_sheet_pdf') {
    try {
        strOutputStockTakeSheetPdf(
            $db,
            (string) ($_GET['sort'] ?? 'name'),
            (string) ($_GET['category'] ?? '')
        );
    } catch (Throwable $e) {
        $_SESSION['str_flash_err'] = $e->getMessage();
        header('Location: stock_take_records.php' . ($strListQuery() !== '' ? '?' . $strListQuery() : ''));
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $recordId = (int) ($_POST['record_id'] ?? 0);
    $listQs = $strListQuery();
    try {
        if ($action === 'delete' && $recordId > 0) {
            strDeleteRecord($db, $recordId);
            $_SESSION['str_flash'] = 'Stock take record deleted and stock adjustments reversed where possible.';
            header('Location: stock_take_records.php' . ($listQs !== '' ? '?' . $listQs : ''));
            exit();
        }
        throw new RuntimeException('Invalid action.');
    } catch (Throwable $e) {
        $_SESSION['str_flash_err'] = $e->getMessage();
        header('Location: stock_take_records.php' . ($listQs !== '' ? '?' . $listQs : ''));
        exit();
    }
}

$recordId = (int) ($_GET['id'] ?? 0);
$detailBundle = $recordId > 0 ? strGetRecord($db, $recordId) : null;
if ($recordId > 0 && !$detailBundle) {
    $_SESSION['str_flash_err'] = 'Stock take record not found.';
    header('Location: stock_take_records.php' . ($strListQuery() !== '' ? '?' . $strListQuery() : ''));
    exit();
}

$list = strListRecords($db, $filters);
$records = $list['rows'];
$listHref = 'stock_take_records.php' . ($strListQuery() !== '' ? '?' . $strListQuery() : '');
$sheetCategories = strStockTakeSheetCategoryNames($db);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $detailBundle ? strStockTypeLabel($detailBundle['record']['stock_type']) . ' #' . (int) $detailBundle['record']['id'] : 'Stock Take Records' ?> - POS</title>
    <script src="../navigation.js" async></script>
    <link href="../src/output.css" rel="stylesheet">
    <link rel="icon" href="../favicon.ico" type="image/png">
    <link rel="stylesheet" href="../src/font-awesome/css/all.min.css">
    <style>
        .str-selectable-row { cursor: pointer; transition: background-color 0.15s ease; }
        .str-selectable-row:hover { background-color: #f9fafb; }
        .str-selected-row { background-color: #f0fdfa !important; box-shadow: inset 3px 0 0 #0d9488; }
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
                        <?php $rec = $detailBundle['record']; ?>
                        <h1 class="text-xl sm:text-2xl font-bold text-gray-900"><?= htmlspecialchars(strStockTypeLabel($rec['stock_type'])) ?> #<?= (int) $rec['id'] ?></h1>
                        <p class="text-gray-600 text-sm hidden sm:block"><?= htmlspecialchars(date('Y-m-d H:i', strtotime($rec['taken_at']))) ?> · <?= htmlspecialchars($rec['username']) ?></p>
                    <?php else: ?>
                        <h1 class="text-xl sm:text-2xl font-bold text-gray-900">Stock Take Records</h1>
                        <p class="text-gray-600 text-sm hidden sm:block">View, delete, and download past opening and closing stock takes</p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <form method="get" class="inline-flex flex-wrap items-center gap-2">
                    <input type="hidden" name="action" value="stock_take_sheet_pdf">
                    <select name="category" class="px-2 py-2 text-sm border border-gray-300 rounded-lg bg-white text-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 max-w-[12rem]">
                        <option value="">All categories</option>
                        <option value="__uncategorized__">Uncategorized</option>
                        <?php foreach ($sheetCategories as $sheetCategory): ?>
                            <option value="<?= htmlspecialchars((string) $sheetCategory, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $sheetCategory) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="sort" class="px-2 py-2 text-sm border border-gray-300 rounded-lg bg-white text-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        <option value="name">Alphabetical</option>
                        <option value="category">By Category</option>
                    </select>
                    <button type="submit" class="inline-flex items-center px-3 py-2 text-sm border border-gray-300 rounded-lg font-medium text-gray-700 bg-white hover:bg-gray-50">
                        <i class="fas fa-clipboard-list mr-2 text-indigo-600"></i> Stock Take Sheet
                    </button>
                </form>
                <?php if ($detailBundle): ?>
                    <a href="?action=pdf&amp;id=<?= (int) $detailBundle['record']['id'] ?>" class="inline-flex items-center px-3 py-2 text-sm border border-gray-300 rounded-lg font-medium text-gray-700 bg-white hover:bg-gray-50">
                        <i class="fas fa-file-pdf mr-2 text-teal-600"></i> <?= $detailBundle['record']['stock_type'] === 'opening' ? 'Opening Stock PDF' : 'Closing Stock PDF' ?>
                    </a>
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
            <div class="bg-white rounded-xl shadow border border-gray-100 overflow-hidden mb-6">
                <div class="px-4 py-4 bg-gradient-to-r from-indigo-50 to-teal-50 border-b border-gray-100">
                    <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
                        <i class="fas fa-clipboard-check text-indigo-600"></i>
                        <?= htmlspecialchars(strStockTypeLabel($rec['stock_type'])) ?>
                    </h2>
                    <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 text-sm">
                        <div>
                            <div class="text-xs font-medium text-gray-500 uppercase">Stock date</div>
                            <div class="text-gray-800"><?= htmlspecialchars($rec['stock_date']) ?></div>
                        </div>
                        <div>
                            <div class="text-xs font-medium text-gray-500 uppercase">Recorded at</div>
                            <div class="text-gray-800"><?= htmlspecialchars(date('Y-m-d H:i', strtotime($rec['taken_at']))) ?></div>
                        </div>
                        <div>
                            <div class="text-xs font-medium text-gray-500 uppercase">Recorded by</div>
                            <div class="text-gray-800"><?= htmlspecialchars($rec['username']) ?></div>
                        </div>
                        <div>
                            <div class="text-xs font-medium text-gray-500 uppercase">Type</div>
                            <div class="text-gray-800">
                                <span class="text-xs px-2 py-0.5 rounded-full <?= $rec['stock_type'] === 'opening' ? 'bg-blue-100 text-blue-800' : 'bg-purple-100 text-purple-800' ?>">
                                    <?= htmlspecialchars(ucfirst($rec['stock_type'])) ?>
                                </span>
                            </div>
                        </div>
                        <div>
                            <div class="text-xs font-medium text-gray-500 uppercase">Category</div>
                            <div class="text-gray-800"><?= htmlspecialchars($rec['category'] !== '' ? $rec['category'] : 'All categories') ?></div>
                        </div>
                        <div>
                            <div class="text-xs font-medium text-gray-500 uppercase">Items counted</div>
                            <div class="text-gray-800"><?= (int) $rec['total_items'] ?></div>
                        </div>
                        <div>
                            <div class="text-xs font-medium text-gray-500 uppercase">Total variance (qty)</div>
                            <div class="text-gray-800"><?= (int) $rec['total_variance'] ?></div>
                        </div>
                        <div>
                            <div class="text-xs font-medium text-gray-500 uppercase">Total value difference</div>
                            <div class="text-gray-900 font-semibold">N$<?= number_format((float) $rec['total_value_difference'], 2) ?></div>
                        </div>
                    </div>
                </div>
                <div class="px-4 py-3 border-b border-gray-100 flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-lg font-semibold text-gray-800">Line items</h3>
                    <form method="post" class="inline" onsubmit="return confirm('Delete this stock take record? Stock quantities will be reversed where possible.');">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="record_id" value="<?= (int) $rec['id'] ?>">
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
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">System qty</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Physical qty</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Variance</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Unit price</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Value diff</th>
                                <?php if ($rec['stock_type'] === 'closing'): ?>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Sold</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                        <?php foreach ($detailBundle['items'] as $li): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 font-medium text-gray-900"><?= htmlspecialchars($li['product_name']) ?></td>
                                <td class="px-4 py-3 text-right text-gray-700"><?= (int) $li['expected_quantity'] ?></td>
                                <td class="px-4 py-3 text-right text-gray-800"><?= (int) $li['actual_quantity'] ?></td>
                                <td class="px-4 py-3 text-right font-medium <?= (int) $li['variance'] < 0 ? 'text-red-700' : ((int) $li['variance'] > 0 ? 'text-teal-700' : 'text-gray-700') ?>">
                                    <?= ((int) $li['variance'] > 0 ? '+' : '') . (int) $li['variance'] ?>
                                </td>
                                <td class="px-4 py-3 text-right text-gray-700">N$<?= number_format((float) $li['unit_price'], 2) ?></td>
                                <td class="px-4 py-3 text-right text-gray-800">N$<?= number_format((float) $li['value_difference'], 2) ?></td>
                                <?php if ($rec['stock_type'] === 'closing'): ?>
                                    <td class="px-4 py-3 text-right text-gray-700"><?= (int) $li['sold_quantity'] ?></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

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
                <label class="block text-xs font-medium text-gray-500 uppercase mb-1">Type</label>
                <select name="stock_type" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    <option value="">All types</option>
                    <option value="opening" <?= $filters['stock_type'] === 'opening' ? 'selected' : '' ?>>Opening stock</option>
                    <option value="closing" <?= $filters['stock_type'] === 'closing' ? 'selected' : '' ?>>Closing stock</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 uppercase mb-1">Search</label>
                <input type="search" name="search" value="<?= htmlspecialchars($filters['search']) ?>" placeholder="User or product" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div class="flex items-end gap-2">
                <button type="submit" class="flex-1 px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white rounded-lg text-sm font-medium">Filter</button>
                <a href="stock_take_records.php" class="px-3 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 bg-white hover:bg-gray-50">Reset</a>
            </div>
        </form>

        <div class="bg-white rounded-xl shadow border border-gray-100 overflow-hidden">
            <div class="px-4 py-3 bg-gradient-to-r from-indigo-50 to-teal-50 border-b border-gray-100 flex justify-between items-center">
                <h2 class="font-semibold text-gray-800 flex items-center gap-2"><i class="fas fa-clipboard-check text-indigo-600"></i> <?= (int) $list['total'] ?> record(s)</h2>
            </div>
            <div id="strBulkBar" class="bulk-actions-bar hidden px-4 py-3 bg-teal-50 border-b border-teal-100 flex flex-wrap items-center justify-between gap-3">
                <span id="strSelectedCount" class="text-sm font-medium text-gray-700">0 selected</span>
                <button type="button" class="inline-flex items-center px-3 py-1.5 bg-teal-600 hover:bg-teal-700 text-white rounded-lg text-sm font-medium" onclick="viewSelectedRecord()">
                    <i class="fas fa-eye mr-2"></i> View
                </button>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left w-10">
                                <input type="checkbox" id="strSelectAll" class="str-row-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500" title="Select all" onclick="event.stopPropagation()">
                            </th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">By</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Category</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Items</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Variance</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Value diff</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                    <?php if (empty($records)): ?>
                        <tr><td colspan="10" class="px-4 py-8 text-center text-gray-500">No stock take records found.</td></tr>
                    <?php else: foreach ($records as $rec): ?>
                        <tr class="str-selectable-row" data-record-id="<?= (int) $rec['id'] ?>" onclick="handleRecordRowClick(event, <?= (int) $rec['id'] ?>)">
                            <td class="px-4 py-3" onclick="event.stopPropagation()">
                                <input type="checkbox" value="<?= (int) $rec['id'] ?>" class="str-row-checkbox str-record-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500">
                            </td>
                            <td class="px-4 py-3 font-medium text-gray-900">#<?= (int) $rec['id'] ?></td>
                            <td class="px-4 py-3 whitespace-nowrap text-gray-700"><?= htmlspecialchars(date('Y-m-d H:i', strtotime($rec['taken_at']))) ?></td>
                            <td class="px-4 py-3">
                                <span class="text-xs px-2 py-0.5 rounded-full <?= $rec['stock_type'] === 'opening' ? 'bg-blue-100 text-blue-800' : 'bg-purple-100 text-purple-800' ?>">
                                    <?= htmlspecialchars(ucfirst($rec['stock_type'])) ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars($rec['username']) ?></td>
                            <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars($rec['category'] !== '' ? $rec['category'] : '—') ?></td>
                            <td class="px-4 py-3 text-right text-gray-800"><?= (int) $rec['total_items'] ?></td>
                            <td class="px-4 py-3 text-right text-gray-800"><?= (int) $rec['total_variance'] ?></td>
                            <td class="px-4 py-3 text-right text-gray-800">N$<?= number_format((float) $rec['total_value_difference'], 2) ?></td>
                            <td class="px-4 py-3 text-right whitespace-nowrap" onclick="event.stopPropagation()">
                                <a href="?<?= htmlspecialchars($strListQuery(['id' => (int) $rec['id']])) ?>" class="text-teal-700 hover:text-teal-900 font-medium mr-3">View</a>
                                <a href="?action=pdf&amp;id=<?= (int) $rec['id'] ?>" class="text-gray-700 hover:text-gray-900 font-medium mr-3">PDF</a>
                                <form method="post" class="inline" onsubmit="return confirm('Delete this stock take record? Stock quantities will be reversed where possible.');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="record_id" value="<?= (int) $rec['id'] ?>">
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
const STR_LIST_QUERY = <?= json_encode($strListQuery()) ?>;

function recordViewUrl(id) {
    const base = 'stock_take_records.php?id=' + encodeURIComponent(id);
    return STR_LIST_QUERY ? base + '&' + STR_LIST_QUERY : base;
}

function handleRecordRowClick(event, id) {
    if (event.target.closest('a, button, input, select, textarea, label, .str-row-checkbox')) return;
    window.location.href = recordViewUrl(id);
}

function viewSelectedRecord() {
    const checked = document.querySelectorAll('.str-record-checkbox:checked');
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
            if (row) row.classList.toggle('str-selected-row', cb.checked);
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
    initBulkTable('strSelectAll', 'str-record-checkbox', 'strBulkBar', 'strSelectedCount');
});
</script>
<?php endif; ?>
</body>
</html>
