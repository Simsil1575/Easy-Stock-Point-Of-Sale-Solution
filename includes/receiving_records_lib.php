<?php

declare(strict_types=1);

require_once __DIR__ . '/../recipe_stock_helper.php';
require_once __DIR__ . '/../purchase_order_lib.php';
require_once __DIR__ . '/../ensure_purchase_order_schema.php';
require_once __DIR__ . '/../ensure_stock_changes_username.php';

function rrEnsureTables(PDO $db): void
{
    configureSqlitePdo($db);
    ensurePurchaseOrderSchema($db);
    ensureStockChangesUsernameColumn($db);

    $db->exec("
        CREATE TABLE IF NOT EXISTS receiving_records (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            username TEXT NOT NULL,
            receiving_date DATETIME NOT NULL,
            total_items INTEGER NOT NULL DEFAULT 0,
            total_quantity INTEGER NOT NULL DEFAULT 0,
            total_value DECIMAL(10,2) NOT NULL DEFAULT 0,
            total_cost DECIMAL(10,2) NOT NULL DEFAULT 0,
            email_status TEXT NOT NULL DEFAULT 'pending',
            email_attempts INTEGER NOT NULL DEFAULT 0,
            email_error TEXT,
            email_sent_at DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $db->exec("
        CREATE TABLE IF NOT EXISTS receiving_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            record_id INTEGER NOT NULL,
            product_id INTEGER NOT NULL,
            product_name TEXT NOT NULL,
            quantity_added INTEGER NOT NULL,
            old_quantity INTEGER NOT NULL,
            new_quantity INTEGER NOT NULL,
            unit_price DECIMAL(10,2) NOT NULL,
            buying_price DECIMAL(10,2) NOT NULL,
            total_value DECIMAL(10,2) NOT NULL,
            total_cost DECIMAL(10,2) NOT NULL,
            FOREIGN KEY(record_id) REFERENCES receiving_records(id) ON DELETE CASCADE,
            FOREIGN KEY(product_id) REFERENCES products(id)
        )
    ");
}

/**
 * @return array{rows: list<array>, total: int}
 */
function rrListRecords(PDO $db, array $filters = []): array
{
    rrEnsureTables($db);

    $where = ['1=1'];
    $params = [];

    if (!empty($filters['date_from'])) {
        $where[] = 'date(rr.receiving_date) >= ?';
        $params[] = $filters['date_from'];
    }
    if (!empty($filters['date_to'])) {
        $where[] = 'date(rr.receiving_date) <= ?';
        $params[] = $filters['date_to'];
    }
    if (!empty($filters['supplier_id'])) {
        $where[] = 'rr.supplier_id = ?';
        $params[] = (int) $filters['supplier_id'];
    }
    if (!empty($filters['search'])) {
        $where[] = '(rr.username LIKE ? OR EXISTS (
            SELECT 1 FROM receiving_items ri WHERE ri.record_id = rr.id AND ri.product_name LIKE ?
        ))';
        $term = '%' . $filters['search'] . '%';
        $params[] = $term;
        $params[] = $term;
    }

    $limit = max(1, min(500, (int) ($filters['limit'] ?? 100)));
    $offset = max(0, (int) ($filters['offset'] ?? 0));
    $whereSql = implode(' AND ', $where);

    $countStmt = $db->prepare("SELECT COUNT(*) FROM receiving_records rr WHERE $whereSql");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $sql = "
        SELECT rr.*, s.name AS supplier_name, po.id AS po_id
        FROM receiving_records rr
        LEFT JOIN suppliers s ON s.id = rr.supplier_id
        LEFT JOIN purchase_orders po ON po.id = rr.purchase_order_id
        WHERE $whereSql
        ORDER BY rr.receiving_date DESC, rr.id DESC
        LIMIT $limit OFFSET $offset
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as &$row) {
        $row['po_number'] = !empty($row['po_id']) ? poFormatNumber((int) $row['po_id']) : '';
    }
    unset($row);

    return ['rows' => $rows, 'total' => $total];
}

/**
 * @return array{record: array, items: list<array>}|null
 */
function rrGetRecord(PDO $db, int $id): ?array
{
    rrEnsureTables($db);
    if ($id < 1) {
        return null;
    }

    $stmt = $db->prepare('
        SELECT rr.*, s.name AS supplier_name, po.id AS po_id
        FROM receiving_records rr
        LEFT JOIN suppliers s ON s.id = rr.supplier_id
        LEFT JOIN purchase_orders po ON po.id = rr.purchase_order_id
        WHERE rr.id = ?
    ');
    $stmt->execute([$id]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$record) {
        return null;
    }
    $record['po_number'] = !empty($record['po_id']) ? poFormatNumber((int) $record['po_id']) : '';

    $itemsStmt = $db->prepare('SELECT * FROM receiving_items WHERE record_id = ? ORDER BY product_name COLLATE NOCASE');
    $itemsStmt->execute([$id]);

    return ['record' => $record, 'items' => $itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: []];
}

/** @throws RuntimeException */
function rrApplyStockDelta(PDO $db, int $productId, float $qtyDelta, float $buyingPrice, string $receivingDateTime, string $username): void
{
    if (abs($qtyDelta) < 0.00001) {
        return;
    }

    $prodStmt = $db->prepare('SELECT quantity FROM products WHERE id = ?');
    $prodStmt->execute([$productId]);
    $product = $prodStmt->fetch(PDO::FETCH_ASSOC);
    if (!$product) {
        throw new RuntimeException('Product not found (id ' . $productId . ')');
    }

    $oldQty = floatval($product['quantity']);
    if ($qtyDelta > 0) {
        $newQty = $oldQty + $qtyDelta;
        $db->prepare('UPDATE products SET quantity = ?, buying_price = ? WHERE id = ?')->execute([$newQty, $buyingPrice, $productId]);
        adjustRecipeStockByProductId($db, $productId, $qtyDelta);
        $action = 'Restock';
    } else {
        deductProductStockById($db, $productId, abs($qtyDelta), false);
        adjustRecipeStockByProductId($db, $productId, $qtyDelta);
        $newQtyRow = $db->prepare('SELECT quantity FROM products WHERE id = ?');
        $newQtyRow->execute([$productId]);
        $newQty = floatval($newQtyRow->fetchColumn());
        $action = 'Adjust';
    }

    if ((int) $db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='stock_changes'")->fetchColumn() > 0) {
        $db->prepare('INSERT INTO stock_changes (product_id, action, quantity_change, old_quantity, new_quantity, changed_at, username) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$productId, $action, $qtyDelta, $oldQty, $newQty, $receivingDateTime, $username]);
    }

    $day = substr($receivingDateTime, 0, 10);
    $db->prepare('INSERT OR IGNORE INTO daily_stock_summary (date, product_id, opening_quantity, closing_quantity, received_quantity, sold_quantity, damaged_quantity) VALUES (?, ?, 0, 0, 0, 0, 0)')->execute([$day, $productId]);
    if ($qtyDelta > 0) {
        $db->prepare('UPDATE daily_stock_summary SET received_quantity = MAX(0, received_quantity + ?), closing_quantity = (SELECT quantity FROM products WHERE id = ?) WHERE date = ? AND product_id = ?')
            ->execute([$qtyDelta, $productId, $day, $productId]);
    } else {
        $db->prepare('UPDATE daily_stock_summary SET received_quantity = MAX(0, received_quantity + ?) WHERE date = ? AND product_id = ?')
            ->execute([$qtyDelta, $day, $productId]);
    }
}

function rrNormalizePdfItems(array $items): array
{
    $out = [];
    foreach ($items as $item) {
        $out[] = [
            'product_name' => (string) ($item['product_name'] ?? ''),
            'added_quantity' => floatval($item['added_quantity'] ?? $item['quantity_added'] ?? 0),
            'price' => floatval($item['price'] ?? $item['unit_price'] ?? 0),
            'buying_price' => floatval($item['buying_price'] ?? 0),
            'total_value' => floatval($item['total_value'] ?? 0),
            'total_cost' => floatval($item['total_cost'] ?? 0),
        ];
    }
    return $out;
}

function rrRenderReceivingPdf(string $displayDate, string $supplierName, string $poNumber, array $pdfItems, float $totalCost, bool $download = true): void
{
    $fpdfPath = __DIR__ . '/../fpdf/fpdf.php';
    if (!file_exists($fpdfPath)) {
        throw new RuntimeException('FPDF library not found');
    }
    require_once $fpdfPath;

    if (!class_exists('ReceivingReportPDF', false)) {
        class ReceivingReportPDF extends FPDF
        {
            public $displayDate = '';
            public $supplierName = '';
            public $poNumber = '';

            public function Header(): void
            {
                $this->SetFont('Arial', 'B', 15);
                $this->Cell(0, 10, 'Stock Receiving Report', 0, 1, 'C');
                $this->SetFont('Arial', '', 12);
                $this->Cell(0, 8, 'Receiving date: ' . $this->displayDate, 0, 1, 'C');
                if ($this->supplierName !== '') {
                    $this->Cell(0, 8, 'Supplier: ' . $this->supplierName, 0, 1, 'C');
                }
                if ($this->poNumber !== '') {
                    $this->Cell(0, 8, 'Purchase order: ' . $this->poNumber, 0, 1, 'C');
                }
                $this->Ln(6);
                $this->SetFont('Arial', 'B', 9);
                $this->Cell(62, 10, 'Product', 1);
                $this->Cell(16, 10, 'Added', 1);
                $this->Cell(26, 10, 'Sell Price', 1);
                $this->Cell(26, 10, 'Cost Price', 1);
                $this->Cell(28, 10, 'Value Added', 1);
                $this->Cell(28, 10, 'Total Cost', 1);
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

    $pdf = new ReceivingReportPDF();
    $pdf->displayDate = $displayDate;
    $pdf->supplierName = $supplierName;
    $pdf->poNumber = $poNumber;
    $pdf->AliasNbPages();
    $pdf->AddPage();
    $pdf->SetFont('Arial', '', 9);

    $totalItems = 0;
    $totalValue = 0;
    foreach ($pdfItems as $item) {
        $name = $item['product_name'];
        if (strlen($name) > 38) {
            $name = substr($name, 0, 35) . '...';
        }
        $pdf->Cell(62, 8, $name, 1);
        $pdf->Cell(16, 8, '+' . $item['added_quantity'], 1, 0, 'C');
        $pdf->Cell(26, 8, 'N$' . number_format($item['price'], 2), 1, 0, 'R');
        $pdf->Cell(26, 8, 'N$' . number_format($item['buying_price'], 2), 1, 0, 'R');
        $pdf->Cell(28, 8, 'N$' . number_format($item['total_value'], 2), 1, 0, 'R');
        $pdf->Cell(28, 8, 'N$' . number_format($item['total_cost'], 2), 1, 0, 'R');
        $pdf->Ln();
        $totalItems += $item['added_quantity'];
        $totalValue += $item['total_value'];
    }

    $pdf->Ln(5);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 10, 'Receiving Summary', 0, 1, 'L');
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(100, 8, 'Total Items Received:', 0, 0, 'L');
    $pdf->Cell(50, 8, (string) $totalItems, 0, 1, 'L');
    $pdf->Cell(100, 8, 'Total Restock Value:', 0, 0, 'L');
    $pdf->Cell(50, 8, 'N$' . number_format($totalValue, 2), 0, 1, 'L');
    $pdf->Cell(100, 8, 'Total Cost (at cost price):', 0, 0, 'L');
    $pdf->Cell(50, 8, 'N$' . number_format($totalCost, 2), 0, 1, 'L');

    $fileName = 'Stock_Receiving_Report_' . date('Y-m-d_H-i-s', strtotime($displayDate)) . '.pdf';
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

function rrOutputPdf(PDO $db, int $id): void
{
    $bundle = rrGetRecord($db, $id);
    if (!$bundle) {
        throw new RuntimeException('Receiving record not found');
    }
    $record = $bundle['record'];
    rrRenderReceivingPdf(
        (string) $record['receiving_date'],
        (string) ($record['supplier_name'] ?? ''),
        (string) ($record['po_number'] ?? ''),
        rrNormalizePdfItems($bundle['items']),
        floatval($record['total_cost'] ?? 0),
        true
    );
}

function rrOutputPdfFromItems(string $displayDate, string $supplierName, string $poNumber, array $liveItems, float $totalCost): void
{
    rrRenderReceivingPdf($displayDate, $supplierName, $poNumber, rrNormalizePdfItems($liveItems), $totalCost, true);
}

function rrDeleteRecord(PDO $db, int $id): void
{
    $bundle = rrGetRecord($db, $id);
    if (!$bundle) {
        throw new RuntimeException('Receiving record not found');
    }
    $record = $bundle['record'];
    $username = currentStockChangeUsername();
    $receivingDate = (string) $record['receiving_date'];

    $db->beginTransaction();
    try {
        $poItems = [];
        foreach ($bundle['items'] as $line) {
            $pid = (int) $line['product_id'];
            $qty = floatval($line['quantity_added']);
            if ($qty <= 0) {
                continue;
            }
            rrApplyStockDelta($db, $pid, -$qty, floatval($line['buying_price']), $receivingDate, $username);
            $poItems[] = ['product_id' => $pid, 'quantity' => (int) $qty];
        }
        $poId = !empty($record['purchase_order_id']) ? (int) $record['purchase_order_id'] : 0;
        if ($poId > 0 && !empty($poItems)) {
            poReverseReceiving($db, $poId, $poItems);
        }
        $db->prepare('DELETE FROM receiving_records WHERE id = ?')->execute([$id]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function rrNormalizeOptionalFkId($value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    $id = (int) $value;
    return $id > 0 ? $id : null;
}

function rrUpdateRecord(PDO $db, int $id, array $header, array $lines): void
{
    $bundle = rrGetRecord($db, $id);
    if (!$bundle) {
        throw new RuntimeException('Receiving record not found');
    }
    $record = $bundle['record'];
    $existingById = [];
    foreach ($bundle['items'] as $item) {
        $existingById[(int) $item['id']] = $item;
    }

    $receivingDate = trim((string) ($header['receiving_date'] ?? $record['receiving_date']));
    try {
        $receivingDate = (new DateTime($receivingDate ?: (string) $record['receiving_date']))->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        throw new RuntimeException('Invalid receiving date');
    }

    $supplierId = array_key_exists('supplier_id', $header)
        ? rrNormalizeOptionalFkId($header['supplier_id'])
        : rrNormalizeOptionalFkId($record['supplier_id'] ?? null);
    $poId = array_key_exists('purchase_order_id', $header)
        ? rrNormalizeOptionalFkId($header['purchase_order_id'])
        : rrNormalizeOptionalFkId($record['purchase_order_id'] ?? null);

    if ($supplierId !== null) {
        $supCheck = $db->prepare('SELECT id FROM suppliers WHERE id = ?');
        $supCheck->execute([$supplierId]);
        if (!$supCheck->fetchColumn()) {
            throw new RuntimeException('Selected supplier does not exist.');
        }
    }
    if ($poId !== null) {
        $poCheck = $db->prepare('SELECT id FROM purchase_orders WHERE id = ?');
        $poCheck->execute([$poId]);
        if (!$poCheck->fetchColumn()) {
            throw new RuntimeException('Linked purchase order does not exist.');
        }
    }

    $username = currentStockChangeUsername();
    $oldPoId = !empty($record['purchase_order_id']) ? (int) $record['purchase_order_id'] : 0;

    $db->beginTransaction();
    try {
        $totalItems = 0;
        $totalQty = 0;
        $totalValue = 0.0;
        $totalCost = 0.0;
        $poDeltaOld = [];
        $poDeltaNew = [];
        $updateLine = $db->prepare('UPDATE receiving_items SET quantity_added = ?, old_quantity = ?, new_quantity = ?, buying_price = ?, total_value = ?, total_cost = ? WHERE id = ? AND record_id = ?');

        foreach ($lines as $lineInput) {
            $lineId = (int) ($lineInput['id'] ?? 0);
            if ($lineId < 1 || !isset($existingById[$lineId])) {
                continue;
            }
            $oldLine = $existingById[$lineId];
            $productId = (int) $oldLine['product_id'];
            $oldQty = floatval($oldLine['quantity_added']);
            $newQty = max(0, floatval($lineInput['quantity_added'] ?? $oldQty));
            $buyingPrice = floatval($lineInput['buying_price'] ?? $oldLine['buying_price']);
            $unitPrice = floatval($oldLine['unit_price']);
            $delta = $newQty - $oldQty;

            if (abs($delta) >= 0.00001) {
                rrApplyStockDelta($db, $productId, $delta, $buyingPrice, $receivingDate, $username);
            } elseif ($buyingPrice != floatval($oldLine['buying_price'])) {
                $db->prepare('UPDATE products SET buying_price = ? WHERE id = ?')->execute([$buyingPrice, $productId]);
            }

            $prodQtyStmt = $db->prepare('SELECT quantity FROM products WHERE id = ?');
            $prodQtyStmt->execute([$productId]);
            $currentQty = floatval($prodQtyStmt->fetchColumn());
            $lineValue = $newQty * $unitPrice;
            $lineCost = $newQty * $buyingPrice;
            $updateLine->execute([$newQty, $currentQty - $newQty, $currentQty, $buyingPrice, $lineValue, $lineCost, $lineId, $id]);

            if ($oldQty > 0) {
                $poDeltaOld[] = ['product_id' => $productId, 'quantity' => (int) $oldQty];
            }
            if ($newQty > 0) {
                $poDeltaNew[] = ['product_id' => $productId, 'quantity' => (int) $newQty];
            }
            $totalItems++;
            $totalQty += (int) $newQty;
            $totalValue += $lineValue;
            $totalCost += $lineCost;
        }

        if ($oldPoId > 0 && !empty($poDeltaOld)) {
            poReverseReceiving($db, $oldPoId, $poDeltaOld);
        }
        $effectivePoId = $poId ?? $oldPoId;
        if ($effectivePoId > 0 && !empty($poDeltaNew)) {
            poApplyReceiving($db, $effectivePoId, $poDeltaNew);
        }

        $db->prepare('UPDATE receiving_records SET receiving_date = ?, supplier_id = ?, purchase_order_id = ?, total_items = ?, total_quantity = ?, total_value = ?, total_cost = ? WHERE id = ?')
            ->execute([$receivingDate, $supplierId, $poId, $totalItems, $totalQty, round($totalValue, 2), round($totalCost, 2), $id]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}
