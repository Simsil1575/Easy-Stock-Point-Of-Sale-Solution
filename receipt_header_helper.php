<?php
declare(strict_types=1);

use Mike42\Escpos\Printer;

/**
 * Word-wrap header text so each line fits the receipt width (one row per line).
 */
function wrapReceiptHeaderText(string $text, int $width): array
{
    $text = trim($text);
    if ($text === '') {
        return [];
    }
    if ($width < 1) {
        $width = 1;
    }

    $words = preg_split('/\s+/', $text) ?: [];
    $lines = [];
    $current = '';

    foreach ($words as $word) {
        if ($word === '') {
            continue;
        }
        if ($current === '') {
            if (strlen($word) <= $width) {
                $current = $word;
            } else {
                $offset = 0;
                $len = strlen($word);
                while ($offset < $len) {
                    $lines[] = substr($word, $offset, $width);
                    $offset += $width;
                }
            }
            continue;
        }

        $candidate = $current . ' ' . $word;
        if (strlen($candidate) <= $width) {
            $current = $candidate;
            continue;
        }

        $lines[] = $current;
        if (strlen($word) <= $width) {
            $current = $word;
        } else {
            $current = '';
            $offset = 0;
            $len = strlen($word);
            while ($offset < $len) {
                $lines[] = substr($word, $offset, $width);
                $offset += $width;
            }
        }
    }

    if ($current !== '') {
        $lines[] = $current;
    }

    return $lines;
}

/**
 * Print primary (large/bold) and optional secondary (smaller bold) business names, one row each.
 */
function printBusinessNameHeader(Printer $printer, array $businessInfo, int $receiptWidth): void
{
    $primary = trim((string)($businessInfo['name'] ?? ''));
    $secondary = trim((string)($businessInfo['name_secondary'] ?? ''));

    if ($primary === '' && $secondary === '') {
        $primary = 'POS SOLUTION';
    }

    $doubleLineWidth = max(1, (int) floor($receiptWidth / 2));
    $singleLineWidth = max(1, $receiptWidth);

    $printer->setJustification(Printer::JUSTIFY_CENTER);

    if ($primary !== '') {
        foreach (wrapReceiptHeaderText($primary, $doubleLineWidth) as $line) {
            $printer->selectPrintMode(Printer::MODE_DOUBLE_WIDTH | Printer::MODE_DOUBLE_HEIGHT | Printer::MODE_EMPHASIZED);
            $printer->text($line . "\n");
            $printer->selectPrintMode();
        }
    }

    if ($secondary !== '') {
        $printer->setEmphasis(true);
        foreach (wrapReceiptHeaderText($secondary, $singleLineWidth) as $line) {
            $printer->text($line . "\n");
        }
        $printer->setEmphasis(false);
    }
}
