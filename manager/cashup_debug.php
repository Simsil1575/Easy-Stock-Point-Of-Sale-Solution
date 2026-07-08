<?php
/**
 * Cash On Hand / Cash In Till – Debug Page
 * Shows in detail how the cash on hand value is calculated. Pick a date and range mode.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Africa/Harare');

$allowedRoles = ['admin', 'manager'];
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || !in_array(strtolower($_SESSION['role']), $allowedRoles)) {
    header('Location: index.php');
    exit;
}

$today = date('Y-m-d');

// Form inputs (GET or POST)
$selectedDate = $_GET['date'] ?? $_POST['date'] ?? $today;
$useBusinessDay = isset($_GET['use_business_day']) ? (int)$_GET['use_business_day'] : (isset($_POST['use_business_day']) ? (int)$_POST['use_business_day'] : 1);
$selectedCashier = $_GET['cashier_id'] ?? $_POST['cashier_id'] ?? 'all';

// Normalize to a valid date
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = $today;
}

try {
    $db = new PDO('sqlite:../pos.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $infoDb = new PDO('sqlite:../info.db');
    $userDb = new PDO('sqlite:../user.db');
    $userDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    $error = 'Database connection failed: ' . $e->getMessage();
    $db = null;
}

$closingTime = '22:00';
if ($infoDb) {
    try {
        $bi = $infoDb->query("SELECT closing_time FROM business_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!empty($bi['closing_time'])) {
            $closingTime = trim($bi['closing_time']);
            if (strlen($closingTime) === 5) $closingTime .= ':00';
        }
    } catch (PDOException $e) {}
}

$nextBusinessDay = date('Y-m-d', strtotime($selectedDate . ' +1 day'));
$isAfterMidnight = (int)substr($closingTime, 0, 2) < 12;

if ($useBusinessDay) {
    $startDatetime = $selectedDate . ' ' . (strlen($closingTime) === 8 ? $closingTime : substr($closingTime, 0, 5) . ':00');
    $endPrev = date('H:i', strtotime('-1 minute', strtotime($selectedDate . ' ' . substr($closingTime, 0, 5))));
    $endDatetime = $nextBusinessDay . ' ' . $endPrev . ':59';
    $rangeMode = 'Business day (same as Cash page)';
    $rangeDetail = "From {$selectedDate} at " . substr($closingTime, 0, 5) . " to {$nextBusinessDay} at {$endPrev}:59";
} else {
    $startDatetime = $selectedDate . ' 00:00:00';
    $endDatetime = $selectedDate . ' 23:59:59';
    $rangeMode = 'Calendar day (00:00–23:59)';
    $rangeDetail = "{$selectedDate} 00:00:00 – {$selectedDate} 23:59:59";
}

// Cashier filter helpers
$selectedCashierNumericId = null;
if ($selectedCashier !== 'all' && !empty($selectedCashier)) {
    if (is_numeric($selectedCashier)) {
        $selectedCashierNumericId = (int)$selectedCashier;
    } else {
        try {
            $idLookup = $userDb->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            $idLookup->execute([$selectedCashier]);
            $r = $idLookup->fetch(PDO::FETCH_ASSOC);
            if ($r) $selectedCashierNumericId = (int)$r['id'];
        } catch (PDOException $e) {}
    }
}

function getRangeWhere($dateField) {
    return " (datetime($dateField) >= datetime(:startDatetime) AND datetime($dateField) <= datetime(:endDatetime)) ";
}
function getCashierFilter($cashierIdField) {
    global $selectedCashier;
    if ($selectedCashier === 'all' || empty($selectedCashier)) return "";
    return " AND $cashierIdField = :cashierId";
}
function bindCashierParam($stmt) {
    global $selectedCashier;
    if ($selectedCashier !== 'all' && !empty($selectedCashier)) $stmt->bindParam(':cashierId', $selectedCashier);
}

$totalCashIn = 0;
$totalCashSales = 0;
$totalCreditPayments = 0;
$totalCashOut = 0;
$expensesOnly = 0;
$tipsSystem = 0;
$cashBackSystem = 0;
$cashInTill = 0;
$eftTableExists = false;

if ($db) {
    try {
        $eftTableExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='eft_payments'")->fetchColumn() !== false;
    } catch (PDOException $e) {}

    // Cash In
    $q = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_transactions WHERE type='cash-in' AND (" . getRangeWhere('created_at') . ")" . getCashierFilter('cashier_id'));
    $q->bindParam(':startDatetime', $startDatetime);
    $q->bindParam(':endDatetime', $endDatetime);
    bindCashierParam($q);
    $q->execute();
    $totalCashIn = (float)$q->fetchColumn();

    // Cash Sales (orders minus EFT if table exists)
    if ($eftTableExists) {
        $q = $db->prepare("SELECT COALESCE(SUM(o.total - COALESCE((SELECT SUM(amount) FROM eft_payments ep WHERE ep.order_id = o.id), 0)), 0) FROM orders o WHERE (" . getRangeWhere('o.created_at') . ")" . getCashierFilter('o.cashier_id'));
    } else {
        $q = $db->prepare("SELECT COALESCE(SUM(total), 0) FROM orders WHERE (" . getRangeWhere('created_at') . ")" . getCashierFilter('cashier_id'));
    }
    $q->bindParam(':startDatetime', $startDatetime);
    $q->bindParam(':endDatetime', $endDatetime);
    bindCashierParam($q);
    $q->execute();
    $totalCashSales = (float)$q->fetchColumn();

    // Credit Payments
    $q = $db->prepare("SELECT COALESCE(SUM(p.amount), 0) FROM payments p JOIN credit_sales cs ON p.sale_id = cs.id WHERE cs.payment_status = 'paid' AND (" . getRangeWhere('p.payment_date') . ")" . getCashierFilter('p.cashier_id'));
    $q->bindParam(':startDatetime', $startDatetime);
    $q->bindParam(':endDatetime', $endDatetime);
    bindCashierParam($q);
    $q->execute();
    $totalCreditPayments = (float)$q->fetchColumn();

    // Cash Out – ALL (same as cash.php)
    $q = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_transactions WHERE type='cash-out' AND (" . getRangeWhere('created_at') . ")" . getCashierFilter('cashier_id'));
    $q->bindParam(':startDatetime', $startDatetime);
    $q->bindParam(':endDatetime', $endDatetime);
    bindCashierParam($q);
    $q->execute();
    $totalCashOut = (float)$q->fetchColumn();

    // Breakdown: Expenses only (exclude tips & cash back)
    $q = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_transactions WHERE type='cash-out' AND (description NOT LIKE '%Tips%' AND description NOT LIKE '%Cash Back%' AND description NOT LIKE '%tip%' AND description NOT LIKE '%cash back%') AND (" . getRangeWhere('created_at') . ")" . getCashierFilter('cashier_id'));
    $q->bindParam(':startDatetime', $startDatetime);
    $q->bindParam(':endDatetime', $endDatetime);
    bindCashierParam($q);
    $q->execute();
    $expensesOnly = (float)$q->fetchColumn();

    // Tips
    $q = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_transactions WHERE type='cash-out' AND (description LIKE '%Tips%' OR description LIKE '%tip%') AND (" . getRangeWhere('created_at') . ")" . getCashierFilter('cashier_id'));
    $q->bindParam(':startDatetime', $startDatetime);
    $q->bindParam(':endDatetime', $endDatetime);
    bindCashierParam($q);
    $q->execute();
    $tipsSystem = (float)$q->fetchColumn();

    // Cash Back
    $q = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_transactions WHERE type='cash-out' AND (description LIKE '%Cash Back%' OR description LIKE '%cash back%') AND (" . getRangeWhere('created_at') . ")" . getCashierFilter('cashier_id'));
    $q->bindParam(':startDatetime', $startDatetime);
    $q->bindParam(':endDatetime', $endDatetime);
    bindCashierParam($q);
    $q->execute();
    $cashBackSystem = (float)$q->fetchColumn();

    $cashInTill = $totalCashIn + $totalCashSales + $totalCreditPayments - $totalCashOut;
} else {
    $error = $error ?? 'Database not available.';
}

// Cashiers for dropdown
$cashiers = [['id' => 'all', 'name' => 'All Staff']];
if ($userDb) {
    try {
        $st = $userDb->query("SELECT id, username FROM users ORDER BY username");
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $cashiers[] = ['id' => $row['username'], 'name' => $row['username']];
        }
    } catch (PDOException $e) {}
}

function fmt($n) { return number_format((float)$n, 2); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cash On Hand – Debug</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/premium-tailwind@2.2.0/dist/premium-tailwind.min.css">
</head>
<body class="bg-slate-100 min-h-screen">
    <div class="max-w-4xl mx-auto p-6">
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl font-bold text-slate-800">Cash On Hand – Debug</h1>
            <a href="home.php" class="text-slate-600 hover:text-slate-900 text-sm">← Back to Manager Home</a>
        </div>

        <?php if (!empty($error)): ?>
            <div class="bg-red-50 border border-red-200 text-red-800 rounded-xl p-4 mb-6"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="get" class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 mb-6">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 items-end">
                <div>
                    <label for="date" class="block text-sm font-medium text-slate-700 mb-1">Date</label>
                    <input type="date" name="date" id="date" value="<?= htmlspecialchars($selectedDate) ?>" class="w-full px-3 py-2.5 border border-slate-300 rounded-xl focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                </div>
                <div>
                    <label for="use_business_day" class="block text-sm font-medium text-slate-700 mb-1">Range</label>
                    <select name="use_business_day" id="use_business_day" class="w-full px-3 py-2.5 border border-slate-300 rounded-xl focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                        <option value="1" <?= $useBusinessDay ? 'selected' : '' ?>>Business day (match Cash page)</option>
                        <option value="0" <?= !$useBusinessDay ? 'selected' : '' ?>>Calendar day (00:00–23:59)</option>
                    </select>
                </div>
                <div>
                    <label for="cashier_id" class="block text-sm font-medium text-slate-700 mb-1">Cashier</label>
                    <select name="cashier_id" id="cashier_id" class="w-full px-3 py-2.5 border border-slate-300 rounded-xl focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                        <?php foreach ($cashiers as $c): ?>
                            <option value="<?= htmlspecialchars($c['id']) ?>" <?= $selectedCashier === $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <button type="submit" class="w-full px-4 py-2.5 bg-teal-600 text-white font-medium rounded-xl hover:bg-teal-700 focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">Show breakdown</button>
                </div>
            </div>
        </form>

        <div class="space-y-6">
            <!-- Range used -->
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
                <h2 class="text-lg font-semibold text-slate-800 mb-2">Date range used</h2>
                <p class="text-slate-600"><span class="font-medium">Mode:</span> <?= htmlspecialchars($rangeMode) ?></p>
                <p class="text-slate-600 mt-1"><span class="font-medium">Detail:</span> <?= htmlspecialchars($rangeDetail) ?></p>
                <p class="text-slate-500 text-sm mt-2">Closing time from settings: <?= htmlspecialchars(substr($closingTime, 0, 5)) ?></p>
            </div>

            <!-- IN: what goes into the till -->
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
                <h2 class="text-lg font-semibold text-slate-800 mb-4">Money in (+)</h2>
                <ul class="space-y-2">
                    <li class="flex justify-between py-2 border-b border-slate-100">
                        <span class="text-slate-700">Cash In (float / top-ups)</span>
                        <span class="font-mono font-semibold text-green-700">N$ <?= fmt($totalCashIn) ?></span>
                    </li>
                    <li class="flex justify-between py-2 border-b border-slate-100">
                        <span class="text-slate-700">Cash Sales <?= $eftTableExists ? '(orders minus EFT)' : '' ?></span>
                        <span class="font-mono font-semibold text-green-700">N$ <?= fmt($totalCashSales) ?></span>
                    </li>
                    <li class="flex justify-between py-2 border-b border-slate-100">
                        <span class="text-slate-700">Credit payments (cash received)</span>
                        <span class="font-mono font-semibold text-green-700">N$ <?= fmt($totalCreditPayments) ?></span>
                    </li>
                </ul>
                <div class="flex justify-between pt-3 mt-2 border-t-2 border-slate-200">
                    <span class="font-medium text-slate-800">Subtotal (in)</span>
                    <span class="font-mono font-bold text-green-700">N$ <?= fmt($totalCashIn + $totalCashSales + $totalCreditPayments) ?></span>
                </div>
            </div>

            <!-- OUT: what goes out -->
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
                <h2 class="text-lg font-semibold text-slate-800 mb-4">Money out (−)</h2>
                <ul class="space-y-2">
                    <li class="flex justify-between py-2 border-b border-slate-100">
                        <span class="text-slate-700">Expenses only (no tips/cash back)</span>
                        <span class="font-mono text-red-700">N$ <?= fmt($expensesOnly) ?></span>
                    </li>
                    <li class="flex justify-between py-2 border-b border-slate-100">
                        <span class="text-slate-700">Tips</span>
                        <span class="font-mono text-red-700">N$ <?= fmt($tipsSystem) ?></span>
                    </li>
                    <li class="flex justify-between py-2 border-b border-slate-100">
                        <span class="text-slate-700">Cash Back</span>
                        <span class="font-mono text-red-700">N$ <?= fmt($cashBackSystem) ?></span>
                    </li>
                </ul>
                <div class="flex justify-between pt-3 mt-2 border-t-2 border-slate-200">
                    <span class="font-medium text-slate-800">Total cash out (all)</span>
                    <span class="font-mono font-bold text-red-700">N$ <?= fmt($totalCashOut) ?></span>
                </div>
                <?php $checkSum = $expensesOnly + $tipsSystem + $cashBackSystem; if (abs($checkSum - $totalCashOut) > 0.02): ?>
                    <p class="text-amber-700 text-sm mt-2">Note: Expenses + Tips + Cash back = N$ <?= fmt($checkSum) ?> (small difference may be other descriptions)</p>
                <?php endif; ?>
            </div>

            <!-- Formula and result -->
            <div class="bg-teal-50 border-2 border-teal-200 rounded-2xl p-6">
                <h2 class="text-lg font-semibold text-teal-900 mb-3">How Cash On Hand is calculated</h2>
                <div class="font-mono text-sm bg-white/80 rounded-xl p-4 text-slate-800 mb-4">
                    Cash On Hand = Cash In + Cash Sales + Credit Payments − Total Cash Out
                </div>
                <div class="flex flex-wrap items-baseline gap-2 text-slate-700">
                    <span>N$ <?= fmt($totalCashIn) ?></span>
                    <span>+</span>
                    <span>N$ <?= fmt($totalCashSales) ?></span>
                    <span>+</span>
                    <span>N$ <?= fmt($totalCreditPayments) ?></span>
                    <span>−</span>
                    <span>N$ <?= fmt($totalCashOut) ?></span>
                    <span>=</span>
                    <span class="text-xl font-bold text-teal-800">N$ <?= fmt($cashInTill) ?></span>
                </div>
                <p class="mt-4 text-teal-800 font-semibold">Cash On Hand / Cash In Till = <strong>N$ <?= fmt($cashInTill) ?></strong></p>
                <p class="text-slate-600 text-sm mt-2">This value should match “Cash Available in Till” on the Cash page when the same date and “Business day” are used and cashier is All Staff.</p>
            </div>
        </div>
    </div>
</body>
</html>
