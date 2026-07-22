<?php

declare(strict_types=1);

/**
 * Quotations & Invoicing reports.
 *
 * invoicing_reports.php?report=<key>&start=YYYY-MM-DD&end=YYYY-MM-DD&format=pdf|csv|excel[&customer_id=]
 *
 * Reports: quotations, invoices, outstanding, overdue, payments,
 *          customer_statement, sales_by_customer, monthly_summary, conversion.
 */

require_once __DIR__ . '/config.php';
date_default_timezone_set('Africa/Harare');
require_once __DIR__ . '/invoicing_lib.php';

if (!isset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['role'])) {
    header('Location: ./');
    exit;
}
if (!in_array(invCurrentRole(), ['admin', 'manager'], true)) {
    header('Location: ./');
    exit;
}

invBootstrap();
$db = invGetDb();
$settings = invGetDocumentSettings();
$currency = (string) ($settings['currency'] ?? 'N$');

$report = (string) ($_GET['report'] ?? 'invoices');
$format = strtolower((string) ($_GET['format'] ?? 'pdf'));
$start = (string) ($_GET['start'] ?? ($_GET['start_date'] ?? date('Y-m-01')));
$end = (string) ($_GET['end'] ?? ($_GET['end_date'] ?? date('Y-m-d')));
$customerId = (int) ($_GET['customer_id'] ?? 0);
$today = date('Y-m-d');

$money = fn($v) => $currency . ' ' . number_format((float) $v, 2);

/** @return array{title:string, columns:array, rows:array, summary:array} */
function buildReport(PDO $db, string $report, string $start, string $end, int $customerId, callable $money, string $today): array
{
    $summary = [];
    switch ($report) {
        case 'quotations': {
            $stmt = $db->prepare("
                SELECT q.quotation_number, c.name AS customer, q.quotation_date, q.expiry_date, q.status, q.total
                FROM quotations q LEFT JOIN customers c ON c.id = q.customer_id
                WHERE q.quotation_date BETWEEN ? AND ? ORDER BY q.quotation_date DESC, q.id DESC
            ");
            $stmt->execute([$start, $end]);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $rows = array_map(fn($r) => [$r['quotation_number'], $r['customer'] ?? 'N/A', $r['quotation_date'], $r['expiry_date'] ?: '-', $r['status'], $money($r['total'])], $data);
            $summary[] = ['Total quotations', count($data)];
            $summary[] = ['Total value', $money(array_sum(array_column($data, 'total')))];
            return ['title' => 'Quotation Report', 'columns' => ['Number', 'Customer', 'Date', 'Expiry', 'Status', 'Total'], 'rows' => $rows, 'summary' => $summary];
        }
        case 'invoices': {
            $stmt = $db->prepare("
                SELECT i.invoice_number, c.name AS customer, i.invoice_date, i.due_date, i.status, i.grand_total, i.paid_amount, i.balance_due
                FROM invoices i LEFT JOIN customers c ON c.id = i.customer_id
                WHERE i.invoice_date BETWEEN ? AND ? ORDER BY i.invoice_date DESC, i.id DESC
            ");
            $stmt->execute([$start, $end]);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $rows = array_map(fn($r) => [$r['invoice_number'], $r['customer'] ?? 'N/A', $r['invoice_date'], $r['due_date'] ?: '-', $r['status'], $money($r['grand_total']), $money($r['paid_amount']), $money($r['balance_due'])], $data);
            $summary[] = ['Total invoices', count($data)];
            $summary[] = ['Total billed', $money(array_sum(array_column($data, 'grand_total')))];
            $summary[] = ['Total collected', $money(array_sum(array_column($data, 'paid_amount')))];
            $summary[] = ['Total outstanding', $money(array_sum(array_column($data, 'balance_due')))];
            return ['title' => 'Invoices Report', 'columns' => ['Number', 'Customer', 'Date', 'Due', 'Status', 'Total', 'Paid', 'Balance'], 'rows' => $rows, 'summary' => $summary];
        }
        case 'outstanding': {
            $stmt = $db->query("
                SELECT i.invoice_number, c.name AS customer, i.invoice_date, i.due_date, i.status, i.grand_total, i.balance_due
                FROM invoices i LEFT JOIN customers c ON c.id = i.customer_id
                WHERE i.balance_due > 0.001 AND i.status NOT IN ('Cancelled','Draft')
                ORDER BY i.due_date ASC, i.id ASC
            ");
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $rows = array_map(fn($r) => [$r['invoice_number'], $r['customer'] ?? 'N/A', $r['invoice_date'], $r['due_date'] ?: '-', $r['status'], $money($r['grand_total']), $money($r['balance_due'])], $data);
            $summary[] = ['Outstanding invoices', count($data)];
            $summary[] = ['Total outstanding', $money(array_sum(array_column($data, 'balance_due')))];
            return ['title' => 'Outstanding Invoices', 'columns' => ['Number', 'Customer', 'Date', 'Due', 'Status', 'Total', 'Balance'], 'rows' => $rows, 'summary' => $summary];
        }
        case 'overdue': {
            $stmt = $db->prepare("
                SELECT i.invoice_number, c.name AS customer, i.invoice_date, i.due_date, i.balance_due,
                       CAST(julianday(?) - julianday(i.due_date) AS INTEGER) AS days_overdue
                FROM invoices i LEFT JOIN customers c ON c.id = i.customer_id
                WHERE i.due_date IS NOT NULL AND i.due_date < ? AND i.balance_due > 0.001 AND i.status NOT IN ('Cancelled','Paid')
                ORDER BY i.due_date ASC
            ");
            $stmt->execute([$today, $today]);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $rows = array_map(fn($r) => [$r['invoice_number'], $r['customer'] ?? 'N/A', $r['due_date'], $r['days_overdue'] . ' days', $money($r['balance_due'])], $data);
            $summary[] = ['Overdue invoices', count($data)];
            $summary[] = ['Total overdue', $money(array_sum(array_column($data, 'balance_due')))];
            return ['title' => 'Overdue Invoices', 'columns' => ['Number', 'Customer', 'Due Date', 'Overdue', 'Balance'], 'rows' => $rows, 'summary' => $summary];
        }
        case 'payments': {
            $stmt = $db->prepare("
                SELECT p.payment_date, i.invoice_number, c.name AS customer, p.payment_method, p.reference, p.amount, p.received_by
                FROM invoice_payments p
                JOIN invoices i ON i.id = p.invoice_id
                LEFT JOIN customers c ON c.id = i.customer_id
                WHERE p.payment_date BETWEEN ? AND ? ORDER BY p.payment_date DESC, p.id DESC
            ");
            $stmt->execute([$start, $end]);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $rows = array_map(fn($r) => [$r['payment_date'], $r['invoice_number'], $r['customer'] ?? 'N/A', $r['payment_method'], $r['reference'] ?: '-', $money($r['amount']), $r['received_by'] ?: '-'], $data);
            $summary[] = ['Payments', count($data)];
            $summary[] = ['Total received', $money(array_sum(array_column($data, 'amount')))];
            return ['title' => 'Payments Report', 'columns' => ['Date', 'Invoice', 'Customer', 'Method', 'Reference', 'Amount', 'Received By'], 'rows' => $rows, 'summary' => $summary];
        }
        case 'customer_statement': {
            $cust = $customerId > 0 ? invGetCustomer($db, $customerId) : null;
            $custName = $cust ? $cust['name'] : 'All Customers';
            // Combine invoices (debit) and payments (credit) chronologically.
            $entries = [];
            $invSql = "SELECT invoice_date AS d, invoice_number AS ref, grand_total AS amt FROM invoices WHERE status NOT IN ('Cancelled','Draft')";
            $paySql = "SELECT p.payment_date AS d, i.invoice_number AS ref, p.amount AS amt FROM invoice_payments p JOIN invoices i ON i.id=p.invoice_id";
            $params1 = [];
            $params2 = [];
            if ($customerId > 0) {
                $invSql .= ' AND customer_id = ?';
                $params1[] = $customerId;
                $paySql .= ' WHERE i.customer_id = ?';
                $params2[] = $customerId;
            }
            $is = $db->prepare($invSql);
            $is->execute($params1);
            foreach ($is->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $entries[] = ['d' => $r['d'], 'ref' => $r['ref'], 'desc' => 'Invoice', 'debit' => (float) $r['amt'], 'credit' => 0.0];
            }
            $ps = $db->prepare($paySql);
            $ps->execute($params2);
            foreach ($ps->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $entries[] = ['d' => $r['d'], 'ref' => $r['ref'], 'desc' => 'Payment', 'debit' => 0.0, 'credit' => (float) $r['amt']];
            }
            usort($entries, fn($a, $b) => strcmp((string) $a['d'], (string) $b['d']));
            $bal = 0.0;
            $rows = [];
            foreach ($entries as $en) {
                $bal += $en['debit'] - $en['credit'];
                $rows[] = [$en['d'], $en['ref'], $en['desc'], $en['debit'] ? $money($en['debit']) : '-', $en['credit'] ? $money($en['credit']) : '-', $money($bal)];
            }
            $summary[] = ['Customer', $custName];
            $summary[] = ['Closing balance', $money($bal)];
            return ['title' => 'Customer Statement - ' . $custName, 'columns' => ['Date', 'Reference', 'Type', 'Debit', 'Credit', 'Balance'], 'rows' => $rows, 'summary' => $summary];
        }
        case 'sales_by_customer': {
            $stmt = $db->prepare("
                SELECT c.name AS customer, COUNT(i.id) AS cnt, COALESCE(SUM(i.grand_total),0) AS billed,
                       COALESCE(SUM(i.paid_amount),0) AS paid, COALESCE(SUM(i.balance_due),0) AS balance
                FROM invoices i LEFT JOIN customers c ON c.id = i.customer_id
                WHERE i.invoice_date BETWEEN ? AND ? AND i.status NOT IN ('Cancelled')
                GROUP BY i.customer_id ORDER BY billed DESC
            ");
            $stmt->execute([$start, $end]);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $rows = array_map(fn($r) => [$r['customer'] ?? 'N/A', $r['cnt'], $money($r['billed']), $money($r['paid']), $money($r['balance'])], $data);
            $summary[] = ['Customers', count($data)];
            $summary[] = ['Total billed', $money(array_sum(array_column($data, 'billed')))];
            return ['title' => 'Sales by Customer', 'columns' => ['Customer', 'Invoices', 'Billed', 'Paid', 'Balance'], 'rows' => $rows, 'summary' => $summary];
        }
        case 'monthly_summary': {
            $stmt = $db->prepare("
                SELECT strftime('%Y-%m', invoice_date) AS ym, COUNT(*) AS cnt,
                       COALESCE(SUM(grand_total),0) AS billed, COALESCE(SUM(paid_amount),0) AS paid, COALESCE(SUM(balance_due),0) AS balance
                FROM invoices WHERE invoice_date BETWEEN ? AND ? AND status NOT IN ('Cancelled')
                GROUP BY ym ORDER BY ym DESC
            ");
            $stmt->execute([$start, $end]);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $rows = array_map(fn($r) => [$r['ym'], $r['cnt'], $money($r['billed']), $money($r['paid']), $money($r['balance'])], $data);
            $summary[] = ['Months', count($data)];
            $summary[] = ['Total billed', $money(array_sum(array_column($data, 'billed')))];
            return ['title' => 'Monthly Invoice Summary', 'columns' => ['Month', 'Invoices', 'Billed', 'Paid', 'Balance'], 'rows' => $rows, 'summary' => $summary];
        }
        case 'conversion': {
            $stmt = $db->prepare("
                SELECT status, COUNT(*) AS cnt, COALESCE(SUM(total),0) AS val
                FROM quotations WHERE quotation_date BETWEEN ? AND ? GROUP BY status
            ");
            $stmt->execute([$start, $end]);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $rows = array_map(fn($r) => [$r['status'], $r['cnt'], $money($r['val'])], $data);
            $totalQ = array_sum(array_column($data, 'cnt'));
            $converted = 0;
            foreach ($data as $r) {
                if ($r['status'] === 'Converted') {
                    $converted = (int) $r['cnt'];
                }
            }
            $rate = $totalQ > 0 ? round($converted / $totalQ * 100, 1) : 0;
            $summary[] = ['Total quotations', $totalQ];
            $summary[] = ['Converted', $converted];
            $summary[] = ['Conversion rate', $rate . '%'];
            return ['title' => 'Quotation Conversion Report', 'columns' => ['Status', 'Count', 'Value'], 'rows' => $rows, 'summary' => $summary];
        }
        default:
            return ['title' => 'Report', 'columns' => ['Info'], 'rows' => [['Unknown report type.']], 'summary' => []];
    }
}

$result = buildReport($db, $report, $start, $end, $customerId, $money, $today);
$rangeLabel = in_array($report, ['outstanding', 'overdue'], true) ? 'As at ' . $today : ($start . ' to ' . $end);
$fileBase = preg_replace('/[^a-z0-9]+/i', '_', $result['title']) . '_' . date('Ymd_His');

/* ---------------- Output ---------------- */
if ($format === 'csv' || $format === 'excel') {
    if ($format === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $fileBase . '.xls"');
        echo "<table border='1'><tr><th colspan='" . count($result['columns']) . "'>" . htmlspecialchars($result['title']) . " (" . htmlspecialchars($rangeLabel) . ")</th></tr>";
        echo '<tr>';
        foreach ($result['columns'] as $c) {
            echo '<th>' . htmlspecialchars((string) $c) . '</th>';
        }
        echo '</tr>';
        foreach ($result['rows'] as $row) {
            echo '<tr>';
            foreach ($row as $cell) {
                echo '<td>' . htmlspecialchars((string) $cell) . '</td>';
            }
            echo '</tr>';
        }
        foreach ($result['summary'] as $s) {
            echo '<tr><td>' . htmlspecialchars((string) $s[0]) . '</td><td>' . htmlspecialchars((string) $s[1]) . '</td></tr>';
        }
        echo '</table>';
        exit;
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileBase . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, [$result['title'], $rangeLabel]);
    fputcsv($out, $result['columns']);
    foreach ($result['rows'] as $row) {
        fputcsv($out, $row);
    }
    fputcsv($out, []);
    foreach ($result['summary'] as $s) {
        fputcsv($out, $s);
    }
    fclose($out);
    exit;
}

/* ---------------- PDF ---------------- */
require_once __DIR__ . '/fpdf/fpdf.php';

class ReportPDF extends FPDF
{
    public string $rtitle = '';
    public string $rrange = '';
    public string $company = '';

    function Header(): void
    {
        $this->SetFont('Arial', 'B', 15);
        $this->Cell(0, 8, iconv('UTF-8', 'windows-1252//TRANSLIT', $this->company) ?: $this->company, 0, 1, 'L');
        $this->SetFont('Arial', 'B', 12);
        $this->SetTextColor(13, 148, 136);
        $this->Cell(0, 7, $this->rtitle, 0, 1, 'L');
        $this->SetTextColor(90, 90, 90);
        $this->SetFont('Arial', '', 9);
        $this->Cell(0, 5, $this->rrange . '   |   Generated: ' . date('Y-m-d H:i'), 0, 1, 'L');
        $this->SetTextColor(0, 0, 0);
        $this->Ln(3);
        $this->SetDrawColor(226, 232, 240);
        $this->Line(10, $this->GetY(), 287, $this->GetY());
        $this->Ln(3);
    }

    function Footer(): void
    {
        $this->SetY(-14);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(120, 120, 120);
        $this->Cell(0, 8, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
        $this->SetTextColor(0, 0, 0);
    }
}

$pdf = new ReportPDF('L', 'mm', 'A4');
$pdf->company = (string) ($settings['company_name'] ?? '');
$pdf->rtitle = $result['title'];
$pdf->rrange = $rangeLabel;
$pdf->AliasNbPages();
$pdf->SetAutoPageBreak(true, 16);
$pdf->AddPage();

$cols = $result['columns'];
$n = count($cols);
$pageW = 297 - 20;
$colW = $pageW / max(1, $n);

$pdf->SetFont('Arial', 'B', 9);
$pdf->SetFillColor(13, 148, 136);
$pdf->SetTextColor(255, 255, 255);
foreach ($cols as $c) {
    $pdf->Cell($colW, 8, iconv('UTF-8', 'windows-1252//TRANSLIT', (string) $c) ?: (string) $c, 1, 0, 'C', true);
}
$pdf->Ln();
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('Arial', '', 8);
$fill = false;
if (empty($result['rows'])) {
    $pdf->Cell($pageW, 8, 'No records found for the selected criteria.', 1, 1, 'C');
} else {
    foreach ($result['rows'] as $row) {
        $pdf->SetFillColor(247, 249, 252);
        foreach ($row as $i => $cell) {
            $align = $i === 0 ? 'L' : ($i >= 2 ? 'R' : 'L');
            $txt = iconv('UTF-8', 'windows-1252//TRANSLIT', (string) $cell) ?: (string) $cell;
            $pdf->Cell($colW, 7, $txt, 1, 0, $align, $fill);
        }
        $pdf->Ln();
        $fill = !$fill;
    }
}

if (!empty($result['summary'])) {
    $pdf->Ln(4);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 6, 'Summary', 0, 1, 'L');
    $pdf->SetFont('Arial', '', 9);
    foreach ($result['summary'] as $s) {
        $pdf->Cell(60, 6, iconv('UTF-8', 'windows-1252//TRANSLIT', (string) $s[0]) ?: (string) $s[0], 0, 0, 'L');
        $pdf->Cell(60, 6, iconv('UTF-8', 'windows-1252//TRANSLIT', (string) $s[1]) ?: (string) $s[1], 0, 1, 'L');
    }
}

$pdf->Output('I', $fileBase . '.pdf');
exit;
