<?php
// Simulate the same data as your receipt.php
$orderData = [
    'is_cashup_report' => false,
    'is_balance_receipt' => false,
    'order_id' => 12345,
    'items' => [
        ['name' => 'Product A', 'quantity' => 2, 'price' => 30.00],
        ['name' => 'Long Product Name That Will Wrap', 'quantity' => 1, 'price' => 15.00],
    ],
    'cash_received' => 50.00,
    // ... add other fields as needed
];

// Copy your formatting logic here, but output to a string
$lines = [];
$lines[] = str_pad('POS SOLUTION', 32, ' ', STR_PAD_BOTH);
$lines[] = str_pad('Your Business Address', 32, ' ', STR_PAD_BOTH);
$lines[] = str_pad('Tel: Your Phone Number', 32, ' ', STR_PAD_BOTH);
$lines[] = str_repeat('-', 32);
$lines[] = 'Receipt #: ' . $orderData['order_id'];
$lines[] = str_repeat('-', 32);
$lines[] = 'Date: ' . date('Y-m-d H:i');
$lines[] = '';
$lines[] = sprintf("%-13s %5s %10s", "Item", "Qty", "Amount");
$lines[] = str_repeat('-', 32);

$subtotal = 0;
foreach ($orderData['items'] as $item) {
    $name = $item['name'];
    $quantity = $item['quantity'];
    $price = $item['price'] / $quantity;
    $amount = $item['price'];
    $subtotal += $amount;
    if (strlen($name) > 32) {
        $name = substr($name, 0, 29) . '...';
    }
    $lines[] = $name;
    $qtyPrice = sprintf("%d x N$%.2f", $quantity, $price);
    $amountText = sprintf("N$%.2f", $amount);
    $lines[] = sprintf("%-20s %11s", $qtyPrice, $amountText);
    $lines[] = str_repeat('-', 32);
}
$lines[] = str_pad("TOTAL: N$" . number_format($subtotal, 2), 32, ' ', STR_PAD_LEFT);
$lines[] = '';
$lines[] = str_repeat('-', 32);
$lines[] = 'Method: Cash';
$lines[] = str_pad("Paid: N$" . number_format($orderData['cash_received'], 2), 32, ' ', STR_PAD_LEFT);
$change = $orderData['cash_received'] - $subtotal;
$lines[] = str_pad("Change: N$" . number_format($change, 2), 32, ' ', STR_PAD_LEFT);
$lines[] = str_repeat('-', 32);
$lines[] = str_pad('Thank you for your purchase!', 32, ' ', STR_PAD_BOTH);

// Output as preformatted text
echo '<pre style="font-family: monospace; border:1px solid #000; width:340px; padding:10px;">';
echo implode("\n", $lines);
echo '</pre>';
?>