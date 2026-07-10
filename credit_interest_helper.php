<?php

function ensureCreditInterestSchema(PDO $db): void
{
    foreach ([
        'ALTER TABLE product_settings ADD COLUMN credit_interest_enabled INTEGER NOT NULL DEFAULT 1',
        'ALTER TABLE product_settings ADD COLUMN credit_interest_rate REAL NOT NULL DEFAULT 18',
    ] as $sql) {
        try {
            $db->exec($sql);
        } catch (PDOException $e) {
        }
    }
}

function loadCreditInterestSettings(PDO $db): array
{
    ensureCreditInterestSchema($db);
    $row = $db->query('SELECT credit_interest_enabled, credit_interest_rate FROM product_settings WHERE id = 1')->fetch(PDO::FETCH_ASSOC) ?: [];

    $enabled = !isset($row['credit_interest_enabled']) || (int) $row['credit_interest_enabled'] === 1;
    $ratePercent = isset($row['credit_interest_rate']) ? round((float) $row['credit_interest_rate'], 2) : 18.0;
    if ($ratePercent < 0) {
        $ratePercent = 0;
    }
    if ($ratePercent > 100) {
        $ratePercent = 100;
    }

    return [
        'enabled' => $enabled,
        'rate_percent' => $ratePercent,
        'rate_decimal' => $ratePercent / 100,
    ];
}

function calculateCreditInterestAmount(float $paymentAmount, array $settings): float
{
    if (empty($settings['enabled']) || $paymentAmount <= 0) {
        return 0.0;
    }

    return round($paymentAmount * (float) $settings['rate_decimal'], 2);
}

function creditInterestProductLabel(array $settings): string
{
    $rate = rtrim(rtrim(number_format((float) $settings['rate_percent'], 2, '.', ''), '0'), '.');

    return 'Interest (' . $rate . '%)';
}

function recordCreditInterestSale(
    PDO $db,
    int $creditorId,
    float $interestAmount,
    array $settings,
    string $paymentStatus = 'paid',
    array $eftOpts = []
): int {
    if ($interestAmount <= 0 || empty($settings['enabled'])) {
        return 0;
    }

    $dueDate = date('Y-m-d', strtotime('+30 days'));
    $cashierId = $_SESSION['username'] ?? 'System';
    $interestStmt = $db->prepare('
        INSERT INTO credit_sales (creditor_id, total_amount, paid_amount, due_date, created_at, payment_status, cashier_id)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');
    $interestStmt->execute([
        $creditorId,
        $interestAmount,
        $interestAmount,
        $dueDate,
        date('Y-m-d H:i:s'),
        $paymentStatus,
        $cashierId,
    ]);
    $interestSaleId = (int) $db->lastInsertId();

    $interestItemStmt = $db->prepare('
        INSERT INTO credit_sale_items (sale_id, product_name, quantity, price)
        VALUES (?, ?, 1, ?)
    ');
    $interestItemStmt->execute([$interestSaleId, creditInterestProductLabel($settings), $interestAmount]);

    $interestPaymentStmt = $db->prepare('INSERT INTO payments (sale_id, amount, payment_date) VALUES (?, ?, ?)');
    $interestPaymentStmt->execute([$interestSaleId, $interestAmount, date('Y-m-d H:i:s')]);

    if ($paymentStatus === 'eft') {
        $interestEftStmt = $db->prepare('INSERT INTO eft_payments (order_id, transaction_ref, wallet_provider, amount, payment_date) VALUES (?, ?, ?, ?, ?)');
        $interestEftStmt->execute([
            $interestSaleId,
            $eftOpts['transaction_ref'] ?? '',
            $eftOpts['wallet_provider'] ?? '',
            $interestAmount,
            date('Y-m-d H:i:s'),
        ]);
    }

    return $interestSaleId;
}
