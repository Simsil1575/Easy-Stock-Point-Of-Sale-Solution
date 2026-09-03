<?php

declare(strict_types=1);

require_once __DIR__ . '/../recipe_stock_helper.php';
require_once __DIR__ . '/../ensure_stock_changes_username.php';
require_once __DIR__ . '/stock_taking_calc.php';

function strEnsureTables(PDO $db): void
{
    configureSqlitePdo($db);
    ensureStockChangesUsernameColumn($db);

    $db->exec("
        CREATE TABLE IF NOT EXISTS stock_take_records (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            username TEXT NOT NULL,
            stock_type TEXT NOT NULL,
            stock_date DATE NOT NULL,
            taken_at DATETIME NOT NULL,
            category TEXT,
            total_items INTEGER NOT NULL DEFAULT 0,
            total_variance INTEGER NOT NULL DEFAULT 0,
            total_value_difference DECIMAL(10,2) NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $db->exec("
        CREATE TABLE IF NOT EXISTS stock_take_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            record_id INTEGER NOT NULL,
            product_id INTEGER NOT NULL,
            product_name TEXT NOT NULL,
            unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            buying_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            expected_quantity INTEGER NOT NULL DEFAULT 0,
            actual_quantity INTEGER NOT NULL DEFAULT 0,
            variance INTEGER NOT NULL DEFAULT 0,
            value_difference DECIMAL(10,2) NOT NULL DEFAULT 0,
            opening_stock INTEGER NOT NULL DEFAULT 0,
            received_stock INTEGER NOT NULL DEFAULT 0,
            sold_quantity INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY(record_id) REFERENCES stock_take_records(id) ON DELETE CASCADE,
            FOREIGN KEY(product_id) REFERENCES products(id)
        )
    ");
    $db->exec('CREATE INDEX IF NOT EXISTS idx_stock_take_records_date ON stock_take_records(stock_date)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_stock_take_records_type ON stock_take_records(stock_type)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_stock_take_items_record ON stock_take_items(record_id)');

    strBackfillLegacyRecords($db);
}

function strLookupUsername(?int $userId): string
{
    if (!$userId) {
        return 'Unknown';
    }
    try {
        $userDb = new PDO('sqlite:' . __DIR__ . '/../user.db');
        $userDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $userDb->prepare('SELECT username FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $name = $stmt->fetchColumn();
        return $name !== false && $name !== '' ? (string) $name : 'User #' . $userId;
    } catch (Throwable $e) {
        return 'User #' . $userId;
    }
}

function strBackfillLegacyRecords(PDO $db): void
{
    $count = (int) $db->query('SELECT COUNT(*) FROM stock_take_records')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $hasOpening = (int) $db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='opening_stock'")->fetchColumn() > 0;
    $hasClosing = (int) $db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='closing_stock'")->fetchColumn() > 0;
    if (!$hasOpening && !$hasClosing) {
        return;
    }

    $db->beginTransaction();
    try {
        if ($hasOpening) {
            $sessions = $db->query("
                SELECT
                    DATE(recorded_at) AS stock_date,
                    strftime('%Y-%m-%d %H:%M', recorded_at) AS session_key,
                    recorded_by,
                    MIN(recorded_at) AS taken_at
                FROM opening_stock
                GROUP BY DATE(recorded_at), strftime('%Y-%m-%d %H:%M', recorded_at), recorded_by
                ORDER BY taken_at ASC
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($sessions as $session) {
                strBackfillSession($db, 'opening', $session);
            }
        }

        if ($hasClosing) {
            $sessions = $db->query("
                SELECT
                    DATE(recorded_at) AS stock_date,
                    strftime('%Y-%m-%d %H:%M', recorded_at) AS session_key,
                    recorded_by,
                    MIN(recorded_at) AS taken_at
                FROM closing_stock
                GROUP BY DATE(recorded_at), strftime('%Y-%m-%d %H:%M', recorded_at), recorded_by
                ORDER BY taken_at ASC
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($sessions as $session) {
                strBackfillSession($db, 'closing', $session);
            }
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
}

function strBackfillSession(PDO $db, string $stockType, array $session): void
{
    $table = $stockType === 'opening' ? 'opening_stock' : 'closing_stock';
    $qtyCol = $stockType === 'opening' ? 'opening_quantity' : 'closing_quantity';
    $sessionKey = (string) ($session['session_key'] ?? '');
    $stockDate = (string) ($session['stock_date'] ?? '');
    $userId = (int) ($session['recorded_by'] ?? 0);
    $takenAt = (string) ($session['taken_at'] ?? $stockDate);

    $stmt = $db->prepare("
        SELECT os.id, os.product_id, os.{$qtyCol} AS actual_quantity, os.recorded_at,
               p.name AS product_name, p.price AS unit_price, p.buying_price
        FROM {$table} os
        JOIN products p ON p.id = os.product_id
        WHERE DATE(os.recorded_at) = ?
          AND strftime('%Y-%m-%d %H:%M', os.recorded_at) = ?
          AND COALESCE(os.recorded_by, 0) = ?
        ORDER BY p.name COLLATE NOCASE
    ");
    $stmt->execute([$stockDate, $sessionKey, $userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (empty($rows)) {
        return;
    }

    $items = [];
    foreach ($rows as $row) {
        $productId = (int) $row['product_id'];
        $actual = (int) $row['actual_quantity'];
        $expected = $actual;
        $variance = 0;

        $action = $stockType === 'opening' ? 'Opening Stock Adjustment' : 'Closing Stock Adjustment';
        $adjStmt = $db->prepare("
            SELECT old_quantity, quantity_change
            FROM stock_changes
            WHERE product_id = ?
              AND action = ?
              AND DATE(changed_at) = ?
              AND COALESCE(is_stock_taken, 0) = 1
            ORDER BY changed_at DESC
            LIMIT 1
        ");
        $adjStmt->execute([$productId, $action, $stockDate]);
        $adj = $adjStmt->fetch(PDO::FETCH_ASSOC);
        if ($adj) {
            $expected = (int) $adj['old_quantity'];
            $variance = (int) $adj['quantity_change'];
        }

        $unitPrice = (float) ($row['unit_price'] ?? 0);
        $items[] = [
            'product_id' => $productId,
            'product_name' => (string) $row['product_name'],
            'unit_price' => $unitPrice,
            'buying_price' => (float) ($row['buying_price'] ?? 0),
            'expected_quantity' => $expected,
            'actual_quantity' => $actual,
            'variance' => $variance !== 0 ? $variance : ($actual - $expected),
            'value_difference' => ($actual - $expected) * $unitPrice,
            'opening_stock' => $stockType === 'opening' ? $actual : 0,
            'received_stock' => 0,
            'sold_quantity' => 0,
        ];
    }

    strInsertRecord($db, [
        'user_id' => $userId,
        'username' => strLookupUsername($userId),
        'stock_type' => $stockType,
        'stock_date' => $stockDate,
        'taken_at' => $takenAt,
        'category' => '',
        'items' => $items,
    ]);
}

/**
 * @param array{user_id:int,username:string,stock_type:string,stock_date:string,taken_at?:string,category?:string,items:list<array>} $data
 */
function strSaveRecord(PDO $db, array $data): int
{
    strEnsureTables($db);
    if (empty($data['items'])) {
        return 0;
    }

    $payload = $data;
    $payload['taken_at'] = $payload['taken_at'] ?? date('Y-m-d H:i:s');
    $payload['category'] = trim((string) ($payload['category'] ?? ''));

    $normalized = [];
    foreach ($data['items'] as $item) {
        $expected = (int) ($item['expected_quantity'] ?? 0);
        $actual = (int) ($item['actual_quantity'] ?? 0);
        $variance = (int) ($item['variance'] ?? ($actual - $expected));
        $unitPrice = (float) ($item['price'] ?? $item['unit_price'] ?? 0);
        $normalized[] = [
            'product_id' => (int) ($item['product_id'] ?? 0),
            'product_name' => (string) ($item['product_name'] ?? ''),
            'unit_price' => $unitPrice,
            'buying_price' => (float) ($item['buying_price'] ?? 0),
            'expected_quantity' => $expected,
            'actual_quantity' => $actual,
            'variance' => $variance,
            'value_difference' => (float) ($item['value_difference'] ?? ($variance * $unitPrice)),
            'opening_stock' => (int) ($item['opening_stock'] ?? 0),
            'received_stock' => (int) ($item['received_stock'] ?? 0),
            'sold_quantity' => (int) ($item['sold_quantity'] ?? 0),
        ];
    }
    $payload['items'] = $normalized;

    return strInsertRecord($db, $payload);
}

/**
 * @param array{user_id:int,username:string,stock_type:string,stock_date:string,taken_at:string,category:string,items:list<array>} $data
 */
function strInsertRecord(PDO $db, array $data): int
{
    $items = $data['items'];
    $totalVariance = 0;
    $totalValueDiff = 0.0;
    foreach ($items as $item) {
        $totalVariance += (int) $item['variance'];
        $totalValueDiff += (float) $item['value_difference'];
    }

    $stmt = $db->prepare('
        INSERT INTO stock_take_records
        (user_id, username, stock_type, stock_date, taken_at, category, total_items, total_variance, total_value_difference)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        (int) $data['user_id'],
        (string) $data['username'],
        (string) $data['stock_type'],
        (string) $data['stock_date'],
        (string) $data['taken_at'],
        (string) ($data['category'] ?? ''),
        count($items),
        $totalVariance,
        $totalValueDiff,
    ]);
    $recordId = (int) $db->lastInsertId();

    $itemStmt = $db->prepare('
        INSERT INTO stock_take_items
        (record_id, product_id, product_name, unit_price, buying_price, expected_quantity, actual_quantity, variance, value_difference, opening_stock, received_stock, sold_quantity)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    foreach ($items as $item) {
        $itemStmt->execute([
            $recordId,
            (int) $item['product_id'],
            (string) $item['product_name'],
            (float) $item['unit_price'],
            (float) $item['buying_price'],
            (int) $item['expected_quantity'],
            (int) $item['actual_quantity'],
            (int) $item['variance'],
            (float) $item['value_difference'],
            (int) $item['opening_stock'],
            (int) $item['received_stock'],
            (int) $item['sold_quantity'],
        ]);
    }

    return $recordId;
}

/**
 * @return array{rows: list<array>, total: int}
 */
function strListRecords(PDO $db, array $filters = []): array
{
    strEnsureTables($db);

    $where = ['1=1'];
    $params = [];

    if (!empty($filters['date_from'])) {
        $where[] = 'date(str.stock_date) >= ?';
        $params[] = $filters['date_from'];
    }
    if (!empty($filters['date_to'])) {
        $where[] = 'date(str.stock_date) <= ?';
        $params[] = $filters['date_to'];
    }
    if (!empty($filters['stock_type']) && in_array($filters['stock_type'], ['opening', 'closing'], true)) {
        $where[] = 'str.stock_type = ?';
        $params[] = $filters['stock_type'];
    }
    if (!empty($filters['search'])) {
        $where[] = '(str.username LIKE ? OR EXISTS (
            SELECT 1 FROM stock_take_items sti WHERE sti.record_id = str.id AND sti.product_name LIKE ?
        ))';
        $term = '%' . $filters['search'] . '%';
        $params[] = $term;
        $params[] = $term;
    }

    $limit = max(1, min(500, (int) ($filters['limit'] ?? 100)));
    $offset = max(0, (int) ($filters['offset'] ?? 0));
    $whereSql = implode(' AND ', $where);

    $countStmt = $db->prepare("SELECT COUNT(*) FROM stock_take_records str WHERE $whereSql");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $sql = "
        SELECT str.*
        FROM stock_take_records str
        WHERE $whereSql
        ORDER BY str.taken_at DESC, str.id DESC
        LIMIT $limit OFFSET $offset
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total];
}

/**
 * @return array{record: array, items: list<array>}|null
 */
function strGetRecord(PDO $db, int $id): ?array
{
    strEnsureTables($db);
    if ($id < 1) {
        return null;
    }

    $stmt = $db->prepare('SELECT * FROM stock_take_records WHERE id = ?');
    $stmt->execute([$id]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$record) {
        return null;
    }

    $itemsStmt = $db->prepare('SELECT * FROM stock_take_items WHERE record_id = ? ORDER BY product_name COLLATE NOCASE');
    $itemsStmt->execute([$id]);

    return ['record' => $record, 'items' => $itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: []];
}

function strDeleteRecord(PDO $db, int $id): void
{
    $bundle = strGetRecord($db, $id);
    if (!$bundle) {
        throw new RuntimeException('Stock take record not found');
    }

    $record = $bundle['record'];
    $stockType = (string) $record['stock_type'];
    $stockDate = (string) $record['stock_date'];
    $username = currentStockChangeUsername();

    $db->beginTransaction();
    try {
        foreach ($bundle['items'] as $item) {
            $productId = (int) $item['product_id'];
            $expected = (int) $item['expected_quantity'];
            $actual = (int) $item['actual_quantity'];
            $variance = (int) $item['variance'];

            $prodStmt = $db->prepare('SELECT quantity FROM products WHERE id = ?');
            $prodStmt->execute([$productId]);
            $currentQty = (float) $prodStmt->fetchColumn();

            if (abs($currentQty - $actual) < 0.00001 && $variance !== 0) {
                $db->prepare('UPDATE products SET quantity = ? WHERE id = ?')->execute([$expected, $productId]);
                $action = $stockType === 'opening' ? 'Opening Stock Adjustment' : 'Closing Stock Adjustment';
                if ((int) $db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='stock_changes'")->fetchColumn() > 0) {
                    $db->prepare('
                        INSERT INTO stock_changes (product_id, action, quantity_change, old_quantity, new_quantity, changed_at, is_stock_taken, username)
                        VALUES (?, ?, ?, ?, ?, ?, 1, ?)
                    ')->execute([$productId, $action . ' Reversal', -$variance, $actual, $expected, date('Y-m-d H:i:s'), $username]);
                }
            }

            if ($stockType === 'opening') {
                $db->prepare('DELETE FROM opening_stock WHERE product_id = ? AND DATE(recorded_at) = ?')->execute([$productId, $stockDate]);
            } else {
                $db->prepare('DELETE FROM closing_stock WHERE product_id = ? AND DATE(recorded_at) = ?')->execute([$productId, $stockDate]);
                $db->prepare("
                    UPDATE stock_changes SET is_stock_taken = 0
                    WHERE product_id = ? AND action = 'Restock' AND DATE(changed_at) = ?
                ")->execute([$productId, $stockDate]);
            }
        }

        $db->prepare('DELETE FROM stock_take_records WHERE id = ?')->execute([$id]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function strStockTypeLabel(string $stockType): string
{
    return $stockType === 'opening' ? 'Opening Stock' : 'Closing Stock';
}

/**
 * @return list<array{product_id:int,product_name:string,quantity:int|float,unit_price:float}>
 */
function strFetchLiveCurrentStockItems(PDO $db): array
{
    $stmt = $db->query("
        SELECT id, name, quantity, price
        FROM products
        WHERE CAST(quantity AS INTEGER) > 0
        ORDER BY name COLLATE NOCASE ASC
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $items = [];
    foreach ($rows as $row) {
        $items[] = [
            'product_id' => (int) $row['id'],
            'product_name' => (string) $row['name'],
            'quantity' => (int) $row['quantity'],
            'unit_price' => (float) $row['price'],
        ];
    }

    return $items;
}

/**
 * @param list<array{product_id:int,product_name:string,quantity:int|float,unit_price:float}> $items
 * @param array{subtitle?:string,generated_at?:string,file_name?:string,download?:bool} $options
 */
function strRenderCurrentStockPdf(array $items, array $options = []): void
{
    $fpdfPath = __DIR__ . '/../fpdf/fpdf.php';
    if (!file_exists($fpdfPath)) {
        throw new RuntimeException('FPDF library not found');
    }
    require_once $fpdfPath;

    $subtitle = (string) ($options['subtitle'] ?? '');
    $generatedAt = (string) ($options['generated_at'] ?? date('Y-m-d H:i:s'));
    $download = (bool) ($options['download'] ?? true);
    $fileName = (string) ($options['file_name'] ?? ('Current_Stock_Inventory_Report_' . date('Y-m-d_H-i-s', strtotime($generatedAt)) . '.pdf'));

    if (!class_exists('CurrentStockInventoryPDF', false)) {
        class CurrentStockInventoryPDF extends FPDF
        {
            public $subtitle = '';
            public $generatedAt = '';

            public function Header(): void
            {
                $this->SetFont('Arial', 'B', 15);
                $this->Cell(0, 10, 'Current Stock Inventory Report', 0, 1, 'C');
                $this->SetFont('Arial', '', 12);
                if ($this->subtitle !== '') {
                    $this->Cell(0, 10, $this->subtitle, 0, 1, 'C');
                }
                $this->Cell(0, 10, 'Generated on ' . $this->generatedAt, 0, 1, 'C');
                $this->Ln(5);

                $this->SetFont('Arial', 'B', 11);
                $this->Cell(10, 10, 'ID', 1);
                $this->Cell(80, 10, 'Product Name', 1);
                $this->Cell(25, 10, 'Quantity', 1);
                $this->Cell(25, 10, 'Price', 1);
                $this->Cell(35, 10, 'Total Value', 1);
                $this->Ln();
            }

            public function Footer(): void
            {
                $this->SetY(-15);
                $this->SetFont('Arial', 'I', 8);
                $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
            }
        }
    }

    $pdf = new CurrentStockInventoryPDF();
    $pdf->subtitle = $subtitle;
    $pdf->generatedAt = date('Y-m-d H:i:s', strtotime($generatedAt));
    $pdf->AliasNbPages();
    $pdf->AddPage();
    $pdf->SetFont('Arial', '', 10);

    $totalProducts = 0;
    $totalItems = 0;
    $totalValue = 0.0;

    foreach ($items as $item) {
        $quantity = (int) $item['quantity'];
        if ($quantity <= 0) {
            continue;
        }
        $unitPrice = (float) $item['unit_price'];
        $lineValue = $unitPrice * $quantity;

        $pdf->Cell(10, 8, (string) $item['product_id'], 1);
        $pdf->Cell(80, 8, substr((string) $item['product_name'], 0, 42), 1);
        $pdf->Cell(25, 8, (string) $quantity, 1);
        $pdf->Cell(25, 8, 'N$' . number_format($unitPrice, 2), 1);
        $pdf->Cell(35, 8, 'N$' . number_format($lineValue, 2), 1);
        $pdf->Ln();

        $totalProducts++;
        $totalItems += $quantity;
        $totalValue += $lineValue;
    }

    $pdf->Ln(5);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 10, 'Current Inventory Summary', 0, 1, 'L');
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(100, 8, 'Total Product Types:', 0, 0, 'L');
    $pdf->Cell(50, 8, (string) $totalProducts, 0, 1, 'L');
    $pdf->Cell(100, 8, 'Total Items in Stock:', 0, 0, 'L');
    $pdf->Cell(50, 8, (string) $totalItems, 0, 1, 'L');
    $pdf->Cell(100, 8, 'Total Inventory Value:', 0, 0, 'L');
    $pdf->Cell(50, 8, 'N$' . number_format($totalValue, 2), 0, 1, 'L');

    if ($download) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        $pdf->Output('D', $fileName);
        exit;
    }

    $pdf->Output('I', $fileName);
    exit;
}

function strOutputCurrentStockPdf(PDO $db): void
{
    strRenderCurrentStockPdf(strFetchLiveCurrentStockItems($db), [
        'generated_at' => date('Y-m-d H:i:s'),
        'download' => true,
    ]);
}

/**
 * @return 'name'|'category'
 */
function strNormalizeStockTakeSheetSort(string $sort): string
{
    return $sort === 'category' ? 'category' : 'name';
}

function strStockTakeSheetCategoryLabel(string $category): string
{
    $category = trim($category);
    if ($category === '') {
        return 'All categories';
    }
    if ($category === '__uncategorized__') {
        return 'Uncategorized';
    }
    return $category;
}

/**
 * @return list<string>
 */
function strStockTakeSheetCategoryNames(PDO $db): array
{
    try {
        require_once __DIR__ . '/categories_lib.php';
        return catListNames($db);
    } catch (Throwable $e) {
        $rows = $db->query("
            SELECT DISTINCT TRIM(category) AS category
            FROM products
            WHERE category IS NOT NULL AND TRIM(category) != ''
            ORDER BY category COLLATE NOCASE
        ")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        return array_values(array_filter(array_map('strval', $rows)));
    }
}

/**
 * @param list<array{product_id:int,product_name:string,system_quantity:int|float,unit_price:float,category:string}> $items
 * @return list<array{product_id:int,product_name:string,system_quantity:int|float,unit_price:float,category:string}>
 */
function strSortStockTakeSheetItems(array $items, string $sort): array
{
    $sort = strNormalizeStockTakeSheetSort($sort);
    usort($items, static function (array $a, array $b) use ($sort): int {
        if ($sort === 'category') {
            $catA = trim((string) ($a['category'] ?? ''));
            $catB = trim((string) ($b['category'] ?? ''));
            if ($catA === '') {
                $catA = 'Uncategorized';
            }
            if ($catB === '') {
                $catB = 'Uncategorized';
            }
            $byCategory = strcasecmp($catA, $catB);
            if ($byCategory !== 0) {
                return $byCategory;
            }
        }

        return strcasecmp((string) $a['product_name'], (string) $b['product_name']);
    });

    return $items;
}

/**
 * @return list<array{product_id:int,product_name:string,system_quantity:int|float,unit_price:float,category:string}>
 */
function strFetchStockTakeSheetItems(PDO $db, string $sort = 'name', string $category = ''): array
{
    $category = trim($category);
    $sql = "
        SELECT id, name, quantity, price, COALESCE(category, '') AS category
        FROM products
    ";
    $params = [];
    if ($category === '__uncategorized__') {
        $sql .= " WHERE category IS NULL OR TRIM(category) = ''";
    } elseif ($category !== '') {
        $sql .= " WHERE TRIM(category) = ? COLLATE NOCASE";
        $params[] = $category;
    }
    $sql .= " ORDER BY name COLLATE NOCASE ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $items = [];
    foreach ($rows as $row) {
        $items[] = [
            'product_id' => (int) $row['id'],
            'product_name' => (string) $row['name'],
            'system_quantity' => (float) $row['quantity'],
            'unit_price' => (float) $row['price'],
            'category' => (string) $row['category'],
        ];
    }

    return strSortStockTakeSheetItems($items, $sort);
}

/**
 * @param list<array{product_id:int,product_name:string,system_quantity:int|float,unit_price:float,category?:string}> $items
 * @param array{generated_at?:string,file_name?:string,download?:bool,sort?:string,category?:string} $options
 */
function strRenderStockTakeSheetPdf(array $items, array $options = []): void
{
    $fpdfPath = __DIR__ . '/../fpdf/fpdf.php';
    if (!file_exists($fpdfPath)) {
        throw new RuntimeException('FPDF library not found');
    }
    require_once $fpdfPath;

    $generatedAt = (string) ($options['generated_at'] ?? date('Y-m-d H:i:s'));
    $download = (bool) ($options['download'] ?? true);
    $sort = strNormalizeStockTakeSheetSort((string) ($options['sort'] ?? 'name'));
    $sortLabel = $sort === 'category' ? 'Category' : 'Alphabetical';
    $categoryLabel = strStockTakeSheetCategoryLabel((string) ($options['category'] ?? ''));
    $fileSlug = preg_replace('/[^A-Za-z0-9_-]+/', '_', $categoryLabel) ?: 'All';
    $fileName = (string) ($options['file_name'] ?? ('Stock_Take_Sheet_' . $fileSlug . '_' . date('Y-m-d_H-i-s', strtotime($generatedAt)) . '.pdf'));

    if (!class_exists('StockTakeSheetPDF', false)) {
        class StockTakeSheetPDF extends FPDF
        {
            public $generatedAt = '';
            public $sortLabel = 'Alphabetical';
            public $categoryLabel = 'All categories';
            private float $colId = 10;
            private float $colCategory = 30;
            private float $colName = 86;
            private float $colSys = 26;
            private float $colPhys = 28;
            private float $rowH = 5;
            private float $headerH = 5;

            public function Header(): void
            {
                if ($this->PageNo() === 1) {
                    $this->SetFont('Arial', 'B', 12);
                    $this->Cell(0, 6, 'STOCK TAKE SHEET', 0, 1, 'C');
                    $this->SetFont('Arial', '', 8);
                    $this->Cell(0, 4, 'Date: _______________    Counted by: _______________', 0, 1, 'C');
                    $this->Cell(0, 4, 'Category: ' . $this->categoryLabel, 0, 1, 'C');
                    $this->Cell(0, 4, 'Generated on ' . $this->generatedAt . ' · Sorted by ' . $this->sortLabel, 0, 1, 'C');
                    $this->Ln(1);
                }
                $this->drawColumnHeaders();
            }

            private function drawColumnHeaders(): void
            {
                $this->SetFont('Arial', 'B', 7);
                $this->Cell($this->colId, $this->headerH, 'ID', 1, 0, 'C');
                $this->Cell($this->colCategory, $this->headerH, 'Category', 1, 0, 'C');
                $this->Cell($this->colName, $this->headerH, 'Product Name', 1, 0, 'C');
                $this->Cell($this->colSys, $this->headerH, 'System Qty', 1, 0, 'C');
                $this->Cell($this->colPhys, $this->headerH, 'Physical Count', 1, 1, 'C');
            }

            public function Footer(): void
            {
                $this->SetY(-10);
                $this->SetFont('Arial', 'I', 7);
                $this->Cell(0, 8, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
            }

            public function rowHeight(): float
            {
                return $this->rowH;
            }

            public function printItemRow(array $item): void
            {
                $category = trim((string) ($item['category'] ?? ''));
                if ($category === '') {
                    $category = '-';
                }

                $this->SetFont('Arial', '', 7);
                $this->Cell($this->colId, $this->rowH, (string) $item['product_id'], 1, 0, 'C');
                $this->Cell($this->colCategory, $this->rowH, substr($category, 0, 16), 1, 0, 'L');
                $this->Cell($this->colName, $this->rowH, substr((string) $item['product_name'], 0, 42), 1, 0, 'L');
                $this->Cell($this->colSys, $this->rowH, (string) $item['system_quantity'], 1, 0, 'C');
                $this->Cell($this->colPhys, $this->rowH, '', 1, 1, 'C');
            }
        }
    }

    $pdf = new StockTakeSheetPDF('P', 'mm', 'A4');
    $pdf->SetMargins(8, 8, 8);
    $pdf->SetAutoPageBreak(true, 12);
    $pdf->generatedAt = date('Y-m-d H:i:s', strtotime($generatedAt));
    $pdf->sortLabel = $sortLabel;
    $pdf->categoryLabel = $categoryLabel;
    $pdf->AliasNbPages();
    $pdf->AddPage();

    $rowHeight = $pdf->rowHeight();
    $pageBottom = 285;
    foreach ($items as $item) {
        if ($pdf->GetY() + $rowHeight > $pageBottom) {
            $pdf->AddPage();
        }
        $pdf->printItemRow($item);
    }

    if ($pdf->GetY() + 10 > $pageBottom) {
        $pdf->AddPage();
    }
    $pdf->Ln(2);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(0, 5, 'Total products on sheet: ' . count($items), 0, 1, 'L');

    if ($download) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        $pdf->Output('D', $fileName);
        exit;
    }

    $pdf->Output('I', $fileName);
    exit;
}

function strOutputStockTakeSheetPdf(PDO $db, string $sort = 'name', string $category = ''): void
{
    $sort = strNormalizeStockTakeSheetSort($sort);
    $category = trim($category);
    strRenderStockTakeSheetPdf(strFetchStockTakeSheetItems($db, $sort, $category), [
        'generated_at' => date('Y-m-d H:i:s'),
        'download' => true,
        'sort' => $sort,
        'category' => $category,
    ]);
}

/**
 * @param list<array> $items
 */
function strCurrentStockItemsFromRecordItems(array $items): array
{
    $out = [];
    foreach ($items as $item) {
        $qty = (int) ($item['actual_quantity'] ?? 0);
        if ($qty <= 0) {
            continue;
        }
        $out[] = [
            'product_id' => (int) ($item['product_id'] ?? 0),
            'product_name' => (string) ($item['product_name'] ?? ''),
            'quantity' => $qty,
            'unit_price' => (float) ($item['unit_price'] ?? 0),
        ];
    }

    usort($out, static fn(array $a, array $b): int => strcasecmp($a['product_name'], $b['product_name']));

    return $out;
}

function strRenderPdf(array $record, array $items, bool $download = true): void
{
    $fpdfPath = __DIR__ . '/../fpdf/fpdf.php';
    if (!file_exists($fpdfPath)) {
        throw new RuntimeException('FPDF library not found');
    }
    require_once $fpdfPath;

    $stockType = (string) ($record['stock_type'] ?? 'closing');
    $takenAt = (string) ($record['taken_at'] ?? '');
    $username = (string) ($record['username'] ?? '');
    $category = trim((string) ($record['category'] ?? ''));
    $stockDate = (string) ($record['stock_date'] ?? '');

    if ($stockType === 'opening') {
        strRenderCurrentStockPdf(strCurrentStockItemsFromRecordItems($items), [
            'subtitle' => 'Generated after Opening Stock Recording',
            'generated_at' => $takenAt !== '' ? $takenAt : date('Y-m-d H:i:s'),
            'file_name' => 'Opening_Stock_Report_' . date('Y-m-d_H-i-s', strtotime($takenAt ?: 'now')) . '.pdf',
            'download' => $download,
        ]);
        return;
    }

    $includeSold = stClosingPdfDetectIncludeSold($items);
    $pdfLayout = stClosingPdfLayout($includeSold);

    if (!class_exists('ClosingStockTakePDF', false)) {
        class ClosingStockTakePDF extends FPDF
        {
            public $displayDate = '';
            public $takenBy = '';
            public $category = '';
            public $includeSold = false;

            public function Header(): void
            {
                $this->SetFont('Arial', 'B', 16);
                $this->Cell(0, 10, 'CLOSING STOCK REPORT', 0, 1, 'C');
                $this->SetFont('Arial', '', 10);
                $this->Cell(0, 8, 'Stock date: ' . $this->displayDate, 0, 1, 'C');
                $this->Cell(0, 8, 'Recorded by: ' . $this->takenBy, 0, 1, 'C');
                if ($this->category !== '') {
                    $this->Cell(0, 8, 'Category: ' . $this->category, 0, 1, 'C');
                }
                if ($this->includeSold) {
                    $this->SetFont('Arial', 'I', 9);
                    $this->Cell(0, 6, 'Adj. Actual = Physical count - Sold today; reflects true remaining stock', 0, 1, 'C');
                }
                $this->Ln(3);
            }

            public function Footer(): void
            {
                $this->SetY(-15);
                $this->SetFont('Arial', 'I', 8);
                $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
            }
        }
    }

    $pdf = new ClosingStockTakePDF('L');
    $pdf->displayDate = $stockDate . ' (' . date('Y-m-d H:i', strtotime($takenAt ?: 'now')) . ')';
    $pdf->takenBy = $username;
    $pdf->category = $category;
    $pdf->includeSold = $includeSold;
    $pdf->AliasNbPages();
    $pdf->AddPage();
    stClosingPdfWriteHeader($pdf, $pdfLayout);

    $pdf->SetFont('Arial', '', 9);
    $totalValueDiff = 0.0;
    foreach ($items as $item) {
        $amounts = stClosingPdfWriteRow($pdf, $pdfLayout, $item);
        $totalValueDiff += $amounts['value_difference'];
    }

    $pdf->Ln(5);
    $pdf->SetFont('Arial', 'B', 12);
    $totalLabel = $totalValueDiff > 0 ? '+' . number_format($totalValueDiff, 2) : number_format($totalValueDiff, 2);
    $pdf->Cell(0, 10, 'TOTAL VALUE DIFFERENCE: ' . $totalLabel, 0, 1, 'L');
    if ($includeSold) {
        $pdf->SetFont('Arial', 'I', 9);
        $pdf->Cell(0, 6, 'Adj. Actual = Physical count - Sold today', 0, 1, 'L');
        $pdf->Cell(0, 6, 'Difference = Adj. Actual - System qty', 0, 1, 'L');
    }
    $fileName = 'Closing_Stock_Report_' . date('Y-m-d_H-i-s', strtotime($takenAt ?: 'now')) . '.pdf';

    if ($download) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        $pdf->Output('D', $fileName);
        exit;
    }

    $pdf->Output('I', $fileName);
    exit;
}

function strOutputPdf(PDO $db, int $id): void
{
    $bundle = strGetRecord($db, $id);
    if (!$bundle) {
        throw new RuntimeException('Stock take record not found');
    }
    strRenderPdf($bundle['record'], $bundle['items'], true);
}
