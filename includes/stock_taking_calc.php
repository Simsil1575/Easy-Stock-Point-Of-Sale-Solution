<?php

declare(strict_types=1);

/**
 * @return array{expected_quantity:int,actual_quantity:int,variance:int,sold_quantity:int,accounted_quantity:int}
 */
function stCalculateStockTakeQuantities(
    string $stockType,
    bool $includeSold,
    float $physicalQuantity,
    float $openingStock,
    float $receivedStock,
    float $actualSales,
    float $systemQuantity
): array {
    $soldQty = $stockType === 'closing' ? (int) round($actualSales) : 0;
    $physical = (int) round($physicalQuantity);

    if ($stockType === 'closing' && $includeSold) {
        $expected = (int) round($systemQuantity);
        $adjustedActual = (int) round($physicalQuantity - $actualSales);

        return [
            'expected_quantity' => $expected,
            'actual_quantity' => $physical,
            'accounted_quantity' => $adjustedActual,
            'variance' => $adjustedActual - $expected,
            'sold_quantity' => $soldQty,
        ];
    }

    $expected = (int) round($systemQuantity);

    return [
        'expected_quantity' => $expected,
        'actual_quantity' => $physical,
        'accounted_quantity' => $physical,
        'variance' => $physical - $expected,
        'sold_quantity' => $soldQty,
    ];
}

/**
 * @param list<array> $items
 */
function stClosingPdfDetectIncludeSold(array $items, ?bool $explicit = null): bool
{
    if ($explicit !== null) {
        return $explicit;
    }

    foreach ($items as $item) {
        if (!empty($item['include_sold'])) {
            return true;
        }
        $sold = (int) ($item['sold_quantity'] ?? $item['actual_sales'] ?? 0);
        $actual = (int) round((float) ($item['actual_quantity'] ?? 0));
        $expected = (int) ($item['expected_quantity'] ?? 0);
        $variance = (int) ($item['variance'] ?? ($actual - $expected));
        if ($sold > 0 && $variance === ($actual + $sold - $expected) && $variance !== ($actual - $expected)) {
            return true;
        }
    }

    return false;
}

/**
 * @return array{include_sold:bool,show_sold:bool,col_widths:array<string,int>}
 */
function stClosingPdfLayout(bool $includeSold): array
{
    if ($includeSold) {
        return [
            'include_sold' => true,
            'show_sold' => true,
            'col_widths' => [
                'id' => 14,
                'name' => 46,
                'unit_price' => 22,
                'system_qty' => 24,
                'sold_qty' => 20,
                'physical_qty' => 24,
                'adjusted_qty' => 22,
                'difference' => 22,
                'value_diff' => 26,
            ],
        ];
    }

    return [
        'include_sold' => false,
        'show_sold' => false,
        'col_widths' => [
            'id' => 20,
            'name' => 60,
            'unit_price' => 28,
            'system_qty' => 32,
            'physical_qty' => 30,
            'difference' => 26,
            'value_diff' => 32,
        ],
    ];
}

/**
 * @return array{unit_price:float,system_qty:int,physical_qty:int,sold_qty:int,difference:int,value_difference:float}
 */
function stClosingPdfLineAmounts(array $item, bool $includeSold): array
{
    $unitPrice = (float) ($item['price'] ?? $item['unit_price'] ?? 0);
    $systemQty = (int) ($item['expected_quantity'] ?? 0);
    $physicalQty = (int) round((float) ($item['actual_quantity'] ?? 0));
    $soldQty = (int) ($item['sold_quantity'] ?? $item['actual_sales'] ?? 0);

    if (array_key_exists('variance', $item)) {
        $difference = (int) $item['variance'];
    } elseif ($includeSold) {
        $adjustedActual = $physicalQty - $soldQty;
        $difference = $adjustedActual - $systemQty;
    } else {
        $difference = $physicalQty - $systemQty;
    }

    return [
        'unit_price' => $unitPrice,
        'system_qty' => $systemQty,
        'physical_qty' => $physicalQty,
        'sold_qty' => $soldQty,
        'difference' => $difference,
        'value_difference' => $difference * $unitPrice,
    ];
}

/**
 * @param object $pdf
 * @param array{show_sold:bool,col_widths:array<string,int>} $layout
 */
function stClosingPdfWriteHeader($pdf, array $layout): void
{
    $w = $layout['col_widths'];
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell($w['id'], 10, 'ID', 1, 0, 'C');
    $pdf->Cell($w['name'], 10, 'Product Name', 1, 0, 'C');
    $pdf->Cell($w['unit_price'], 10, 'Unit Price', 1, 0, 'C');
    $pdf->Cell($w['system_qty'], 10, 'System Qty (Exp)', 1, 0, 'C');
    if (!empty($layout['show_sold'])) {
        $pdf->Cell($w['sold_qty'], 10, 'Sold Today', 1, 0, 'C');
    }
    $pdf->Cell($w['physical_qty'], 10, 'Physical (Act)', 1, 0, 'C');
    if (!empty($layout['show_sold'])) {
        $pdf->Cell($w['adjusted_qty'], 10, 'Adj. Actual', 1, 0, 'C');
    }
    $pdf->Cell($w['difference'], 10, 'Difference', 1, 0, 'C');
    $pdf->Cell($w['value_diff'], 10, 'Value Diff', 1, 1, 'C');
}

/**
 * @param object $pdf
 * @param array{include_sold:bool,show_sold:bool,col_widths:array<string,int>} $layout
 * @return array{unit_price:float,system_qty:int,physical_qty:int,sold_qty:int,difference:int,value_difference:float}
 */
function stClosingPdfWriteRow($pdf, array $layout, array $item): array
{
    $amounts = stClosingPdfLineAmounts($item, !empty($layout['include_sold']));
    $w = $layout['col_widths'];
    $diffLabel = $amounts['difference'] > 0 ? '+' . $amounts['difference'] : (string) $amounts['difference'];
    $valueLabel = $amounts['value_difference'] > 0
        ? '+' . number_format($amounts['value_difference'], 2)
        : number_format($amounts['value_difference'], 2);
    $nameWidth = !empty($layout['show_sold']) ? 24 : 32;
    $adjustedQty = $amounts['physical_qty'] - $amounts['sold_qty'];

    $pdf->Cell($w['id'], 8, (string) ($item['product_id'] ?? ''), 1, 0, 'C');
    $pdf->Cell($w['name'], 8, substr((string) ($item['product_name'] ?? ''), 0, $nameWidth), 1, 0, 'L');
    $pdf->Cell($w['unit_price'], 8, number_format($amounts['unit_price'], 2), 1, 0, 'R');
    $pdf->Cell($w['system_qty'], 8, (string) $amounts['system_qty'], 1, 0, 'C');
    if (!empty($layout['show_sold'])) {
        $pdf->Cell($w['sold_qty'], 8, (string) $amounts['sold_qty'], 1, 0, 'C');
    }
    $pdf->Cell($w['physical_qty'], 8, (string) $amounts['physical_qty'], 1, 0, 'C');
    if (!empty($layout['show_sold'])) {
        $pdf->Cell($w['adjusted_qty'], 8, (string) $adjustedQty, 1, 0, 'C');
    }
    $pdf->Cell($w['difference'], 8, $diffLabel, 1, 0, 'C');
    $pdf->Cell($w['value_diff'], 8, $valueLabel, 1, 1, 'R');

    return $amounts;
}

/**
 * @param object $pdf
 * @param array{show_sold:bool,col_widths:array<string,int>} $layout
 */
function stClosingPdfWriteSpacer($pdf, array $layout): void
{
    $w = $layout['col_widths'];
    $pdf->Cell($w['id'], 8, '', 1, 0, 'C');
    $pdf->Cell($w['name'], 8, '', 1, 0, 'L');
    $pdf->Cell($w['unit_price'], 8, '', 1, 0, 'R');
    $pdf->Cell($w['system_qty'], 8, '', 1, 0, 'C');
    if (!empty($layout['show_sold'])) {
        $pdf->Cell($w['sold_qty'], 8, '', 1, 0, 'C');
    }
    $pdf->Cell($w['physical_qty'], 8, '', 1, 0, 'C');
    if (!empty($layout['show_sold'])) {
        $pdf->Cell($w['adjusted_qty'], 8, '', 1, 0, 'C');
    }
    $pdf->Cell($w['difference'], 8, '', 1, 0, 'C');
    $pdf->Cell($w['value_diff'], 8, '', 1, 1, 'R');
}
