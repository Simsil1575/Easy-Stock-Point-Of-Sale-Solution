<?php
// Database connection
$db = new PDO('sqlite:pos.db');

// Get EFT payments with order details
$payments = $db->query("
    SELECT ep.*, 
           o.total as order_total,
           o.created_at as order_date,
           GROUP_CONCAT(oi.product_name || ' (' || oi.quantity || ')') as items
    FROM eft_payments ep
    JOIN orders o ON ep.order_id = o.id
    JOIN order_items oi ON o.id = oi.order_id
    GROUP BY ep.id
    ORDER BY ep.payment_date DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EFT Payments - POS Solution</title>
    <link href="src/output.css" rel="stylesheet">
    <link rel="stylesheet" href="src/font-awesome/css/all.min.css">
</head>
<body style="background-color:rgb(249 250 251 / var(--tw-bg-opacity, 1))">

    <div class="flex">
        <?php include 'sidebar.php'; ?>
        <div class="flex-1 content p-6">
            <h1 class="text-2xl font-bold text-gray-800 mb-6">EFT Payment Records</h1>
            
            <?php if (empty($payments)): ?>
                <div class="bg-white rounded-xl shadow-md p-6 text-center">
                    <p class="text-gray-500">No EFT payments recorded yet.</p>
                </div>
            <?php else: ?>
                <div class="bg-white rounded-xl shadow-md overflow-hidden">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Order ID</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Provider</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Reference</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Items</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($payments as $payment): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= date('d M Y H:i', strtotime($payment['payment_date'])) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        #<?= $payment['order_id'] ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        N$<?= number_format($payment['amount'], 2) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= htmlspecialchars($payment['wallet_provider']) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= htmlspecialchars($payment['transaction_ref']) ?>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-500 max-w-xs truncate">
                                        <?= htmlspecialchars($payment['items']) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-teal-100 text-teal-800">
                                            <?= htmlspecialchars($payment['status']) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html> 