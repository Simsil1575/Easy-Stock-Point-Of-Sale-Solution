<?php
/**
 * Product categories hub — set $categoriesRoleFolder = 'admin'|'manager' before include,
 * or rely on $roleFolder / default admin.
 */
if (!isset($categoriesRoleFolder)) {
    $categoriesRoleFolder = isset($roleFolder) ? $roleFolder : 'admin';
}

session_start();
date_default_timezone_set('Africa/Harare');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    header('Location: ../');
    exit();
}

$pdo = new PDO('sqlite:../active.db');
$activationStatus = $pdo->query("SELECT COUNT(*) FROM software_keys WHERE is_used = 1")->fetchColumn();
if ($activationStatus == 0) {
    header('Location: settings');
    exit();
}

try {
    $db = new PDO('sqlite:../pos.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('Database connection failed: ' . $e->getMessage());
}

require_once __DIR__ . '/categories_lib.php';

$flash = (string) ($_SESSION['cat_flash'] ?? '');
$flashErr = (string) ($_SESSION['cat_flash_err'] ?? '');
unset($_SESSION['cat_flash'], $_SESSION['cat_flash_err']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $redirectCategory = trim((string) ($_POST['category'] ?? ''));
    $redirect = 'categories';
    if ($redirectCategory !== '') {
        $redirect .= '?' . http_build_query(['category' => $redirectCategory]);
    }
    try {
        if ($action === 'add_category') {
            catCreate($db, (string) ($_POST['category_name'] ?? ''));
            $_SESSION['cat_flash'] = 'Category added successfully.';
            header('Location: categories');
            exit();
        }
        if ($action === 'assign_products' && $redirectCategory !== '') {
            $productIds = $_POST['product_ids'] ?? [];
            if (!is_array($productIds)) {
                $productIds = [$productIds];
            }
            $canonical = catFindByName($db, $redirectCategory);
            $categoryName = $canonical ? $canonical['category'] : $redirectCategory;
            $count = catAssignProducts($db, $categoryName, $productIds);
            if ($count === 0) {
                throw new RuntimeException('No products were added. Please select products and try again.');
            }
            $_SESSION['cat_flash'] = $count . ' product(s) added to category.';
            $redirectCategory = $categoryName;
        } elseif ($action === 'remove_products' && $redirectCategory !== '') {
            $count = catRemoveProducts($db, (array) ($_POST['product_ids'] ?? []));
            $_SESSION['cat_flash'] = $count . ' product(s) removed from category.';
        } else {
            throw new RuntimeException('Invalid action.');
        }
    } catch (Throwable $e) {
        $_SESSION['cat_flash_err'] = $e->getMessage();
    }
    header('Location: ' . $redirect);
    exit();
}

$categories = [];
try {
    $categories = catListMerged($db);
} catch (PDOException $e) {
    $categories = [];
}

$viewCategory = trim((string) ($_GET['category'] ?? ''));
$viewCategoryRow = $viewCategory !== '' ? catFindByName($db, $viewCategory) : null;
if ($viewCategory !== '' && !$viewCategoryRow) {
    $_SESSION['cat_flash_err'] = 'Category not found.';
    header('Location: categories');
    exit();
}
$categoryProducts = $viewCategoryRow ? catListProductsInCategory($db, $viewCategoryRow['category']) : [];
$pickerProducts = $viewCategoryRow ? catListProductsForPicker($db, $viewCategoryRow['category']) : [];

$uncategorizedCount = 0;
try {
    $uncategorizedCount = (int) $db->query("
        SELECT COUNT(*) FROM products
        WHERE category IS NULL OR TRIM(category) = ''
    ")->fetchColumn();
} catch (PDOException $e) {
    $uncategorizedCount = 0;
}

$totalProducts = array_sum(array_map(function ($row) {
    return (int) $row['product_count'];
}, $categories)) + $uncategorizedCount;

$pageTitle = 'Categories';
$menuBackHref = $categoriesRoleFolder === 'manager' ? 'manager-center' : 'admin-center';
$today = date('Y-m-d');
$monthStart = date('Y-m-01');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Categories - POS System</title>
    <script src="../navigation.js" async></script>
    <link href="../src/output.css" rel="stylesheet">
    <link rel="icon" href="../favicon.ico" type="image/png">
    <link rel="stylesheet" href="../src/font-awesome/css/all.min.css">
    <style>
        .cat-table-row { cursor: pointer; transition: background-color 0.15s ease; }
        .cat-table-row:hover { background-color: #f9fafb; }
        .cat-table-row.cat-selected-row { background-color: #f0fdfa !important; }
        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 9999; display: flex; align-items: center; justify-content: center; opacity: 0; visibility: hidden; transition: all 0.2s ease; }
        .modal-overlay.active { opacity: 1; visibility: visible; }
        .modal-panel { background: white; border-radius: 1rem; width: 95%; max-width: 28rem; transform: scale(0.96); transition: transform 0.2s ease; }
        .modal-panel.modal-wide { max-width: 42rem; max-height: 90vh; overflow-y: auto; }
        .modal-overlay.active .modal-panel { transform: scale(1); }
        .cat-selectable-row { cursor: pointer; }
        .cat-selectable-row:hover { background-color: #f9fafb; }
        .cat-selected-row { background-color: #f0fdfa !important; }
        .hamburger { display: flex; flex-direction: column; cursor: pointer; padding: 8px; z-index: 10000; position: relative; }
        .hamburger span { width: 25px; height: 3px; background-color: #333; margin: 3px 0; transition: 0.3s; border-radius: 2px; }
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 80; }
        .sidebar-overlay.active { display: block; }
        @media (max-width: 1023px) {
            #sidebar { transform: translateX(-100%); transition: transform 0.3s ease; }
            #sidebar.open { transform: translateX(0); }
        }
    </style>
</head>
<body class="bg-gray-100">
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>
    <div class="flex min-h-screen">
        <?php include __DIR__ . '/../' . $categoriesRoleFolder . '/sidebar.php'; ?>

        <div class="content flex-1 lg:ml-64">
            <div class="lg:hidden bg-white shadow-sm p-4 flex items-center justify-between sticky top-0 z-50">
                <a href="<?= htmlspecialchars($menuBackHref, ENT_QUOTES, 'UTF-8') ?>" class="text-teal-700 hover:text-teal-900 p-1" aria-label="Back to Menu">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <h1 class="text-lg font-semibold text-gray-800">Categories</h1>
                <div class="hamburger" onclick="toggleSidebar()">
                    <span></span><span></span><span></span>
                </div>
            </div>

            <main class="p-4 lg:p-6">
                <div class="mb-4">
                    <a href="<?= htmlspecialchars($menuBackHref, ENT_QUOTES, 'UTF-8') ?>" class="inline-flex items-center gap-2 text-sm font-medium text-teal-700 hover:text-teal-900 no-underline">
                        <i class="fas fa-arrow-left"></i> Back to Menu
                    </a>
                </div>
                <?php if ($flash !== ''): ?>
                    <div class="mb-4 p-3 rounded-lg bg-teal-50 text-teal-800 text-sm"><?= htmlspecialchars($flash) ?></div>
                <?php endif; ?>
                <?php if ($flashErr !== ''): ?>
                    <div class="mb-4 p-3 rounded-lg bg-red-50 text-red-800 text-sm"><?= htmlspecialchars($flashErr) ?></div>
                <?php endif; ?>

                <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0 flex-1">
                        <?php if ($viewCategoryRow): ?>
                            <a href="categories" class="inline-flex items-center gap-1 text-sm text-teal-700 hover:text-teal-900 mb-2">
                                <i class="fas fa-arrow-left"></i> All categories
                            </a>
                            <h1 class="text-2xl lg:text-3xl font-bold text-gray-800 mb-2"><?= htmlspecialchars($viewCategoryRow['category']) ?></h1>
                            <p class="text-gray-600"><?= (int) $viewCategoryRow['product_count'] ?> products in this category</p>
                        <?php else: ?>
                            <h1 class="text-2xl lg:text-3xl font-bold text-gray-800 mb-2">Categories</h1>
                            <p class="text-gray-600">Quick access to product categories for inventory and reports</p>
                        <?php endif; ?>
                    </div>
                    <div class="w-full sm:w-72 lg:w-80 shrink-0">
                        <label for="categorySearchInput" class="sr-only">Search categories</label>
                        <div class="relative">
                            <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm pointer-events-none"></i>
                            <input type="search" id="categorySearchInput"
                                class="w-full pl-9 pr-3 py-2.5 text-sm border border-gray-300 rounded-lg bg-white shadow-sm focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500"
                                placeholder="Search categories..."
                                autocomplete="off"
                                oninput="filterCategoryCards()">
                        </div>
                    </div>
                </div>

                <?php if ($viewCategoryRow): ?>
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-6">
                    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                        <h2 class="text-lg font-semibold text-gray-800">Products in category</h2>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" onclick="openAssignProductsModal(<?= json_encode($viewCategoryRow['category'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)"
                                class="inline-flex items-center gap-2 px-4 py-2 bg-teal-600 text-white rounded-lg text-sm font-medium hover:bg-teal-700">
                                <i class="fas fa-plus"></i> Add products
                            </button>
                            <a href="add_product?category=<?= urlencode($viewCategoryRow['category']) ?>" class="inline-flex items-center gap-2 px-3 py-2 text-sm border border-gray-200 rounded-lg text-gray-700 bg-white hover:bg-gray-50">
                                <i class="fas fa-box"></i> New product
                            </a>
                        </div>
                    </div>
                    <?php if (empty($categoryProducts)): ?>
                        <div class="text-center py-10 text-gray-500">
                            <p class="font-medium text-gray-600">No products in this category yet</p>
                            <p class="text-sm mt-1 mb-4">Add existing products or create a new one.</p>
                            <button type="button" onclick="openAssignProductsModal(<?= json_encode($viewCategoryRow['category'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)"
                                class="inline-flex items-center gap-2 px-4 py-2 bg-teal-600 text-white rounded-lg text-sm font-medium hover:bg-teal-700">
                                <i class="fas fa-plus"></i> Add products
                            </button>
                        </div>
                    <?php else: ?>
                        <form method="post" id="removeProductsForm">
                            <input type="hidden" name="action" value="remove_products">
                            <input type="hidden" name="category" value="<?= htmlspecialchars($viewCategoryRow['category'], ENT_QUOTES, 'UTF-8') ?>">
                            <div class="overflow-x-auto border border-gray-200 rounded-lg">
                                <table class="min-w-full text-sm divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-3 py-2 w-10"><input type="checkbox" id="selectAllInCategory" class="rounded border-gray-300 text-teal-600 focus:ring-teal-500"></th>
                                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Product</th>
                                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Qty</th>
                                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Price</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                    <?php foreach ($categoryProducts as $p): ?>
                                        <tr class="cat-selectable-row" onclick="toggleProductRowCheckbox(event, this)">
                                            <td class="px-3 py-2" onclick="event.stopPropagation()">
                                                <input type="checkbox" name="product_ids[]" value="<?= (int) $p['id'] ?>" class="cat-product-cb rounded border-gray-300 text-teal-600 focus:ring-teal-500">
                                            </td>
                                            <td class="px-3 py-2 font-medium text-gray-900"><?= htmlspecialchars($p['name']) ?></td>
                                            <td class="px-3 py-2 text-right text-gray-700"><?= number_format((float) $p['quantity'], 0) ?></td>
                                            <td class="px-3 py-2 text-right text-gray-700">N$<?= number_format((float) $p['price'], 2) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="mt-3 flex justify-end">
                                <button type="submit" class="px-3 py-1.5 text-sm border border-red-200 text-red-700 rounded-lg hover:bg-red-50"
                                    onclick="return confirm('Remove selected products from this category?');">
                                    Remove selected
                                </button>
                            </div>
                        </form>
                    <?php endif; ?>

                    <?php if (!empty($pickerProducts)): ?>
                    <div id="inlineAssignProducts" class="mt-6 pt-6 border-t border-gray-100">
                        <h3 class="text-sm font-semibold text-gray-800 mb-3">Add existing products</h3>
                        <form method="post" action="categories" class="space-y-3">
                            <input type="hidden" name="action" value="assign_products">
                            <input type="hidden" name="category" value="<?= htmlspecialchars($viewCategoryRow['category'], ENT_QUOTES, 'UTF-8') ?>">
                            <div class="border border-gray-200 rounded-lg max-h-64 overflow-y-auto divide-y divide-gray-100">
                                <?php foreach ($pickerProducts as $p): ?>
                                <label class="flex items-center gap-3 px-3 py-2 hover:bg-gray-50 cursor-pointer">
                                    <input type="checkbox" name="product_ids[]" value="<?= (int) $p['id'] ?>" class="rounded border-gray-300 text-teal-600 focus:ring-teal-500">
                                    <span class="flex-1 min-w-0">
                                        <span class="block text-sm font-medium text-gray-900 truncate"><?= htmlspecialchars($p['name']) ?></span>
                                        <?php if ($p['category'] !== ''): ?>
                                        <span class="block text-xs text-gray-500">Currently: <?= htmlspecialchars($p['category']) ?></span>
                                        <?php else: ?>
                                        <span class="block text-xs text-gray-500">Uncategorized</span>
                                        <?php endif; ?>
                                    </span>
                                    <span class="text-xs text-gray-500 shrink-0">N$<?= number_format((float) $p['price'], 2) ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                            <div class="flex justify-end">
                                <button type="submit" class="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white rounded-lg text-sm font-medium">Add selected to category</button>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
                <?php else: ?>

                <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
                    <div class="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                        <p class="text-xs text-gray-500 mb-1">Categories</p>
                        <p class="text-2xl font-bold text-gray-800"><?= count($categories) ?></p>
                    </div>
                    <div class="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                        <p class="text-xs text-gray-500 mb-1">Products</p>
                        <p class="text-2xl font-bold text-gray-800"><?= (int) $totalProducts ?></p>
                    </div>
                    <div class="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                        <p class="text-xs text-gray-500 mb-1">Uncategorized</p>
                        <p class="text-2xl font-bold text-gray-800"><?= (int) $uncategorizedCount ?></p>
                    </div>
                    <a href="reports-center" class="bg-teal-50 hover:bg-teal-100 rounded-xl border border-teal-100 p-4 shadow-sm transition-colors no-underline">
                        <p class="text-xs text-teal-700 mb-1">Reports</p>
                        <p class="text-sm font-semibold text-teal-800 flex items-center gap-2">
                            Open Reports Center
                            <i class="fas fa-arrow-right text-xs"></i>
                        </p>
                    </a>
                </div>

                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                        <h2 class="text-lg font-semibold text-gray-800">Easy category access</h2>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" onclick="openAddCategoryModal()"
                                class="inline-flex items-center gap-2 px-4 py-2 bg-teal-600 text-white rounded-lg text-sm font-medium hover:bg-teal-700">
                                <i class="fas fa-plus"></i> Add category
                            </button>
                            <a href="inventory" class="inline-flex items-center px-3 py-2 text-sm text-teal-700 hover:text-teal-900 font-medium border border-gray-200 rounded-lg bg-white hover:bg-gray-50">Open inventory</a>
                        </div>
                    </div>

                    <?php if (empty($categories)): ?>
                        <div class="text-center py-12 text-gray-500">
                            <i class="fas fa-tags text-4xl mb-3 text-gray-300"></i>
                            <p class="font-medium text-gray-600">No categories yet</p>
                            <p class="text-sm mt-1 mb-4">Create a category here, then assign products in inventory or add product.</p>
                            <button type="button" onclick="openAddCategoryModal()"
                                class="inline-flex items-center gap-2 px-4 py-2 bg-teal-600 text-white rounded-lg text-sm font-medium hover:bg-teal-700">
                                <i class="fas fa-plus"></i> Add category
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto border border-gray-200 rounded-lg">
                            <table class="min-w-full text-sm divide-y divide-gray-200" id="categoriesTable">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">Category</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wide">Products</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wide">Units</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wide">Stock value</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wide">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="categoriesGrid" class="divide-y divide-gray-100 bg-white">
                            <?php foreach ($categories as $row):
                                $name = $row['category'];
                                $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
                                $jsName = json_encode($name, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                                $viewUrl = '?category=' . urlencode($name);
                                $stockReportUrl = 'generate_report_pdf.php?' . http_build_query([
                                    'report_type' => 'current_stock',
                                    'category' => $name,
                                    'start_date' => $today,
                                    'end_date' => $today,
                                ]);
                                $salesReportUrl = 'generate_report_pdf.php?' . http_build_query([
                                    'report_type' => 'item_sales',
                                    'category' => $name,
                                    'start_date' => $monthStart,
                                    'end_date' => $today,
                                ]);
                            ?>
                                    <tr class="cat-table-row" data-category-name="<?= $safeName ?>" onclick="handleCategoryRowClick(event, <?= $jsName ?>)">
                                        <td class="px-4 py-3">
                                            <div class="flex items-center gap-3 min-w-0">
                                                <div class="w-9 h-9 bg-teal-100 rounded-lg flex items-center justify-center shrink-0">
                                                    <i class="fas fa-tags text-teal-600 text-sm"></i>
                                                </div>
                                                <div class="min-w-0">
                                                    <a href="<?= htmlspecialchars($viewUrl, ENT_QUOTES, 'UTF-8') ?>"
                                                        class="font-medium text-gray-900 hover:text-teal-700 no-underline truncate block"
                                                        title="<?= $safeName ?>"><?= $safeName ?></a>
                                                    <?php if (!empty($row['registered'])): ?>
                                                    <span class="text-xs text-gray-500">Registered category</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3 text-right text-gray-700 whitespace-nowrap"><?= (int) $row['product_count'] ?></td>
                                        <td class="px-4 py-3 text-right text-gray-700 whitespace-nowrap"><?= number_format((float) $row['total_qty'], 0) ?></td>
                                        <td class="px-4 py-3 text-right text-gray-700 whitespace-nowrap">N$<?= number_format((float) $row['stock_value'], 2) ?></td>
                                        <td class="px-4 py-3 text-right whitespace-nowrap" onclick="event.stopPropagation()">
                                            <div class="inline-flex flex-wrap justify-end gap-1.5">
                                                <button type="button"
                                                    class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-medium rounded-lg bg-teal-600 text-white hover:bg-teal-700"
                                                    onclick='openAssignProductsModal(<?= $jsName ?>)' title="Add products">
                                                    <i class="fas fa-plus"></i><span class="hidden sm:inline">Add</span>
                                                </button>
                                                <a href="<?= htmlspecialchars($viewUrl, ENT_QUOTES, 'UTF-8') ?>"
                                                    class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-medium rounded-lg bg-white border border-gray-200 text-gray-700 hover:bg-gray-100 no-underline"
                                                    title="View category">
                                                    <i class="fas fa-eye"></i><span class="hidden sm:inline">View</span>
                                                </a>
                                                <button type="button"
                                                    class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-medium rounded-lg bg-white border border-gray-200 text-gray-700 hover:bg-gray-100"
                                                    onclick='openInventoryCategory(<?= $jsName ?>)' title="Open inventory">
                                                    <i class="fas fa-boxes"></i><span class="hidden sm:inline">Stock</span>
                                                </button>
                                                <a href="<?= htmlspecialchars($stockReportUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank"
                                                    class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-medium rounded-lg bg-white border border-gray-200 text-gray-700 hover:bg-gray-100 no-underline"
                                                    title="Stock report">
                                                    <i class="fas fa-clipboard-list"></i>
                                                </a>
                                                <a href="<?= htmlspecialchars($salesReportUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank"
                                                    class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-medium rounded-lg bg-teal-600 text-white hover:bg-teal-700 no-underline"
                                                    title="Sales report">
                                                    <i class="fas fa-chart-pie"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                            <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div id="categorySearchEmpty" class="hidden text-center py-10 text-gray-500 border border-gray-200 rounded-lg mt-0">
                            <i class="fas fa-search text-3xl mb-3 text-gray-300"></i>
                            <p class="font-medium text-gray-600">No categories match your search</p>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <div class="modal-overlay" id="assignProductsModal" onclick="if(event.target===this)closeAssignProductsModal()">
        <div class="modal-panel modal-wide p-6 shadow-xl" role="dialog" aria-labelledby="assignProductsTitle">
            <div class="flex items-start justify-between mb-4">
                <div>
                    <h3 id="assignProductsTitle" class="text-lg font-semibold text-gray-900">Add products to category</h3>
                    <p id="assignProductsCategoryLabel" class="text-sm text-gray-600 mt-1"></p>
                </div>
                <button type="button" class="text-gray-400 hover:text-gray-600" onclick="closeAssignProductsModal()" aria-label="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="post" action="categories" id="assignProductsForm" onsubmit="return validateAssignProductsForm()">
                <input type="hidden" name="action" value="assign_products">
                <input type="hidden" name="category" id="assignProductsCategoryInput" value="<?= $viewCategoryRow ? htmlspecialchars($viewCategoryRow['category'], ENT_QUOTES, 'UTF-8') : '' ?>">
                <input type="search" id="assignProductSearch" placeholder="Search products..."
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm mb-3 focus:outline-none focus:ring-2 focus:ring-teal-500"
                    oninput="filterAssignProductRows()"
                    onkeydown="if(event.key==='Enter'){event.preventDefault();}">
                <div class="mb-2 flex items-center justify-between gap-2">
                    <label class="inline-flex items-center gap-2 text-sm text-gray-700 cursor-pointer">
                        <input type="checkbox" id="assignSelectAll" class="rounded border-gray-300 text-teal-600 focus:ring-teal-500" onchange="toggleAssignSelectAll(this.checked)">
                        Select all shown
                    </label>
                    <span id="assignPickerCount" class="text-xs text-gray-500"></span>
                </div>
                <div id="assignProductsList" class="border border-gray-200 rounded-lg max-h-80 overflow-y-auto divide-y divide-gray-100">
                    <?php foreach ($pickerProducts as $p): ?>
                    <label class="flex items-center gap-3 px-3 py-2 hover:bg-gray-50 cursor-pointer assign-product-row"
                        data-name="<?= htmlspecialchars(strtolower($p['name']), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="checkbox" name="product_ids[]" value="<?= (int) $p['id'] ?>" class="assign-product-cb rounded border-gray-300 text-teal-600 focus:ring-teal-500">
                        <span class="flex-1 min-w-0">
                            <span class="block text-sm font-medium text-gray-900 truncate"><?= htmlspecialchars($p['name']) ?></span>
                            <?php if ($p['category'] !== ''): ?>
                            <span class="block text-xs text-gray-500">Currently: <?= htmlspecialchars($p['category']) ?></span>
                            <?php else: ?>
                            <span class="block text-xs text-gray-500">Uncategorized</span>
                            <?php endif; ?>
                        </span>
                        <span class="text-xs text-gray-500 shrink-0">N$<?= number_format((float) $p['price'], 2) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <p id="assignProductsEmpty" class="hidden text-sm text-gray-500 py-4 text-center">No products available to add.</p>
                <p class="text-xs text-gray-500 mt-3">Selected products will be moved into this category.</p>
                <div class="flex justify-end gap-2 mt-4">
                    <button type="button" onclick="closeAssignProductsModal()"
                        class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 bg-white hover:bg-gray-50">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white rounded-lg text-sm font-medium">Add to category</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal-overlay" id="addCategoryModal" onclick="if(event.target===this)closeAddCategoryModal()">
        <div class="modal-panel p-6 shadow-xl" role="dialog" aria-labelledby="addCategoryTitle">
            <div class="flex items-start justify-between mb-4">
                <h3 id="addCategoryTitle" class="text-lg font-semibold text-gray-900">Add category</h3>
                <button type="button" class="text-gray-400 hover:text-gray-600" onclick="closeAddCategoryModal()" aria-label="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="post" action="categories">
                <input type="hidden" name="action" value="add_category">
                <label for="category_name" class="block text-sm font-medium text-gray-700 mb-1">Category name</label>
                <input type="text" name="category_name" id="category_name" required maxlength="120"
                    placeholder="e.g. Beverages, Grocery, Hardware"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm mb-4 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                <p class="text-xs text-gray-500 mb-4">Categories are saved for reuse when adding or editing products.</p>
                <div class="flex justify-end gap-2">
                    <button type="button" onclick="closeAddCategoryModal()"
                        class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 bg-white hover:bg-gray-50">Cancel</button>
                    <button type="submit"
                        class="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white rounded-lg text-sm font-medium">Create category</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        var allProductsForAssign = <?= json_encode(catListAllProductsForAssign($db), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        function escapeHtml(text) {
            var div = document.createElement('div');
            div.textContent = text == null ? '' : String(text);
            return div.innerHTML;
        }
        function formatPrice(value) {
            var n = Number(value);
            if (isNaN(n)) n = 0;
            return 'N$' + n.toFixed(2);
        }
        function categoryMatches(a, b) {
            return String(a || '').trim().toLowerCase() === String(b || '').trim().toLowerCase();
        }
        function renderAssignProductRows(category) {
            var list = document.getElementById('assignProductsList');
            if (!list) return;
            var products = (allProductsForAssign || []).filter(function(p) {
                return !categoryMatches(p.category, category);
            });
            products.sort(function(a, b) {
                var aUncat = !String(a.category || '').trim();
                var bUncat = !String(b.category || '').trim();
                if (aUncat !== bUncat) return aUncat ? -1 : 1;
                return String(a.name || '').localeCompare(String(b.name || ''), undefined, { sensitivity: 'base' });
            });
            list.innerHTML = products.map(function(p) {
                var current = String(p.category || '').trim();
                var sub = current ? ('Currently: ' + escapeHtml(current)) : 'Uncategorized';
                return '<label class="flex items-center gap-3 px-3 py-2 hover:bg-gray-50 cursor-pointer assign-product-row" data-name="' + escapeHtml(String(p.name || '').toLowerCase()) + '">' +
                    '<input type="checkbox" name="product_ids[]" value="' + Number(p.id) + '" class="assign-product-cb rounded border-gray-300 text-teal-600 focus:ring-teal-500">' +
                    '<span class="flex-1 min-w-0">' +
                    '<span class="block text-sm font-medium text-gray-900 truncate">' + escapeHtml(p.name) + '</span>' +
                    '<span class="block text-xs text-gray-500">' + sub + '</span>' +
                    '</span>' +
                    '<span class="text-xs text-gray-500 shrink-0">' + formatPrice(p.price) + '</span>' +
                    '</label>';
            }).join('');
            var selectAll = document.getElementById('assignSelectAll');
            if (selectAll) selectAll.checked = false;
            filterAssignProductRows();
        }
        function toggleAssignSelectAll(checked) {
            document.querySelectorAll('.assign-product-row').forEach(function(row) {
                if (row.style.display === 'none') return;
                var cb = row.querySelector('.assign-product-cb');
                if (cb) cb.checked = checked;
            });
        }
        function validateAssignProductsForm() {
            var checked = document.querySelectorAll('#assignProductsForm .assign-product-cb:checked');
            if (!checked.length) {
                alert('Please select at least one product to add.');
                return false;
            }
            return true;
        }
        function updateAssignPickerCount(visible, total) {
            var el = document.getElementById('assignPickerCount');
            if (el) el.textContent = visible + ' of ' + total + ' shown';
        }
        function toggleSidebar() {
            var sidebar = document.getElementById('sidebar');
            var overlay = document.getElementById('sidebarOverlay');
            if (!sidebar) return;
            sidebar.classList.toggle('open');
            if (overlay) overlay.classList.toggle('active');
        }
        function closeSidebar() {
            var sidebar = document.getElementById('sidebar');
            var overlay = document.getElementById('sidebarOverlay');
            if (sidebar) sidebar.classList.remove('open');
            if (overlay) overlay.classList.remove('active');
        }
        function openInventoryCategory(category) {
            try {
                sessionStorage.setItem('inventoryCategory', category || '');
            } catch (e) {}
            window.location.href = 'inventory?category=' + encodeURIComponent(category || '');
        }
        function handleCategoryRowClick(event, category) {
            if (event.target.closest('button, a, input, label')) return;
            window.location.href = 'categories?category=' + encodeURIComponent(category || '');
        }
        function filterCategoryCards() {
            var input = document.getElementById('categorySearchInput');
            var tbody = document.getElementById('categoriesGrid');
            var table = document.getElementById('categoriesTable');
            var empty = document.getElementById('categorySearchEmpty');
            if (!input || !tbody) return;
            var query = input.value.trim().toLowerCase();
            var rows = tbody.querySelectorAll('.cat-table-row');
            var visible = 0;
            rows.forEach(function(row) {
                var name = (row.getAttribute('data-category-name') || '').toLowerCase();
                var show = query === '' || name.indexOf(query) !== -1;
                row.style.display = show ? '' : 'none';
                if (show) visible++;
            });
            if (empty) empty.classList.toggle('hidden', query === '' || visible > 0);
            if (table) table.classList.toggle('hidden', query !== '' && visible === 0);
        }
        function openAddCategoryModal() {
            var modal = document.getElementById('addCategoryModal');
            var input = document.getElementById('category_name');
            if (modal) modal.classList.add('active');
            if (input) {
                input.value = '';
                setTimeout(function() { input.focus(); }, 100);
            }
        }
        function closeAddCategoryModal() {
            var modal = document.getElementById('addCategoryModal');
            if (modal) modal.classList.remove('active');
        }
        function openAssignProductsModal(category) {
            var modal = document.getElementById('assignProductsModal');
            var label = document.getElementById('assignProductsCategoryLabel');
            var input = document.getElementById('assignProductsCategoryInput');
            var form = document.getElementById('assignProductsForm');
            if (label) label.textContent = category;
            if (input) input.value = category;
            if (form) form.action = 'categories';
            renderAssignProductRows(category);
            if (modal) modal.classList.add('active');
            var search = document.getElementById('assignProductSearch');
            if (search) {
                search.value = '';
                setTimeout(function() { search.focus(); }, 100);
            }
        }
        function closeAssignProductsModal() {
            var modal = document.getElementById('assignProductsModal');
            if (modal) modal.classList.remove('active');
        }
        function filterAssignProductRows() {
            var searchEl = document.getElementById('assignProductSearch');
            var q = (searchEl && searchEl.value ? searchEl.value : '').trim().toLowerCase();
            var rows = document.querySelectorAll('.assign-product-row');
            var visible = 0;
            rows.forEach(function(row) {
                var name = row.getAttribute('data-name') || '';
                var show = q === '' || name.indexOf(q) !== -1;
                row.style.display = show ? '' : 'none';
                if (show) visible++;
            });
            var empty = document.getElementById('assignProductsEmpty');
            var list = document.getElementById('assignProductsList');
            if (empty && list) {
                empty.classList.toggle('hidden', visible > 0 || rows.length === 0);
                list.classList.toggle('hidden', rows.length === 0);
            }
            updateAssignPickerCount(visible, rows.length);
            var selectAll = document.getElementById('assignSelectAll');
            if (selectAll) selectAll.checked = false;
        }
        function toggleProductRowCheckbox(event, row) {
            if (event.target.closest('input, button, a, label')) return;
            var cb = row.querySelector('.cat-product-cb');
            if (cb) {
                cb.checked = !cb.checked;
                row.classList.toggle('cat-selected-row', cb.checked);
            }
        }
        document.addEventListener('DOMContentLoaded', function() {
            filterAssignProductRows();
            var selectAll = document.getElementById('selectAllInCategory');
            if (selectAll) {
                selectAll.addEventListener('change', function() {
                    document.querySelectorAll('.cat-product-cb').forEach(function(cb) {
                        cb.checked = selectAll.checked;
                        var row = cb.closest('tr');
                        if (row) row.classList.toggle('cat-selected-row', cb.checked);
                    });
                });
            }
            document.querySelectorAll('.cat-product-cb').forEach(function(cb) {
                cb.addEventListener('change', function() {
                    var row = cb.closest('tr');
                    if (row) row.classList.toggle('cat-selected-row', cb.checked);
                });
            });
        });
    </script>
</body>
</html>
