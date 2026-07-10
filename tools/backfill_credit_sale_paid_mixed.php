<?php
/**
 * One-time: recompute payment_status for settled credit_sales rows from payments vs eft_payments.
 * Run from project root: php tools/backfill_credit_sale_paid_mixed.php
 */
$root = dirname(__DIR__);
$dbPath = $root . DIRECTORY_SEPARATOR . 'pos.db';
if (!is_file($dbPath)) {
    fwrite(STDERR, "Missing pos.db at $dbPath\n");
    exit(1);
}
require_once $root . DIRECTORY_SEPARATOR . 'inc' . DIRECTORY_SEPARATOR . 'credit_sale_payment_status.php';

$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$rows = $db->query("
    SELECT id FROM credit_sales
    WHERE ABS(CAST(paid_amount AS REAL) - CAST(total_amount AS REAL)) < 0.02
       OR paid_amount >= total_amount - 0.01
")->fetchAll(PDO::FETCH_ASSOC);

$updated = 0;
foreach ($rows as $r) {
    $id = (int) $r['id'];
    $newStatus = resolve_credit_sale_payment_status($db, $id);
    $cur = $db->query("SELECT payment_status FROM credit_sales WHERE id = $id")->fetchColumn();
    if ($cur !== $newStatus) {
        $stmt = $db->prepare('UPDATE credit_sales SET payment_status = ? WHERE id = ?');
        $stmt->execute([$newStatus, $id]);
        $updated++;
    }
}

echo "Scanned " . count($rows) . " settled sales; updated $updated row(s).\n";
