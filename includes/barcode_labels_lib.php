<?php

declare(strict_types=1);

function blRequireAdminOrManager(): void
{
    $role = strtolower((string) ($_SESSION['role'] ?? ''));
    if (!in_array($role, ['admin', 'manager'], true)) {
        header('Location: ../');
        exit;
    }
}

function blGetDb(): PDO
{
    $db = new PDO('sqlite:' . __DIR__ . '/../pos.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $db;
}

function blEnsureSettings(PDO $db): void
{
    $columns = [
        'label_printer_qz_name TEXT',
        'label_printer_ip TEXT',
        'label_printer_port INTEGER NOT NULL DEFAULT 9100',
        'label_width_mm INTEGER NOT NULL DEFAULT 51',
        'label_height_mm INTEGER NOT NULL DEFAULT 38',
        'label_height_tenths INTEGER NOT NULL DEFAULT 378',
        'label_default_copies INTEGER NOT NULL DEFAULT 1',
        'label_orientation TEXT NOT NULL DEFAULT \'landscape90\'',
        'label_show_name INTEGER NOT NULL DEFAULT 1',
        'label_show_barcode INTEGER NOT NULL DEFAULT 1',
        'label_show_price INTEGER NOT NULL DEFAULT 1',
        'label_font_size INTEGER NOT NULL DEFAULT 18',
    ];
    foreach ($columns as $col) {
        $name = trim(explode(' ', $col)[0]);
        try {
            $db->exec("ALTER TABLE product_settings ADD COLUMN {$col}");
        } catch (PDOException $e) {
            if (stripos($e->getMessage(), 'duplicate column') === false) {
                throw $e;
            }
        }
    }

    $count = (int) $db->query('SELECT COUNT(*) FROM product_settings')->fetchColumn();
    if ($count === 0) {
        $db->exec('INSERT INTO product_settings (id) VALUES (1)');
    }
}

/**
 * @return array<string, mixed>
 */
function blGetSettings(PDO $db): array
{
    blEnsureSettings($db);
    $row = $db->query('SELECT * FROM product_settings LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return [
            'label_printer_qz_name' => '',
            'label_printer_ip' => '',
            'label_printer_port' => 9100,
            'label_width_mm' => 51,
            'label_height_mm' => 38,
            'label_height_tenths' => 378,
            'label_default_copies' => 1,
            'label_orientation' => 'landscape90',
            'label_show_name' => true,
            'label_show_barcode' => true,
            'label_show_price' => true,
            'label_font_size' => 18,
        ];
    }
    return [
        'label_printer_qz_name' => (string) ($row['label_printer_qz_name'] ?? ''),
        'label_printer_ip' => (string) ($row['label_printer_ip'] ?? ''),
        'label_printer_port' => (int) ($row['label_printer_port'] ?? 9100),
        'label_width_mm' => (int) ($row['label_width_mm'] ?? 51),
        'label_height_mm' => blHeightMmFromRow($row),
        'label_height_tenths' => (int) ($row['label_height_tenths'] ?? blHeightMmToTenths(blHeightMmFromRow($row))),
        'label_default_copies' => max(1, (int) ($row['label_default_copies'] ?? 1)),
        'label_orientation' => blNormalizeOrientation((string) ($row['label_orientation'] ?? 'landscape90')),
        'label_show_name' => (bool) ((int) ($row['label_show_name'] ?? 1)),
        'label_show_barcode' => (bool) ((int) ($row['label_show_barcode'] ?? 1)),
        'label_show_price' => (bool) ((int) ($row['label_show_price'] ?? 1)),
        'label_font_size' => blClampFontSize((int) ($row['label_font_size'] ?? 18)),
    ];
}

function blClampFontSize(int $size): int
{
    return max(10, min(72, $size));
}

function blNormalizeOrientation(string $orientation): string
{
    $valid = ['portrait', 'landscape90', 'inverted180', 'landscape270'];
    return in_array($orientation, $valid, true) ? $orientation : 'landscape90';
}

function blOrientationZplCode(string $orientation): string
{
    switch (blNormalizeOrientation($orientation)) {
        case 'portrait':
            return 'N';
        case 'landscape90':
            return 'R';
        case 'inverted180':
            return 'I';
        case 'landscape270':
            return 'B';
        default:
            return 'R';
    }
}

function blMmToDots(float $mm): int
{
    return (int) round($mm * 8);
}

function blParseMm(array $options, string $primaryKey, string $altKey, float $default): float
{
    if (isset($options[$primaryKey]) && $options[$primaryKey] !== '') {
        return (float) $options[$primaryKey];
    }
    if (isset($options[$altKey]) && $options[$altKey] !== '') {
        return (float) $options[$altKey];
    }
    return $default;
}

function blHeightMmFromRow(array $row): float
{
    if (isset($row['label_height_tenths']) && (int) $row['label_height_tenths'] > 0) {
        return ((int) $row['label_height_tenths']) / 10.0;
    }
    return (float) ($row['label_height_mm'] ?? 37.8);
}

function blHeightMmToTenths(float $mm): int
{
    return max(150, min(1500, (int) round($mm * 10)));
}

function blBoolSetting(array $options, string $primaryKey, string $altKey, bool $default): bool
{
    if (array_key_exists($primaryKey, $options)) {
        return filter_var($options[$primaryKey], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }
    if (array_key_exists($altKey, $options)) {
        return filter_var($options[$altKey], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }
    return $default;
}

/**
 * @return array{width_mm: float, height_mm: float, orientation: string, show_name: bool, show_barcode: bool, show_price: bool}
 */
function blNormalizeLabelOptions(array $options = []): array
{
    $orientation = blNormalizeOrientation((string) ($options['label_orientation'] ?? $options['orientation'] ?? 'landscape90'));

    return [
        'width_mm' => max(20.0, min(200.0, blParseMm($options, 'label_width_mm', 'width_mm', 51.0))),
        'height_mm' => max(15.0, min(150.0, blParseMm($options, 'label_height_mm', 'height_mm', 37.8))),
        'orientation' => $orientation,
        'font_size' => blClampFontSize((int) ($options['label_font_size'] ?? $options['font_size'] ?? 18)),
        'show_name' => blBoolSetting($options, 'label_show_name', 'show_name', true),
        'show_barcode' => blBoolSetting($options, 'label_show_barcode', 'show_barcode', true),
        'show_price' => blBoolSetting($options, 'label_show_price', 'show_price', true),
    ];
}

/**
 * Merge saved settings with per-request overrides (print job options).
 *
 * @return array<string, mixed>
 */
function blMergePrintOptions(array $settings, array $overrides = []): array
{
    return array_merge($settings, array_filter([
        'label_width_mm' => isset($overrides['label_width_mm']) ? (float) $overrides['label_width_mm'] : null,
        'label_height_mm' => isset($overrides['label_height_mm']) ? (float) $overrides['label_height_mm'] : null,
        'label_orientation' => isset($overrides['label_orientation']) ? blNormalizeOrientation((string) $overrides['label_orientation']) : null,
        'label_font_size' => isset($overrides['label_font_size']) ? blClampFontSize((int) $overrides['label_font_size']) : null,
        'label_show_name' => array_key_exists('label_show_name', $overrides) ? blBoolSetting($overrides, 'label_show_name', 'show_name', true) : null,
        'label_show_barcode' => array_key_exists('label_show_barcode', $overrides) ? blBoolSetting($overrides, 'label_show_barcode', 'show_barcode', true) : null,
        'label_show_price' => array_key_exists('label_show_price', $overrides) ? blBoolSetting($overrides, 'label_show_price', 'show_price', true) : null,
    ], static fn($v) => $v !== null));
}

/**
 * @param array<string, mixed> $settings
 */
function blSaveSettings(PDO $db, array $settings): void
{
    blEnsureSettings($db);
    $widthMm = max(20.0, min(200.0, (float) ($settings['label_width_mm'] ?? 51)));
    $heightMm = max(15.0, min(150.0, (float) ($settings['label_height_mm'] ?? 37.8)));
    $heightTenths = blHeightMmToTenths($heightMm);

    $stmt = $db->prepare('
        UPDATE product_settings SET
            label_printer_qz_name = :qz_name,
            label_printer_ip = :ip,
            label_printer_port = :port,
            label_width_mm = :width,
            label_height_mm = :height_int,
            label_height_tenths = :height_tenths,
            label_default_copies = :copies,
            label_orientation = :orientation,
            label_font_size = :font_size,
            label_show_name = :show_name,
            label_show_barcode = :show_barcode,
            label_show_price = :show_price
        WHERE id = (SELECT id FROM product_settings LIMIT 1)
    ');
    $stmt->execute([
        ':qz_name' => trim((string) ($settings['label_printer_qz_name'] ?? '')),
        ':ip' => trim((string) ($settings['label_printer_ip'] ?? '')),
        ':port' => max(1, min(65535, (int) ($settings['label_printer_port'] ?? 9100))),
        ':width' => (int) round($widthMm),
        ':height_int' => (int) round($heightMm),
        ':height_tenths' => $heightTenths,
        ':copies' => max(1, min(99, (int) ($settings['label_default_copies'] ?? 1))),
        ':orientation' => blNormalizeOrientation((string) ($settings['label_orientation'] ?? 'landscape90')),
        ':font_size' => blClampFontSize((int) ($settings['label_font_size'] ?? 18)),
        ':show_name' => blBoolSetting($settings, 'label_show_name', 'show_name', true) ? 1 : 0,
        ':show_barcode' => blBoolSetting($settings, 'label_show_barcode', 'show_barcode', true) ? 1 : 0,
        ':show_price' => blBoolSetting($settings, 'label_show_price', 'show_price', true) ? 1 : 0,
    ]);
}

function blEscapeZpl(string $text): string
{
    $text = str_replace('\\', '\\\\', $text);
    $text = str_replace('^', '\\^', $text);
    $text = str_replace('~', '\\~', $text);
    return $text;
}

function blTruncate(string $text, int $maxLen): string
{
    if (strlen($text) <= $maxLen) {
        return $text;
    }
    return substr($text, 0, max(0, $maxLen - 1)) . '…';
}

function blEstimateBarcodeWidth(string $code, int $moduleWidth): int
{
    $len = strlen($code);
    if ($len === 0) {
        return 0;
    }
    $moduleWidth = max(1, min(3, $moduleWidth));
    return (11 * $len + 35) * $moduleWidth;
}

function blChooseBarcodeModuleWidth(string $code, int $maxWidth): int
{
    foreach ([2, 1] as $moduleWidth) {
        if (blEstimateBarcodeWidth($code, $moduleWidth) <= $maxWidth) {
            return $moduleWidth;
        }
    }
    return 1;
}

function blEstimateTextWidth(string $text, int $fontW): int
{
    return (int) ceil(strlen($text) * max(6, $fontW) * 0.62);
}

/**
 * Shrink name font (width first, then height) until the full name fits on the label.
 *
 * @return array{text: string, h: int, w: int}
 */
function blFitNameToLabel(string $name, int $maxWidth, int $baseSize): array
{
    $name = strtoupper(trim(preg_replace('/\s+/', ' ', $name) ?? ''));
    if ($name === '') {
        return ['text' => '', 'h' => $baseSize, 'w' => max(8, $baseSize - 2)];
    }

    $h = blClampFontSize($baseSize);
    $w = max(8, $h - 2);
    $min = 8;

    while ($w > $min && blEstimateTextWidth($name, $w) > $maxWidth) {
        $w--;
    }

    while ($h > $min && blEstimateTextWidth($name, $w) > $maxWidth) {
        $h--;
        $w = max($min, min($w, $h));
    }

    while ($w > $min && blEstimateTextWidth($name, $w) > $maxWidth) {
        $w--;
    }

    if (blEstimateTextWidth($name, $w) > $maxWidth) {
        $fitted = $name;
        while (strlen($fitted) > 3 && blEstimateTextWidth($fitted . '.', $w) > $maxWidth) {
            $fitted = rtrim(substr($fitted, 0, -1));
        }
        $name = $fitted !== $name ? $fitted . '.' : $fitted;
    }

    return ['text' => $name, 'h' => $h, 'w' => $w];
}

function blCenterAlong(int $canvas, int $contentW, int $margin): int
{
    return max($margin, (int) floor(($canvas - $contentW) / 2));
}

function blCenterX(int $canvasW, int $contentW, int $margin): int
{
    return blCenterAlong($canvasW, $contentW, $margin);
}

function blFieldRotation(string $orientation): string
{
    return blOrientationZplCode($orientation);
}

function blIsVerticalRotation(string $orientation): bool
{
    return in_array(blFieldRotation($orientation), ['R', 'B'], true);
}

/**
 * Retail label rows: name, barcode bars, barcode digits, price.
 *
 * @return list<array{type: string, h: int}>
 */
function blRetailRows(bool $hasName, bool $hasBarcode, bool $hasPrice, int $nameRowH, int $barcodeH, int $digitH, int $priceRowH): array
{
    $rows = [];
    if ($hasName) {
        $rows[] = ['type' => 'name', 'h' => $nameRowH];
    }
    if ($hasBarcode) {
        $rows[] = ['type' => 'barcode', 'h' => $barcodeH];
        $rows[] = ['type' => 'digits', 'h' => $digitH];
    }
    if ($hasPrice) {
        $rows[] = ['type' => 'price', 'h' => $priceRowH];
    }
    return $rows;
}

/**
 * Retail shelf-label layout. The whole block is centered on the label.
 *
 * @return array<string, mixed>
 */
function blComputeLabelLayout(array $opts, bool $hasName, bool $hasBarcode, bool $hasPrice, string $barcodeValue = ''): array
{
    $pw = blMmToDots($opts['width_mm']);
    $ll = blMmToDots($opts['height_mm']);
    $rot = blFieldRotation($opts['orientation']);
    $vertical = blIsVerticalRotation($opts['orientation']);
    $margin = 10;
    $gap = 4;

    $fontH = blClampFontSize((int) ($opts['font_size'] ?? 18));
    $fontW = max(10, $fontH - 2);
    $priceH = blClampFontSize($fontH + 8);
    $priceW = max(12, $priceH - 2);
    $digitH = max(12, min(16, $fontH - 2));
    $digitW = max(10, $digitH - 2);

    $nameRowH = $fontH + 4;
    $digitRowH = $digitH + 4;
    $priceRowH = $priceH + 4;

    $stackLen = $vertical ? $pw : $ll;
    $textAxis = $vertical ? $ll : $pw;
    $usableStack = max(40, $stackLen - ($margin * 2));
    $blockW = max(40, $textAxis - ($margin * 2));

    $barcodeH = 0;
    if ($hasBarcode) {
        $other = ($hasName ? $nameRowH + $gap : 0) + $digitRowH + $gap + ($hasPrice ? $priceRowH + $gap : 0);
        $barcodeH = min(70, max(28, $usableStack - $other - 8));
    }

    $rows = blRetailRows($hasName, $hasBarcode, $hasPrice, $nameRowH, $barcodeH, $digitRowH, $priceRowH);
    $totalH = array_sum(array_column($rows, 'h')) + max(0, count($rows) - 1) * $gap;

    while ($totalH > $usableStack && $barcodeH > 24) {
        $barcodeH -= 4;
        $rows = blRetailRows($hasName, $hasBarcode, $hasPrice, $nameRowH, $barcodeH, $digitRowH, $priceRowH);
        $totalH = array_sum(array_column($rows, 'h')) + max(0, count($rows) - 1) * $gap;
    }

    while ($totalH > $usableStack && $fontH > 12) {
        $fontH -= 2;
        $fontW = max(10, $fontH - 2);
        $priceH = blClampFontSize($fontH + 6);
        $priceW = max(12, $priceH - 2);
        $nameRowH = $fontH + 4;
        $priceRowH = $priceH + 4;
        $digitH = max(10, min(14, $fontH - 2));
        $digitW = max(10, $digitH - 2);
        $digitRowH = $digitH + 2;
        $rows = blRetailRows($hasName, $hasBarcode, $hasPrice, $nameRowH, $barcodeH, $digitRowH, $priceRowH);
        $totalH = array_sum(array_column($rows, 'h')) + max(0, count($rows) - 1) * $gap;
    }

    $start = $margin + (int) floor(max(0, $usableStack - $totalH) / 2);
    $slots = [];

    if ($vertical) {
        $x = $start;
        if ($rot === 'B') {
            $x = max($margin, $pw - $margin - $totalH);
        }
        foreach ($rows as $row) {
            $slots[] = ['type' => $row['type'], 'x' => $x, 'y' => $margin, 'h' => $row['h']];
            $x += $row['h'] + $gap;
        }
    } elseif ($rot === 'I') {
        $y = $ll - $margin;
        foreach ($rows as $row) {
            $y -= $row['h'];
            $slots[] = ['type' => $row['type'], 'x' => $margin, 'y' => max($margin, $y), 'h' => $row['h']];
            $y -= $gap;
        }
    } else {
        $y = $start;
        foreach ($rows as $row) {
            $slots[] = ['type' => $row['type'], 'x' => $margin, 'y' => $y, 'h' => $row['h']];
            $y += $row['h'] + $gap;
        }
    }

    $nameMaxLen = (int) max(10, min(26, floor($blockW / max(6, (int) ($fontW * 0.55)))));

    return [
        'pw' => $pw,
        'll' => $ll,
        'rot' => $rot,
        'vertical' => $vertical,
        'margin' => $margin,
        'block_w' => $blockW,
        'name_max_len' => $nameMaxLen,
        'font_h' => $fontH,
        'font_w' => $fontW,
        'price_h' => $priceH,
        'price_w' => $priceW,
        'digit_h' => $digitH,
        'digit_w' => $digitW,
        'barcode_h' => $barcodeH,
        'gap' => $gap,
        'slots' => $slots,
    ];
}

function blZplCenteredText(
    array $layout,
    int $x,
    int $y,
    int $fh,
    int $fw,
    string $text
): string {
    $rot = (string) $layout['rot'];
    $vertical = !empty($layout['vertical']);
    $margin = (int) $layout['margin'];
    $pw = (int) $layout['pw'];
    $ll = (int) $layout['ll'];
    $blockW = (int) $layout['block_w'];
    $tw = blEstimateTextWidth($text, $fw);

    if ($vertical) {
        $y = blCenterAlong($ll, $tw, $margin);
        return "^FO{$x},{$y}^A0{$rot},{$fh},{$fw}^FD{$text}^FS\n";
    }

    if ($rot === 'N') {
        return "^FO{$margin},{$y}^A0N,{$fh},{$fw}^FB{$blockW},1,0,C,0^FD{$text}^FS\n";
    }

    $x = blCenterAlong($pw, $tw, $margin);
    return "^FO{$x},{$y}^A0{$rot},{$fh},{$fw}^FD{$text}^FS\n";
}

/**
 * @return array{name: string, barcode: string, price: float}
 */
function blTestLabelProduct(): array
{
    return [
        'name' => 'TEST PRODUCT',
        'barcode' => '123456789012',
        'price' => 0.00,
    ];
}

/**
 * @param array{name: string, barcode: string, price: float|string} $product
 * @param array<string, mixed> $options
 */
function blBuildLabelZpl(array $product, array $options = []): string
{
    $opts = blNormalizeLabelOptions($options);
    if (!$opts['show_name'] && !$opts['show_barcode'] && !$opts['show_price']) {
        throw new RuntimeException('Select at least one label field (name, barcode, or price).');
    }

    $hasName = $opts['show_name'] && trim((string) ($product['name'] ?? '')) !== '';
    $hasBarcode = $opts['show_barcode'] && trim((string) ($product['barcode'] ?? '')) !== '';
    $hasPrice = $opts['show_price'];

    if (!$hasName && !$hasBarcode && !$hasPrice) {
        throw new RuntimeException('Nothing to print on this label for the selected fields.');
    }

    $barcodeValue = preg_replace('/[^\x20-\x7E]/', '', (string) ($product['barcode'] ?? '')) ?? '';
    $layout = blComputeLabelLayout($opts, $hasName, $hasBarcode, $hasPrice, $barcodeValue);
    $rot = (string) $layout['rot'];
    $vertical = !empty($layout['vertical']);
    $margin = (int) $layout['margin'];
    $pw = (int) $layout['pw'];
    $ll = (int) $layout['ll'];
    $fh = (int) $layout['font_h'];
    $fw = (int) $layout['font_w'];
    $blockW = (int) $layout['block_w'];

    $zpl = "^XA\n"
        . "^PON\n"
        . "^PW{$pw}\n"
        . "^LL{$ll}\n"
        . "^LH0,0\n"
        . "^LT0\n"
        . "^LS0\n"
        . "^CI28\n"
        . "^FW{$rot}\n";

    foreach ($layout['slots'] as $slot) {
        $x = (int) $slot['x'];
        $y = (int) $slot['y'];

        if ($slot['type'] === 'name') {
            $rawName = trim((string) ($product['name'] ?? ''));
            if ($rawName !== '') {
                $fitted = blFitNameToLabel($rawName, $blockW, $fh);
                $zpl .= blZplCenteredText($layout, $x, $y, $fitted['h'], $fitted['w'], blEscapeZpl($fitted['text']));
            }
        } elseif ($slot['type'] === 'barcode') {
            $barcode = preg_replace('/[^\x20-\x7E]/', '', (string) ($product['barcode'] ?? '')) ?? '';
            if ($barcode !== '') {
                $bh = (int) $layout['barcode_h'];
                $moduleW = blChooseBarcodeModuleWidth($barcode, $blockW);
                $estW = blEstimateBarcodeWidth($barcode, $moduleW);
                if ($vertical) {
                    $y = blCenterAlong($ll, $estW, $margin);
                } else {
                    $x = blCenterAlong($pw, $estW, $margin);
                }
                $zpl .= "^FO{$x},{$y}^BY{$moduleW},3,{$bh}^BC{$rot},{$bh},N,N,N^FD{$barcode}^FS\n";
            }
        } elseif ($slot['type'] === 'digits') {
            $barcode = preg_replace('/[^\x20-\x7E]/', '', (string) ($product['barcode'] ?? '')) ?? '';
            if ($barcode !== '') {
                $zpl .= blZplCenteredText(
                    $layout,
                    $x,
                    $y,
                    (int) $layout['digit_h'],
                    (int) $layout['digit_w'],
                    blEscapeZpl($barcode)
                );
            }
        } elseif ($slot['type'] === 'price') {
            $priceText = 'N$ ' . number_format((float) ($product['price'] ?? 0), 2);
            $priceText = blEscapeZpl($priceText);
            $zpl .= blZplCenteredText(
                $layout,
                $x,
                $y,
                (int) $layout['price_h'],
                (int) $layout['price_w'],
                $priceText
            );
        }
    }

    $zpl .= "^XZ\n";
    return $zpl;
}

/**
 * @param array{name: string, barcode: string, price: float|string} $product
 * @param array<string, mixed> $options
 */
function blProductPrintable(array $product, array $options = []): bool
{
    $opts = blNormalizeLabelOptions($options);
    if (!$opts['show_name'] && !$opts['show_barcode'] && !$opts['show_price']) {
        return false;
    }
    if ($opts['show_name'] && trim((string) ($product['name'] ?? '')) !== '') {
        return true;
    }
    if ($opts['show_price']) {
        return true;
    }
    if ($opts['show_barcode'] && trim((string) ($product['barcode'] ?? '')) !== '') {
        return true;
    }
    return false;
}

/**
 * @param list<array{name: string, barcode: string, price: float|string}> $products
 * @param array<string, mixed> $options
 */
function blBuildBulkZpl(array $products, int $copies = 1, array $options = []): string
{
    $copies = max(1, min(99, $copies));
    $opts = blNormalizeLabelOptions($options);
    if (!$opts['show_name'] && !$opts['show_barcode'] && !$opts['show_price']) {
        throw new RuntimeException('Select at least one label field (name, barcode, or price).');
    }

    $zpl = '';
    foreach ($products as $product) {
        if (!blProductPrintable($product, $opts)) {
            continue;
        }
        try {
            $label = blBuildLabelZpl($product, $opts);
        } catch (RuntimeException $e) {
            continue;
        }
        for ($i = 0; $i < $copies; $i++) {
            $zpl .= $label;
        }
    }
    return $zpl;
}

function blBarcodeExists(PDO $db, string $barcode, ?int $excludeId = null): bool
{
    $barcode = trim($barcode);
    if ($barcode === '') {
        return false;
    }
    $sql = 'SELECT COUNT(*) FROM products WHERE TRIM(barcode) = ?';
    $params = [$barcode];
    if ($excludeId !== null && $excludeId > 0) {
        $sql .= ' AND id != ?';
        $params[] = $excludeId;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn() > 0;
}

function blGenerateBarcode(PDO $db, int $productId): string
{
    $base = '2' . str_pad((string) $productId, 11, '0', STR_PAD_LEFT);
    $candidate = $base;
    $suffix = 0;
    while (blBarcodeExists($db, $candidate)) {
        $suffix++;
        $candidate = $base . (string) $suffix;
        if ($suffix > 99) {
            throw new RuntimeException('Unable to generate a unique barcode.');
        }
    }
    return $candidate;
}

/**
 * @return array{id: int, name: string, barcode: ?string, price: float, category: ?string}
 */
function blGetProduct(PDO $db, int $productId): ?array
{
    $stmt = $db->prepare('SELECT id, name, barcode, price, category FROM products WHERE id = ?');
    $stmt->execute([$productId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    return [
        'id' => (int) $row['id'],
        'name' => (string) $row['name'],
        'barcode' => $row['barcode'] !== null ? (string) $row['barcode'] : null,
        'price' => (float) $row['price'],
        'category' => $row['category'] !== null ? (string) $row['category'] : null,
    ];
}

function blSetBarcode(PDO $db, int $productId, ?string $barcode): void
{
    $product = blGetProduct($db, $productId);
    if (!$product) {
        throw new RuntimeException('Product not found.');
    }
    $barcode = $barcode !== null ? trim($barcode) : '';
    if ($barcode === '') {
        $stmt = $db->prepare('UPDATE products SET barcode = NULL WHERE id = ?');
        $stmt->execute([$productId]);
        return;
    }
    if (blBarcodeExists($db, $barcode, $productId)) {
        throw new RuntimeException('Barcode already assigned to another product.');
    }
    $stmt = $db->prepare('UPDATE products SET barcode = ? WHERE id = ?');
    $stmt->execute([$barcode, $productId]);
}

function blCreateBarcode(PDO $db, int $productId, ?string $barcode = null): string
{
    $product = blGetProduct($db, $productId);
    if (!$product) {
        throw new RuntimeException('Product not found.');
    }
    $existing = trim((string) ($product['barcode'] ?? ''));
    if ($existing !== '') {
        throw new RuntimeException('Product already has a barcode.');
    }
    $value = $barcode !== null && trim($barcode) !== '' ? trim($barcode) : blGenerateBarcode($db, $productId);
    if (blBarcodeExists($db, $value, $productId)) {
        throw new RuntimeException('Barcode already assigned to another product.');
    }
    $stmt = $db->prepare('UPDATE products SET barcode = ? WHERE id = ?');
    $stmt->execute([$value, $productId]);
    return $value;
}

/**
 * @return int Number of barcodes generated
 */
function blGenerateMissingBarcodes(PDO $db, ?array $productIds = null): int
{
    if ($productIds !== null && $productIds !== []) {
        $ids = array_values(array_filter(array_map('intval', $productIds), fn($id) => $id > 0));
        if ($ids === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("
            SELECT id FROM products
            WHERE id IN ({$placeholders})
              AND (barcode IS NULL OR TRIM(barcode) = '')
        ");
        $stmt->execute($ids);
    } else {
        $stmt = $db->query("
            SELECT id FROM products
            WHERE barcode IS NULL OR TRIM(barcode) = ''
            ORDER BY name
        ");
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $count = 0;
    foreach ($rows as $row) {
        blCreateBarcode($db, (int) $row['id']);
        $count++;
    }
    return $count;
}

/**
 * @param list<int> $productIds
 * @return list<array{id: int, name: string, barcode: string, price: float, category: ?string}>
 */
function blGetProductsByIds(PDO $db, array $productIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $productIds), fn($id) => $id > 0)));
    if ($ids === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("
        SELECT id, name, barcode, price, category
        FROM products
        WHERE id IN ({$placeholders})
        ORDER BY name COLLATE NOCASE
    ");
    $stmt->execute($ids);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $out[] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'barcode' => (string) ($row['barcode'] ?? ''),
            'price' => (float) $row['price'],
            'category' => $row['category'] !== null ? (string) $row['category'] : null,
        ];
    }
    return $out;
}

/**
 * @return array{products: list<array>, total: int, with_barcode: int, without_barcode: int}
 */
function blListProducts(PDO $db, array $filters = []): array
{
    $search = trim((string) ($filters['search'] ?? ''));
    $category = trim((string) ($filters['category'] ?? ''));
    $status = trim((string) ($filters['status'] ?? ''));
    $limit = max(1, min(500, (int) ($filters['limit'] ?? 200)));
    $offset = max(0, (int) ($filters['offset'] ?? 0));

    $where = ['1=1'];
    $params = [];

    if ($search !== '') {
        $where[] = '(name LIKE ? OR barcode LIKE ?)';
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
    }
    if ($category !== '') {
        if ($category === '__uncategorized__') {
            $where[] = "(category IS NULL OR TRIM(category) = '')";
        } else {
            $where[] = 'TRIM(category) = ?';
            $params[] = $category;
        }
    }
    if ($status === 'has') {
        $where[] = "(barcode IS NOT NULL AND TRIM(barcode) != '')";
    } elseif ($status === 'missing') {
        $where[] = "(barcode IS NULL OR TRIM(barcode) = '')";
    }

    $whereSql = implode(' AND ', $where);

    $countStmt = $db->prepare("SELECT COUNT(*) FROM products WHERE {$whereSql}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $listParams = array_merge($params, [$limit, $offset]);
    $stmt = $db->prepare("
        SELECT id, name, barcode, price, category
        FROM products
        WHERE {$whereSql}
        ORDER BY name COLLATE NOCASE
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($listParams);

    $products = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $barcode = trim((string) ($row['barcode'] ?? ''));
        $products[] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'barcode' => $barcode,
            'price' => (float) $row['price'],
            'category' => $row['category'] !== null ? trim((string) $row['category']) : '',
            'has_barcode' => $barcode !== '',
        ];
    }

    $withBarcode = (int) $db->query("SELECT COUNT(*) FROM products WHERE barcode IS NOT NULL AND TRIM(barcode) != ''")->fetchColumn();
    $withoutBarcode = (int) $db->query("SELECT COUNT(*) FROM products WHERE barcode IS NULL OR TRIM(barcode) = ''")->fetchColumn();

    return [
        'products' => $products,
        'total' => $total,
        'with_barcode' => $withBarcode,
        'without_barcode' => $withoutBarcode,
    ];
}

/**
 * @return list<string>
 */
function blListCategories(PDO $db): array
{
    $stmt = $db->query("
        SELECT DISTINCT TRIM(category) AS category
        FROM products
        WHERE category IS NOT NULL AND TRIM(category) != ''
        ORDER BY category COLLATE NOCASE
    ");
    return array_map(fn($r) => (string) $r['category'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

function blSendZplToNetwork(string $ip, int $port, string $zpl): void
{
    $ip = trim($ip);
    if ($ip === '') {
        throw new RuntimeException('Label printer IP is not configured.');
    }
    if ($zpl === '') {
        throw new RuntimeException('No printable labels in this selection.');
    }

    $errno = 0;
    $errstr = '';
    $socket = @fsockopen($ip, $port, $errno, $errstr, 8);
    if ($socket === false) {
        throw new RuntimeException("Could not connect to printer at {$ip}:{$port} — {$errstr}");
    }
    stream_set_timeout($socket, 10);
    $written = fwrite($socket, $zpl);
    fclose($socket);
    if ($written === false || $written < strlen($zpl)) {
        throw new RuntimeException('Failed to send all label data to the printer.');
    }
}

function blSanitizeBarcodeValue(string $barcode): string
{
    return preg_replace('/[^\x20-\x7E]/', '', trim($barcode)) ?? '';
}
