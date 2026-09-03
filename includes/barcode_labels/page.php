<?php require __DIR__ . '/layout_top.php';

$totalProducts = (int) ($list['with_barcode'] + $list['without_barcode']);
$missingCount = (int) $list['without_barcode'];
$hasFilters = ($search !== '' || $category !== '' || $status !== '');
$inputCls = 'border border-gray-300 rounded-md px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent';
$btnGhost = 'inline-flex items-center h-9 px-3 text-sm rounded-md border border-gray-300 bg-white text-gray-700 hover:bg-gray-50';
$btnPrimary = 'inline-flex items-center h-9 px-3 text-sm rounded-md bg-teal-600 text-white hover:bg-teal-700';
?>

<div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
    <div class="flex items-center gap-3 min-w-0">
        <a href="<?= htmlspecialchars($backHref) ?>" class="inline-flex h-9 w-9 items-center justify-center rounded-md text-gray-500 hover:bg-white hover:text-gray-800" title="Back">
            <i class="fas fa-arrow-left"></i>
        </a>
        <div class="min-w-0">
            <h1 class="text-xl font-semibold text-gray-900 leading-tight">Barcode Labels</h1>
            <p class="text-xs text-gray-500 mt-0.5">
                <?= $totalProducts ?> product<?= $totalProducts === 1 ? '' : 's' ?>
                <?php if ($missingCount > 0): ?>
                    · <span class="text-amber-600"><?= $missingCount ?> missing</span>
                <?php endif; ?>
            </p>
        </div>
    </div>
    <form method="get" class="flex flex-wrap items-center gap-2 lg:justify-end">
        <button type="button" id="btnGenerateMissingAll" class="<?= $btnGhost ?>">
            <i class="fas fa-barcode mr-1.5 text-amber-600"></i> Generate missing
        </button>
        <select name="category" onchange="this.form.submit()" class="<?= $inputCls ?> min-w-[150px]">
            <option value="">All categories</option>
            <option value="__uncategorized__" <?= $category === '__uncategorized__' ? 'selected' : '' ?>>Uncategorized</option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?= htmlspecialchars($cat) ?>" <?= $category === $cat ? 'selected' : '' ?>><?= htmlspecialchars($cat) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status" onchange="this.form.submit()" class="<?= $inputCls ?> min-w-[150px]">
            <option value="">All status</option>
            <option value="has" <?= $status === 'has' ? 'selected' : '' ?>>Has barcode</option>
            <option value="missing" <?= $status === 'missing' ? 'selected' : '' ?>>Missing barcode</option>
        </select>
        <div class="relative">
            <button type="submit" class="absolute left-0 top-0 h-full w-9 text-gray-400 hover:text-gray-600" title="Search">
                <i class="fas fa-search text-xs"></i>
            </button>
            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search name or barcode"
                   class="<?= $inputCls ?> w-56 sm:w-72 pl-9 <?= $hasFilters ? 'pr-8' : '' ?>">
            <?php if ($hasFilters): ?>
                <a href="barcode_labels" class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-700" title="Clear">
                    <i class="fas fa-times text-xs"></i>
                </a>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php if ($flash !== ''): ?>
    <div class="mb-4 rounded-md bg-green-50 px-4 py-2.5 text-sm text-green-800"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>
<?php if ($flashErr !== ''): ?>
    <div class="mb-4 rounded-md bg-red-50 px-4 py-2.5 text-sm text-red-800"><?= htmlspecialchars($flashErr) ?></div>
<?php endif; ?>

<div class="bg-white rounded-lg border border-gray-200 shadow-sm overflow-hidden">
    <div class="px-4 py-2.5 border-b border-gray-200 flex flex-wrap items-center gap-2">
        <span class="text-sm text-gray-500"><span id="selectedCount">0</span> selected</span>
        <label class="inline-flex items-center gap-1.5 text-xs text-gray-500">
            Copies
            <input type="number" id="printCopies" value="<?= (int) $settings['label_default_copies'] ?>" min="1" max="99" class="w-14 border border-gray-300 rounded-md px-2 py-1 text-sm">
        </label>
        <div class="flex-1"></div>
        <button type="button" id="btnPrintTest" class="<?= $btnGhost ?>" title="Print test label">
            <i class="fas fa-vial mr-1.5"></i> Test
        </button>
        <button type="button" id="btnPrintQz" class="<?= $btnPrimary ?>">
            <i class="fas fa-print mr-1.5"></i> Print
        </button>
        <button type="button" id="btnPrintNetwork" class="<?= $btnGhost ?>">
            <i class="fas fa-network-wired mr-1.5"></i> Network
        </button>
        <button type="button" id="btnExportPdf" class="<?= $btnGhost ?>">
            <i class="fas fa-file-pdf mr-1.5"></i> PDF
        </button>
        <button type="button" id="btnGenerateMissingSelected" class="<?= $btnGhost ?>">
            <i class="fas fa-barcode mr-1.5"></i> Generate
        </button>
        <button type="button" id="togglePrinterSettings" class="inline-flex items-center justify-center h-9 w-9 rounded-md text-gray-500 hover:bg-gray-100" title="Printer settings">
            <i class="fas fa-cog"></i>
        </button>
    </div>

    <div id="printerSettingsPanel" class="hidden px-4 py-4 border-b border-gray-200 bg-gray-50">
        <div class="flex flex-wrap items-center gap-4 mb-4">
            <span class="text-xs font-medium text-gray-600">Print fields</span>
            <label class="inline-flex items-center gap-1.5 text-sm text-gray-700 cursor-pointer">
                <input type="checkbox" id="fieldShowName" class="rounded border-gray-300 text-teal-600 focus:ring-teal-500" <?= !empty($settings['label_show_name']) ? 'checked' : '' ?>>
                Name
            </label>
            <label class="inline-flex items-center gap-1.5 text-sm text-gray-700 cursor-pointer">
                <input type="checkbox" id="fieldShowBarcode" class="rounded border-gray-300 text-teal-600 focus:ring-teal-500" <?= !empty($settings['label_show_barcode']) ? 'checked' : '' ?>>
                Barcode
            </label>
            <label class="inline-flex items-center gap-1.5 text-sm text-gray-700 cursor-pointer">
                <input type="checkbox" id="fieldShowPrice" class="rounded border-gray-300 text-teal-600 focus:ring-teal-500" <?= !empty($settings['label_show_price']) ? 'checked' : '' ?>>
                Price
            </label>
            <button type="button" id="btnSelectAllFields" class="text-xs text-teal-700 hover:underline">Select all</button>
            <input type="hidden" id="printFontSize" value="<?= (int) ($settings['label_font_size'] ?? 18) ?>">
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3">
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">QZ Tray printer</label>
                <input type="text" id="settingQzName" value="<?= htmlspecialchars((string) $settings['label_printer_qz_name']) ?>" class="w-full <?= $inputCls ?>" placeholder="ZDesigner / Zebra">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Network IP</label>
                <input type="text" id="settingPrinterIp" value="<?= htmlspecialchars((string) $settings['label_printer_ip']) ?>" class="w-full <?= $inputCls ?>" placeholder="192.168.1.100">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Port</label>
                <input type="number" id="settingPrinterPort" value="<?= (int) $settings['label_printer_port'] ?>" min="1" max="65535" class="w-full <?= $inputCls ?>">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Orientation</label>
                <select id="settingOrientation" class="w-full <?= $inputCls ?>">
                    <option value="portrait" <?= ($settings['label_orientation'] ?? '') === 'portrait' ? 'selected' : '' ?>>Horizontal (0°)</option>
                    <option value="landscape90" <?= ($settings['label_orientation'] ?? 'landscape90') === 'landscape90' ? 'selected' : '' ?>>Vertical 90°</option>
                    <option value="inverted180" <?= ($settings['label_orientation'] ?? '') === 'inverted180' ? 'selected' : '' ?>>Upside down (180°)</option>
                    <option value="landscape270" <?= ($settings['label_orientation'] ?? '') === 'landscape270' ? 'selected' : '' ?>>Vertical 270°</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Width (mm)</label>
                <input type="number" id="settingLabelWidth" value="<?= (int) $settings['label_width_mm'] ?>" min="20" max="200" class="w-full <?= $inputCls ?>">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Height (mm)</label>
                <input type="number" id="settingLabelHeight" value="<?= htmlspecialchars(number_format((float) $settings['label_height_mm'], 1, '.', '')) ?>" min="15" max="150" step="0.1" class="w-full <?= $inputCls ?>">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Default copies</label>
                <input type="number" id="settingDefaultCopies" value="<?= (int) $settings['label_default_copies'] ?>" min="1" max="99" class="w-full <?= $inputCls ?>">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Text size</label>
                <input type="number" id="settingFontSize" value="<?= (int) ($settings['label_font_size'] ?? 18) ?>" min="10" max="72" class="w-full <?= $inputCls ?>">
            </div>
        </div>
        <div class="mt-3 flex justify-end gap-2">
            <button type="button" id="btnResetDefaultSize" class="<?= $btnGhost ?>">Reset size</button>
            <button type="button" id="btnSaveSettings" class="inline-flex items-center h-9 px-3 text-sm rounded-md bg-gray-800 text-white hover:bg-gray-900">Save</button>
        </div>
    </div>

    <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-gray-500 uppercase text-xs tracking-wide">
                <tr>
                    <th class="px-4 py-3 w-10">
                        <input type="checkbox" id="selectAllProducts" class="rounded border-gray-300 text-teal-600 focus:ring-teal-500" aria-label="Select all">
                    </th>
                    <th class="px-4 py-3 text-left">Product</th>
                    <th class="px-4 py-3 text-left">Barcode</th>
                    <th class="px-4 py-3 text-left">Preview</th>
                    <th class="px-4 py-3 text-right">Price</th>
                    <th class="px-4 py-3 text-left">Category</th>
                    <th class="px-4 py-3 text-center">Status</th>
                    <th class="px-4 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100" id="productsTableBody">
                <?php if (empty($list['products'])): ?>
                    <tr><td colspan="8" class="px-4 py-12 text-center text-gray-400">No products match your filters.</td></tr>
                <?php else: foreach ($list['products'] as $p): ?>
                    <tr class="hover:bg-teal-50/50 bl-product-row" data-id="<?= (int) $p['id'] ?>">
                        <td class="px-4 py-3" onclick="event.stopPropagation()">
                            <input type="checkbox" class="bl-product-cb rounded border-gray-300 text-teal-600 focus:ring-teal-500" value="<?= (int) $p['id'] ?>">
                        </td>
                        <td class="px-4 py-3 font-semibold text-gray-800"><?= htmlspecialchars($p['name']) ?></td>
                        <td class="px-4 py-3 text-gray-700 font-mono text-xs"><?= $p['has_barcode'] ? htmlspecialchars($p['barcode']) : '—' ?></td>
                        <td class="px-4 py-3">
                            <?php if ($p['has_barcode']): ?>
                                <img src="https://barcode.tec-it.com/barcode.ashx?data=<?= urlencode($p['barcode']) ?>&amp;code=Code128&amp;dpi=96"
                                     alt="" class="h-8 max-w-[120px] object-contain bg-white border border-gray-100 rounded">
                            <?php else: ?>
                                <span class="text-gray-400 text-xs">No barcode</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-right text-gray-700">N$ <?= number_format((float) $p['price'], 2) ?></td>
                        <td class="px-4 py-3 text-gray-600"><?= $p['category'] !== '' ? htmlspecialchars($p['category']) : '—' ?></td>
                        <td class="px-4 py-3 text-center">
                            <?php if ($p['has_barcode']): ?>
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-teal-100 text-teal-700">Has barcode</span>
                            <?php else: ?>
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-700">Missing</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap" onclick="event.stopPropagation()">
                            <?php if ($p['has_barcode']): ?>
                                <button type="button" class="bl-btn-edit inline-flex w-8 h-8 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100" title="Edit barcode"
                                    data-id="<?= (int) $p['id'] ?>" data-name="<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>" data-barcode="<?= htmlspecialchars($p['barcode'], ENT_QUOTES) ?>">
                                    <i class="fas fa-pen"></i>
                                </button>
                                <button type="button" class="bl-btn-delete inline-flex w-8 h-8 items-center justify-center rounded-lg text-red-500 hover:bg-red-50" title="Clear barcode"
                                    data-id="<?= (int) $p['id'] ?>" data-name="<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>">
                                    <i class="fas fa-trash"></i>
                                </button>
                            <?php else: ?>
                                <button type="button" class="bl-btn-create inline-flex w-8 h-8 items-center justify-center rounded-lg text-teal-600 hover:bg-teal-50" title="Assign barcode"
                                    data-id="<?= (int) $p['id'] ?>" data-name="<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>">
                                    <i class="fas fa-plus"></i>
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($hasMore): ?>
        <div class="p-4 border-t border-gray-100 text-center">
            <a href="barcode_labels<?= htmlspecialchars($listQuery(['offset' => (string) $nextOffset])) ?>"
               class="inline-flex items-center gap-2 px-4 py-2 text-sm rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50">
                Load more (<?= count($list['products']) ?> of <?= (int) $list['total'] ?>)
            </a>
        </div>
    <?php elseif ($list['total'] > 0): ?>
        <div class="p-3 border-t border-gray-100 text-center text-xs text-gray-500">
            Showing <?= count($list['products']) ?> of <?= (int) $list['total'] ?> products
        </div>
    <?php endif; ?>
</div>

<div id="barcodeModal" class="fixed inset-0 z-[100] hidden items-center justify-center bg-black/40 p-4">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-md p-6">
        <h3 id="barcodeModalTitle" class="text-lg font-semibold text-gray-800 mb-1">Barcode</h3>
        <p id="barcodeModalProduct" class="text-sm text-gray-500 mb-4"></p>
        <input type="hidden" id="barcodeModalProductId">
        <input type="hidden" id="barcodeModalMode">
        <label class="block text-sm font-medium text-gray-700 mb-1" for="barcodeModalInput">Barcode value</label>
        <input type="text" id="barcodeModalInput" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm font-mono mb-4" placeholder="Leave empty to auto-generate">
        <div class="flex justify-end gap-2">
            <button type="button" id="barcodeModalCancel" class="px-4 py-2 text-sm rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-100">Cancel</button>
            <button type="button" id="barcodeModalSave" class="px-4 py-2 text-sm rounded-lg bg-teal-600 text-white hover:bg-teal-700">Save</button>
        </div>
    </div>
</div>

<script src="<?= htmlspecialchars($blBase) ?>receipt/js/qz-tray.js"></script>
<script src="<?= htmlspecialchars($blBase) ?>terminal.js"></script>
<script>
(function () {
    var AJAX_URL = <?= json_encode($ajaxUrl) ?>;
    var PRINT_URL = <?= json_encode($printUrl) ?>;
    var PDF_URL = <?= json_encode($pdfUrl) ?>;

    function selectedIds() {
        return Array.from(document.querySelectorAll('.bl-product-cb:checked')).map(function (cb) {
            return parseInt(cb.value, 10);
        }).filter(function (id) { return id > 0; });
    }

    function updateSelectedCount() {
        document.getElementById('selectedCount').textContent = String(selectedIds().length);
    }

    function collectSettings() {
        return {
            label_printer_qz_name: document.getElementById('settingQzName').value.trim(),
            label_printer_ip: document.getElementById('settingPrinterIp').value.trim(),
            label_printer_port: parseInt(document.getElementById('settingPrinterPort').value, 10) || 9100,
            label_width_mm: parseInt(document.getElementById('settingLabelWidth').value, 10) || 51,
            label_height_mm: parseFloat(document.getElementById('settingLabelHeight').value) || 37.8,
            label_default_copies: parseInt(document.getElementById('settingDefaultCopies').value, 10) || 1,
            label_orientation: document.getElementById('settingOrientation').value,
            label_font_size: parseInt(document.getElementById('printFontSize').value, 10) || parseInt(document.getElementById('settingFontSize').value, 10) || 18,
            label_show_name: document.getElementById('fieldShowName').checked,
            label_show_barcode: document.getElementById('fieldShowBarcode').checked,
            label_show_price: document.getElementById('fieldShowPrice').checked
        };
    }

    function collectLabelOptions() {
        return {
            label_show_name: document.getElementById('fieldShowName').checked,
            label_show_barcode: document.getElementById('fieldShowBarcode').checked,
            label_show_price: document.getElementById('fieldShowPrice').checked,
            label_orientation: document.getElementById('settingOrientation').value,
            label_width_mm: parseInt(document.getElementById('settingLabelWidth').value, 10) || 51,
            label_height_mm: parseFloat(document.getElementById('settingLabelHeight').value) || 37.8,
            label_font_size: parseInt(document.getElementById('printFontSize').value, 10) || parseInt(document.getElementById('settingFontSize').value, 10) || 18
        };
    }

    function printZplData(zpl, onSuccess) {
        return connectQz().then(function () {
            var printerName = collectSettings().label_printer_qz_name;
            var findPromise = printerName
                ? qz.printers.find(printerName)
                : qz.printers.getDefault();
            return findPromise.then(function (printer) {
                var config = qz.configs.create(printer);
                return qz.print(config, [{
                    type: 'raw',
                    format: 'command',
                    data: zpl
                }]).then(onSuccess);
            });
        });
    }

    function fetchTestZpl(labelOptions) {
        var params = new URLSearchParams();
        params.set('action', 'test_label');
        params.set('label_show_name', labelOptions.label_show_name ? '1' : '0');
        params.set('label_show_barcode', labelOptions.label_show_barcode ? '1' : '0');
        params.set('label_show_price', labelOptions.label_show_price ? '1' : '0');
        params.set('label_orientation', labelOptions.label_orientation);
        params.set('label_width_mm', String(labelOptions.label_width_mm));
        params.set('label_height_mm', String(labelOptions.label_height_mm));
        params.set('label_font_size', String(labelOptions.label_font_size || 18));
        return fetch(AJAX_URL + '?' + params.toString()).then(function (r) { return r.json(); });
    }

    document.getElementById('btnPrintTest').addEventListener('click', function () {
        if (!validateLabelFields()) return;
        var labelOptions = collectLabelOptions();
        fetchTestZpl(labelOptions).then(function (res) {
            if (!res.success) throw new Error(res.error || 'Could not build test label');
            return printZplData(res.zpl, function () {
                showToast('success', 'Test label sent to printer');
            });
        }).catch(function (e) {
            showToast('error', e.message + '. Try network print or check QZ Tray.');
        });
    });

    function validateLabelFields() {
        if (!document.getElementById('fieldShowName').checked
            && !document.getElementById('fieldShowBarcode').checked
            && !document.getElementById('fieldShowPrice').checked) {
            showToast('warning', 'Select at least one label field');
            return false;
        }
        return true;
    }

    document.getElementById('btnSelectAllFields').addEventListener('click', function () {
        document.getElementById('fieldShowName').checked = true;
        document.getElementById('fieldShowBarcode').checked = true;
        document.getElementById('fieldShowPrice').checked = true;
    });

    function apiPost(action, body) {
        return fetch(AJAX_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign({ action: action }, body || {}))
        }).then(function (r) { return r.json(); });
    }

    function showToast(icon, title) {
        if (typeof Swal !== 'undefined') {
            Swal.fire({ icon: icon, title: title, timer: 2200, showConfirmButton: false });
        } else {
            alert(title);
        }
    }

    document.getElementById('selectAllProducts').addEventListener('change', function () {
        var checked = this.checked;
        document.querySelectorAll('.bl-product-cb').forEach(function (cb) { cb.checked = checked; });
        updateSelectedCount();
    });
    document.querySelectorAll('.bl-product-cb').forEach(function (cb) {
        cb.addEventListener('change', updateSelectedCount);
    });

    document.getElementById('settingFontSize').addEventListener('input', function () {
        document.getElementById('printFontSize').value = this.value;
    });
    document.getElementById('printFontSize').addEventListener('input', function () {
        document.getElementById('settingFontSize').value = this.value;
    });

    document.getElementById('togglePrinterSettings').addEventListener('click', function () {
        document.getElementById('printerSettingsPanel').classList.toggle('hidden');
    });

    document.getElementById('btnSaveSettings').addEventListener('click', function () {
        apiPost('save_settings', collectSettings()).then(function (res) {
            if (!res.success) throw new Error(res.error || 'Save failed');
            showToast('success', 'Printer settings saved');
        }).catch(function (e) { showToast('error', e.message); });
    });

    document.getElementById('btnResetDefaultSize').addEventListener('click', function () {
        document.getElementById('settingLabelWidth').value = '51';
        document.getElementById('settingLabelHeight').value = '37.8';
        document.getElementById('settingOrientation').value = 'landscape90';
        document.getElementById('settingDefaultCopies').value = '1';
        document.getElementById('printCopies').value = '1';
        document.getElementById('settingFontSize').value = '18';
        document.getElementById('printFontSize').value = '18';
        apiPost('save_settings', collectSettings()).then(function (res) {
            if (!res.success) throw new Error(res.error || 'Reset failed');
            showToast('success', 'Reset to 51 × 37.8 mm, vertical 90°');
        }).catch(function (e) { showToast('error', e.message); });
    });

    var modal = document.getElementById('barcodeModal');
    function openModal(mode, id, name, barcode) {
        document.getElementById('barcodeModalMode').value = mode;
        document.getElementById('barcodeModalProductId').value = String(id);
        document.getElementById('barcodeModalProduct').textContent = name;
        document.getElementById('barcodeModalTitle').textContent = mode === 'create' ? 'Assign barcode' : 'Edit barcode';
        document.getElementById('barcodeModalInput').value = barcode || '';
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }
    function closeModal() {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }
    document.getElementById('barcodeModalCancel').addEventListener('click', closeModal);
    modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });

    document.querySelectorAll('.bl-btn-create').forEach(function (btn) {
        btn.addEventListener('click', function () {
            openModal('create', btn.getAttribute('data-id'), btn.getAttribute('data-name'), '');
        });
    });
    document.querySelectorAll('.bl-btn-edit').forEach(function (btn) {
        btn.addEventListener('click', function () {
            openModal('update', btn.getAttribute('data-id'), btn.getAttribute('data-name'), btn.getAttribute('data-barcode'));
        });
    });
    document.querySelectorAll('.bl-btn-delete').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = parseInt(btn.getAttribute('data-id'), 10);
            var name = btn.getAttribute('data-name') || 'this product';
            var confirmFn = (typeof Swal !== 'undefined')
                ? Swal.fire({ title: 'Clear barcode?', text: 'Remove barcode from ' + name + '?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc2626' }).then(function (r) { return r.isConfirmed; })
                : Promise.resolve(confirm('Clear barcode from ' + name + '?'));
            confirmFn.then(function (ok) {
                if (!ok) return;
                return apiPost('delete', { product_id: id });
            }).then(function (res) {
                if (!res) return;
                if (!res.success) throw new Error(res.error || 'Delete failed');
                location.reload();
            }).catch(function (e) { if (e) showToast('error', e.message); });
        });
    });

    document.getElementById('barcodeModalSave').addEventListener('click', function () {
        var mode = document.getElementById('barcodeModalMode').value;
        var id = parseInt(document.getElementById('barcodeModalProductId').value, 10);
        var barcode = document.getElementById('barcodeModalInput').value.trim();
        var action = mode === 'create' ? 'create' : 'update';
        var payload = { product_id: id };
        if (barcode !== '') payload.barcode = barcode;
        apiPost(action, payload).then(function (res) {
            if (!res.success) throw new Error(res.error || 'Save failed');
            location.reload();
        }).catch(function (e) { showToast('error', e.message); });
    });

    function generateMissing(ids) {
        return apiPost('generate_missing', ids ? { product_ids: ids } : {}).then(function (res) {
            if (!res.success) throw new Error(res.error || 'Generation failed');
            showToast('success', (res.generated || 0) + ' barcode(s) generated');
            setTimeout(function () { location.reload(); }, 800);
        });
    }

    document.getElementById('btnGenerateMissingAll').addEventListener('click', function () {
        generateMissing(null).catch(function (e) { showToast('error', e.message); });
    });
    document.getElementById('btnGenerateMissingSelected').addEventListener('click', function () {
        var ids = selectedIds();
        if (!ids.length) { showToast('warning', 'Select at least one product'); return; }
        generateMissing(ids).catch(function (e) { showToast('error', e.message); });
    });

    function getZpl(ids, copies, labelOptions) {
        var params = new URLSearchParams();
        params.set('action', 'get_zpl');
        params.set('copies', String(copies));
        params.set('label_show_name', labelOptions.label_show_name ? '1' : '0');
        params.set('label_show_barcode', labelOptions.label_show_barcode ? '1' : '0');
        params.set('label_show_price', labelOptions.label_show_price ? '1' : '0');
        params.set('label_orientation', labelOptions.label_orientation);
        params.set('label_width_mm', String(labelOptions.label_width_mm));
        params.set('label_height_mm', String(labelOptions.label_height_mm));
        params.set('label_font_size', String(labelOptions.label_font_size || 18));
        ids.forEach(function (id) { params.append('ids[]', String(id)); });
        return fetch(AJAX_URL + '?' + params.toString()).then(function (r) { return r.json(); });
    }

    function ensureQzSecurity() {
        if (window._blQzSecurityConfigured || typeof qz === 'undefined' || !qz.security) return;
        var assetBase = <?= json_encode($blBase . 'receipt/') ?>;
        qz.security.setCertificatePromise(function (resolve, reject) {
            fetch(assetBase + 'digital-certificate.txt', { cache: 'no-store', headers: { 'Content-Type': 'text/plain' } })
                .then(function (data) { data.ok ? resolve(data.text()) : reject(data.text()); })
                .catch(reject);
        });
        qz.security.setSignatureAlgorithm('SHA512');
        qz.security.setSignaturePromise(function (toSign) {
            return function (resolve, reject) {
                fetch(assetBase + 'assets/signing/sign-message.php?request=' + encodeURIComponent(toSign), { cache: 'no-store' })
                    .then(function (data) { data.ok ? resolve(data.text()) : reject(data.text()); })
                    .catch(reject);
            };
        });
        window._blQzSecurityConfigured = true;
    }

    function connectQz() {
        if (typeof qz === 'undefined') {
            return Promise.reject(new Error('QZ Tray is not loaded'));
        }
        ensureQzSecurity();
        if (qz.websocket.isActive()) return Promise.resolve();
        return qz.websocket.connect({ retries: 2, delay: 1 });
    }

    document.getElementById('btnPrintQz').addEventListener('click', function () {
        var ids = selectedIds();
        if (!ids.length) { showToast('warning', 'Select at least one product'); return; }
        if (!validateLabelFields()) return;
        var copies = parseInt(document.getElementById('printCopies').value, 10) || 1;
        var labelOptions = collectLabelOptions();

        getZpl(ids, copies, labelOptions).then(function (zplRes) {
            if (!zplRes.success) throw new Error(zplRes.error || 'Could not build labels');
            return printZplData(zplRes.zpl, function () {
                var msg = 'Sent ' + zplRes.printed_count + ' label(s) to printer';
                if (zplRes.skipped > 0) msg += ' (' + zplRes.skipped + ' skipped — missing selected fields)';
                showToast('success', msg);
            });
        }).catch(function (e) {
            showToast('error', e.message + '. Try network print or check QZ Tray.');
        });
    });

    document.getElementById('btnPrintNetwork').addEventListener('click', function () {
        var ids = selectedIds();
        if (!ids.length) { showToast('warning', 'Select at least one product'); return; }
        if (!validateLabelFields()) return;
        var copies = parseInt(document.getElementById('printCopies').value, 10) || 1;
        fetch(PRINT_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                product_ids: ids,
                copies: copies,
                settings: collectSettings(),
                label_options: collectLabelOptions()
            })
        }).then(function (r) { return r.json(); }).then(function (res) {
            if (!res.success) throw new Error(res.error || 'Network print failed');
            var msg = res.message || ('Sent ' + res.printed_count + ' label(s)');
            if (res.skipped > 0) msg += ' (' + res.skipped + ' skipped)';
            showToast('success', msg);
        }).catch(function (e) { showToast('error', e.message); });
    });

    document.getElementById('btnExportPdf').addEventListener('click', function () {
        var ids = selectedIds();
        if (!ids.length) { showToast('warning', 'Select at least one product'); return; }
        if (!validateLabelFields()) return;
        var labelOptions = collectLabelOptions();
        var params = new URLSearchParams();
        ids.forEach(function (id) { params.append('ids[]', String(id)); });
        params.set('label_show_name', labelOptions.label_show_name ? '1' : '0');
        params.set('label_show_barcode', labelOptions.label_show_barcode ? '1' : '0');
        params.set('label_show_price', labelOptions.label_show_price ? '1' : '0');
        window.location.href = PDF_URL + '?' + params.toString();
    });

    updateSelectedCount();
})();
</script>

<?php require __DIR__ . '/layout_bottom.php'; ?>
