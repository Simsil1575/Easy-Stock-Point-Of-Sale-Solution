<?php
/**
 * Shared list view for quotations and invoices.
 * Expects: $type ('quotation'|'invoice'), $result, $filters, $customers,
 *          $settings, $sort, $listPage, $editPage, $viewPage, $printPage.
 */
$isQuote = $type === 'quotation';
$rows = $result['rows'];
$page = $result['page'];
$pages = $result['pages'];
$total = $result['total'];
$currency = (string) ($settings['currency'] ?? 'N$');

$statusOptions = $isQuote
    ? ['Draft', 'Sent', 'Accepted', 'Rejected', 'Expired', 'Converted']
    : ['Draft', 'Issued', 'Partially Paid', 'Paid', 'Overdue', 'Cancelled'];

$buildQ = function (array $overrides = []) use ($filters, $sort, $listPage) {
    $params = array_merge([
        'status' => $filters['status'] ?? '',
        'payment' => $filters['payment'] ?? '',
        'customer_id' => $filters['customer_id'] ?? '',
        'date_from' => $filters['date_from'] ?? '',
        'date_to' => $filters['date_to'] ?? '',
        'search' => $filters['search'] ?? '',
        'sort' => $sort,
    ], $overrides);
    $params = array_filter($params, fn($v) => $v !== '' && $v !== null);
    return $listPage . (empty($params) ? '' : ('?' . http_build_query($params)));
};
$e = fn($v) => htmlspecialchars((string) $v);
?>
<div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
    <div>
        <h1 class="text-2xl lg:text-3xl font-bold text-gray-800"><?= $isQuote ? 'Quotations' : 'Invoices' ?></h1>
        <p class="text-gray-600 text-sm mt-1"><?= $total ?> <?= $isQuote ? 'quotation(s)' : 'invoice(s)' ?> found</p>
    </div>
    <div class="flex items-center gap-2">
        <a href="<?= $backHref ?>" class="px-3 py-2 text-sm rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-100"><i class="fas fa-arrow-left mr-1"></i> Menu</a>
        <a href="<?= $editPage ?>" class="px-4 py-2 text-sm rounded-lg bg-teal-600 text-white hover:bg-teal-700 font-medium"><i class="fas fa-plus mr-1"></i> New <?= $isQuote ? 'Quotation' : 'Invoice' ?></a>
    </div>
</div>

<?php if (!empty($notifications)): ?>
<div class="mb-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
    <?php foreach ($notifications as $note): ?>
    <div class="bg-white border border-gray-100 rounded-xl p-4 flex items-center gap-3 shadow-sm">
        <div class="w-10 h-10 rounded-lg flex items-center justify-center <?= $e($note['bg']) ?>"><i class="fas <?= $e($note['icon']) ?>"></i></div>
        <div>
            <div class="text-xl font-bold text-gray-800"><?= $e($note['value']) ?></div>
            <div class="text-xs text-gray-500"><?= $e($note['label']) ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-4">
    <form method="get" action="<?= $listPage ?>" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-6 gap-3 items-end">
        <div class="lg:col-span-2">
            <label class="block text-xs font-medium text-gray-500 mb-1">Search</label>
            <input type="text" name="search" value="<?= $e($filters['search'] ?? '') ?>" placeholder="Number, customer, phone, email" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Status</label>
            <select name="status" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <option value="">All</option>
                <?php foreach ($statusOptions as $s): ?>
                    <option value="<?= $e($s) ?>" <?= ($filters['status'] ?? '') === $s ? 'selected' : '' ?>><?= $e($s) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if (!$isQuote): ?>
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Payment</label>
            <select name="payment" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <option value="">All</option>
                <?php foreach (['paid' => 'Paid', 'unpaid' => 'Unpaid', 'overdue' => 'Overdue'] as $k => $lbl): ?>
                    <option value="<?= $k ?>" <?= ($filters['payment'] ?? '') === $k ? 'selected' : '' ?>><?= $lbl ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Customer</label>
            <select name="customer_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <option value="">All</option>
                <?php foreach ($customers as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= (string) ($filters['customer_id'] ?? '') === (string) $c['id'] ? 'selected' : '' ?>><?= $e($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">From</label>
            <input type="date" name="date_from" value="<?= $e($filters['date_from'] ?? '') ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">To</label>
            <input type="date" name="date_to" value="<?= $e($filters['date_to'] ?? '') ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <div class="flex items-center gap-2 lg:col-span-2">
            <button type="submit" class="px-4 py-2 text-sm rounded-lg bg-gray-800 text-white hover:bg-gray-900"><i class="fas fa-filter mr-1"></i> Filter</button>
            <a href="<?= $listPage ?>" class="px-4 py-2 text-sm rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-100">Reset</a>
        </div>
    </form>
</div>

<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="min-w-full inv-table text-sm">
            <thead class="bg-gray-100 text-gray-600 uppercase text-xs">
                <tr>
                    <th class="px-4 py-3 text-left"><a href="<?= $e($buildQ(['sort' => 'date_desc'])) ?>"><?= $isQuote ? 'Quotation #' : 'Invoice #' ?></a></th>
                    <th class="px-4 py-3 text-left">Customer</th>
                    <th class="px-4 py-3 text-left"><a href="<?= $e($buildQ(['sort' => $sort === 'date_desc' ? 'date_asc' : 'date_desc'])) ?>">Date <i class="fas fa-sort text-gray-400"></i></a></th>
                    <th class="px-4 py-3 text-left"><?= $isQuote ? 'Expiry' : 'Due Date' ?></th>
                    <th class="px-4 py-3 text-center">Status</th>
                    <?php if (!$isQuote): ?><th class="px-4 py-3 text-right">Balance</th><?php endif; ?>
                    <th class="px-4 py-3 text-right"><a href="<?= $e($buildQ(['sort' => $sort === 'amount_desc' ? 'amount_asc' : 'amount_desc'])) ?>">Amount <i class="fas fa-sort text-gray-400"></i></a></th>
                    <th class="px-4 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php if (empty($rows)): ?>
                    <tr><td colspan="8" class="px-4 py-12 text-center text-gray-400"><i class="fas fa-inbox text-3xl mb-2 block"></i>No records found.</td></tr>
                <?php else: foreach ($rows as $r):
                    $id = (int) $r['id'];
                    $status = (string) $r['status'];
                    $amount = $isQuote ? (float) $r['total'] : (float) $r['grand_total'];
                    $num = $isQuote ? $r['quotation_number'] : $r['invoice_number'];
                    $dateVal = $isQuote ? $r['quotation_date'] : $r['invoice_date'];
                    $secondDate = $isQuote ? ($r['expiry_date'] ?? '') : ($r['due_date'] ?? '');
                ?>
                    <tr class="hover:bg-gray-50" data-id="<?= $id ?>" data-status="<?= $e($status) ?>">
                        <td class="px-4 py-3 font-semibold text-gray-800"><a class="hover:text-teal-600" href="<?= $viewPage ?>?id=<?= $id ?>"><?= $e($num) ?></a></td>
                        <td class="px-4 py-3 text-gray-700"><?= $e($r['customer_name'] ?? 'N/A') ?></td>
                        <td class="px-4 py-3 text-gray-600"><?= $e($dateVal) ?></td>
                        <td class="px-4 py-3 text-gray-600"><?= $e($secondDate ?: '-') ?></td>
                        <td class="px-4 py-3 text-center"><span class="inv-badge <?= invStatusBadgeClass($status) ?>"><?= $e($status) ?></span></td>
                        <?php if (!$isQuote): ?><td class="px-4 py-3 text-right text-gray-700"><?= $currency ?> <?= number_format((float) $r['balance_due'], 2) ?></td><?php endif; ?>
                        <td class="px-4 py-3 text-right font-semibold text-gray-800"><?= $currency ?> <?= number_format($amount, 2) ?></td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <a href="<?= $viewPage ?>?id=<?= $id ?>" title="View" class="inline-flex w-8 h-8 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100"><i class="fas fa-eye"></i></a>
                            <a href="<?= $editPage ?>?id=<?= $id ?>" title="Edit" class="inline-flex w-8 h-8 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100"><i class="fas fa-pen"></i></a>
                            <a href="../invoicing_pdf.php?type=<?= $type ?>&id=<?= $id ?>" target="_blank" title="PDF" class="inline-flex w-8 h-8 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100"><i class="fas fa-file-pdf"></i></a>
                            <button type="button" onclick="invRowAction(<?= $id ?>, 'duplicate')" title="Duplicate" class="inline-flex w-8 h-8 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100"><i class="fas fa-copy"></i></button>
                            <?php if ($isQuote): ?>
                                <?php if ($status !== 'Converted'): ?>
                                <button type="button" onclick="invRowAction(<?= $id ?>, 'convert')" title="Convert to Invoice" class="inline-flex w-8 h-8 items-center justify-center rounded-lg text-teal-600 hover:bg-teal-50"><i class="fas fa-file-invoice-dollar"></i></button>
                                <?php endif; ?>
                            <?php endif; ?>
                            <button type="button" onclick="invRowAction(<?= $id ?>, 'delete')" title="Delete" class="inline-flex w-8 h-8 items-center justify-center rounded-lg text-rose-500 hover:bg-rose-50"><i class="fas fa-trash"></i></button>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pages > 1): ?>
    <div class="flex items-center justify-between px-4 py-3 border-t border-gray-100 text-sm text-gray-600">
        <div>Page <?= $page ?> of <?= $pages ?></div>
        <div class="flex items-center gap-1">
            <?php if ($page > 1): ?><a href="<?= $e($buildQ(['page' => $page - 1])) ?>" class="px-3 py-1 rounded border border-gray-300 hover:bg-gray-100">Prev</a><?php endif; ?>
            <?php for ($p = max(1, $page - 2); $p <= min($pages, $page + 2); $p++): ?>
                <a href="<?= $e($buildQ(['page' => $p])) ?>" class="px-3 py-1 rounded border <?= $p === $page ? 'bg-teal-600 text-white border-teal-600' : 'border-gray-300 hover:bg-gray-100' ?>"><?= $p ?></a>
            <?php endfor; ?>
            <?php if ($page < $pages): ?><a href="<?= $e($buildQ(['page' => $page + 1])) ?>" class="px-3 py-1 rounded border border-gray-300 hover:bg-gray-100">Next</a><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
    window.INV_CURRENCY = <?= json_encode($currency) ?>;
    const INV_TYPE = <?= json_encode($type) ?>;

    async function invRowAction(id, action) {
        try {
            if (action === 'delete') {
                const ok = await invConfirm({ title: 'Delete this ' + INV_TYPE + '?', text: 'This action cannot be undone.', confirmText: 'Delete', danger: true });
                if (!ok) return;
                await invApi(INV_TYPE === 'quotation' ? 'delete_quotation' : 'delete_invoice', { id });
                invToast('Deleted.', 'success');
                setTimeout(() => location.reload(), 500);
            } else if (action === 'duplicate') {
                const d = await invApi(INV_TYPE === 'quotation' ? 'duplicate_quotation' : 'duplicate_invoice', { id });
                invToast('Duplicated.', 'success');
                setTimeout(() => location.href = '<?= $editPage ?>?id=' + d.id, 400);
            } else if (action === 'convert') {
                const ok = await invConfirm({ title: 'Convert to invoice?', text: 'The quotation will be marked as Converted.', confirmText: 'Convert', icon: 'question' });
                if (!ok) return;
                const d = await invApi('convert_quotation', { id });
                invToast('Converted to invoice.', 'success');
                setTimeout(() => location.href = 'invoice_view?id=' + d.invoice_id, 500);
            }
        } catch (err) {
            invToast(err.message, 'error');
        }
    }
</script>
