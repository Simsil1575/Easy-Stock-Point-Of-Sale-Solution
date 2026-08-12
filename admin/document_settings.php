<?php
$roleFolder = 'admin';
require __DIR__ . '/../includes/invoicing/context.php';

// Admin-only settings page.
if (invCurrentRole() !== 'admin') {
    header('Location: quotations');
    exit;
}

$flash = '';
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $data = [
            'email' => trim((string) ($_POST['email'] ?? '')),
            'website' => trim((string) ($_POST['website'] ?? '')),
            'tax_number' => trim((string) ($_POST['tax_number'] ?? '')),
            'vat_number' => trim((string) ($_POST['vat_number'] ?? '')),
            'currency' => trim((string) ($_POST['currency'] ?? 'N$')) ?: 'N$',
            'invoice_prefix' => trim((string) ($_POST['invoice_prefix'] ?? 'INV-')),
            'quotation_prefix' => trim((string) ($_POST['quotation_prefix'] ?? 'QTN-')),
            'default_payment_terms' => trim((string) ($_POST['default_payment_terms'] ?? '')),
            'default_terms_conditions' => trim((string) ($_POST['default_terms_conditions'] ?? '')),
            'default_notes' => trim((string) ($_POST['default_notes'] ?? '')),
            'footer_bank_name' => trim((string) ($_POST['footer_bank_name'] ?? '')),
            'footer_account_number' => trim((string) ($_POST['footer_account_number'] ?? '')),
            'footer_branch_code' => trim((string) ($_POST['footer_branch_code'] ?? '')),
            'footer_tax_info' => trim((string) ($_POST['footer_tax_info'] ?? '')),
            'footer_custom_info' => trim((string) ($_POST['footer_custom_info'] ?? '')),
        ];

        invSaveDocumentSettings($data);
        $flash = 'Document settings saved.';
    } catch (Throwable $e) {
        $flash = $e->getMessage();
        $flashType = 'error';
    }
    $settings = invGetDocumentSettings();
}

$e = fn($v) => htmlspecialchars((string) $v);
$pageTitle = 'Document Settings';
$mobileTitle = 'Document Settings';
include __DIR__ . '/../includes/invoicing/layout_top.php';
?>
<div class="mb-4 flex items-center justify-between">
    <div>
        <h1 class="text-2xl font-bold text-gray-800">Document Settings</h1>
        <p class="text-gray-500 text-sm mt-1">Company details, numbering and defaults used on quotations &amp; invoices</p>
    </div>
    <a href="settings" class="px-3 py-2 text-sm rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-100"><i class="fas fa-arrow-left mr-1"></i> Back to Settings</a>
</div>

<?php if ($flash !== ''): ?>
<div class="mb-4 rounded-lg px-4 py-3 text-sm <?= $flashType === 'success' ? 'bg-emerald-50 border border-emerald-200 text-emerald-700' : 'bg-rose-50 border border-rose-200 text-rose-700' ?>"><?= $e($flash) ?></div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="space-y-4 max-w-4xl">
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
        <div class="flex items-start justify-between gap-3 mb-4">
            <div>
                <h3 class="font-semibold text-gray-800">Company Information</h3>
                <p class="text-xs text-gray-500 mt-1">Pulled live from Business Settings (<code>business_info</code>). Edit name, address, phone and logo there.</p>
            </div>
            <a href="business_settings" class="text-xs px-3 py-1.5 rounded-lg border border-teal-200 text-teal-700 hover:bg-teal-50 whitespace-nowrap"><i class="fas fa-building mr-1"></i> Business Settings</a>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="md:col-span-2">
                <label class="block text-xs font-medium text-gray-500 mb-1">Company Name</label>
                <input value="<?= $e($settings['company_name'] ?? '') ?>" readonly class="w-full border border-gray-200 bg-gray-50 rounded-lg px-3 py-2 text-sm text-gray-700">
            </div>
            <div class="md:col-span-2">
                <label class="block text-xs font-medium text-gray-500 mb-1">Address</label>
                <textarea rows="2" readonly class="w-full border border-gray-200 bg-gray-50 rounded-lg px-3 py-2 text-sm text-gray-700"><?= $e($settings['company_address'] ?? '') ?></textarea>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Telephone</label>
                <input value="<?= $e($settings['telephone'] ?? '') ?>" readonly class="w-full border border-gray-200 bg-gray-50 rounded-lg px-3 py-2 text-sm text-gray-700">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Logo</label>
                <div class="flex items-center gap-3 min-h-[42px]">
                    <?php if (!empty($settings['company_logo'])): ?>
                        <img src="../<?= $e(ltrim((string) $settings['company_logo'], '/')) ?>" alt="Logo" class="h-12 w-auto border rounded">
                        <span class="text-xs text-gray-500"><?= $e($settings['company_logo']) ?></span>
                    <?php else: ?>
                        <span class="text-xs text-gray-400">No logo set in Business Settings</span>
                    <?php endif; ?>
                </div>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Email</label>
                <input name="email" value="<?= $e($settings['email'] ?? '') ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Website</label>
                <input name="website" value="<?= $e($settings['website'] ?? '') ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Currency Symbol</label>
                <input name="currency" value="<?= $e($settings['currency'] ?? 'N$') ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Tax Number</label>
                <input name="tax_number" value="<?= $e($settings['tax_number'] ?? '') ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">VAT Number</label>
                <input name="vat_number" value="<?= $e($settings['vat_number'] ?? '') ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
        </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
        <h3 class="font-semibold text-gray-800 mb-4">Numbering &amp; Defaults</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Invoice Prefix</label>
                <input name="invoice_prefix" value="<?= $e($settings['invoice_prefix'] ?? 'INV-') ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Quotation Prefix</label>
                <input name="quotation_prefix" value="<?= $e($settings['quotation_prefix'] ?? 'QTN-') ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div class="md:col-span-2">
                <label class="block text-xs font-medium text-gray-500 mb-1">Default Payment Terms</label>
                <input name="default_payment_terms" value="<?= $e($settings['default_payment_terms'] ?? '') ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div class="md:col-span-2">
                <label class="block text-xs font-medium text-gray-500 mb-1">Default Notes</label>
                <textarea name="default_notes" rows="2" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"><?= $e($settings['default_notes'] ?? '') ?></textarea>
            </div>
            <div class="md:col-span-2">
                <label class="block text-xs font-medium text-gray-500 mb-1">Default Terms &amp; Conditions</label>
                <textarea name="default_terms_conditions" rows="3" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"><?= $e($settings['default_terms_conditions'] ?? '') ?></textarea>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
        <h3 class="font-semibold text-gray-800 mb-1">Invoice &amp; Quotation Footer</h3>
        <p class="text-xs text-gray-500 mb-4">Banking, tax and custom details printed at the bottom of invoices and quotations.</p>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Bank Name</label>
                <input name="footer_bank_name" value="<?= $e($settings['footer_bank_name'] ?? '') ?>" placeholder="e.g. FNB, Bank Windhoek" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Account Number</label>
                <input name="footer_account_number" value="<?= $e($settings['footer_account_number'] ?? '') ?>" placeholder="e.g. 62123456789" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Branch Code</label>
                <input name="footer_branch_code" value="<?= $e($settings['footer_branch_code'] ?? '') ?>" placeholder="Optional" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Footer Tax Info</label>
                <input name="footer_tax_info" value="<?= $e($settings['footer_tax_info'] ?? '') ?>" placeholder="e.g. Tax No: 12345678 | VAT No: 98765432" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <p class="text-xs text-gray-400 mt-1">Shown in the document footer. Tax/VAT numbers above also appear in the header.</p>
            </div>
            <div class="md:col-span-2">
                <label class="block text-xs font-medium text-gray-500 mb-1">Additional Footer Information</label>
                <textarea name="footer_custom_info" rows="3" placeholder="Any other details (EFT reference, payment instructions, etc.)" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"><?= $e($settings['footer_custom_info'] ?? '') ?></textarea>
            </div>
        </div>
    </div>

    <div class="flex justify-end">
        <button type="submit" class="px-6 py-2.5 rounded-lg bg-teal-600 text-white hover:bg-teal-700 text-sm font-medium"><i class="fas fa-save mr-1"></i> Save Settings</button>
    </div>
</form>
<?php include __DIR__ . '/../includes/invoicing/layout_bottom.php'; ?>
