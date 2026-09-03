<?php

declare(strict_types=1);

require_once __DIR__ . '/context.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$patient = $id > 0 ? medicalAidGetPatient($db, $id) : null;

if (!$patient) {
    header('Location: ' . ($listPage ?? 'medical_aid'));
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_payment'])) {
    $amount = (float) ($_POST['amount'] ?? 0);
    $reference = trim((string) ($_POST['payment_reference'] ?? ''));
    $schemeName = trim((string) ($_POST['scheme_name'] ?? $patient['scheme_name'] ?? ''));

    if ($amount <= 0) {
        $error = 'Payment amount must be greater than zero.';
    } else {
        try {
            $db->beginTransaction();
            $result = medicalAidAllocatePayment(
                $db,
                $id,
                $amount,
                $reference,
                $schemeName,
                medicalAidCurrentUsername()
            );
            $db->commit();
            $success = medicalAidDescribePaymentResult($result);
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error = $e->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['close_session'])) {
    $session = medicalAidGetOpenSession($db, $id);
    if ($session) {
        medicalAidCloseSession($db, (int) $session['id']);
        $success = 'Running session closed.';
    }
}

$outstanding = medicalAidGetPatientOutstanding($db, $id);
$sales = medicalAidGetPatientSales($db, $id);
$payments = medicalAidGetPatientPayments($db, $id);
$openSession = medicalAidGetOpenSession($db, $id);
$canRemovePatient = !empty($maCanDeletePatient)
    && $outstanding <= 0
    && $openSession === null;

$pageTitle = 'Medical Aid — ' . $patient['patient_name'];
$mobileTitle = 'Patient Account';

require __DIR__ . '/layout_top.php';
?>

<div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
    <div>
        <h1 class="text-2xl font-bold text-gray-800"><?= htmlspecialchars($patient['patient_name']) ?></h1>
        <p class="text-gray-600 text-sm mt-1">
            <?= htmlspecialchars($patient['scheme_name'] ?: 'No scheme') ?>
            <?php if ($patient['member_number']): ?> · Member <?= htmlspecialchars($patient['member_number']) ?><?php endif; ?>
        </p>
    </div>
    <div class="flex items-center gap-2">
        <a href="<?= htmlspecialchars($listPage ?? 'medical_aid') ?>" class="px-3 py-2 text-sm rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-100"><i class="fas fa-arrow-left mr-1"></i> Back</a>
        <a href="<?= htmlspecialchars($editPage) ?>?id=<?= $id ?>" class="px-3 py-2 text-sm rounded-lg bg-gray-800 text-white hover:bg-gray-900"><i class="fas fa-pen mr-1"></i> Edit</a>
        <?php if ($canRemovePatient): ?>
            <button type="button"
                id="btnDeletePatient"
                class="px-3 py-2 text-sm rounded-lg border border-red-200 text-red-600 hover:bg-red-50"
                data-id="<?= $id ?>"
                data-name="<?= htmlspecialchars($patient['patient_name'], ENT_QUOTES) ?>">
                <i class="fas fa-trash mr-1"></i> Delete
            </button>
        <?php endif; ?>
    </div>
</div>

<?php if ($error): ?><div class="mb-4 p-3 rounded-lg bg-red-50 text-red-700 text-sm"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="mb-4 p-3 rounded-lg bg-green-50 text-green-700 text-sm"><?= htmlspecialchars($success) ?></div><?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
    <div class="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
        <div class="text-xs text-gray-500">Outstanding Balance</div>
        <div class="text-2xl font-bold text-amber-600">N$ <?= number_format($outstanding, 2) ?></div>
    </div>
    <div class="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
        <div class="text-xs text-gray-500">Phone</div>
        <div class="text-lg font-semibold text-gray-800"><?= htmlspecialchars($patient['phone'] ?: '—') ?></div>
    </div>
    <div class="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
        <div class="text-xs text-gray-500">Running Session</div>
        <?php if ($openSession): ?>
            <div class="text-lg font-semibold text-teal-600">Open · N$ <?= number_format((float) $openSession['current_balance'], 2) ?></div>
            <form method="post" class="mt-2">
                <input type="hidden" name="close_session" value="1">
                <button type="submit" class="text-xs text-red-600 hover:underline">Close session</button>
            </form>
        <?php else: ?>
            <div class="text-lg text-gray-400">None</div>
        <?php endif; ?>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-6">
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-100 font-semibold text-gray-800">Sales / Claims</div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                        <tr>
                            <th class="px-4 py-2 text-left">Date</th>
                            <th class="px-4 py-2 text-left">Type</th>
                            <th class="px-4 py-2 text-left">Items</th>
                            <th class="px-4 py-2 text-right">Total</th>
                            <th class="px-4 py-2 text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php if (empty($sales)): ?>
                            <tr><td colspan="5" class="px-4 py-8 text-center text-gray-400">No sales yet.</td></tr>
                        <?php else: foreach ($sales as $s): ?>
                            <tr>
                                <td class="px-4 py-2 text-gray-600"><?= htmlspecialchars(substr($s['created_at'], 0, 16)) ?></td>
                                <td class="px-4 py-2"><?= $s['sale_type'] === 'running' ? 'Running' : 'Account' ?></td>
                                <td class="px-4 py-2 text-gray-600 max-w-xs truncate"><?= htmlspecialchars($s['items_summary'] ?: '-') ?></td>
                                <td class="px-4 py-2 text-right font-medium">N$ <?= number_format((float) $s['total_amount'], 2) ?></td>
                                <td class="px-4 py-2 text-center">
                                    <?php
                                    $st = $s['payment_status'];
                                    $cls = $st === 'paid' ? 'bg-green-100 text-green-700' : ($st === 'partial' ? 'bg-amber-100 text-amber-700' : 'bg-gray-100 text-gray-600');
                                    ?>
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium <?= $cls ?>"><?= htmlspecialchars(ucfirst($st)) ?></span>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-100 font-semibold text-gray-800">Scheme Payments</div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                        <tr>
                            <th class="px-4 py-2 text-left">Date</th>
                            <th class="px-4 py-2 text-left">Reference</th>
                            <th class="px-4 py-2 text-left">Scheme</th>
                            <th class="px-4 py-2 text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php if (empty($payments)): ?>
                            <tr><td colspan="4" class="px-4 py-8 text-center text-gray-400">No payments recorded.</td></tr>
                        <?php else: foreach ($payments as $pay): ?>
                            <tr>
                                <td class="px-4 py-2 text-gray-600"><?= htmlspecialchars(substr($pay['created_at'], 0, 16)) ?></td>
                                <td class="px-4 py-2"><?= htmlspecialchars($pay['payment_reference'] ?: '—') ?></td>
                                <td class="px-4 py-2"><?= htmlspecialchars($pay['scheme_name'] ?: '—') ?></td>
                                <td class="px-4 py-2 text-right font-medium text-green-700">N$ <?= number_format((float) $pay['amount'], 2) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div>
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
            <h3 class="font-semibold text-gray-800 mb-3">Record Scheme Payment</h3>
            <?php if ($outstanding <= 0): ?>
                <p class="text-sm text-gray-500">No outstanding claims — scheme payment can be recorded once new sales are on this account.</p>
            <?php else: ?>
            <p class="text-xs text-gray-500 mb-3">Payments apply to oldest unpaid claims first (FIFO). Maximum: <strong class="text-amber-600">N$ <?= number_format($outstanding, 2) ?></strong></p>
            <form method="post" class="space-y-3">
                <input type="hidden" name="record_payment" value="1">
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Amount (N$)</label>
                    <input type="number" step="0.01" min="0.01" max="<?= htmlspecialchars(number_format($outstanding, 2, '.', '')) ?>" name="amount" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" placeholder="Up to <?= number_format($outstanding, 2) ?>">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Payment Reference</label>
                    <input type="text" name="payment_reference" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" placeholder="Claim / remittance ref">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Scheme</label>
                    <input type="text" name="scheme_name" value="<?= htmlspecialchars($patient['scheme_name'] ?? '') ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <button type="submit" class="w-full px-4 py-2 text-sm rounded-lg bg-teal-600 text-white hover:bg-teal-700 font-medium">Record Payment</button>
            </form>
            <?php endif; ?>
        </div>

        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 mt-4 text-sm text-gray-600">
            <div class="font-medium text-gray-800 mb-2">Patient Details</div>
            <?php if ($patient['auth_reference']): ?><div>Auth: <?= htmlspecialchars($patient['auth_reference']) ?></div><?php endif; ?>
            <?php if ($patient['dependant_code']): ?><div>Dependant: <?= htmlspecialchars($patient['dependant_code']) ?></div><?php endif; ?>
            <?php if ($patient['email']): ?><div>Email: <?= htmlspecialchars($patient['email']) ?></div><?php endif; ?>
            <?php if ($patient['notes']): ?><div class="mt-2 text-gray-500"><?= nl2br(htmlspecialchars($patient['notes'])) ?></div><?php endif; ?>
        </div>
    </div>
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
