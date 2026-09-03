<?php

declare(strict_types=1);

require_once __DIR__ . '/context.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$patient = $id > 0 ? medicalAidGetPatient($db, $id) : null;
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'patient_name' => trim((string) ($_POST['patient_name'] ?? '')),
        'phone' => trim((string) ($_POST['phone'] ?? '')),
        'email' => trim((string) ($_POST['email'] ?? '')),
        'scheme_name' => trim((string) ($_POST['scheme_name'] ?? '')),
        'member_number' => trim((string) ($_POST['member_number'] ?? '')),
        'dependant_code' => trim((string) ($_POST['dependant_code'] ?? '')),
        'auth_reference' => trim((string) ($_POST['auth_reference'] ?? '')),
        'notes' => trim((string) ($_POST['notes'] ?? '')),
        'active' => isset($_POST['active']) ? 1 : 0,
    ];

    if ($data['patient_name'] === '') {
        $error = 'Patient name is required.';
    } else {
        try {
            if ($id > 0) {
                medicalAidUpdatePatient($db, $id, $data);
                $success = 'Patient updated.';
                $patient = medicalAidGetPatient($db, $id);
            } else {
                $newId = medicalAidCreatePatient($db, $data);
                header('Location: ' . ($viewPage ?? 'medical_aid_view') . '?id=' . $newId);
                exit;
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$pageTitle = $id > 0 ? 'Edit Patient' : 'New Patient';
$mobileTitle = $pageTitle;

require __DIR__ . '/layout_top.php';

$p = $patient ?: [
    'patient_name' => '', 'phone' => '', 'email' => '', 'scheme_name' => '',
    'member_number' => '', 'dependant_code' => '', 'auth_reference' => '', 'notes' => '', 'active' => 1,
];
$canRemovePatient = $id > 0 && !empty($maCanDeletePatient) && medicalAidPatientCanBeRemoved($db, $id);
?>

<div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
    <div>
        <h1 class="text-2xl lg:text-3xl font-bold text-gray-800"><?= htmlspecialchars($pageTitle) ?></h1>
        <p class="text-gray-600 text-sm mt-1"><?= $id > 0 ? 'Update patient details and scheme information' : 'Add a new medical aid patient account' ?></p>
    </div>
    <div class="flex items-center gap-2">
        <a href="<?= htmlspecialchars($listPage ?? 'medical_aid') ?>" class="px-3 py-2 text-sm rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-100">
            <i class="fas fa-arrow-left mr-1"></i> Back
        </a>
        <?php if ($id > 0): ?>
            <a href="<?= htmlspecialchars($viewPage) ?>?id=<?= $id ?>" class="px-4 py-2 text-sm rounded-lg bg-teal-600 text-white hover:bg-teal-700 font-medium">
                <i class="fas fa-eye mr-1"></i> View Account
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($error): ?><div class="mb-4 p-3 rounded-lg bg-red-50 text-red-700 text-sm border border-red-100"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="mb-4 p-3 rounded-lg bg-green-50 text-green-700 text-sm border border-green-100"><?= htmlspecialchars($success) ?></div><?php endif; ?>

<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 lg:p-8">
    <form method="post">
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 lg:gap-6">
        <div class="md:col-span-2 xl:col-span-3">
            <label class="block text-xs font-medium text-gray-500 mb-1">Patient Name *</label>
            <input type="text" name="patient_name" required value="<?= htmlspecialchars($p['patient_name']) ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Phone</label>
            <input type="text" name="phone" value="<?= htmlspecialchars($p['phone'] ?? '') ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Email</label>
            <input type="email" name="email" value="<?= htmlspecialchars($p['email'] ?? '') ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Scheme Name</label>
            <input type="text" name="scheme_name" value="<?= htmlspecialchars($p['scheme_name'] ?? '') ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Member Number</label>
            <input type="text" name="member_number" value="<?= htmlspecialchars($p['member_number'] ?? '') ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Dependant Code</label>
            <input type="text" name="dependant_code" value="<?= htmlspecialchars($p['dependant_code'] ?? '') ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Auth Reference</label>
            <input type="text" name="auth_reference" value="<?= htmlspecialchars($p['auth_reference'] ?? '') ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <div class="md:col-span-2 xl:col-span-3">
            <label class="block text-xs font-medium text-gray-500 mb-1">Notes</label>
            <textarea name="notes" rows="3" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"><?= htmlspecialchars($p['notes'] ?? '') ?></textarea>
        </div>
        <?php if ($id > 0): ?>
        <div class="md:col-span-2 xl:col-span-3">
            <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" name="active" value="1" <?= !empty($p['active']) ? 'checked' : '' ?>>
                Active
            </label>
        </div>
        <?php endif; ?>
        </div>
        <div class="mt-6 pt-6 border-t border-gray-100 flex flex-wrap gap-2 items-center">
            <button type="submit" class="px-4 py-2 text-sm rounded-lg bg-teal-600 text-white hover:bg-teal-700 font-medium">Save</button>
            <?php if ($id > 0 && $canRemovePatient): ?>
                <button type="button"
                    id="btnDeletePatient"
                    class="px-4 py-2 text-sm rounded-lg border border-red-200 text-red-600 hover:bg-red-50"
                    data-id="<?= $id ?>"
                    data-name="<?= htmlspecialchars($p['patient_name'], ENT_QUOTES) ?>">
                    <i class="fas fa-trash mr-1"></i> Delete Patient
                </button>
            <?php endif; ?>
        </div>
    </form>
    <?php if (!$canRemovePatient && $id > 0 && !empty($maCanDeletePatient)): ?>
        <p class="mt-4 text-xs text-gray-500">Delete is available once outstanding balance is cleared and any open session is closed.</p>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/delete_patient_script.php'; ?>
<?php if ($canRemovePatient): ?>
<script>
document.getElementById('btnDeletePatient')?.addEventListener('click', function () {
    medicalAidDeletePatient(
        parseInt(this.getAttribute('data-id'), 10),
        this.getAttribute('data-name') || 'this patient',
        '<?= htmlspecialchars($listPage ?? 'medical_aid') ?>'
    );
});
</script>
<?php endif; ?>

<?php require __DIR__ . '/layout_bottom.php'; ?>
