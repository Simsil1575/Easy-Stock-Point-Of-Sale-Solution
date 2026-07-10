<?php
/**
 * Cash-up session report (snapshot). Payload: ?s= base64-encoded JSON.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Africa/Harare');

$allowedRoles = ['admin', 'manager'];
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || !in_array(strtolower($_SESSION['role']), $allowedRoles)) {
    header('Location: ../');
    exit();
}

$raw = isset($_GET['s']) ? (string) $_GET['s'] : '';
$json = null;
if ($raw !== '') {
    $decoded = base64_decode($raw, true);
    if ($decoded !== false) {
        $json = json_decode($decoded, true);
    }
}

if (!is_array($json)) {
    http_response_code(400);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Report</title></head><body><p>Invalid or missing report data.</p></body></html>';
    exit();
}

function money($n) {
    return number_format((float) $n, 2);
}

function h($s) {
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/** Normalize JSON snapshot into a structured array for the template. */
function cashup_report_data(array $j, string $sessionUser): array {
    $cashTill = floatval($j['cash_sales_expected'] ?? 0);
    $cashOut = floatval($j['total_cash_out'] ?? 0);
    $totalReceived = floatval($j['total_cash_received'] ?? 0);
    if ($totalReceived <= 0 && ($cashTill > 0 || $cashOut > 0)) {
        $totalReceived = $cashTill + $cashOut;
    }

    $start = $j['start_date'] ?? '';
    $end = $j['end_date'] ?? '';
    $period = $j['range_label'] ?? '';
    if ($period === '' && $end !== '') {
        $period = trim($start . ' — ' . $end);
    }
    if ($period === '') {
        $period = '—';
    }

    $dateCell = '';
    if ($end !== '') {
        $ts = strtotime($end);
        $dateCell = $ts ? date('M j, Y', $ts) : $end;
    } else {
        $dateCell = date('M j, Y');
    }
    if ($start !== '' && $end !== '' && $start !== $end) {
        $t1 = strtotime($start);
        $t2 = strtotime($end);
        if ($t1 && $t2) {
            $dateCell = date('M j, Y', $t1) . ' — ' . date('M j, Y', $t2);
        }
    }

    $filter = $j['filter_cashier_name'] ?? 'All Staff';

    $unpaidCredit = floatval($j['unpaid_credit_sales'] ?? 0);
    $openTabs = floatval($j['open_tabs_balance'] ?? 0);
    $unpaidTabsVal = isset($j['unpaid_tabs']) ? floatval($j['unpaid_tabs']) : ($unpaidCredit + $openTabs);

    return [
        'meta' => [
            'period' => $period,
            'date_cell' => $dateCell,
            'staff_filter' => $filter,
            'printed_by' => $j['cashier_username'] ?? '',
            'generated_user' => $sessionUser,
            'generated_at' => date('F j, Y \a\t H:i'),
        ],
        'summary' => [
            ['label' => 'Cash In Till', 'value' => $cashTill, 'hint' => 'Expected cash balance (system)', 'negative' => false],
            ['label' => 'Total Cash Received', 'value' => $totalReceived, 'hint' => 'Deposits + cash sales + credit payments', 'negative' => false],
            ['label' => 'Card Sales', 'value' => floatval($j['card_sales_expected'] ?? 0), 'hint' => 'EFT/Card transactions', 'negative' => false],
            ['label' => 'Cash Withdrawals', 'value' => $cashOut, 'hint' => 'Total cash out', 'negative' => true],
        ],
        'cash' => [
            'cash_in' => floatval($j['total_cash_in'] ?? 0),
            'cash_sales' => floatval($j['total_cash_sales'] ?? 0),
            'credit_payments' => floatval($j['total_credit_payments'] ?? 0),
            'cash_out' => $cashOut,
            'cash_in_till' => $cashTill,
        ],
        'card' => [
            'expected' => floatval($j['card_sales_expected'] ?? 0),
        ],
        'credit' => [
            'unpaid_credit' => $unpaidCredit,
            'open_tabs' => $openTabs,
            'unpaid_tabs' => $unpaidTabsVal,
            'credit_returns' => floatval($j['credit_returns'] ?? 0),
        ],
        'deductions' => [
            'expenses' => floatval($j['expenses'] ?? 0),
            'cash_back' => floatval($j['cash_back_system'] ?? 0),
            'tips' => floatval($j['tips'] ?? 0),
        ],
        'adjustments' => [
            'voids' => floatval($j['voids'] ?? 0),
            'refunds' => floatval($j['refunds'] ?? 0),
            'damages' => floatval($j['damages'] ?? 0),
        ],
        'totals' => [
            'items_sold' => floatval($j['total_items_sold'] ?? 0),
        ],
        'verification' => [
            'cash_on_hand' => floatval($j['cash_on_hand'] ?? 0),
            'eft_on_hand' => floatval($j['eft_on_hand'] ?? 0),
            'cash_over_short' => floatval($j['over_short'] ?? (floatval($j['cash_on_hand'] ?? 0) - $cashTill)),
            'eft_over_short' => floatval($j['eft_over_short'] ?? 0),
        ],
    ];
}

$sessionUser = $_SESSION['username'] ?? 'Manager';
$d = cashup_report_data($json, $sessionUser);
$meta = $d['meta'];
$showCashBack = abs((float) ($d['deductions']['cash_back'] ?? 0)) > 0.00001;

$businessName = 'POS System';
$businessAddress = '';
$businessPhone = '';
try {
    $infoDb = new PDO('sqlite:../info.db');
    $infoDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $bi = $infoDb->query('SELECT * FROM business_info LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if ($bi) {
        $businessName = $bi['name'] ?? $businessName;
        $businessAddress = $bi['location'] ?? ($bi['address'] ?? '');
        $businessPhone = $bi['phone'] ?? '';
    }
} catch (PDOException $e) {
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cash reconciliation — <?= h($businessName) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 12px;
            line-height: 1.45;
            color: #333;
            background: #fff;
            padding: 20px;
        }
        /* Toolbar */
        .toolbar { position: fixed; top: 20px; right: 20px; z-index: 1000; display: flex; gap: 10px; }
        .toolbar button {
            border: none; border-radius: 8px; padding: 12px 20px; font-size: 14px; font-weight: 500; cursor: pointer;
        }
        .toolbar .print { background: #14b8a6; color: #fff; display: flex; align-items: center; gap: 8px; box-shadow: 0 4px 6px -1px rgba(0,0,0,.1); }
        .toolbar .print:hover { background: #0d9488; }
        .toolbar .close { background: #e2e8f0; color: #334155; }
        /* Document */
        .report { max-width: 960px; margin: 0 auto; }
        .report-header {
            text-align: center; margin-bottom: 28px; padding-bottom: 20px; border-bottom: 2px solid #14b8a6;
        }
        .report-header h1 { font-size: 24px; color: #14b8a6; font-weight: 700; }
        .report-header .brand { font-size: 18px; font-weight: 700; color: #1e293b; margin-top: 8px; }
        .report-header .sub { font-size: 11px; color: #64748b; margin-top: 4px; }
        .report-header .period { font-size: 14px; color: #475569; margin-top: 12px; font-weight: 500; }
        .report-header .fine { font-size: 10px; color: #94a3b8; margin-top: 8px; }
        /* Sections: h2 = major block, h3 = table group */
        .report section { margin-bottom: 28px; }
        .report section > h2 {
            font-size: 15px; font-weight: 700; color: #0f766e; text-transform: uppercase; letter-spacing: .04em;
            margin-bottom: 14px; padding-bottom: 6px; border-bottom: 1px solid #e2e8f0;
        }
        .report section > h3 {
            font-size: 14px; font-weight: 700; color: #1e293b; margin: 20px 0 6px 0;
        }
        .section-lead { font-size: 11px; color: #64748b; margin: 0 0 10px 0; }
        .summary-cards { display: flex; flex-wrap: wrap; gap: 14px; }
        .summary-card {
            flex: 1; min-width: 140px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px;
        }
        .summary-card .label { font-size: 10px; color: #64748b; text-transform: uppercase; letter-spacing: .5px; }
        .summary-card .value { font-size: 19px; font-weight: 700; color: #1e293b; margin-top: 6px; }
        .summary-card.negative .value { color: #ef4444; }
        .summary-card .hint { font-size: 10px; color: #94a3b8; margin-top: 4px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { padding: 10px 12px; border-bottom: 1px solid #e5e7eb; text-align: left; }
        th {
            background: #14b8a6; color: #fff; font-size: 11px; font-weight: 600;
            text-transform: uppercase; letter-spacing: .04em;
        }
        tbody tr:nth-child(even) { background: #f9fafb; }
        .num { text-align: right; font-variant-numeric: tabular-nums; }
        .footnote { font-size: 11px; color: #64748b; margin-top: 10px; }
        @media print {
            .no-print { display: none !important; }
            body { padding: 0; }
        }
    </style>
</head>
<body>
    <div class="toolbar no-print">
        <button type="button" class="close" onclick="window.close()">Close</button>
        <button type="button" class="print" onclick="window.print()">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v8H6z"/></svg>
            Print report
        </button>
    </div>

    <main class="report">
        <header class="report-header">
            <h1>Cash reconciliation</h1>
            <p class="brand"><?= h($businessName) ?></p>
            <?php if ($businessAddress !== ''): ?><p class="sub"><?= h($businessAddress) ?></p><?php endif; ?>
            <?php if ($businessPhone !== ''): ?><p class="sub">Tel: <?= h($businessPhone) ?></p><?php endif; ?>
            <p class="period"><?= h($meta['period']) ?></p>
            <?php if ($meta['staff_filter'] !== '' && $meta['staff_filter'] !== 'All Staff'): ?>
                <p class="sub">Staff: <?= h($meta['staff_filter']) ?></p>
            <?php endif; ?>
            <p class="fine">
                Generated <?= h($meta['generated_at']) ?> · <?= h($meta['generated_user']) ?>
                <?php if ($meta['printed_by'] !== ''): ?> · Receipt: <?= h($meta['printed_by']) ?><?php endif; ?>
            </p>
        </header>

        <section aria-labelledby="sec-overview">
            <h2 id="sec-overview">Overview</h2>
            <div class="summary-cards">
                <?php foreach ($d['summary'] as $card): ?>
                <div class="summary-card<?= !empty($card['negative']) ? ' negative' : '' ?>">
                    <div class="label"><?= h($card['label']) ?></div>
                    <div class="value">N$<?= money($card['value']) ?></div>
                    <div class="hint"><?= h($card['hint']) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section aria-labelledby="sec-detail">
            <h2 id="sec-detail">Period detail</h2>

            <h3>Cash</h3>
            <table>
                <thead>
                    <tr>
                        <th scope="col">Date</th>
                        <th scope="col" class="num">Cash in</th>
                        <th scope="col" class="num">Cash sales</th>
                        <th scope="col" class="num">Credit payments</th>
                        <th scope="col" class="num">Cash out</th>
                        <th scope="col" class="num">Cash in till</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?= h($meta['date_cell']) ?></td>
                        <td class="num">N$<?= money($d['cash']['cash_in']) ?></td>
                        <td class="num">N$<?= money($d['cash']['cash_sales']) ?></td>
                        <td class="num">N$<?= money($d['cash']['credit_payments']) ?></td>
                        <td class="num">N$<?= money($d['cash']['cash_out']) ?></td>
                        <td class="num">N$<?= money($d['cash']['cash_in_till']) ?></td>
                    </tr>
                </tbody>
            </table>

            <h3>Card sales</h3>
            <p class="section-lead">EFT / card transactions for the period.</p>
            <table>
                <thead>
                    <tr>
                        <th scope="col">Date</th>
                        <th scope="col" class="num">Card sales (expected)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?= h($meta['date_cell']) ?></td>
                        <td class="num">N$<?= money($d['card']['expected']) ?></td>
                    </tr>
                </tbody>
            </table>

            <h3>Credit</h3>
            <p class="section-lead">Outstanding credit, tabs, and returns.</p>
            <table>
                <thead>
                    <tr>
                        <th scope="col">Date</th>
                        <th scope="col" class="num">Unpaid credit</th>
                        <th scope="col" class="num">Open tabs</th>
                        <th scope="col" class="num">Unpaid tabs</th>
                        <th scope="col" class="num">Credit returns</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?= h($meta['date_cell']) ?></td>
                        <td class="num">N$<?= money($d['credit']['unpaid_credit']) ?></td>
                        <td class="num">N$<?= money($d['credit']['open_tabs']) ?></td>
                        <td class="num">N$<?= money($d['credit']['unpaid_tabs']) ?></td>
                        <td class="num">N$<?= money($d['credit']['credit_returns']) ?></td>
                    </tr>
                </tbody>
            </table>

            <h3>Deductions</h3>
            <table>
                <thead>
                    <tr>
                        <th scope="col">Date</th>
                        <th scope="col" class="num">Expenses</th>
                        <?php if ($showCashBack): ?>
                        <th scope="col" class="num">Cash back</th>
                        <?php endif; ?>
                        <th scope="col" class="num">Tips</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?= h($meta['date_cell']) ?></td>
                        <td class="num">N$<?= money($d['deductions']['expenses']) ?></td>
                        <?php if ($showCashBack): ?>
                        <td class="num">N$<?= money($d['deductions']['cash_back']) ?></td>
                        <?php endif; ?>
                        <td class="num">N$<?= money($d['deductions']['tips']) ?></td>
                    </tr>
                </tbody>
            </table>

            <h3>Adjustments</h3>
            <table>
                <thead>
                    <tr>
                        <th scope="col">Date</th>
                        <th scope="col" class="num">Voids</th>
                        <th scope="col" class="num">Refunds</th>
                        <th scope="col" class="num">Damages</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?= h($meta['date_cell']) ?></td>
                        <td class="num">N$<?= money($d['adjustments']['voids']) ?></td>
                        <td class="num">N$<?= money($d['adjustments']['refunds']) ?></td>
                        <td class="num">N$<?= money($d['adjustments']['damages']) ?></td>
                    </tr>
                </tbody>
            </table>

            <h3>Total items sold (value)</h3>
            <table>
                <thead>
                    <tr>
                        <th scope="col">Date</th>
                        <th scope="col" class="num">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?= h($meta['date_cell']) ?></td>
                        <td class="num">N$<?= money($d['totals']['items_sold']) ?></td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section aria-labelledby="sec-verify">
            <h2 id="sec-verify">Physical count (receipt)</h2>
            <table>
                <thead>
                    <tr>
                        <th scope="col">Cash on hand</th>
                        <th scope="col" class="num">EFT on hand</th>
                        <th scope="col" class="num">Cash over / short</th>
                        <th scope="col" class="num">EFT over / short</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>N$<?= money($d['verification']['cash_on_hand']) ?></td>
                        <td class="num">N$<?= money($d['verification']['eft_on_hand']) ?></td>
                        <td class="num">N$<?= money($d['verification']['cash_over_short']) ?></td>
                        <td class="num">N$<?= money($d['verification']['eft_over_short']) ?></td>
                    </tr>
                </tbody>
            </table>
            <p class="footnote no-print">System amounts use the cash-up period; this row matches the printed receipt.</p>
        </section>
    </main>
</body>
</html>

