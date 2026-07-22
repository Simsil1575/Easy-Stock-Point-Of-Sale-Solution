<?php

declare(strict_types=1);

/**
 * Shared A4 PDF generator for quotations and invoices.
 *
 * Usage: invoicing_pdf.php?type=quotation|invoice&id=123&dl=1
 *   dl=1 forces download, otherwise it is streamed inline (I).
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

require_once __DIR__ . '/fpdf/fpdf.php';

invBootstrap();
$db = invGetDb();
$settings = invGetDocumentSettings();

$type = (string) ($_GET['type'] ?? 'invoice');
$type = $type === 'quotation' ? 'quotation' : 'invoice';
$id = (int) ($_GET['id'] ?? 0);
$disposition = !empty($_GET['dl']) ? 'D' : 'I';

if ($type === 'quotation') {
    $bundle = invLoadQuotation($db, $id);
    if (!$bundle) {
        http_response_code(404);
        exit('Quotation not found.');
    }
    $doc = $bundle['quotation'];
    $docTitle = 'QUOTATION';
    $number = (string) $doc['quotation_number'];
    $primaryDateLabel = 'Quotation Date';
    $secondaryDateLabel = 'Valid Until';
    $secondaryDate = (string) ($doc['expiry_date'] ?? '');
    $grandTotal = (float) $doc['total'];
} else {
    $bundle = invLoadInvoice($db, $id);
    if (!$bundle) {
        http_response_code(404);
        exit('Invoice not found.');
    }
    $doc = $bundle['invoice'];
    $docTitle = 'INVOICE';
    $number = (string) $doc['invoice_number'];
    $primaryDateLabel = 'Invoice Date';
    $secondaryDateLabel = 'Due Date';
    $secondaryDate = (string) ($doc['due_date'] ?? '');
    $grandTotal = (float) $doc['grand_total'];
}
$customer = $bundle['customer'] ?? null;
$items = $bundle['items'] ?? [];
$currency = (string) ($settings['currency'] ?? 'N$');

/** Convert to a latin-1 safe string for FPDF core fonts. */
function pdfText(string $s): string
{
    return iconv('UTF-8', 'windows-1252//TRANSLIT', $s) ?: $s;
}
function pdfMoney(string $currency, $amount): string
{
    return pdfText($currency . ' ' . number_format((float) $amount, 2));
}

class InvoicingPDF extends FPDF
{
    public array $s = [];
    public string $docTitle = '';
    public string $number = '';

    function Header(): void
    {
        $s = $this->s;
        $startY = 12;

        // Logo (left) — resolve relative paths against project root
        $logo = (string) ($s['company_logo'] ?? '');
        $logoPath = '';
        if ($logo !== '') {
            $candidates = [
                $logo,
                __DIR__ . '/' . ltrim(str_replace('\\', '/', $logo), '/'),
                __DIR__ . '/uploads/business/' . basename($logo),
            ];
            foreach ($candidates as $c) {
                if (is_readable($c) && preg_match('/\.(png|jpe?g|gif)$/i', $c)) {
                    $logoPath = $c;
                    break;
                }
            }
        }
        $textX = 12;
        if ($logoPath !== '') {
            $ext = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
            $ext = $ext === 'jpg' ? 'JPG' : strtoupper($ext);
            try {
                $this->Image($logoPath, 12, $startY, 28, 0, $ext);
                $textX = 44;
            } catch (Throwable $e) {
                $textX = 12;
            }
        }

        // Company block
        $this->SetXY($textX, $startY);
        $this->SetFont('Arial', 'B', 16);
        $this->SetTextColor(31, 41, 55);
        $this->Cell(110, 7, pdfText((string) ($s['company_name'] ?? '')), 0, 2, 'L');
        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(90, 90, 90);
        foreach (array_filter([
            (string) ($s['company_address'] ?? ''),
            trim(((string) ($s['telephone'] ?? '')) . (($s['email'] ?? '') ? '  |  ' . $s['email'] : '')),
            (string) ($s['website'] ?? ''),
            $this->taxLine($s),
        ]) as $line) {
            $this->SetX($textX);
            $this->Cell(110, 5, pdfText($line), 0, 2, 'L');
        }

        // Document title block (right)
        $this->SetXY(140, $startY);
        $this->SetFont('Arial', 'B', 22);
        $this->SetTextColor(13, 148, 136); // teal-600
        $this->Cell(58, 10, $this->docTitle, 0, 2, 'R');
        $this->SetFont('Arial', 'B', 10);
        $this->SetTextColor(31, 41, 55);
        $this->SetX(140);
        $this->Cell(58, 6, pdfText($this->number), 0, 2, 'R');

        $this->SetY(max($this->GetY(), 42));
        $this->SetDrawColor(226, 232, 240);
        $this->SetLineWidth(0.3);
        $this->Line(12, 46, 198, 46);
        $this->SetY(50);
        $this->SetTextColor(0, 0, 0);
    }

    /** Number of lines a MultiCell of width $w will need for $txt. */
    function NbLines($w, $txt): int
    {
        $cw = $this->CurrentFont['cw'] ?? null;
        if ($cw === null) {
            return 1;
        }
        if ($w == 0) {
            $w = $this->w - $this->rMargin - $this->x;
        }
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', (string) $txt);
        $nb = strlen($s);
        if ($nb > 0 && $s[$nb - 1] === "\n") {
            $nb--;
        }
        $sep = -1;
        $i = 0;
        $j = 0;
        $l = 0;
        $nl = 1;
        while ($i < $nb) {
            $c = $s[$i];
            if ($c === "\n") {
                $i++;
                $sep = -1;
                $j = $i;
                $l = 0;
                $nl++;
                continue;
            }
            if ($c === ' ') {
                $sep = $i;
            }
            $l += $cw[$c] ?? 0;
            if ($l > $wmax) {
                if ($sep == -1) {
                    if ($i == $j) {
                        $i++;
                    }
                } else {
                    $i = $sep + 1;
                }
                $sep = -1;
                $j = $i;
                $l = 0;
                $nl++;
            } else {
                $i++;
            }
        }
        return $nl;
    }

    function taxLine(array $s): string
    {
        $parts = [];
        if (!empty($s['tax_number'])) {
            $parts[] = 'Tax No: ' . $s['tax_number'];
        }
        if (!empty($s['vat_number'])) {
            $parts[] = 'VAT No: ' . $s['vat_number'];
        }
        return implode('   ', $parts);
    }

    function Footer(): void
    {
        $this->SetY(-18);
        $this->SetDrawColor(226, 232, 240);
        $this->Line(12, $this->GetY(), 198, $this->GetY());
        $this->Ln(2);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(120, 120, 120);
        $thanks = trim((string) ($this->s['footer_text'] ?? ''));
        if ($thanks === '') {
            $thanks = 'Thank you for your business.';
        }
        $this->Cell(0, 5, pdfText($thanks), 0, 1, 'C');
        $this->Cell(0, 5, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
        $this->SetTextColor(0, 0, 0);
    }
}

$pdf = new InvoicingPDF();
$pdf->s = $settings;
$pdf->docTitle = $docTitle;
$pdf->number = $number;
$pdf->AliasNbPages();
$pdf->SetAutoPageBreak(true, 22);
$pdf->AddPage();

// ---- Bill To + meta boxes ----
$topY = $pdf->GetY();
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetTextColor(13, 148, 136);
$pdf->Cell(110, 6, 'BILL TO', 0, 2, 'L');
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(110, 6, pdfText((string) ($customer['name'] ?? 'N/A')), 0, 2, 'L');
$pdf->SetFont('Arial', '', 9);
$pdf->SetTextColor(90, 90, 90);
foreach (array_filter([
    (string) ($customer['address'] ?? ''),
    (string) ($customer['phone'] ?? ''),
    (string) ($customer['email'] ?? ''),
    ($customer['tax_number'] ?? '') ? 'Tax No: ' . $customer['tax_number'] : '',
]) as $line) {
    $pdf->MultiCell(110, 5, pdfText($line), 0, 'L');
}

// Meta box (right)
$metaX = 130;
$pdf->SetXY($metaX, $topY);
$pdf->SetFont('Arial', '', 9);
$rows = [
    [$primaryDateLabel, (string) ($doc[$type === 'quotation' ? 'quotation_date' : 'invoice_date'] ?? '')],
    [$secondaryDateLabel, $secondaryDate !== '' ? $secondaryDate : '-'],
    ['Status', (string) ($doc['status'] ?? '')],
];
if ($type === 'invoice' && !empty($doc['payment_terms'])) {
    $rows[] = ['Payment Terms', (string) $doc['payment_terms']];
}
foreach ($rows as $r) {
    $pdf->SetX($metaX);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetTextColor(90, 90, 90);
    $pdf->Cell(30, 6, pdfText($r[0]), 0, 0, 'L');
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(38, 6, pdfText($r[1]), 0, 2, 'R');
}

$pdf->SetY(max($pdf->GetY(), $topY + 34));
$pdf->Ln(4);

// ---- Items table ----
$wDesc = 86;
$wQty = 18;
$wPrice = 28;
$wDisc = 24;
$wTotal = 30;

$pdf->SetFont('Arial', 'B', 9);
$pdf->SetFillColor(13, 148, 136);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell($wDesc, 8, 'Description', 0, 0, 'L', true);
$pdf->Cell($wQty, 8, 'Qty', 0, 0, 'C', true);
$pdf->Cell($wPrice, 8, 'Unit Price', 0, 0, 'R', true);
$pdf->Cell($wDisc, 8, 'Discount', 0, 0, 'R', true);
$pdf->Cell($wTotal, 8, 'Amount', 0, 1, 'R', true);
$pdf->SetTextColor(0, 0, 0);

$pdf->SetFont('Arial', '', 9);
$fill = false;
foreach ($items as $it) {
    $desc = trim((string) ($it['product_name'] ?? ''));
    $extra = trim((string) ($it['description'] ?? ''));
    if ($desc === '') {
        $desc = $extra;
        $extra = '';
    }
    $label = $desc . ($extra !== '' ? ' - ' . $extra : '');

    $pdf->SetFillColor(247, 249, 252);
    $x = $pdf->GetX();
    $y = $pdf->GetY();
    // Description may wrap; compute height.
    $lines = max(1, $pdf->NbLines($wDesc, pdfText($label)));
    $rowH = max(7, $lines * 5);

    $pdf->Cell($wDesc, $rowH, '', 0, 0, 'L', $fill);
    $pdf->SetXY($x, $y + ($rowH - $lines * 5) / 2);
    $pdf->MultiCell($wDesc, 5, pdfText($label), 0, 'L', false);
    $pdf->SetXY($x + $wDesc, $y);

    $pdf->Cell($wQty, $rowH, rtrim(rtrim(number_format((float) $it['quantity'], 2), '0'), '.'), 0, 0, 'C', $fill);
    $pdf->Cell($wPrice, $rowH, pdfMoney($currency, $it['unit_price']), 0, 0, 'R', $fill);
    $pdf->Cell($wDisc, $rowH, pdfMoney($currency, $it['discount']), 0, 0, 'R', $fill);
    $pdf->Cell($wTotal, $rowH, pdfMoney($currency, $it['line_total']), 0, 1, 'R', $fill);
    $fill = !$fill;
}

$pdf->Ln(3);

// ---- Totals box (right aligned) ----
$totalsX = 120;
$labelW = 48;
$valW = 30;
$addTotal = function (string $label, $value, bool $bold = false, array $bg = null) use ($pdf, $totalsX, $labelW, $valW, $currency): void {
    $pdf->SetX($totalsX);
    if ($bg !== null) {
        $pdf->SetFillColor($bg[0], $bg[1], $bg[2]);
    }
    $pdf->SetFont('Arial', $bold ? 'B' : '', $bold ? 11 : 9);
    if ($bold) {
        $pdf->SetTextColor(255, 255, 255);
    } else {
        $pdf->SetTextColor(60, 60, 60);
    }
    $pdf->Cell($labelW, $bold ? 9 : 6, pdfText($label), 0, 0, 'L', $bg !== null);
    $pdf->Cell($valW, $bold ? 9 : 6, pdfMoney($currency, $value), 0, 1, 'R', $bg !== null);
    $pdf->SetTextColor(0, 0, 0);
};

$addTotal('Subtotal', $doc['subtotal']);
if ((float) $doc['discount_amount'] > 0) {
    $addTotal('Discount', -1 * (float) $doc['discount_amount']);
}
if ((float) $doc['vat_amount'] > 0) {
    $addTotal('VAT (' . rtrim(rtrim(number_format((float) $doc['vat_percentage'], 2), '0'), '.') . '%)', $doc['vat_amount']);
}
if ((float) $doc['shipping_amount'] > 0) {
    $addTotal('Shipping', $doc['shipping_amount']);
}
$addTotal($type === 'quotation' ? 'Total' : 'Grand Total', $grandTotal, true, [13, 148, 136]);

if ($type === 'invoice') {
    $addTotal('Paid', $doc['paid_amount']);
    $addTotal('Balance Due', $doc['balance_due'], true, [220, 38, 38]);
}

$pdf->Ln(6);

// ---- Notes & Terms ----
$notes = trim((string) ($doc['notes'] ?? ''));
$terms = trim((string) ($doc['terms_conditions'] ?? ''));
if ($notes === '' && !empty($settings['default_notes'])) {
    $notes = (string) $settings['default_notes'];
}
if ($terms === '' && !empty($settings['default_terms_conditions'])) {
    $terms = (string) $settings['default_terms_conditions'];
}
if ($notes !== '') {
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetTextColor(13, 148, 136);
    $pdf->Cell(0, 6, 'Notes', 0, 1, 'L');
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(60, 60, 60);
    $pdf->MultiCell(0, 5, pdfText($notes), 0, 'L');
    $pdf->Ln(2);
}
if ($terms !== '') {
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetTextColor(13, 148, 136);
    $pdf->Cell(0, 6, 'Terms & Conditions', 0, 1, 'L');
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(60, 60, 60);
    $pdf->MultiCell(0, 5, pdfText($terms), 0, 'L');
}

$pdf->SetTextColor(0, 0, 0);
$pdf->Ln(10);

// ---- Signatures ----
$sigY = $pdf->GetY();
if ($sigY > 245) {
    $pdf->AddPage();
    $sigY = $pdf->GetY() + 10;
}
$pdf->SetY($sigY);
$pdf->SetDrawColor(150, 150, 150);
$pdf->Line(20, $sigY + 12, 80, $sigY + 12);
$pdf->Line(120, $sigY + 12, 180, $sigY + 12);
$pdf->SetXY(20, $sigY + 13);
$pdf->SetFont('Arial', '', 8);
$pdf->Cell(60, 5, pdfText('Prepared / Authorized By'), 0, 0, 'C');
$pdf->SetX(120);
$pdf->Cell(60, 5, pdfText('Customer Signature'), 0, 0, 'C');
$pdf->SetXY(20, $sigY + 6);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(60, 5, pdfText((string) ($doc['approved_by'] ?? $doc['created_by'] ?? '')), 0, 0, 'C');

$filename = ($type === 'quotation' ? 'Quotation_' : 'Invoice_') . preg_replace('/[^A-Za-z0-9_-]/', '', $number) . '.pdf';
$pdf->Output($disposition, $filename);
exit;
