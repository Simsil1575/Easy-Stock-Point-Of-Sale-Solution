<?php
session_start();

// Set timezone to Central Africa Time (CAT)
date_default_timezone_set('Africa/Harare');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    // Redirect to login page if not logged in
    header("Location: ");
    exit();
}


$pdo = new PDO('sqlite:active.db');
$activationStatus = $pdo->query("SELECT COUNT(*) FROM software_keys WHERE is_used = 1")->fetchColumn();
if ($activationStatus == 0) {
    header('Location: settings');
    exit();
}

// Use path relative to this script so we always use the same pos.db
$posDb = __DIR__ . '/pos.db';
$db = new PDO('sqlite:' . $posDb);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function ensureDamagedGoodsTable(PDO $db) {
    $db->exec("CREATE TABLE IF NOT EXISTS damaged_goods (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        quantity INTEGER NOT NULL,
        reason TEXT,
        date DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
}
ensureDamagedGoodsTable($db);
require_once __DIR__ . '/ensure_stock_changes_username.php';
ensureStockChangesUsernameColumn($db);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $product_id = $_POST['product_id'];
    $quantity = $_POST['quantity'];
    $reason = $_POST['reason'];
    $damageDate = isset($_POST['date']) ? preg_replace('/[^0-9\-]/', '', $_POST['date']) : '';
    if ($damageDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $damageDate)) {
        $damageDate = date('Y-m-d');
    }
    $damageDateTime = $damageDate . ' 10:00:00';

    try {
        $db->beginTransaction();
        
        // Get current product quantity before update
        $stmt = $db->prepare("SELECT quantity FROM products WHERE id = ?");
        $stmt->execute([$product_id]);
        $old_quantity = $stmt->fetchColumn();
        
        // Add validation check
        if ($quantity > $old_quantity) {
            throw new Exception("Cannot damage more items than are available in stock");
        }
        
        // Insert into damaged goods with selected date at 10:00
        $stmt = $db->prepare("INSERT INTO damaged_goods (product_id, quantity, reason, date) VALUES (?, ?, ?, ?)");
        $stmt->execute([$product_id, $quantity, $reason, $damageDateTime]);
        
        // Update product quantity
        $stmt = $db->prepare("UPDATE products SET quantity = quantity - ? WHERE id = ?");
        $stmt->execute([$quantity, $product_id]);
        
        // Get new product quantity after update
        $stmt = $db->prepare("SELECT quantity FROM products WHERE id = ?");
        $stmt->execute([$product_id]);
        $new_quantity = $stmt->fetchColumn();
        
        // Insert into stock_changes
        $stmt = $db->prepare("INSERT INTO stock_changes (product_id, action, quantity_change, old_quantity, new_quantity, username) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$product_id, 'damaged', -1, $old_quantity, $new_quantity, currentStockChangeUsername()]);
        
        $db->commit();
        
        // Store success message in session and redirect
        $_SESSION['success'] = "Damaged goods recorded successfully!";
        header('Location: damaged_goods');
        exit();
    } catch (Exception $e) {
        $db->rollBack();
        $_SESSION['error'] = "Error recording damaged goods: " . $e->getMessage();
        header('Location: damaged_goods');
        exit();
    }
}

// Get success/error messages from session if they exist
if (isset($_SESSION['success'])) {
    $success = $_SESSION['success'];
    unset($_SESSION['success']);
}
if (isset($_SESSION['error'])) {
    $error = $_SESSION['error'];
    unset($_SESSION['error']);
}

// Get products and damaged goods records
$products = $db->query("SELECT id, name FROM products")->fetchAll(PDO::FETCH_ASSOC);
try {
    $damagedGoods = $db->query("
        SELECT d.*, p.name as product_name 
        FROM damaged_goods d
        JOIN products p ON d.product_id = p.id
        ORDER BY d.date DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    ensureDamagedGoodsTable($db);
    $damagedGoods = $db->query("
        SELECT d.*, p.name as product_name 
        FROM damaged_goods d
        JOIN products p ON d.product_id = p.id
        ORDER BY d.date DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Damaged Goods Tracking</title>
    <link href="src/output.css" rel="stylesheet">
    <script src="navigation.js" async></script>
    <script src="src/howler.min.js"></script>
    <script src="src/chart.js"></script>
    <meta name="google" content="notranslate">
    <link rel="icon" href="favicon.ico" type="image/png">
    <link rel="stylesheet" href="src/font-awesome/css/all.min.css">
    <script src="3.4.16"></script>
    <script src="sweetalert2@11.js"></script>
    <script src="js/pos-confirm.js"></script>

</head>
<body class="bg-gray-100">
    <div class="flex">
        <div class="sidebar fixed h-full">
            <?php include 'sidebar.php'; ?>
        </div>
        <div class="content flex-1 lg:ml-64">
            <div class="w-full px-4 lg:px-6 py-6">
                <div class="flex justify-between items-center mb-8 border-b border-gray-200 pb-6">
                    <div>
                        <h1 class="text-3xl font-bold text-gray-900">Damaged Goods Tracking</h1>
                        <p class="mt-2 text-sm text-gray-500">Manage and track damaged inventory items</p>
                    </div>
                    <div class="flex items-center gap-4">
                        <div class="bg-blue-50 px-4 py-2 rounded-lg">
                            <span class="text-blue-700 font-medium">Total Damaged Items:</span>
                            <?php 
                                try {
                                    $total = $db->query("SELECT SUM(quantity) FROM damaged_goods")->fetchColumn();
                                } catch (PDOException $e) {
                                    ensureDamagedGoodsTable($db);
                                    $total = $db->query("SELECT SUM(quantity) FROM damaged_goods")->fetchColumn();
                                }
                                echo '<span class="ml-2 text-blue-800">'.($total ?: 0).'</span>';
                            ?>
                        </div>
                        <a href="cashier-center" class="inline-flex items-center px-4 py-2 bg-gray-200 hover:bg-gray-300 text-gray-800 rounded-lg transition-colors">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                            </svg>
                            back
                        </a>
                    </div>
                </div>

                <?php if(isset($success)): ?>
                    <div class="mb-8 p-4 bg-teal-50 border-l-4 border-teal-400">
                        <div class="flex items-center">
                            <svg class="h-5 w-5 text-teal-400" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                            </svg>
                            <p class="ml-3 text-sm text-teal-700"><?= $success ?></p>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if(isset($error)): ?>
                    <div class="mb-8 p-4 bg-red-50 border-l-4 border-red-400">
                        <div class="flex items-center">
                            <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                            </svg>
                            <p class="ml-3 text-sm text-red-700"><?= $error ?></p>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="bg-white rounded-xl shadow-sm p-6 mb-8 border border-gray-100">
                    <h2 class="text-lg font-semibold text-gray-900 mb-6">Record Damaged Goods</h2>
                    <form method="POST">
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                            <div class="space-y-2">
                                <label class="block text-sm font-medium text-gray-700">Date <span class="text-red-500">*</span></label>
                                <input type="date" name="date" value="<?= date('Y-m-d') ?>"
                                    class="mt-1 block w-full rounded-lg border border-gray-400 shadow-sm focus:border-teal-500 focus:ring-teal-500 h-8" required>
                            </div>
                            <div class="space-y-2">
                                <label class="block text-sm font-medium text-gray-700">Product <span class="text-red-500">*</span></label>
                                <select name="product_id" class="mt-1 block w-full rounded-lg border border-gray-400 shadow-sm focus:border-teal-500 focus:ring-teal-500 h-8">
                                    <?php foreach($products as $product): ?>
                                        <option value="<?= $product['id'] ?>"><?= htmlspecialchars($product['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="space-y-2">
                                <label class="block text-sm font-medium text-gray-700">Quantity <span class="text-red-500">*</span></label>
                                <input type="number" name="quantity" min="1" class="mt-1 block w-full rounded-lg border border-gray-400 shadow-sm focus:border-teal-500 focus:ring-teal-500 h-8" required
                                    oninput="checkQuantity(this)">
                                <p id="quantity-error" class="text-red-500 text-sm hidden">Cannot damage more items than are available in stock</p>
                            </div>
                            <div class="space-y-2">
                                <label class="block text-sm font-medium text-gray-700">Reason</label>
                                <input type="text" name="reason" class="mt-1 block w-full rounded-lg border border-gray-400 shadow-sm focus:border-teal-500 focus:ring-teal-500 h-8">
                            </div>
                        </div>
                        <button type="submit" class="mt-6 px-6 py-3 bg-red-500 text-white font-medium rounded-lg hover:bg-red-700 transition-colors duration-200 flex items-center justify-center gap-2 h-12">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                            </svg>
                            Record Damage
                        </button>
                    </form>
                </div>

                <div class="bg-white rounded-xl shadow-sm overflow-hidden border border-gray-100">
                    <div class="px-6 py-4 border-b border-gray-100 bg-gray-50">
                        <h3 class="text-lg font-medium text-gray-900">Damage History</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-300">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Product</th>
                                    <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Quantity</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Reason</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                    <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach($damagedGoods as $record): ?>
                                    <tr class="hover:bg-gray-50 transition-colors">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?= htmlspecialchars($record['product_name']) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-center text-gray-500"><?= $record['quantity'] ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 max-w-xs truncate"><?= htmlspecialchars($record['reason']) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= date('M j, Y H:i', strtotime($record['date'])) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-center">
                                            <form method="POST" action="delete_damaged.php" class="inline" onsubmit="return confirmPosFormSubmit(event, { title: 'Delete this record?', text: 'This damaged goods entry will be permanently removed.', confirmButtonText: 'Delete', variant: 'danger' });">
                                                <input type="hidden" name="id" value="<?= $record['id'] ?>">
                                                <button type="submit" class="text-red-400 hover:text-red-600 transition-colors duration-200">
                                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                    </svg>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    function checkQuantity(input) {
        const productId = document.querySelector('select[name="product_id"]').value;
        const quantity = input.value;
        const errorMessage = document.getElementById('quantity-error');
        
        // Fetch current stock for selected product
        fetch(`get_stock.php?product_id=${productId}`)
            .then(response => response.json())
            .then(data => {
                if (quantity > data.stock) {
                    errorMessage.classList.remove('hidden');
                    input.setCustomValidity('Quantity exceeds available stock');
                } else {
                    errorMessage.classList.add('hidden');
                    input.setCustomValidity('');
                }
            });
    }

    // Add event listener to product select
    document.querySelector('select[name="product_id"]').addEventListener('change', function() {
        const quantityInput = document.querySelector('input[name="quantity"]');
        checkQuantity(quantityInput);
    });
    </script>
</body>
</html> 