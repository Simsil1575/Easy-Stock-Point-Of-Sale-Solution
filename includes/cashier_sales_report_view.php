<?php
/**
 * Cashier Sales Report body (included from generate_report_pdf.php).
 * Expects $reportData and formatCurrency() in scope.
 */
$expectedCash = (float) ($reportData['summary']['net_cash_expected'] ?? (($reportData['summary']['total_cash'] ?? 0) - ($reportData['summary']['total_expenses'] ?? 0)));
$expectedEft = (float) ($reportData['summary']['total_eft'] ?? 0);
$totalExpenses = (float) ($reportData['summary']['total_expenses'] ?? 0);
$cashDenominations = [
    ['label' => 'N$200'],
    ['label' => 'N$100'],
    ['label' => 'N$60'],
    ['label' => 'N$50'],
    ['label' => 'N$30'],
    ['label' => 'N$20'],
    ['label' => 'N$10'],
    ['label' => 'N$5'],
    ['label' => 'N$1'],
    ['label' => '50c'],
    ['label' => '10c'],
    ['label' => '5c'],
];
$hasCashierActivity = false;
foreach ($reportData['cashiers'] ?? [] as $cashierRow) {
    if (($cashierRow['grand_total'] ?? 0) > 0 || ($cashierRow['expense_total'] ?? 0) > 0) {
        $hasCashierActivity = true;
        break;
    }
}
?>
<div class="summary-cards">
    <div class="summary-card">
        <div class="label">Total Staff</div>
        <div class="value"><?= $reportData['summary']['total_cashiers'] ?></div>
    </div>
    <div class="summary-card positive">
        <div class="label">Cash Sales</div>
        <div class="value">N$<?= formatCurrency($reportData['summary']['total_cash'] ?? 0) ?></div>
    </div>
    <div class="summary-card positive">
        <div class="label">EFT Sales</div>
        <div class="value">N$<?= formatCurrency($reportData['summary']['total_eft'] ?? 0) ?></div>
    </div>
    <div class="summary-card">
        <div class="label">Credit Sales</div>
        <div class="value">N$<?= formatCurrency($reportData['summary']['total_credit'] ?? 0) ?></div>
    </div>
    <div class="summary-card negative">
        <div class="label">Expenses</div>
        <div class="value">N$<?= formatCurrency($totalExpenses) ?></div>
    </div>
    <div class="summary-card positive">
        <div class="label">Grand Total</div>
        <div class="value">N$<?= formatCurrency($reportData['summary']['total_sales']) ?></div>
    </div>
</div>

<table>
    <thead>
        <tr>
            <th>Cashier</th>
            <th>Role</th>
            <th class="text-right">Orders</th>
            <th class="text-right">Cash</th>
            <th class="text-right">EFT</th>
            <th class="text-right">Credit</th>
            <th class="text-right">Expenses</th>
            <th class="text-right">Total</th>
        </tr>
    </thead>
    <tbody>
        <?php if ($hasCashierActivity): ?>
            <?php foreach ($reportData['cashiers'] as $cashier): ?>
            <?php if (($cashier['grand_total'] ?? 0) > 0 || ($cashier['expense_total'] ?? 0) > 0): ?>
            <tr>
                <td><?= htmlspecialchars($cashier['cashier_name']) ?></td>
                <td><?= ucfirst($cashier['role']) ?></td>
                <td class="text-right"><?= $cashier['order_count'] ?></td>
                <td class="text-right">N$<?= formatCurrency($cashier['cash_sales'] ?? 0) ?></td>
                <td class="text-right">N$<?= formatCurrency($cashier['eft_sales'] ?? 0) ?></td>
                <td class="text-right">N$<?= formatCurrency($cashier['credit_total']) ?></td>
                <td class="text-right text-red">N$<?= formatCurrency($cashier['expense_total'] ?? 0) ?></td>
                <td class="text-right font-bold">N$<?= formatCurrency($cashier['grand_total']) ?></td>
            </tr>
            <?php endif; ?>
            <?php endforeach; ?>
            <tr class="total-row">
                <td colspan="3">Total</td>
                <td class="text-right">N$<?= formatCurrency($reportData['summary']['total_cash'] ?? 0) ?></td>
                <td class="text-right">N$<?= formatCurrency($reportData['summary']['total_eft'] ?? 0) ?></td>
                <td class="text-right">N$<?= formatCurrency($reportData['summary']['total_credit'] ?? 0) ?></td>
                <td class="text-right text-red">N$<?= formatCurrency($totalExpenses) ?></td>
                <td class="text-right">N$<?= formatCurrency($reportData['summary']['total_sales']) ?></td>
            </tr>
        <?php else: ?>
            <tr><td colspan="8" class="no-data">No cashier sales found for this period</td></tr>
        <?php endif; ?>
    </tbody>
</table>

<h3 class="section-title">Cashier Expenses</h3>
<table>
    <thead>
        <tr>
            <th>Date/Time</th>
            <?php if (($reportData['summary']['cashier_label'] ?? '') === 'All cashiers'): ?>
            <th>Cashier</th>
            <?php endif; ?>
            <th>Description</th>
            <th class="text-right">Amount</th>
        </tr>
    </thead>
    <tbody>
        <?php if (!empty($reportData['expenses'])): ?>
            <?php foreach ($reportData['expenses'] as $expense): ?>
            <tr>
                <td><?= date('M j, Y H:i', strtotime($expense['created_at'])) ?></td>
                <?php if (($reportData['summary']['cashier_label'] ?? '') === 'All cashiers'): ?>
                <td><?= htmlspecialchars($expense['cashier_name'] ?? 'Unknown') ?></td>
                <?php endif; ?>
                <td><?= htmlspecialchars($expense['description'] !== '' ? $expense['description'] : 'Expense') ?></td>
                <td class="text-right text-red font-bold">N$<?= formatCurrency($expense['amount']) ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="total-row">
                <td colspan="<?= ($reportData['summary']['cashier_label'] ?? '') === 'All cashiers' ? 3 : 2 ?>">Total Expenses</td>
                <td class="text-right text-red">N$<?= formatCurrency($totalExpenses) ?></td>
            </tr>
        <?php else: ?>
            <tr>
                <td colspan="<?= ($reportData['summary']['cashier_label'] ?? '') === 'All cashiers' ? 4 : 3 ?>" class="no-data">No expenses recorded for this cashier during this period</td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>

<div class="cash-recon-section">
    <h3 class="section-title cash-recon-title">Physical Cash Count &amp; Reconciliation</h3>
    <p class="business-info" style="margin-bottom:8px;">Expected cash = cash sales minus expenses (N$<?= formatCurrency($reportData['summary']['total_cash'] ?? 0) ?> − N$<?= formatCurrency($totalExpenses) ?>)</p>

    <table class="cash-verify-table">
        <thead>
            <tr>
                <th></th>
                <th class="text-right">System Expected</th>
                <th class="text-center">Actual on Hand</th>
                <th class="text-center">Over / Short</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="font-bold">Cash</td>
                <td class="text-right">N$<?= formatCurrency($expectedCash) ?></td>
                <td class="write-cell"></td>
                <td class="write-cell"></td>
            </tr>
            <tr>
                <td class="font-bold">EFT</td>
                <td class="text-right">N$<?= formatCurrency($expectedEft) ?></td>
                <td class="write-cell"></td>
                <td class="write-cell"></td>
            </tr>
        </tbody>
    </table>

    <table class="denomination-table">
        <thead>
            <tr>
                <th class="denom-row-label"></th>
                <?php foreach ($cashDenominations as $denom): ?>
                <th><?= htmlspecialchars($denom['label']) ?></th>
                <?php endforeach; ?>
                <th class="denom-total-col">Total</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="denom-row-label font-bold">Count</td>
                <?php foreach ($cashDenominations as $denom): ?>
                <td class="write-cell denom-cell"></td>
                <?php endforeach; ?>
                <td class="write-cell denom-total-cell"></td>
            </tr>
            <tr>
                <td class="denom-row-label font-bold">Value</td>
                <?php foreach ($cashDenominations as $denom): ?>
                <td class="write-cell denom-cell"></td>
                <?php endforeach; ?>
                <td class="write-cell denom-total-cell"></td>
            </tr>
        </tbody>
    </table>

    <div class="signature-row">
        <div class="signature-block">
            <div class="signature-line"></div>
            <div class="signature-label">Cashier Signature</div>
            <div class="signature-meta">Name: _________________________&nbsp;&nbsp;Date: ______________</div>
        </div>
        <div class="signature-block">
            <div class="signature-line"></div>
            <div class="signature-label">Supervisor Signature</div>
            <div class="signature-meta">Name: _________________________&nbsp;&nbsp;Date: ______________</div>
        </div>
    </div>
</div>
