<?php
/**
 * Shared document view for quotations and invoices.
 * Expects: $type, $bundle, $settings, $listPage, $editPage, $viewPage.
 */
$isQuote = $type === 'quotation';
$doc = $isQuote ? $bundle['quotation'] : $bundle['invoice'];
$customer = $bundle['customer'];
$items = $bundle['items'];
$payments = $bundle['payments'] ?? [];
$currency = (string) ($settings['currency'] ?? 'N$');
$id = (int) $doc['id'];
$status = (string) $doc['status'];
$number = $isQuote ? $doc['quotation_number'] : $doc['invoice_number'];
$grand = $isQuote ? (float) $doc['total'] : (float) $doc['grand_total'];
$e = fn($v) => htmlspecialchars((string) $v);
$m = fn($v) => $currency . ' ' . number_format((float) $v, 2);

$canDeletePaid = invCan('delete_paid_invoice');
$isReadonly = $isQuote ? ($status === 'Converted') : in_array($status, ['Paid', 'Cancelled'], true);
$printPage = $isQuote ? 'quotation_print' : 'invoice_print';
?>
<div class="mb-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
    <div>
        <div class="flex items-center gap-3">
            <h1 class="text-2xl font-bold text-gray-800"><?= $e($number) ?></h1>
            <span class="inv-badge <?= invStatusBadgeClass($status) ?>"><?= $e($status) ?></span>
        </div>
        <p class="text-gray-500 text-sm mt-1"><?= $e($customer['name'] ?? 'N/A') ?> · <?= $e($isQuote ? $doc['quotation_date'] : $doc['invoice_date']) ?></p>
    </div>
    <a href="<?= $listPage ?>" class="px-3 py-2 text-sm rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-100 self-start"><i class="fas fa-arrow-left mr-1"></i> Back to list</a>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
    <div class="lg:col-span-2 space-y-4">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 mb-6">
                <div>
                    <div class="text-xs font-semibold text-teal-600 uppercase mb-1">Bill To</div>
                    <div class="font-semibold text-gray-800"><?= $e($customer['name'] ?? 'N/A') ?></div>
                    <div class="text-sm text-gray-500"><?= nl2br($e($customer['address'] ?? '')) ?></div>
                    <div class="text-sm text-gray-500"><?= $e($customer['phone'] ?? '') ?></div>
                    <div class="text-sm text-gray-500"><?= $e($customer['email'] ?? '') ?></div>
                </div>
                <div class="sm:text-right text-sm text-gray-600 space-y-1">
                    <div><span class="text-gray-400"><?= $isQuote ? 'Quotation Date' : 'Invoice Date' ?>:</span> <?= $e($isQuote ? $doc['quotation_date'] : $doc['invoice_date']) ?></div>
                    <div><span class="text-gray-400"><?= $isQuote ? 'Valid Until' : 'Due Date' ?>:</span> <?= $e(($isQuote ? $doc['expiry_date'] : $doc['due_date']) ?: '-') ?></div>
                    <?php if (!$isQuote && !empty($doc['payment_terms'])): ?><div><span class="text-gray-400">Terms:</span> <?= $e($doc['payment_terms']) ?></div><?php endif; ?>
                </div>
            </div>

            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
                    <tr><th class="px-3 py-2 text-left">Description</th><th class="px-3 py-2 text-right">Qty</th><th class="px-3 py-2 text-right">Unit</th><th class="px-3 py-2 text-right">Disc</th><th class="px-3 py-2 text-right">Total</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($items as $it):
                        $label = trim((string) ($it['product_name'] ?? ''));
                        $desc = trim((string) ($it['description'] ?? ''));
                        if ($label === '') { $label = $desc; $desc = ''; }
                    ?>
                    <tr>
                        <td class="px-3 py-2 text-gray-700"><?= $e($label) ?><?php if ($desc !== '' && $desc !== $label): ?><div class="text-xs text-gray-400"><?= $e($desc) ?></div><?php endif; ?></td>
                        <td class="px-3 py-2 text-right"><?= rtrim(rtrim(number_format((float) $it['quantity'], 2), '0'), '.') ?></td>
                        <td class="px-3 py-2 text-right"><?= $m($it['unit_price']) ?></td>
                        <td class="px-3 py-2 text-right"><?= $m($it['discount']) ?></td>
                        <td class="px-3 py-2 text-right font-medium"><?= $m($it['line_total']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="flex justify-end mt-4">
                <div class="w-full sm:w-72 text-sm space-y-1">
                    <div class="flex justify-between"><span class="text-gray-500">Subtotal</span><span><?= $m($doc['subtotal']) ?></span></div>
                    <?php if ((float) $doc['discount_amount'] > 0): ?><div class="flex justify-between"><span class="text-gray-500">Discount</span><span>- <?= $m($doc['discount_amount']) ?></span></div><?php endif; ?>
                    <?php if ((float) $doc['vat_amount'] > 0): ?><div class="flex justify-between"><span class="text-gray-500">VAT (<?= rtrim(rtrim(number_format((float) $doc['vat_percentage'], 2), '0'), '.') ?>%)</span><span><?= $m($doc['vat_amount']) ?></span></div><?php endif; ?>
                    <?php if ((float) $doc['shipping_amount'] > 0): ?><div class="flex justify-between"><span class="text-gray-500">Shipping</span><span><?= $m($doc['shipping_amount']) ?></span></div><?php endif; ?>
                    <div class="flex justify-between font-bold text-base border-t border-gray-100 pt-2 mt-1"><span>Grand Total</span><span class="text-teal-700"><?= $m($grand) ?></span></div>
                    <?php if (!$isQuote): ?>
                    <div class="flex justify-between"><span class="text-gray-500">Paid</span><span><?= $m($doc['paid_amount']) ?></span></div>
                    <div class="flex justify-between font-semibold"><span>Balance Due</span><span class="text-rose-600"><?= $m($doc['balance_due']) ?></span></div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($doc['notes']) || !empty($doc['terms_conditions'])): ?>
            <div class="mt-6 grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                <?php if (!empty($doc['notes'])): ?><div><div class="text-xs font-semibold text-gray-500 uppercase mb-1">Notes</div><p class="text-gray-600"><?= nl2br($e($doc['notes'])) ?></p></div><?php endif; ?>
                <?php if (!empty($doc['terms_conditions'])): ?><div><div class="text-xs font-semibold text-gray-500 uppercase mb-1">Terms &amp; Conditions</div><p class="text-gray-600"><?= nl2br($e($doc['terms_conditions'])) ?></p></div><?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <?php if (!$isQuote): ?>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-semibold text-gray-800">Payment History</h3>
                <?php if (!in_array($status, ['Paid', 'Cancelled'], true)): ?>
                <button onclick="openPaymentModal()" class="text-xs px-3 py-1.5 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700"><i class="fas fa-plus mr-1"></i> Record Payment</button>
                <?php endif; ?>
            </div>
            <?php if (empty($payments)): ?>
                <p class="text-sm text-gray-400">No payments recorded.</p>
            <?php else: ?>
                <table class="min-w-full text-sm">
                    <thead class="text-xs text-gray-500 uppercase border-b border-gray-100"><tr><th class="py-2 text-left">Date</th><th class="py-2 text-left">Method</th><th class="py-2 text-left">Reference</th><th class="py-2 text-right">Amount</th><th class="py-2 text-left">By</th></tr></thead>
                    <tbody class="divide-y divide-gray-50">
                        <?php foreach ($payments as $p): ?>
                        <tr><td class="py-2"><?= $e($p['payment_date']) ?></td><td class="py-2"><?= $e($p['payment_method']) ?></td><td class="py-2 text-gray-500"><?= $e($p['reference'] ?? '') ?></td><td class="py-2 text-right font-medium"><?= $m($p['amount']) ?></td><td class="py-2 text-gray-500"><?= $e($p['received_by'] ?? '') ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Action panel -->
    <div class="space-y-4">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 inv-sticky-actions">
            <h3 class="font-semibold text-gray-800 mb-3">Actions</h3>
            <div class="space-y-2">
                <?php if (!$isReadonly): ?>
                <a href="<?= $editPage ?>?id=<?= $id ?>" class="block text-center w-full px-4 py-2.5 rounded-lg bg-gray-800 text-white hover:bg-gray-900 text-sm font-medium"><i class="fas fa-pen mr-1"></i> Edit</a>
                <?php endif; ?>

                <?php if (!$isQuote && $status === 'Draft'): ?>
                <button onclick="invAct('issue_invoice', {id: <?= $id ?>}, 'Invoice issued.')" class="w-full px-4 py-2.5 rounded-lg bg-teal-600 text-white hover:bg-teal-700 text-sm font-medium"><i class="fas fa-check mr-1"></i> Issue Invoice</button>
                <?php endif; ?>

                <?php if (!$isQuote && !in_array($status, ['Paid', 'Cancelled'], true)): ?>
                <button onclick="openPaymentModal()" class="w-full px-4 py-2.5 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 text-sm font-medium"><i class="fas fa-money-bill-wave mr-1"></i> Record Payment</button>
                <?php endif; ?>

                <?php if ($isQuote && $status !== 'Converted'): ?>
                <button onclick="invConvert(<?= $id ?>)" class="w-full px-4 py-2.5 rounded-lg bg-teal-600 text-white hover:bg-teal-700 text-sm font-medium"><i class="fas fa-file-invoice-dollar mr-1"></i> Convert to Invoice</button>
                <?php endif; ?>

                <a href="../invoicing_pdf.php?type=<?= $type ?>&id=<?= $id ?>" target="_blank" class="block text-center w-full px-4 py-2.5 rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-100 text-sm font-medium"><i class="fas fa-file-pdf mr-1"></i> Download PDF</a>
                <a href="../<?= $printPage ?>.php?id=<?= $id ?>" target="_blank" class="block text-center w-full px-4 py-2.5 rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-100 text-sm font-medium"><i class="fas fa-print mr-1"></i> Print</a>
                <button onclick="invEmailPlaceholder()" class="w-full px-4 py-2.5 rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-100 text-sm font-medium"><i class="fas fa-envelope mr-1"></i> Email</button>
                <button onclick="invDuplicate(<?= $id ?>)" class="w-full px-4 py-2.5 rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-100 text-sm font-medium"><i class="fas fa-copy mr-1"></i> Duplicate</button>

                <?php if (!$isQuote && !in_array($status, ['Cancelled'], true) && $status !== 'Paid'): ?>
                <button onclick="invAct('cancel_invoice', {id: <?= $id ?>}, 'Invoice cancelled.', true)" class="w-full px-4 py-2.5 rounded-lg border border-orange-200 text-orange-600 hover:bg-orange-50 text-sm font-medium"><i class="fas fa-ban mr-1"></i> Cancel Invoice</button>
                <?php endif; ?>

                <?php
                $showDelete = true;
                if ($isQuote && $status === 'Converted') { $showDelete = false; }
                if (!$isQuote && $status === 'Paid' && !$canDeletePaid) { $showDelete = false; }
                if ($showDelete): ?>
                <button onclick="invDelete(<?= $id ?>)" class="w-full px-4 py-2.5 rounded-lg border border-rose-200 text-rose-600 hover:bg-rose-50 text-sm font-medium"><i class="fas fa-trash mr-1"></i> Delete</button>
                <?php endif; ?>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 text-sm text-gray-500 space-y-1">
            <div><span class="text-gray-400">Created by:</span> <?= $e($doc['created_by'] ?? '') ?></div>
            <div><span class="text-gray-400">Created:</span> <?= $e($doc['created_at'] ?? '') ?></div>
            <?php if ($isQuote && !empty($doc['approved_by'])): ?><div><span class="text-gray-400">Approved by:</span> <?= $e($doc['approved_by']) ?></div><?php endif; ?>
        </div>
    </div>
</div>

<?php if (!$isQuote): ?>
<!-- Payment modal -->
<div id="paymentModal" class="modal-overlay">
    <div class="modal-card p-5">
        <div class="flex items-center justify-between mb-4"><h3 class="text-lg font-semibold">Record Payment</h3><button onclick="closePaymentModal()" class="text-gray-400 hover:text-gray-700"><i class="fas fa-times"></i></button></div>
        <div class="text-sm text-gray-500 mb-3">Outstanding balance: <span class="font-semibold text-gray-800"><?= $m($doc['balance_due']) ?></span></div>
        <div class="space-y-3">
            <div class="grid grid-cols-2 gap-3">
                <div><label class="block text-xs text-gray-500 mb-1">Amount *</label><input id="payAmount" type="number" step="0.01" value="<?= number_format((float) $doc['balance_due'], 2, '.', '') ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"></div>
                <div><label class="block text-xs text-gray-500 mb-1">Date</label><input id="payDate" type="date" value="<?= date('Y-m-d') ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"></div>
            </div>
            <div><label class="block text-xs text-gray-500 mb-1">Method *</label>
                <select id="payMethod" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    <?php foreach (['Cash', 'Card', 'Bank Transfer', 'Mobile Money', 'Cheque', 'Credit'] as $mth): ?><option value="<?= $mth ?>"><?= $mth ?></option><?php endforeach; ?>
                </select>
            </div>
            <div><label class="block text-xs text-gray-500 mb-1">Reference</label><input id="payRef" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"></div>
            <div><label class="block text-xs text-gray-500 mb-1">Notes</label><input id="payNotes" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"></div>
        </div>
        <div class="flex justify-end gap-2 mt-4">
            <button onclick="closePaymentModal()" class="px-4 py-2 rounded-lg border border-gray-300 text-gray-600 text-sm">Cancel</button>
            <button onclick="submitPayment(<?= $id ?>)" class="px-4 py-2 rounded-lg bg-emerald-600 text-white text-sm">Save Payment</button>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
    window.INV_CURRENCY = <?= json_encode($currency) ?>;
    const INV_TYPE = <?= json_encode($type) ?>;

    async function invAct(action, payload, msg, confirmDanger) {
        try {
            if (confirmDanger) { const ok = await invConfirm({ title: 'Are you sure?', confirmText: 'Yes', danger: true }); if (!ok) return; }
            await invApi(action, payload);
            invToast(msg, 'success');
            setTimeout(() => location.reload(), 600);
        } catch (err) { invToast(err.message, 'error'); }
    }
    async function invDelete(id) {
        const ok = await invConfirm({ title: 'Delete this ' + INV_TYPE + '?', text: 'This cannot be undone.', confirmText: 'Delete', danger: true });
        if (!ok) return;
        try { await invApi(INV_TYPE === 'quotation' ? 'delete_quotation' : 'delete_invoice', { id }); invToast('Deleted.', 'success'); setTimeout(() => location.href = '<?= $listPage ?>', 500); }
        catch (err) { invToast(err.message, 'error'); }
    }
    async function invDuplicate(id) {
        try { const d = await invApi(INV_TYPE === 'quotation' ? 'duplicate_quotation' : 'duplicate_invoice', { id }); invToast('Duplicated.', 'success'); setTimeout(() => location.href = '<?= $editPage ?>?id=' + d.id, 500); }
        catch (err) { invToast(err.message, 'error'); }
    }
    async function invConvert(id) {
        const ok = await invConfirm({ title: 'Convert to invoice?', text: 'The quotation will be marked as Converted.', confirmText: 'Convert', icon: 'question' });
        if (!ok) return;
        try { const d = await invApi('convert_quotation', { id }); invToast('Converted.', 'success'); setTimeout(() => location.href = 'invoice_view?id=' + d.invoice_id, 500); }
        catch (err) { invToast(err.message, 'error'); }
    }
    function invEmailPlaceholder() { invToast('Email delivery is coming soon. Download or print the PDF for now.', 'info'); }

    function openPaymentModal() { document.getElementById('paymentModal').classList.add('active'); }
    function closePaymentModal() { document.getElementById('paymentModal').classList.remove('active'); }
    async function submitPayment(invoiceId) {
        const amount = parseFloat(document.getElementById('payAmount').value) || 0;
        if (amount <= 0) { invToast('Enter a valid amount.', 'error'); return; }
        try {
            await invApi('record_payment', {
                invoice_id: invoiceId,
                amount,
                payment_date: document.getElementById('payDate').value,
                payment_method: document.getElementById('payMethod').value,
                reference: document.getElementById('payRef').value,
                notes: document.getElementById('payNotes').value,
            });
            invToast('Payment recorded.', 'success');
            setTimeout(() => location.reload(), 700);
        } catch (err) { invToast(err.message, 'error'); }
    }
</script>
