<?php

declare(strict_types=1);

/**
 * @return string e.g. PO-000042
 */
function poFormatNumber(int $id): string
{
    return 'PO-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
}

function poRecalculateTotal(PDO $db, int $purchaseOrderId): float
{
    $stmt = $db->prepare('SELECT COALESCE(SUM(line_total), 0) FROM purchase_order_items WHERE purchase_order_id = ?');
    $stmt->execute([$purchaseOrderId]);
    $sum = (float) $stmt->fetchColumn();
    $u = $db->prepare('UPDATE purchase_orders SET total_amount = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
    $u->execute([round($sum, 2), $purchaseOrderId]);
    return round($sum, 2);
}

/**
 * @return array{po: array, supplier: array|null, items: array}|null
 */
function poLoadWithDetails(PDO $db, int $id): ?array
{
    $stmt = $db->prepare('
        SELECT po.*, s.name AS supplier_name, s.phone AS supplier_phone, s.email AS supplier_email
        FROM purchase_orders po
        JOIN suppliers s ON s.id = po.supplier_id
        WHERE po.id = ?
    ');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $supplier = [
        'id' => (int) $row['supplier_id'],
        'name' => $row['supplier_name'],
        'phone' => $row['supplier_phone'],
        'email' => $row['supplier_email'],
    ];
    unset($row['supplier_name'], $row['supplier_phone'], $row['supplier_email']);
    $itemsStmt = $db->prepare('
        SELECT id, purchase_order_id, product_id, product_name, quantity,
               COALESCE(quantity_received, 0) AS quantity_received,
               unit_cost, line_total
        FROM purchase_order_items
        WHERE purchase_order_id = ?
        ORDER BY id ASC
    ');
    $itemsStmt->execute([$id]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    return ['po' => $row, 'supplier' => $supplier, 'items' => $items];
}

/**
 * @return array{name: string, location: string, phone: string, email: string}
 */
function poGetBusinessInfo(string $infoDbPath): array
{
    $defaults = [
        'name' => 'Your Business Name',
        'location' => '',
        'phone' => '',
        'email' => '',
    ];
    if (!is_readable($infoDbPath)) {
        return $defaults;
    }
    try {
        $infoDb = new PDO('sqlite:' . $infoDbPath);
        $infoDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $row = $infoDb->query('SELECT * FROM business_info LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $defaults['name'] = (string) ($row['name'] ?? $defaults['name']);
            $defaults['location'] = (string) ($row['location'] ?? '');
            $defaults['phone'] = (string) ($row['phone'] ?? '');
            $defaults['email'] = (string) ($row['email'] ?? '');
        }
    } catch (Throwable $e) {
        // keep defaults
    }
    return $defaults;
}

function poRequireAdminOrManager(): void
{
    $role = strtolower((string) ($_SESSION['role'] ?? ''));
    if (!in_array($role, ['admin', 'manager'], true)) {
        header('Location: ../');
        exit;
    }
}

/**
 * @return list<array{id: int, supplier_id: int, supplier_name: string, order_date: string, status: string, total_amount: float}>
 */
function poListOpenForReceiving(PDO $db): array
{
    $stmt = $db->query("
        SELECT po.id, po.supplier_id, s.name AS supplier_name, po.order_date, po.status, po.total_amount
        FROM purchase_orders po
        JOIN suppliers s ON s.id = po.supplier_id
        WHERE po.status IN ('ordered', 'partially_received')
        ORDER BY po.order_date DESC, po.id DESC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return array{supplier_id: int, supplier_name: string, lines: list<array>}|null
 */
function poLinesForReceiving(PDO $db, int $poId): ?array
{
    $bundle = poLoadWithDetails($db, $poId);
    if (!$bundle) {
        return null;
    }
    if (!in_array($bundle['po']['status'], ['ordered', 'partially_received'], true)) {
        return null;
    }
    $lines = [];
    foreach ($bundle['items'] as $item) {
        $ordered = (int) $item['quantity'];
        $received = (int) ($item['quantity_received'] ?? 0);
        $remaining = max(0, $ordered - $received);
        if ($remaining <= 0 || empty($item['product_id'])) {
            continue;
        }
        $lines[] = [
            'product_id' => (int) $item['product_id'],
            'product_name' => $item['product_name'],
            'quantity_ordered' => $ordered,
            'quantity_received' => $received,
            'quantity_remaining' => $remaining,
            'unit_cost' => (float) $item['unit_cost'],
        ];
    }
    return [
        'supplier_id' => (int) $bundle['supplier']['id'],
        'supplier_name' => (string) $bundle['supplier']['name'],
        'po_number' => poFormatNumber((int) $bundle['po']['id']),
        'lines' => $lines,
    ];
}

/**
 * Resolve supplier for a receiving batch. PO and supplier are optional for ad-hoc receives.
 *
 * @return array{supplier_id: int|null, purchase_order_id: int|null}
 */
function poResolveSupplierForReceiving(PDO $db, ?int $poId, ?int $supplierId): array
{
    if ($poId !== null && $poId > 0) {
        $stmt = $db->prepare('SELECT id, supplier_id, status FROM purchase_orders WHERE id = ?');
        $stmt->execute([$poId]);
        $po = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$po) {
            throw new RuntimeException('Purchase order not found.');
        }
        if (!in_array($po['status'], ['ordered', 'partially_received'], true)) {
            throw new RuntimeException('This purchase order is not open for receiving.');
        }
        return [
            'supplier_id' => (int) $po['supplier_id'],
            'purchase_order_id' => (int) $po['id'],
        ];
    }
    if ($supplierId === null || $supplierId < 1) {
        return [
            'supplier_id' => null,
            'purchase_order_id' => null,
        ];
    }
    $chk = $db->prepare('SELECT id FROM suppliers WHERE id = ? AND active = 1');
    $chk->execute([$supplierId]);
    if (!$chk->fetchColumn()) {
        throw new RuntimeException('Supplier not found or inactive.');
    }
    return [
        'supplier_id' => $supplierId,
        'purchase_order_id' => null,
    ];
}

/**
 * Apply received quantities to PO lines and update PO status.
 *
 * @param list<array{product_id: int, quantity: int}> $receivedItems
 */
function poApplyReceiving(PDO $db, int $poId, array $receivedItems): void
{
    if ($poId < 1 || empty($receivedItems)) {
        return;
    }

    $itemsStmt = $db->prepare('
        SELECT id, product_id, quantity, COALESCE(quantity_received, 0) AS quantity_received
        FROM purchase_order_items
        WHERE purchase_order_id = ?
    ');
    $itemsStmt->execute([$poId]);
    $poItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($poItems)) {
        return;
    }

    $byProduct = [];
    foreach ($receivedItems as $ri) {
        $pid = (int) ($ri['product_id'] ?? 0);
        $qty = (int) ($ri['quantity'] ?? 0);
        if ($pid < 1 || $qty < 1) {
            continue;
        }
        $byProduct[$pid] = ($byProduct[$pid] ?? 0) + $qty;
    }

    $update = $db->prepare('UPDATE purchase_order_items SET quantity_received = ? WHERE id = ?');
    foreach ($poItems as $line) {
        $pid = (int) ($line['product_id'] ?? 0);
        if ($pid < 1 || !isset($byProduct[$pid])) {
            continue;
        }
        $add = $byProduct[$pid];
        $current = (int) $line['quantity_received'];
        $ordered = (int) $line['quantity'];
        $newReceived = min($ordered, $current + $add);
        $update->execute([$newReceived, (int) $line['id']]);
        $byProduct[$pid] -= ($newReceived - $current);
    }

    poRefreshReceivingStatus($db, $poId);
}

function poRefreshReceivingStatus(PDO $db, int $poId): void
{
    $stmt = $db->prepare('
        SELECT
            SUM(quantity) AS total_ordered,
            SUM(COALESCE(quantity_received, 0)) AS total_received
        FROM purchase_order_items
        WHERE purchase_order_id = ?
    ');
    $stmt->execute([$poId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $ordered = (int) ($row['total_ordered'] ?? 0);
    $received = (int) ($row['total_received'] ?? 0);

    if ($ordered <= 0 || $received <= 0) {
        return;
    }

    $status = $received >= $ordered ? 'received' : 'partially_received';
    $db->prepare('UPDATE purchase_orders SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND status IN (\'ordered\', \'partially_received\', \'received\')')
        ->execute([$status, $poId]);
}

/**
 * @return list<array>
 */
function poReceivingRecordsForPo(PDO $db, int $poId): array
{
    $stmt = $db->prepare('
        SELECT id, receiving_date, username, total_items, total_quantity, total_cost
        FROM receiving_records
        WHERE purchase_order_id = ?
        ORDER BY receiving_date DESC
    ');
    $stmt->execute([$poId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return list<array>
 */
function poSupplierReceivingReport(PDO $db, string $startDate, string $endDate, ?int $supplierId = null): array
{
    $sql = "
        SELECT rr.id AS record_id, rr.receiving_date, rr.username, rr.total_cost AS record_total_cost,
               s.id AS supplier_id, s.name AS supplier_name,
               po.id AS po_id, ri.product_name, ri.quantity_added, ri.total_cost
        FROM receiving_records rr
        LEFT JOIN suppliers s ON s.id = rr.supplier_id
        LEFT JOIN purchase_orders po ON po.id = rr.purchase_order_id
        JOIN receiving_items ri ON ri.record_id = rr.id
        WHERE date(rr.receiving_date) BETWEEN ? AND ?
    ";
    $params = [$startDate, $endDate];
    if ($supplierId !== null && $supplierId > 0) {
        $sql .= ' AND rr.supplier_id = ?';
        $params[] = $supplierId;
    }
    $sql .= ' ORDER BY COALESCE(s.name, \'Unknown\'), rr.receiving_date, ri.product_name';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return list<array{id: int, name: string}>
 */
function poListActiveSuppliers(PDO $db): array
{
    return $db->query('SELECT id, name FROM suppliers WHERE active = 1 ORDER BY name COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return list<array{
 *   id: int,
 *   receiving_date: string,
 *   username: string,
 *   total_items: int,
 *   total_quantity: int,
 *   total_cost: float,
 *   purchase_order_id: int|null,
 *   can_unlink: bool,
 *   items: list<array{product_name: string, quantity_added: int, buying_price: float, line_cost: float}>
 * }>
 */
function poReceivingHistoryForSupplier(PDO $db, int $supplierId): array
{
    if ($supplierId < 1) {
        return [];
    }
    $tableExists = (int) $db->query(
        "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='receiving_records'"
    )->fetchColumn() > 0;
    if (!$tableExists) {
        return [];
    }

    $stmt = $db->prepare("
        SELECT rr.id, rr.receiving_date, rr.username, rr.total_items, rr.total_quantity, rr.total_cost,
               rr.purchase_order_id, rr.supplier_id AS record_supplier_id,
               ri.id AS item_id, ri.product_name, ri.quantity_added, ri.buying_price, ri.total_cost AS line_cost
        FROM receiving_records rr
        LEFT JOIN purchase_orders po ON po.id = rr.purchase_order_id
        LEFT JOIN receiving_items ri ON ri.record_id = rr.id
        WHERE rr.supplier_id = ? OR (rr.supplier_id IS NULL AND po.supplier_id = ?)
        ORDER BY rr.receiving_date DESC, rr.id DESC, ri.product_name COLLATE NOCASE
    ");
    $stmt->execute([$supplierId, $supplierId]);

    $grouped = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $rid = (int) $row['id'];
        if (!isset($grouped[$rid])) {
            $grouped[$rid] = [
                'id' => $rid,
                'receiving_date' => (string) $row['receiving_date'],
                'username' => (string) $row['username'],
                'total_items' => (int) $row['total_items'],
                'total_quantity' => (int) $row['total_quantity'],
                'total_cost' => (float) $row['total_cost'],
                'purchase_order_id' => $row['purchase_order_id'] !== null ? (int) $row['purchase_order_id'] : null,
                'can_unlink' => (int) ($row['record_supplier_id'] ?? 0) === $supplierId,
                'items' => [],
            ];
        }
        if ($row['item_id'] !== null) {
            $grouped[$rid]['items'][] = [
                'product_name' => (string) $row['product_name'],
                'quantity_added' => (int) $row['quantity_added'],
                'buying_price' => (float) $row['buying_price'],
                'line_cost' => (float) $row['line_cost'],
            ];
        }
    }

    return array_values($grouped);
}

/**
 * Receiving batches not yet assigned to any supplier.
 *
 * @return list<array{
 *   id: int,
 *   receiving_date: string,
 *   username: string,
 *   total_items: int,
 *   total_quantity: int,
 *   total_cost: float,
 *   purchase_order_id: int|null,
 *   item_summary: string
 * }>
 */
function poListUnlinkedReceivingRecords(PDO $db, int $limit = 100): array
{
    $tableExists = (int) $db->query(
        "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='receiving_records'"
    )->fetchColumn() > 0;
    if (!$tableExists) {
        return [];
    }

    $limit = max(1, min(500, $limit));
    $stmt = $db->query("
        SELECT rr.id, rr.receiving_date, rr.username, rr.total_items, rr.total_quantity, rr.total_cost,
               rr.purchase_order_id,
               GROUP_CONCAT(ri.product_name, ', ') AS item_summary
        FROM receiving_records rr
        LEFT JOIN receiving_items ri ON ri.record_id = rr.id
        WHERE rr.supplier_id IS NULL
        GROUP BY rr.id
        ORDER BY rr.receiving_date DESC
        LIMIT $limit
    ");

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $summary = (string) ($row['item_summary'] ?? '');
        if (strlen($summary) > 120) {
            $summary = substr($summary, 0, 117) . '…';
        }
        $rows[] = [
            'id' => (int) $row['id'],
            'receiving_date' => (string) $row['receiving_date'],
            'username' => (string) $row['username'],
            'total_items' => (int) $row['total_items'],
            'total_quantity' => (int) $row['total_quantity'],
            'total_cost' => (float) $row['total_cost'],
            'purchase_order_id' => $row['purchase_order_id'] !== null ? (int) $row['purchase_order_id'] : null,
            'item_summary' => $summary,
        ];
    }

    return $rows;
}

/**
 * @param list<int> $recordIds
 */
function poLinkReceivingToSupplier(PDO $db, int $supplierId, array $recordIds): int
{
    if ($supplierId < 1) {
        throw new RuntimeException('Invalid supplier.');
    }
    $chk = $db->prepare('SELECT id FROM suppliers WHERE id = ?');
    $chk->execute([$supplierId]);
    if (!$chk->fetchColumn()) {
        throw new RuntimeException('Supplier not found.');
    }

    $recordIds = array_values(array_unique(array_filter(array_map('intval', $recordIds))));
    if ($recordIds === []) {
        throw new RuntimeException('Select at least one receiving record.');
    }

    $upd = $db->prepare('UPDATE receiving_records SET supplier_id = ? WHERE id = ? AND supplier_id IS NULL');
    $linked = 0;
    foreach ($recordIds as $rid) {
        if ($rid < 1) {
            continue;
        }
        $upd->execute([$supplierId, $rid]);
        $linked += $upd->rowCount();
    }

    if ($linked === 0) {
        throw new RuntimeException('No receiving records were linked. They may already be assigned to a supplier.');
    }

    return $linked;
}

/**
 * Removes supplier assignment from receiving batches (does not delete stock or receiving data).
 *
 * @param list<int> $recordIds
 */
function poUnlinkReceivingFromSupplier(PDO $db, int $supplierId, array $recordIds): int
{
    if ($supplierId < 1) {
        throw new RuntimeException('Invalid supplier.');
    }
    $chk = $db->prepare('SELECT id FROM suppliers WHERE id = ?');
    $chk->execute([$supplierId]);
    if (!$chk->fetchColumn()) {
        throw new RuntimeException('Supplier not found.');
    }

    $recordIds = array_values(array_unique(array_filter(array_map('intval', $recordIds))));
    if ($recordIds === []) {
        throw new RuntimeException('Select at least one receiving record.');
    }

    $upd = $db->prepare('UPDATE receiving_records SET supplier_id = NULL WHERE id = ? AND supplier_id = ?');
    $unlinked = 0;
    foreach ($recordIds as $rid) {
        if ($rid < 1) {
            continue;
        }
        $upd->execute([$rid, $supplierId]);
        $unlinked += $upd->rowCount();
    }

    if ($unlinked === 0) {
        throw new RuntimeException('No receiving records were unlinked. They may only be linked via purchase order.');
    }

    return $unlinked;
}

/**
 * @return array{supplier: array, orders: list<array>}|null
 */
function poLoadSupplierBundle(PDO $db, int $supplierId): ?array
{
    if ($supplierId < 1) {
        return null;
    }
    $st = $db->prepare('SELECT * FROM suppliers WHERE id = ?');
    $st->execute([$supplierId]);
    $supplier = $st->fetch(PDO::FETCH_ASSOC);
    if (!$supplier) {
        return null;
    }
    $ordersSt = $db->prepare('
        SELECT id, status, order_date, total_amount, created_at
        FROM purchase_orders
        WHERE supplier_id = ?
        ORDER BY created_at DESC
    ');
    $ordersSt->execute([$supplierId]);
    return [
        'supplier' => $supplier,
        'orders' => $ordersSt->fetchAll(PDO::FETCH_ASSOC) ?: [],
    ];
}

/**
 * Deletes a purchase order and unlinks any receiving records.
 */
function poDeletePurchaseOrder(PDO $db, int $poId): void
{
    if ($poId < 1) {
        throw new RuntimeException('Invalid purchase order.');
    }
    $chk = $db->prepare('SELECT id FROM purchase_orders WHERE id = ?');
    $chk->execute([$poId]);
    if (!$chk->fetchColumn()) {
        throw new RuntimeException('Purchase order not found.');
    }

    $db->beginTransaction();
    try {
        $recvTableExists = (int) $db->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='receiving_records'"
        )->fetchColumn() > 0;
        if ($recvTableExists) {
            $db->prepare('UPDATE receiving_records SET purchase_order_id = NULL WHERE purchase_order_id = ?')
                ->execute([$poId]);
        }
        $db->prepare('DELETE FROM purchase_orders WHERE id = ?')->execute([$poId]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/**
 * Deletes a supplier and all of their purchase orders.
 *
 * @return int Number of purchase orders removed
 */
function poDeleteSupplier(PDO $db, int $supplierId): int
{
    if ($supplierId < 1) {
        throw new RuntimeException('Invalid supplier.');
    }
    $chk = $db->prepare('SELECT id FROM suppliers WHERE id = ?');
    $chk->execute([$supplierId]);
    if (!$chk->fetchColumn()) {
        throw new RuntimeException('Supplier not found.');
    }

    $db->beginTransaction();
    try {
        $countSt = $db->prepare('SELECT COUNT(*) FROM purchase_orders WHERE supplier_id = ?');
        $countSt->execute([$supplierId]);
        $poCount = (int) $countSt->fetchColumn();

        $poIdsSt = $db->prepare('SELECT id FROM purchase_orders WHERE supplier_id = ?');
        $poIdsSt->execute([$supplierId]);
        $poIds = $poIdsSt->fetchAll(PDO::FETCH_COLUMN);

        $recvTableExists = (int) $db->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='receiving_records'"
        )->fetchColumn() > 0;
        if ($recvTableExists) {
            if ($poIds !== []) {
                $placeholders = implode(',', array_fill(0, count($poIds), '?'));
                $db->prepare("UPDATE receiving_records SET purchase_order_id = NULL WHERE purchase_order_id IN ($placeholders)")
                    ->execute($poIds);
            }
            $db->prepare('UPDATE receiving_records SET supplier_id = NULL WHERE supplier_id = ?')
                ->execute([$supplierId]);
        }

        $db->prepare('DELETE FROM purchase_orders WHERE supplier_id = ?')->execute([$supplierId]);
        $db->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$supplierId]);
        $db->commit();

        return $poCount;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}
