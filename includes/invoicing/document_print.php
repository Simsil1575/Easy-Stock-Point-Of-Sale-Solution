<?php
/**
 * Print-friendly A4 HTML for a quotation or invoice.
 * Expects: $type, $bundle, $settings.
 */
$isQuote = $type === 'quotation';
$doc = $isQuote ? $bundle['quotation'] : $bundle['invoice'];
$customer = $bundle['customer'];
$items = $bundle['items'];
$currency = (string) ($settings['currency'] ?? 'N$');
$number = $isQuote ? $doc['quotation_number'] : $doc['invoice_number'];
$grand = $isQuote ? (float) $doc['total'] : (float) $doc['grand_total'];
$e = fn($v) => htmlspecialchars((string) $v);
$m = fn($v) => $currency . ' ' . number_format((float) $v, 2);
$title = $isQuote ? 'QUOTATION' : 'INVOICE';

$logo = (string) ($settings['company_logo'] ?? '');
$logoSrc = '';
if ($logo !== '') {
    // Resolve to a browser path relative to web root.
    $logoSrc = '../' . ltrim($logo, '/');
    if (preg_match('#^https?://#', $logo)) { $logoSrc = $logo; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= $title ?> <?= $e($number) ?></title>
<style>
    * { box-sizing: border-box; }
    body { font-family: 'Segoe UI', Arial, sans-serif; color: #1f2937; margin: 0; background: #f3f4f6; }
    .sheet { background: #fff; width: 210mm; min-height: 297mm; margin: 12px auto; padding: 18mm 16mm; box-shadow: 0 0 10px rgba(0,0,0,.1); }
    .toolbar { text-align: center; padding: 12px; }
    .toolbar button { padding: 8px 18px; margin: 0 4px; border: 0; border-radius: 6px; cursor: pointer; font-size: 14px; }
    .btn-print { background: #0d9488; color: #fff; }
    .btn-close { background: #e5e7eb; color: #374151; }
    .head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #e5e7eb; padding-bottom: 14px; }
    .company h1 { margin: 0 0 4px; font-size: 22px; }
    .company p, .bill p { margin: 1px 0; font-size: 12px; color: #6b7280; }
    .doc-title { text-align: right; }
    .doc-title h2 { margin: 0; font-size: 30px; color: #0d9488; letter-spacing: 1px; }
    .doc-title .num { font-weight: 700; margin-top: 4px; }
    .logo { max-height: 70px; max-width: 180px; margin-bottom: 8px; }
    .meta { display: flex; justify-content: space-between; margin-top: 18px; }
    .bill h3 { font-size: 11px; text-transform: uppercase; color: #0d9488; margin: 0 0 4px; }
    .bill .name { font-weight: 700; font-size: 14px; }
    .meta-table td { font-size: 12px; padding: 2px 0; }
    .meta-table td.k { color: #6b7280; padding-right: 14px; }
    table.items { width: 100%; border-collapse: collapse; margin-top: 22px; }
    table.items thead th { background: #0d9488; color: #fff; font-size: 11px; text-transform: uppercase; padding: 8px; text-align: left; }
    table.items thead th.r { text-align: right; }
    table.items tbody td { padding: 8px; font-size: 12px; border-bottom: 1px solid #eef2f7; }
    table.items tbody td.r { text-align: right; }
    .totals { width: 46%; margin-left: auto; margin-top: 14px; font-size: 13px; }
    .totals .row { display: flex; justify-content: space-between; padding: 3px 0; }
    .totals .grand { font-weight: 700; font-size: 16px; border-top: 2px solid #e5e7eb; padding-top: 6px; color: #0d9488; }
    .totals .bal { font-weight: 700; color: #dc2626; }
    .notes { margin-top: 20px; font-size: 12px; color: #4b5563; }
    .notes h4 { font-size: 11px; text-transform: uppercase; color: #0d9488; margin: 0 0 3px; }
    .signs { display: flex; justify-content: space-between; margin-top: 48px; }
    .signs .sig { width: 44%; border-top: 1px solid #9ca3af; padding-top: 4px; font-size: 11px; text-align: center; color: #6b7280; }
    .foot { text-align: center; margin-top: 26px; font-size: 12px; color: #6b7280; border-top: 1px solid #e5e7eb; padding-top: 10px; }
    @media print {
        body { background: #fff; }
        .toolbar { display: none !important; }
        .sheet { box-shadow: none; margin: 0; width: auto; min-height: auto; padding: 0; }
        @page { size: A4; margin: 14mm; }
    }
</style>
</head>
<body>
<div class="toolbar">
    <button class="btn-print" onclick="window.print()">Print</button>
    <button class="btn-close" onclick="window.close()">Close</button>
</div>
<div class="sheet">
    <div class="head">
        <div class="company">
            <?php if ($logoSrc !== ''): ?><img src="<?= $e($logoSrc) ?>" class="logo" alt="Logo"><?php endif; ?>
            <h1><?= $e($settings['company_name'] ?? '') ?></h1>
            <?php if (!empty($settings['company_address'])): ?><p><?= nl2br($e($settings['company_address'])) ?></p><?php endif; ?>
            <?php if (!empty($settings['telephone']) || !empty($settings['email'])): ?><p><?= $e($settings['telephone'] ?? '') ?><?= !empty($settings['email']) ? ' | ' . $e($settings['email']) : '' ?></p><?php endif; ?>
            <?php if (!empty($settings['website'])): ?><p><?= $e($settings['website']) ?></p><?php endif; ?>
            <?php if (!empty($settings['tax_number']) || !empty($settings['vat_number'])): ?><p><?= !empty($settings['tax_number']) ? 'Tax No: ' . $e($settings['tax_number']) : '' ?> <?= !empty($settings['vat_number']) ? 'VAT No: ' . $e($settings['vat_number']) : '' ?></p><?php endif; ?>
        </div>
        <div class="doc-title">
            <h2><?= $title ?></h2>
            <div class="num"><?= $e($number) ?></div>
            <div style="font-size:12px;color:#6b7280;margin-top:4px;"><?= $e($doc['status']) ?></div>
        </div>
    </div>

    <div class="meta">
        <div class="bill">
            <h3>Bill To</h3>
            <div class="name"><?= $e($customer['name'] ?? 'N/A') ?></div>
            <?php if (!empty($customer['address'])): ?><p><?= nl2br($e($customer['address'])) ?></p><?php endif; ?>
            <?php if (!empty($customer['phone'])): ?><p><?= $e($customer['phone']) ?></p><?php endif; ?>
            <?php if (!empty($customer['email'])): ?><p><?= $e($customer['email']) ?></p><?php endif; ?>
            <?php if (!empty($customer['tax_number'])): ?><p>Tax No: <?= $e($customer['tax_number']) ?></p><?php endif; ?>
        </div>
        <table class="meta-table">
            <tr><td class="k"><?= $isQuote ? 'Quotation Date' : 'Invoice Date' ?></td><td><?= $e($isQuote ? $doc['quotation_date'] : $doc['invoice_date']) ?></td></tr>
            <tr><td class="k"><?= $isQuote ? 'Valid Until' : 'Due Date' ?></td><td><?= $e(($isQuote ? $doc['expiry_date'] : $doc['due_date']) ?: '-') ?></td></tr>
            <?php if (!$isQuote && !empty($doc['payment_terms'])): ?><tr><td class="k">Payment Terms</td><td><?= $e($doc['payment_terms']) ?></td></tr><?php endif; ?>
        </table>
    </div>

    <table class="items">
        <thead><tr><th>Description</th><th class="r">Qty</th><th class="r">Unit Price</th><th class="r">Discount</th><th class="r">Amount</th></tr></thead>
        <tbody>
        <?php foreach ($items as $it):
            $label = trim((string) ($it['product_name'] ?? ''));
            $desc = trim((string) ($it['description'] ?? ''));
            if ($label === '') { $label = $desc; $desc = ''; }
        ?>
            <tr>
                <td><?= $e($label) ?><?php if ($desc !== '' && $desc !== $label): ?><br><span style="color:#9ca3af;font-size:11px;"><?= $e($desc) ?></span><?php endif; ?></td>
                <td class="r"><?= rtrim(rtrim(number_format((float) $it['quantity'], 2), '0'), '.') ?></td>
                <td class="r"><?= $m($it['unit_price']) ?></td>
                <td class="r"><?= $m($it['discount']) ?></td>
                <td class="r"><?= $m($it['line_total']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="totals">
        <div class="row"><span>Subtotal</span><span><?= $m($doc['subtotal']) ?></span></div>
        <?php if ((float) $doc['discount_amount'] > 0): ?><div class="row"><span>Discount</span><span>- <?= $m($doc['discount_amount']) ?></span></div><?php endif; ?>
        <?php if ((float) $doc['vat_amount'] > 0): ?><div class="row"><span>VAT (<?= rtrim(rtrim(number_format((float) $doc['vat_percentage'], 2), '0'), '.') ?>%)</span><span><?= $m($doc['vat_amount']) ?></span></div><?php endif; ?>
        <?php if ((float) $doc['shipping_amount'] > 0): ?><div class="row"><span>Shipping</span><span><?= $m($doc['shipping_amount']) ?></span></div><?php endif; ?>
        <div class="row grand"><span><?= $isQuote ? 'Total' : 'Grand Total' ?></span><span><?= $m($grand) ?></span></div>
        <?php if (!$isQuote): ?>
        <div class="row"><span>Paid</span><span><?= $m($doc['paid_amount']) ?></span></div>
        <div class="row bal"><span>Balance Due</span><span><?= $m($doc['balance_due']) ?></span></div>
        <?php endif; ?>
    </div>

    <?php
    $notes = trim((string) ($doc['notes'] ?? '')) ?: (string) ($settings['default_notes'] ?? '');
    $terms = trim((string) ($doc['terms_conditions'] ?? '')) ?: (string) ($settings['default_terms_conditions'] ?? '');
    ?>
    <?php if ($notes !== ''): ?><div class="notes"><h4>Notes</h4><?= nl2br($e($notes)) ?></div><?php endif; ?>
    <?php if ($terms !== ''): ?><div class="notes"><h4>Terms &amp; Conditions</h4><?= nl2br($e($terms)) ?></div><?php endif; ?>

    <div class="signs">
        <div class="sig"><?= $e($doc['approved_by'] ?? $doc['created_by'] ?? '') ?><br>Prepared / Authorized By</div>
        <div class="sig">&nbsp;<br>Customer Signature</div>
    </div>

    <div class="foot"><?= $e(trim((string) ($settings['footer_text'] ?? '')) ?: 'Thank you for your business.') ?></div>
</div>
<script>window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 350); });</script>
</body>
</html>
