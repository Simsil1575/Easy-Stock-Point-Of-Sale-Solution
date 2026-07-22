<?php
/**
 * Shared create/edit form for quotations and invoices.
 * Expects: $type, $bundle (existing bundle|null), $customers, $products,
 *          $settings, $listPage, $viewPage.
 */
$isQuote = $type === 'quotation';
$doc = $bundle ? ($isQuote ? $bundle['quotation'] : $bundle['invoice']) : null;
$items = $bundle['items'] ?? [];
$isEdit = $doc !== null;
$docId = $isEdit ? (int) $doc['id'] : 0;
$currency = (string) ($settings['currency'] ?? 'N$');
$e = fn($v) => htmlspecialchars((string) $v);
$defaultVat = (float) ($settings['default_vat_rate'] ?? 15);

$readonly = false;
$readonlyReason = '';
if ($isEdit && !$isQuote) {
    $st = (string) $doc['status'];
    if ($st === 'Paid') { $readonly = true; $readonlyReason = 'A paid invoice cannot be edited.'; }
    elseif ($st === 'Cancelled') { $readonly = true; $readonlyReason = 'A cancelled invoice cannot be edited.'; }
} elseif ($isEdit && $isQuote && (string) $doc['status'] === 'Converted') {
    $readonly = true; $readonlyReason = 'A converted quotation cannot be edited.';
}

$curCustomer = $isEdit ? (int) $doc['customer_id'] : 0;
$vatPct = $isEdit ? (float) $doc['vat_percentage'] : $defaultVat;
$discType = $isEdit ? (string) $doc['discount_type'] : 'none';
$discValue = $isEdit ? (float) $doc['discount_value'] : 0;
$shipping = $isEdit ? (float) $doc['shipping_amount'] : 0;
$title = ($isEdit ? 'Edit ' : 'New ') . ($isQuote ? 'Quotation' : 'Invoice');
?>
<div class="mb-4 flex items-center justify-between">
    <div>
        <h1 class="text-2xl font-bold text-gray-800"><?= $title ?></h1>
        <?php if ($isEdit): ?><p class="text-gray-500 text-sm mt-1"><?= $e($isQuote ? $doc['quotation_number'] : $doc['invoice_number']) ?> · <span class="inv-badge <?= invStatusBadgeClass((string) $doc['status']) ?>"><?= $e($doc['status']) ?></span></p><?php endif; ?>
    </div>
    <a href="<?= $listPage ?>" class="px-3 py-2 text-sm rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-100"><i class="fas fa-arrow-left mr-1"></i> Back</a>
</div>

<?php if ($readonly): ?>
<div class="mb-4 bg-amber-50 border border-amber-200 text-amber-800 rounded-lg px-4 py-3 text-sm"><i class="fas fa-lock mr-1"></i> <?= $e($readonlyReason) ?> You can still view or print it.</div>
<?php endif; ?>

<form id="invForm" onsubmit="return false;" <?= $readonly ? 'inert' : '' ?>>
    <input type="hidden" id="docId" value="<?= $docId ?>">
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <!-- Left: header + items -->
        <div class="lg:col-span-2 space-y-4">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div class="md:col-span-2">
                        <label class="block text-xs font-medium text-gray-500 mb-1">Customer <span class="text-rose-500">*</span></label>
                        <div class="flex gap-2">
                            <select id="customerId" class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm">
                                <option value="">Select customer...</option>
                                <?php foreach ($customers as $c): ?>
                                    <option value="<?= (int) $c['id'] ?>" <?= $curCustomer === (int) $c['id'] ? 'selected' : '' ?>><?= $e($c['name']) ?><?= $c['phone'] ? ' · ' . $e($c['phone']) : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" onclick="openCustomerModal()" class="px-3 py-2 rounded-lg bg-gray-800 text-white text-sm hover:bg-gray-900" title="New customer"><i class="fas fa-user-plus"></i></button>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1"><?= $isQuote ? 'Quotation Date' : 'Invoice Date' ?> <span class="text-rose-500">*</span></label>
                        <input type="date" id="primaryDate" value="<?= $e($isEdit ? ($isQuote ? $doc['quotation_date'] : $doc['invoice_date']) : date('Y-m-d')) ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1"><?= $isQuote ? 'Expiry Date' : 'Due Date' ?></label>
                        <input type="date" id="secondaryDate" value="<?= $e($isEdit ? ($isQuote ? ($doc['expiry_date'] ?? '') : ($doc['due_date'] ?? '')) : '') ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    </div>
                    <?php if (!$isQuote): ?>
                    <div class="md:col-span-2">
                        <label class="block text-xs font-medium text-gray-500 mb-1">Payment Terms</label>
                        <input type="text" id="paymentTerms" value="<?= $e($isEdit ? ($doc['payment_terms'] ?? '') : ($settings['default_payment_terms'] ?? '')) ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="font-semibold text-gray-800">Items</h3>
                    <div class="flex gap-2">
                        <button type="button" onclick="openProductModal()" class="text-xs px-3 py-1.5 rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-100"><i class="fas fa-box mr-1"></i> Quick Product</button>
                        <button type="button" onclick="addRow()" class="text-xs px-3 py-1.5 rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-100"><i class="fas fa-pen mr-1"></i> Custom Line</button>
                        <button type="button" onclick="openProductPicker()" class="text-xs px-3 py-1.5 rounded-lg bg-teal-600 text-white hover:bg-teal-700"><i class="fas fa-plus mr-1"></i> Add Product</button>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm" id="itemsTable">
                        <thead>
                            <tr class="text-xs text-gray-500 uppercase border-b border-gray-100">
                                <th class="py-2 text-left w-1/3">Product / Description</th>
                                <th class="py-2 text-right">Qty</th>
                                <th class="py-2 text-right">Unit Price</th>
                                <th class="py-2 text-right">Discount</th>
                                <th class="py-2 text-right">Total</th>
                                <th class="py-2"></th>
                            </tr>
                        </thead>
                        <tbody id="itemsBody"></tbody>
                    </table>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-medium text-gray-500 mb-1">Notes</label>
                    <textarea id="notes" rows="3" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"><?= $e($isEdit ? ($doc['notes'] ?? '') : ($settings['default_notes'] ?? '')) ?></textarea>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 mb-1">Terms &amp; Conditions</label>
                    <textarea id="terms" rows="3" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"><?= $e($isEdit ? ($doc['terms_conditions'] ?? '') : ($settings['default_terms_conditions'] ?? '')) ?></textarea>
                </div>
            </div>
        </div>

        <!-- Right: summary -->
        <div class="space-y-4">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
                <h3 class="font-semibold text-gray-800 mb-3">Summary</h3>
                <div class="flex justify-between text-sm mb-2"><span class="text-gray-500">Subtotal</span><span id="sumSubtotal" class="font-medium">-</span></div>
                <div class="grid grid-cols-2 gap-2 mb-2">
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Discount</label>
                        <select id="discountType" class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm">
                            <option value="none" <?= $discType === 'none' ? 'selected' : '' ?>>None</option>
                            <option value="percentage" <?= $discType === 'percentage' ? 'selected' : '' ?>>Percent %</option>
                            <option value="fixed" <?= $discType === 'fixed' ? 'selected' : '' ?>>Fixed</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Value</label>
                        <input type="number" step="0.01" id="discountValue" value="<?= $discValue ?>" class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm text-right">
                    </div>
                </div>
                <div class="flex justify-between text-sm mb-2"><span class="text-gray-500">Discount Amount</span><span id="sumDiscount" class="font-medium">-</span></div>
                <div class="grid grid-cols-2 gap-2 mb-2">
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">VAT %</label>
                        <input type="number" step="0.01" id="vatPercentage" value="<?= $vatPct ?>" class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm text-right">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Shipping</label>
                        <input type="number" step="0.01" id="shipping" value="<?= $shipping ?>" class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm text-right">
                    </div>
                </div>
                <div class="flex justify-between text-sm mb-2"><span class="text-gray-500">VAT Amount</span><span id="sumVat" class="font-medium">-</span></div>
                <div class="flex justify-between text-base font-bold border-t border-gray-100 pt-3 mt-2"><span>Grand Total</span><span id="sumTotal" class="text-teal-700">-</span></div>
                <div id="autosaveHint" class="text-xs text-gray-400 mt-2 h-4"></div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 inv-sticky-actions">
                <div class="space-y-2">
                    <button type="button" onclick="saveDoc('draft')" <?= $readonly ? 'disabled' : '' ?> class="w-full px-4 py-2.5 rounded-lg bg-gray-800 text-white hover:bg-gray-900 disabled:opacity-50 text-sm font-medium"><i class="fas fa-save mr-1"></i> Save Draft</button>
                    <?php if ($isQuote): ?>
                    <button type="button" onclick="saveDoc('send')" <?= $readonly ? 'disabled' : '' ?> class="w-full px-4 py-2.5 rounded-lg bg-teal-600 text-white hover:bg-teal-700 disabled:opacity-50 text-sm font-medium"><i class="fas fa-paper-plane mr-1"></i> Save &amp; Mark Sent</button>
                    <?php else: ?>
                    <button type="button" onclick="saveDoc('issue')" <?= $readonly ? 'disabled' : '' ?> class="w-full px-4 py-2.5 rounded-lg bg-teal-600 text-white hover:bg-teal-700 disabled:opacity-50 text-sm font-medium"><i class="fas fa-check mr-1"></i> Save &amp; Issue</button>
                    <?php endif; ?>
                    <?php if ($isEdit): ?>
                    <a href="../invoicing_pdf.php?type=<?= $type ?>&id=<?= $docId ?>" target="_blank" class="block text-center w-full px-4 py-2.5 rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-100 text-sm font-medium"><i class="fas fa-file-pdf mr-1"></i> Generate PDF</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</form>

<!-- Customer modal -->
<div id="customerModal" class="modal-overlay">
    <div class="modal-card p-5">
        <div class="flex items-center justify-between mb-4"><h3 class="text-lg font-semibold">New Customer</h3><button onclick="closeCustomerModal()" class="text-gray-400 hover:text-gray-700"><i class="fas fa-times"></i></button></div>
        <div class="space-y-3">
            <input id="cxName" placeholder="Name *" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            <div class="grid grid-cols-2 gap-3">
                <input id="cxPhone" placeholder="Phone" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <input id="cxEmail" placeholder="Email" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <input id="cxAddress" placeholder="Address" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            <input id="cxTax" placeholder="Tax Number" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <div class="flex justify-end gap-2 mt-4">
            <button onclick="closeCustomerModal()" class="px-4 py-2 rounded-lg border border-gray-300 text-gray-600 text-sm">Cancel</button>
            <button onclick="saveCustomer()" class="px-4 py-2 rounded-lg bg-teal-600 text-white hover:bg-teal-700 text-sm">Save Customer</button>
        </div>
    </div>
</div>

<!-- Product modal -->
<div id="productModal" class="modal-overlay">
    <div class="modal-card p-5">
        <div class="flex items-center justify-between mb-4"><h3 class="text-lg font-semibold">Quick Product</h3><button onclick="closeProductModal()" class="text-gray-400 hover:text-gray-700"><i class="fas fa-times"></i></button></div>
        <div class="space-y-3">
            <input id="pxName" placeholder="Product name *" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            <div class="grid grid-cols-2 gap-3">
                <input id="pxPrice" type="number" step="0.01" placeholder="Price" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <input id="pxQty" type="number" placeholder="Opening qty" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
        </div>
        <div class="flex justify-end gap-2 mt-4">
            <button onclick="closeProductModal()" class="px-4 py-2 rounded-lg border border-gray-300 text-gray-600 text-sm">Cancel</button>
            <button onclick="saveProduct()" class="px-4 py-2 rounded-lg bg-teal-600 text-white hover:bg-teal-700 text-sm">Create</button>
        </div>
    </div>
</div>

<!-- Product picker (POS-style grid) -->
<div id="productPicker" class="modal-overlay">
    <div class="modal-card p-0" style="max-width:940px;width:100%;max-height:88vh;display:flex;flex-direction:column;">
        <div class="flex items-center justify-between p-4 border-b border-gray-100">
            <h3 class="text-lg font-semibold text-gray-800"><i class="fas fa-box-open text-teal-600 mr-2"></i>Select Products</h3>
            <button onclick="closeProductPicker()" class="text-gray-400 hover:text-gray-700"><i class="fas fa-times text-lg"></i></button>
        </div>
        <div class="p-4 border-b border-gray-100">
            <div class="relative">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                <input id="pickerSearch" oninput="renderPicker()" placeholder="Search by name or barcode..." class="w-full border border-gray-300 rounded-lg pl-9 pr-3 py-2 text-sm">
            </div>
        </div>
        <div class="p-4 overflow-y-auto custom-scrollbar" style="flex:1;">
            <div id="pickerGrid" class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3"></div>
            <div id="pickerEmpty" class="hidden text-center text-gray-400 py-12"><i class="fas fa-box-open text-3xl mb-2 block"></i>No products found.</div>
        </div>
        <div class="p-4 border-t border-gray-100 flex items-center justify-between">
            <span id="pickerCount" class="text-xs text-gray-500"></span>
            <button onclick="closeProductPicker()" class="px-4 py-2 rounded-lg bg-teal-600 text-white text-sm hover:bg-teal-700">Done</button>
        </div>
    </div>
</div>

<datalist id="productList">
    <?php foreach ($products as $p): ?>
        <option value="<?= $e($p['name']) ?>"></option>
    <?php endforeach; ?>
</datalist>

<script>
    const INV_TYPE = <?= json_encode($type) ?>;
    const IS_QUOTE = <?= $isQuote ? 'true' : 'false' ?>;
    window.INV_CURRENCY = <?= json_encode($currency) ?>;
    let PRODUCTS = <?= json_encode(array_map(fn($p) => [
        'id' => (int) $p['id'],
        'name' => $p['name'],
        'price' => (float) $p['price'],
        'quantity' => (int) ($p['quantity'] ?? 0),
        'category' => $p['category'] ?? '',
        'image_url' => $p['image_url'] ?? '',
        'barcode' => $p['barcode'] ?? '',
    ], $products)) ?>;
    let productByName = {};
    function rebuildProductIndex() { productByName = {}; PRODUCTS.forEach(p => productByName[p.name.toLowerCase()] = p); }
    rebuildProductIndex();

    const existingItems = <?= json_encode(array_map(fn($it) => [
        'product_id' => $it['product_id'] !== null ? (int) $it['product_id'] : '',
        'product_name' => $it['product_name'] ?? '',
        'description' => $it['description'] ?? '',
        'quantity' => (float) $it['quantity'],
        'unit_price' => (float) $it['unit_price'],
        'discount' => (float) $it['discount'],
    ], $items)) ?>;

    function rowTemplate(it) {
        it = it || { product_id: '', product_name: '', description: '', quantity: 1, unit_price: 0, discount: 0 };
        const tr = document.createElement('tr');
        tr.className = 'border-b border-gray-50 inv-item-row';
        tr.innerHTML = `
            <td class="py-2 pr-2">
                <input type="hidden" class="it-pid" value="${it.product_id || ''}">
                <input list="productList" class="it-name w-full border border-gray-200 rounded px-2 py-1.5 text-sm mb-1" placeholder="Product" value="${escapeHtml(it.product_name || '')}">
                <input class="it-desc w-full border border-gray-200 rounded px-2 py-1 text-xs text-gray-500" placeholder="Description (optional)" value="${escapeHtml(it.description || '')}">
            </td>
            <td class="py-2 px-1"><input type="number" step="0.01" class="it-qty w-20 border border-gray-200 rounded px-2 py-1.5 text-sm text-right" value="${it.quantity}"></td>
            <td class="py-2 px-1"><input type="number" step="0.01" class="it-price w-24 border border-gray-200 rounded px-2 py-1.5 text-sm text-right" value="${it.unit_price}"></td>
            <td class="py-2 px-1"><input type="number" step="0.01" class="it-disc w-20 border border-gray-200 rounded px-2 py-1.5 text-sm text-right" value="${it.discount}"></td>
            <td class="py-2 px-1 text-right it-total font-medium text-gray-700">-</td>
            <td class="py-2 pl-1 text-right"><button type="button" class="it-remove text-rose-400 hover:text-rose-600"><i class="fas fa-times-circle"></i></button></td>`;
        return tr;
    }

    function escapeHtml(s) { return String(s).replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c])); }

    function addRow(it) {
        const tr = rowTemplate(it);
        document.getElementById('itemsBody').appendChild(tr);
        wireRow(tr);
        recalc();
    }

    function wireRow(tr) {
        const nameEl = tr.querySelector('.it-name');
        const pidEl = tr.querySelector('.it-pid');
        const priceEl = tr.querySelector('.it-price');
        nameEl.addEventListener('input', () => {
            const p = productByName[nameEl.value.trim().toLowerCase()];
            if (p) { pidEl.value = p.id; if (!parseFloat(priceEl.value)) priceEl.value = p.price; }
            else { pidEl.value = ''; }
            recalc();
        });
        tr.querySelectorAll('.it-qty,.it-price,.it-disc').forEach(el => el.addEventListener('input', recalc));
        tr.querySelector('.it-remove').addEventListener('click', () => { tr.remove(); recalc(); });
    }

    function gatherItems() {
        const items = [];
        document.querySelectorAll('.inv-item-row').forEach(tr => {
            const name = tr.querySelector('.it-name').value.trim();
            const desc = tr.querySelector('.it-desc').value.trim();
            if (!name && !desc) return;
            items.push({
                product_id: tr.querySelector('.it-pid').value || '',
                description: desc || name,
                quantity: parseFloat(tr.querySelector('.it-qty').value) || 0,
                unit_price: parseFloat(tr.querySelector('.it-price').value) || 0,
                discount: parseFloat(tr.querySelector('.it-disc').value) || 0,
            });
        });
        return items;
    }

    function recalc() {
        let subtotal = 0;
        document.querySelectorAll('.inv-item-row').forEach(tr => {
            const qty = parseFloat(tr.querySelector('.it-qty').value) || 0;
            const price = parseFloat(tr.querySelector('.it-price').value) || 0;
            const disc = parseFloat(tr.querySelector('.it-disc').value) || 0;
            let lt = qty * price - disc; if (lt < 0) lt = 0;
            tr.querySelector('.it-total').textContent = invMoneyFmt(lt);
            subtotal += lt;
        });
        const dType = document.getElementById('discountType').value;
        const dVal = parseFloat(document.getElementById('discountValue').value) || 0;
        let discAmt = 0;
        if (dType === 'percentage') discAmt = subtotal * dVal / 100;
        else if (dType === 'fixed') discAmt = dVal;
        if (discAmt > subtotal) discAmt = subtotal;
        const taxable = subtotal - discAmt;
        const vatPct = parseFloat(document.getElementById('vatPercentage').value) || 0;
        const vatAmt = taxable * vatPct / 100;
        const ship = parseFloat(document.getElementById('shipping').value) || 0;
        const total = taxable + vatAmt + ship;
        document.getElementById('sumSubtotal').textContent = invMoneyFmt(subtotal);
        document.getElementById('sumDiscount').textContent = invMoneyFmt(discAmt);
        document.getElementById('sumVat').textContent = invMoneyFmt(vatAmt);
        document.getElementById('sumTotal').textContent = invMoneyFmt(total);
        scheduleAutosave();
    }

    function collectPayload(status) {
        const p = {
            id: parseInt(document.getElementById('docId').value) || 0,
            customer_id: parseInt(document.getElementById('customerId').value) || 0,
            discount_type: document.getElementById('discountType').value,
            discount_value: parseFloat(document.getElementById('discountValue').value) || 0,
            vat_percentage: parseFloat(document.getElementById('vatPercentage').value) || 0,
            shipping_amount: parseFloat(document.getElementById('shipping').value) || 0,
            notes: document.getElementById('notes').value,
            terms_conditions: document.getElementById('terms').value,
            items: gatherItems(),
        };
        if (IS_QUOTE) {
            p.quotation_date = document.getElementById('primaryDate').value;
            p.expiry_date = document.getElementById('secondaryDate').value;
            p.status = status === 'send' ? 'Sent' : 'Draft';
        } else {
            p.invoice_date = document.getElementById('primaryDate').value;
            p.due_date = document.getElementById('secondaryDate').value;
            p.payment_terms = (document.getElementById('paymentTerms') || {}).value || '';
            if (status === 'issue') p.issue = 1;
        }
        return p;
    }

    let saving = false;
    async function saveDoc(status) {
        if (saving) return;
        const payload = collectPayload(status);
        if (!payload.customer_id) { invToast('Please select a customer.', 'error'); return; }
        if (payload.items.length === 0) { invToast('Add at least one item.', 'error'); return; }
        saving = true;
        try {
            const d = await invApi(IS_QUOTE ? 'save_quotation' : 'save_invoice', payload);
            invToast('Saved.', 'success');
            setTimeout(() => location.href = '<?= $viewPage ?>?id=' + d.id, 500);
        } catch (err) {
            invToast(err.message, 'error');
            saving = false;
        }
    }

    // ---- Auto-save draft (debounced) ----
    let autosaveTimer = null;
    function scheduleAutosave() {
        <?php if ($readonly): ?>return;<?php endif; ?>
        clearTimeout(autosaveTimer);
        autosaveTimer = setTimeout(autosave, 2800);
    }
    async function autosave() {
        const payload = collectPayload('draft');
        if (!payload.customer_id || payload.items.length === 0) return;
        try {
            document.getElementById('autosaveHint').textContent = 'Saving draft...';
            const d = await invApi(IS_QUOTE ? 'save_quotation' : 'save_invoice', payload);
            if (d.id) document.getElementById('docId').value = d.id;
            const t = new Date().toLocaleTimeString();
            document.getElementById('autosaveHint').textContent = 'Draft auto-saved at ' + t;
        } catch (err) {
            document.getElementById('autosaveHint').textContent = '';
        }
    }

    // ---- Product picker (POS-style) ----
    const PRODUCT_IMG_BASE = '../products/';
    function openProductPicker() {
        document.getElementById('pickerSearch').value = '';
        renderPicker();
        document.getElementById('productPicker').classList.add('active');
        setTimeout(() => document.getElementById('pickerSearch').focus(), 100);
    }
    function closeProductPicker() { document.getElementById('productPicker').classList.remove('active'); }

    function renderPicker() {
        const q = (document.getElementById('pickerSearch').value || '').trim().toLowerCase();
        const grid = document.getElementById('pickerGrid');
        const list = q === '' ? PRODUCTS : PRODUCTS.filter(p =>
            (p.name && p.name.toLowerCase().includes(q)) ||
            (p.barcode && String(p.barcode).toLowerCase().includes(q)) ||
            (p.category && p.category.toLowerCase().includes(q)));
        document.getElementById('pickerEmpty').classList.toggle('hidden', list.length !== 0);
        document.getElementById('pickerCount').textContent = list.length + ' product(s)';
        grid.innerHTML = list.slice(0, 300).map(p => {
            const qty = Number(p.quantity) || 0;
            const qtyColor = qty < 5 ? 'text-red-600' : (qty < 10 ? 'text-yellow-600' : 'text-teal-600');
            const img = p.image_url
                ? `<img src="${PRODUCT_IMG_BASE}${encodeURIComponent(p.image_url)}" alt="" class="object-cover object-center pointer-events-none" loading="lazy" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">`
                : '';
            return `
            <div class="bg-white rounded-xl shadow-lg overflow-hidden cursor-pointer transition-transform duration-300 hover:scale-105 product-item select-none" onclick="pickProduct(${p.id})">
                <div class="w-full overflow-hidden relative product-image-container bg-gray-100">
                    ${img}
                    <div class="w-full h-full items-center justify-center bg-gray-100" style="display:${p.image_url ? 'none' : 'flex'};">
                        <i class="fas fa-cube text-gray-400 text-4xl"></i>
                    </div>
                </div>
                <div class="p-3 flex flex-col">
                    <p class="text-sm font-bold text-gray-900 whitespace-nowrap overflow-hidden text-ellipsis" title="${escapeHtml(p.name)}">${escapeHtml(p.name)}</p>
                    <p class="text-lg font-extrabold text-teal-800">${invMoneyFmt(p.price)}</p>
                    <p class="text-xs ${qtyColor}">Available: ${qty}</p>
                </div>
            </div>`;
        }).join('');
    }

    function pickProduct(id) {
        const p = PRODUCTS.find(x => x.id === id);
        if (!p) return;
        // If a row already has this product, increment its quantity.
        let found = false;
        document.querySelectorAll('.inv-item-row').forEach(tr => {
            if (!found && String(tr.querySelector('.it-pid').value) === String(id)) {
                const qtyEl = tr.querySelector('.it-qty');
                qtyEl.value = (parseFloat(qtyEl.value) || 0) + 1;
                found = true;
            }
        });
        if (found) { recalc(); invToast(p.name + ' quantity updated.', 'success'); return; }
        // Reuse an empty starter row if present.
        let emptyRow = null;
        document.querySelectorAll('.inv-item-row').forEach(tr => {
            if (!emptyRow && !tr.querySelector('.it-name').value.trim() && !tr.querySelector('.it-desc').value.trim()) emptyRow = tr;
        });
        if (emptyRow) {
            emptyRow.querySelector('.it-pid').value = p.id;
            emptyRow.querySelector('.it-name').value = p.name;
            if (!parseFloat(emptyRow.querySelector('.it-price').value)) emptyRow.querySelector('.it-price').value = p.price;
            emptyRow.querySelector('.it-qty').value = emptyRow.querySelector('.it-qty').value || 1;
        } else {
            addRow({ product_id: p.id, product_name: p.name, description: '', quantity: 1, unit_price: p.price, discount: 0 });
        }
        recalc();
        invToast(p.name + ' added.', 'success');
    }

    // ---- Customer modal ----
    function openCustomerModal() { document.getElementById('customerModal').classList.add('active'); }
    function closeCustomerModal() { document.getElementById('customerModal').classList.remove('active'); }
    async function saveCustomer() {
        const name = document.getElementById('cxName').value.trim();
        if (!name) { invToast('Customer name is required.', 'error'); return; }
        try {
            const d = await invApi('save_customer', {
                name,
                phone: document.getElementById('cxPhone').value,
                email: document.getElementById('cxEmail').value,
                address: document.getElementById('cxAddress').value,
                tax_number: document.getElementById('cxTax').value,
            });
            const sel = document.getElementById('customerId');
            const opt = document.createElement('option');
            opt.value = d.customer.id; opt.textContent = d.customer.name + (d.customer.phone ? ' · ' + d.customer.phone : '');
            sel.appendChild(opt); sel.value = d.customer.id;
            closeCustomerModal();
            ['cxName','cxPhone','cxEmail','cxAddress','cxTax'].forEach(i => document.getElementById(i).value = '');
            invToast('Customer added.', 'success');
        } catch (err) { invToast(err.message, 'error'); }
    }

    // ---- Product modal ----
    function openProductModal() { document.getElementById('productModal').classList.add('active'); }
    function closeProductModal() { document.getElementById('productModal').classList.remove('active'); }
    async function saveProduct() {
        const name = document.getElementById('pxName').value.trim();
        if (!name) { invToast('Product name is required.', 'error'); return; }
        try {
            const d = await invApi('quick_create_product', {
                name,
                price: parseFloat(document.getElementById('pxPrice').value) || 0,
                quantity: parseInt(document.getElementById('pxQty').value) || 0,
            });
            PRODUCTS.push({ id: d.product.id, name: d.product.name, price: parseFloat(d.product.price) || 0, quantity: parseInt(document.getElementById('pxQty').value) || 0, category: '', image_url: '', barcode: '' });
            rebuildProductIndex();
            const dl = document.getElementById('productList');
            const opt = document.createElement('option'); opt.value = d.product.name; dl.appendChild(opt);
            closeProductModal();
            ['pxName','pxPrice','pxQty'].forEach(i => document.getElementById(i).value = '');
            invToast('Product created.', 'success');
        } catch (err) { invToast(err.message, 'error'); }
    }

    // Init
    document.getElementById('discountType').addEventListener('change', recalc);
    ['discountValue','vatPercentage','shipping'].forEach(id => document.getElementById(id).addEventListener('input', recalc));
    document.getElementById('customerId').addEventListener('change', scheduleAutosave);
    if (existingItems.length) { existingItems.forEach(addRow); } else { addRow(); }
    recalc();
</script>
