<?php

declare(strict_types=1);

require_once __DIR__ . '/context.php';

$pageTitle = 'Medical Aid';
$mobileTitle = 'Medical Aid';

$search = trim((string) ($_GET['search'] ?? ''));
$patients = medicalAidFetchPatientsWithBalances($db);

if ($search !== '') {
    $needle = strtolower($search);
    $patients = array_values(array_filter($patients, function ($p) use ($needle) {
        $hay = strtolower(
            ($p['patient_name'] ?? '') . ' ' .
            ($p['phone'] ?? '') . ' ' .
            ($p['scheme_name'] ?? '') . ' ' .
            ($p['member_number'] ?? '')
        );
        return str_contains($hay, $needle);
    }));
}

$totalOutstanding = array_sum(array_map(fn($p) => (float) ($p['outstanding_balance'] ?? 0), $patients));
$openSessions = count(array_filter($patients, fn($p) => !empty($p['open_session_id'])));

require __DIR__ . '/layout_top.php';
?>

<div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
    <div>
        <h1 class="text-2xl lg:text-3xl font-bold text-gray-800">Medical Aid</h1>
        <p class="text-gray-600 text-sm mt-1">Manage patients and medical aid accounts</p>
    </div>
    <div class="flex items-center gap-2">
        <a href="<?= htmlspecialchars($backHref) ?>" class="px-3 py-2 text-sm rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-100">
            <i class="fas fa-arrow-left mr-1"></i> Menu
        </a>
        <a href="<?= htmlspecialchars($editPage) ?>" class="px-4 py-2 text-sm rounded-lg bg-teal-600 text-white hover:bg-teal-700 font-medium">
            <i class="fas fa-plus mr-1"></i> New Patient
        </a>
    </div>
</div>

<div class="mb-4 grid grid-cols-1 sm:grid-cols-3 gap-3">
    <div class="bg-white border border-gray-100 rounded-xl p-4 shadow-sm">
        <div class="text-2xl font-bold text-gray-800"><?= count($patients) ?></div>
        <div class="text-xs text-gray-500">Active Patients</div>
    </div>
    <div class="bg-white border border-gray-100 rounded-xl p-4 shadow-sm">
        <div class="text-2xl font-bold text-amber-600">N$ <?= number_format($totalOutstanding, 2) ?></div>
        <div class="text-xs text-gray-500">Outstanding Claims</div>
    </div>
    <div class="bg-white border border-gray-100 rounded-xl p-4 shadow-sm">
        <div class="text-2xl font-bold text-teal-600"><?= $openSessions ?></div>
        <div class="text-xs text-gray-500">Open Sessions</div>
    </div>
</div>

<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-4">
    <form method="get" class="flex flex-col sm:flex-row gap-3">
        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search name, phone, scheme, member no." class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm">
        <button type="submit" class="px-4 py-2 text-sm rounded-lg bg-gray-800 text-white hover:bg-gray-900"><i class="fas fa-search mr-1"></i> Search</button>
        <?php if ($search !== ''): ?>
            <a href="<?= htmlspecialchars($listPage ?? 'medical_aid') ?>" class="px-4 py-2 text-sm rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-100 text-center">Reset</a>
        <?php endif; ?>
    </form>
</div>

<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-100 text-gray-600 uppercase text-xs">
                <tr>
                    <th class="px-4 py-3 text-left">Patient</th>
                    <th class="px-4 py-3 text-left">Scheme</th>
                    <th class="px-4 py-3 text-left">Member No.</th>
                    <th class="px-4 py-3 text-left">Phone</th>
                    <th class="px-4 py-3 text-right">Outstanding</th>
                    <th class="px-4 py-3 text-center">Session</th>
                    <th class="px-4 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php if (empty($patients)): ?>
                    <tr><td colspan="7" class="px-4 py-12 text-center text-gray-400">No patients found.</td></tr>
                <?php else: foreach ($patients as $p):
                    $viewUrl = htmlspecialchars($viewPage) . '?id=' . (int) $p['id'];
                    $canRemove = !empty($maCanDeletePatient)
                        && (float) ($p['outstanding_balance'] ?? 0) <= 0
                        && empty($p['open_session_id']);
                ?>
                    <tr class="hover:bg-teal-50/70 cursor-pointer transition-colors"
                        onclick="window.location.href='<?= $viewUrl ?>'"
                        role="button"
                        tabindex="0"
                        onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();window.location.href='<?= $viewUrl ?>';}">
                        <td class="px-4 py-3 font-semibold text-gray-800"><?= htmlspecialchars($p['patient_name']) ?></td>
                        <td class="px-4 py-3 text-gray-600"><?= htmlspecialchars($p['scheme_name'] ?: '-') ?></td>
                        <td class="px-4 py-3 text-gray-600"><?= htmlspecialchars($p['member_number'] ?: '-') ?></td>
                        <td class="px-4 py-3 text-gray-600"><?= htmlspecialchars($p['phone'] ?: '-') ?></td>
                        <td class="px-4 py-3 text-right font-semibold <?= (float) $p['outstanding_balance'] > 0 ? 'text-amber-600' : 'text-gray-500' ?>">
                            N$ <?= number_format((float) $p['outstanding_balance'], 2) ?>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <?php if (!empty($p['open_session_id'])): ?>
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-teal-100 text-teal-700">Open</span>
                            <?php else: ?>
                                <span class="text-gray-400">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap" onclick="event.stopPropagation()">
                            <a href="<?= $viewUrl ?>" class="inline-flex w-8 h-8 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100" title="View"><i class="fas fa-eye"></i></a>
                            <a href="<?= htmlspecialchars($editPage) ?>?id=<?= (int) $p['id'] ?>" class="inline-flex w-8 h-8 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100" title="Edit"><i class="fas fa-pen"></i></a>
                            <?php if ($canRemove): ?>
                                <button type="button"
                                    class="btn-ma-delete inline-flex w-8 h-8 items-center justify-center rounded-lg text-red-500 hover:bg-red-50"
                                    title="Delete"
                                    data-id="<?= (int) $p['id'] ?>"
                                    data-name="<?= htmlspecialchars($p['patient_name'], ENT_QUOTES) ?>">
                                    <i class="fas fa-trash"></i>
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/delete_patient_script.php'; ?>
<script>
document.querySelectorAll('.btn-ma-delete').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
        e.stopPropagation();
        medicalAidDeletePatient(
            parseInt(btn.getAttribute('data-id'), 10),
            btn.getAttribute('data-name') || 'this patient',
            '<?= htmlspecialchars($listPage ?? 'medical_aid') ?>'
        );
    });
});
</script>

<?php require __DIR__ . '/layout_bottom.php'; ?>
