<?php
// Database connection
$db = new SQLite3('pos.db');

// Fetch Sales Revenue from orders table
$salesRevenueQuery = $db->query("SELECT SUM(total) as total_sales FROM orders");
$salesRevenue = $salesRevenueQuery->fetchArray(SQLITE3_ASSOC)['total_sales'];

// Fetch Other Income from cash_transactions
$otherIncomeQuery = $db->query("SELECT SUM(amount) as other_income FROM cash_transactions WHERE type = 'income'");
$otherIncome = $otherIncomeQuery->fetchArray(SQLITE3_ASSOC)['other_income'];

// Total Revenue
$totalRevenue = $salesRevenue + $otherIncome;

// Cost of Goods Sold (COGS) using products table
$beginningInventoryQuery = $db->query("SELECT SUM(quantity * buying_price) as beginning_inventory FROM products");
$beginningInventory = $beginningInventoryQuery->fetchArray(SQLITE3_ASSOC)['beginning_inventory'];

$purchasesQuery = $db->query("SELECT SUM(quantity * buying_price) as purchases FROM stock_changes WHERE action = 'add'");
$purchases = $purchasesQuery->fetchArray(SQLITE3_ASSOC)['purchases'];

$endingInventoryQuery = $db->query("SELECT SUM(quantity * buying_price) as ending_inventory FROM products");
$endingInventory = $endingInventoryQuery->fetchArray(SQLITE3_ASSOC)['ending_inventory'];

$totalCOGS = $beginningInventory + $purchases - $endingInventory;

// Gross Profit
$grossProfit = $totalRevenue - $totalCOGS;

// Operating Expenses from cash_transactions
$operatingExpensesQuery = $db->query("SELECT SUM(amount) as operating_expenses FROM cash_transactions WHERE type = 'expense'");
$totalOperatingExpenses = $operatingExpensesQuery->fetchArray(SQLITE3_ASSOC)['operating_expenses'];

// Operating Profit (EBIT)
$operatingProfit = $grossProfit - $totalOperatingExpenses;

// Other Income & Expenses from cash_transactions
$otherIncomeExpensesQuery = $db->query("SELECT 
    SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) as other_income,
    SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as other_expenses
    FROM cash_transactions");
$otherIncomeExpenses = $otherIncomeExpensesQuery->fetchArray(SQLITE3_ASSOC);

// Net Profit
$netProfit = $operatingProfit + $otherIncomeExpenses['other_income'] - $otherIncomeExpenses['other_expenses'];

// Output the Income Statement
echo "Company Name\n";
echo "Income Statement\n";
echo "For the Year Ended [Date]\n\n";

echo "Revenue:\n";
echo "Sales Revenue: N$" . number_format($salesRevenue, 2) . "\n";
echo "Other Income: N$" . number_format($otherIncome, 2) . "\n";
echo "Total Revenue: N$" . number_format($totalRevenue, 2) . "\n\n";

echo "Cost of Goods Sold (COGS):\n";
echo "Beginning Inventory: N$" . number_format($beginningInventory, 2) . "\n";
echo "Purchases: N$" . number_format($purchases, 2) . "\n";
echo "Less: Ending Inventory: (N$" . number_format($endingInventory, 2) . ")\n";
echo "Total COGS: N$" . number_format($totalCOGS, 2) . "\n\n";

echo "Gross Profit:\n";
echo "Total Revenue - COGS = N$" . number_format($grossProfit, 2) . "\n\n";

echo "Operating Expenses:\n";
echo "Total Operating Expenses: N$" . number_format($totalOperatingExpenses, 2) . "\n\n";

echo "Operating Profit (EBIT):\n";
echo "Gross Profit - Total Operating Expenses = N$" . number_format($operatingProfit, 2) . "\n\n";

echo "Other Income & Expenses:\n";
echo "Other Income: N$" . number_format($otherIncomeExpenses['other_income'], 2) . "\n";
echo "Other Expenses: (N$" . number_format($otherIncomeExpenses['other_expenses'], 2) . ")\n\n";

echo "Net Profit (or Net Loss): N$" . number_format($netProfit, 2) . "\n";

$tablesQuery = $db->query("SELECT name FROM sqlite_master WHERE type='table'");
while ($table = $tablesQuery->fetchArray(SQLITE3_ASSOC)) {
    echo $table['name'] . "\n";
}
?>
