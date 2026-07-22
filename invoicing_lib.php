<?php

declare(strict_types=1);

/**
 * Quotations & Invoicing core library.
 *
 * Single source of truth for numbering, totals, validation, CRUD, conversion,
 * payments and stock integration. Reusable from any role folder (admin/manager)
 * and the shared AJAX router.
 */

require_once __DIR__ . '/ensure_invoicing_schema.php';

/* ============================================================
 * Connections & bootstrap
 * ========================================================== */

function invPosDbPath(): string
{
    return __DIR__ . '/pos.db';
}

function invInfoDbPath(): string
{
    return __DIR__ . '/info.db';
}

function invGetDb(): PDO
{
    static $db = null;
    if ($db instanceof PDO) {
        return $db;
    }
    $db = new PDO('sqlite:' . invPosDbPath());
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA busy_timeout=5000');
    $db->exec('PRAGMA foreign_keys=ON');
    return $db;
}

function invGetInfoDb(): PDO
{
    static $infoDb = null;
    if ($infoDb instanceof PDO) {
        return $infoDb;
    }
    $infoDb = new PDO('sqlite:' . invInfoDbPath());
    $infoDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $infoDb->exec('PRAGMA busy_timeout=5000');
    return $infoDb;
}

/**
 * Ensure schema on both databases. Cheap to call repeatedly.
 */
function invBootstrap(): void
{
    ensureInvoicingSchema(invGetDb());
    ensureDocumentSettingsSchema(invGetInfoDb());
}

/* ============================================================
 * Auth & permissions
 * ========================================================== */

function invCurrentRole(): string
{
    return strtolower((string) ($_SESSION['role'] ?? ''));
}

function invCurrentUsername(): string
{
    return (string) ($_SESSION['username'] ?? 'Unknown');
}

function invCurrentUserId()
{
    return $_SESSION['user_id'] ?? null;
}

function invRequireLogin(): void
{
    if (!isset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['role'])) {
        header('Location: ../');
        exit;
    }
}

/**
 * Page guard for admin/manager module pages.
 */
function invRequireAdminOrManager(): void
{
    invRequireLogin();
    if (!in_array(invCurrentRole(), ['admin', 'manager'], true)) {
        header('Location: ../');
        exit;
    }
}

/**
 * Permission matrix. Cashier support is scaffolded for later phases.
 *
 * @param string $action e.g. 'delete_paid_invoice', 'edit', 'delete', 'create', 'view'
 */
function invCan(string $action, string $role = ''): bool
{
    $role = $role !== '' ? strtolower($role) : invCurrentRole();
    switch ($role) {
        case 'admin':
            return true;
        case 'manager':
            // Everything except deleting paid invoices.
            return $action !== 'delete_paid_invoice';
        case 'cashier':
            return in_array($action, ['view', 'create', 'print', 'pdf'], true);
        default:
            return false;
    }
}

/**
 * Can the current role delete this invoice given its status?
 */
function invCanDeleteInvoice(array $invoice, string $role = ''): bool
{
    $status = (string) ($invoice['status'] ?? '');
    if ($status === 'Paid') {
        return invCan('delete_paid_invoice', $role);
    }
    return invCan('delete', $role);
}

/* ============================================================
 * Formatting helpers
 * ========================================================== */

function invFormatNumber(string $prefix, int $number): string
{
    return $prefix . str_pad((string) $number, 6, '0', STR_PAD_LEFT);
}

/**
 * @param array<string,mixed> $settings document_settings row
 */
function invMoney(array $settings, $amount): string
{
    $currency = (string) ($settings['currency'] ?? 'N$');
    return $currency . ' ' . number_format((float) $amount, 2);
}

/* ============================================================
 * Document settings
 * ========================================================== */

/**
 * Merged company/document settings.
 * Company identity (name, address, phone, logo, VAT rate, footer) always
 * comes from business_info in info.db. Document-specific fields (email,
 * website, tax numbers, prefixes, terms, notes, currency) come from
 * document_settings.
 *
 * @return array<string,mixed>
 */
function invGetDocumentSettings(): array
{
    $infoDb = invGetInfoDb();
    $row = $infoDb->query('SELECT * FROM document_settings ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];

    $defaults = [
        'company_name' => '',
        'company_logo' => '',
        'company_address' => '',
        'telephone' => '',
        'email' => '',
        'website' => '',
        'tax_number' => '',
        'vat_number' => '',
        'currency' => 'N$',
        'invoice_prefix' => 'INV-',
        'quotation_prefix' => 'QTN-',
        'default_payment_terms' => 'Due within 30 days',
        'default_terms_conditions' => '',
        'default_notes' => '',
        'default_vat_rate' => 15.0,
        'footer_text' => '',
    ];
    $settings = array_merge($defaults, $row);

    // Always overlay live business_info — source of truth for company identity.
    try {
        $bi = $infoDb->query('SELECT * FROM business_info LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        if ($bi) {
            if (!empty($bi['name'])) {
                $settings['company_name'] = (string) $bi['name'];
            }
            if (!empty($bi['location'])) {
                $settings['company_address'] = (string) $bi['location'];
            }
            if (!empty($bi['phone'])) {
                $settings['telephone'] = (string) $bi['phone'];
            }
            if (!empty($bi['logo_path'])) {
                $settings['company_logo'] = (string) $bi['logo_path'];
            }
            if (isset($bi['vat_rate'])) {
                $settings['default_vat_rate'] = (float) $bi['vat_rate'];
            }
            if (isset($bi['footer_text'])) {
                $settings['footer_text'] = (string) $bi['footer_text'];
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    if ($settings['company_name'] === '') {
        $settings['company_name'] = 'Your Business Name';
    }

    return $settings;
}

/**
 * @param array<string,mixed> $data keyed by document_settings columns
 */
function invSaveDocumentSettings(array $data): void
{
    $infoDb = invGetInfoDb();
    ensureDocumentSettingsSchema($infoDb);
    $fields = [
        'company_name', 'company_logo', 'company_address', 'telephone', 'email',
        'website', 'tax_number', 'vat_number', 'currency', 'invoice_prefix',
        'quotation_prefix', 'default_payment_terms', 'default_terms_conditions', 'default_notes',
    ];
    $id = (int) $infoDb->query('SELECT id FROM document_settings ORDER BY id LIMIT 1')->fetchColumn();

    $set = [];
    $params = [];
    foreach ($fields as $f) {
        if (array_key_exists($f, $data)) {
            $set[] = "$f = ?";
            $params[] = (string) $data[$f];
        }
    }
    if ($set === []) {
        return;
    }
    $set[] = 'updated_at = CURRENT_TIMESTAMP';

    if ($id > 0) {
        $params[] = $id;
        $infoDb->prepare('UPDATE document_settings SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($params);
    } else {
        // No row yet; insert then update.
        $infoDb->exec("INSERT INTO document_settings (company_name) VALUES ('')");
        $newId = (int) $infoDb->lastInsertId();
        $params[] = $newId;
        $infoDb->prepare('UPDATE document_settings SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($params);
    }
}

/* ============================================================
 * Numbering (transaction-safe)
 * ========================================================== */

/**
 * Reserve and return the next document number for a type.
 * MUST be called inside an active transaction on $db to be atomic.
 *
 * @param string $type 'invoice' | 'quotation'
 */
function invNextNumber(PDO $db, string $type): string
{
    $type = $type === 'invoice' ? 'invoice' : 'quotation';
    $db->exec("INSERT OR IGNORE INTO document_sequence (type, last_number) VALUES ('$type', 0)");
    $db->prepare('UPDATE document_sequence SET last_number = last_number + 1 WHERE type = ?')->execute([$type]);
    $stmt = $db->prepare('SELECT last_number FROM document_sequence WHERE type = ?');
    $stmt->execute([$type]);
    $n = (int) $stmt->fetchColumn();

    $settings = invGetDocumentSettings();
    $prefix = $type === 'invoice'
        ? (string) ($settings['invoice_prefix'] ?? 'INV-')
        : (string) ($settings['quotation_prefix'] ?? 'QTN-');

    return invFormatNumber($prefix, $n);
}

/* ============================================================
 * Totals calculation (single source of truth)
 * ========================================================== */

/**
 * Normalise line items and compute all document totals.
 *
 * Line total = quantity * unit_price - line discount (amount).
 * Document discount applies on subtotal (percentage or fixed).
 * VAT applies on (subtotal - document discount). Shipping added after VAT.
 *
 * @param array<int,array<string,mixed>> $items
 * @return array{items: array<int,array<string,mixed>>, subtotal: float, discount_amount: float, vat_amount: float, shipping_amount: float, total: float}
 */
function invCalcTotals(array $items, string $discountType, float $discountValue, float $vatPercentage, float $shipping): array
{
    $normalized = [];
    $subtotal = 0.0;
    foreach ($items as $it) {
        $qty = round((float) ($it['quantity'] ?? 0), 3);
        $unit = round((float) ($it['unit_price'] ?? 0), 2);
        $lineDiscount = round((float) ($it['discount'] ?? 0), 2);
        $lineTotal = round(($qty * $unit) - $lineDiscount, 2);
        if ($lineTotal < 0) {
            $lineTotal = 0.0;
        }
        $subtotal += $lineTotal;
        $normalized[] = [
            'product_id' => isset($it['product_id']) && $it['product_id'] !== '' ? (int) $it['product_id'] : null,
            'description' => trim((string) ($it['description'] ?? '')),
            'quantity' => $qty,
            'unit_price' => $unit,
            'discount' => $lineDiscount,
            'tax' => round($vatPercentage, 2),
            'line_total' => $lineTotal,
        ];
    }
    $subtotal = round($subtotal, 2);

    $discountType = in_array($discountType, ['none', 'percentage', 'fixed'], true) ? $discountType : 'none';
    $discountAmount = 0.0;
    if ($discountType === 'percentage') {
        $discountAmount = round($subtotal * ($discountValue / 100), 2);
    } elseif ($discountType === 'fixed') {
        $discountAmount = round($discountValue, 2);
    }
    if ($discountAmount > $subtotal) {
        $discountAmount = $subtotal;
    }

    $taxable = round($subtotal - $discountAmount, 2);
    $vatAmount = round($taxable * ($vatPercentage / 100), 2);
    $shipping = round($shipping, 2);
    $total = round($taxable + $vatAmount + $shipping, 2);

    return [
        'items' => $normalized,
        'subtotal' => $subtotal,
        'discount_amount' => $discountAmount,
        'vat_amount' => $vatAmount,
        'shipping_amount' => $shipping,
        'total' => $total,
    ];
}

/* ============================================================
 * Validation
 * ========================================================== */

/**
 * Validate a quotation/invoice payload. Throws RuntimeException on failure.
 *
 * @param array<int,array<string,mixed>> $items
 */
function invValidateDocument(array $header, array $items, string $kind): void
{
    if ((int) ($header['customer_id'] ?? 0) < 1) {
        throw new RuntimeException('Please select a customer.');
    }
    $clean = array_values(array_filter($items, static function ($it) {
        $hasProduct = isset($it['product_id']) && $it['product_id'] !== '' && (int) $it['product_id'] > 0;
        $hasDesc = trim((string) ($it['description'] ?? '')) !== '';
        return $hasProduct || $hasDesc;
    }));
    if (count($clean) < 1) {
        throw new RuntimeException('Add at least one line item.');
    }
    foreach ($clean as $it) {
        if ((float) ($it['quantity'] ?? 0) <= 0) {
            throw new RuntimeException('Quantity must be greater than zero for all items.');
        }
        if ((float) ($it['unit_price'] ?? 0) < 0) {
            throw new RuntimeException('Unit price cannot be negative.');
        }
    }

    if ($kind === 'quotation') {
        $qDate = (string) ($header['quotation_date'] ?? '');
        $exp = (string) ($header['expiry_date'] ?? '');
        if ($qDate === '') {
            throw new RuntimeException('Quotation date is required.');
        }
        if ($exp !== '' && $exp < $qDate) {
            throw new RuntimeException('Expiry date must be on or after the quotation date.');
        }
    } else {
        $iDate = (string) ($header['invoice_date'] ?? '');
        $due = (string) ($header['due_date'] ?? '');
        if ($iDate === '') {
            throw new RuntimeException('Invoice date is required.');
        }
        if ($due !== '' && $due < $iDate) {
            throw new RuntimeException('Due date must be on or after the invoice date.');
        }
    }
}

/* ============================================================
 * Customers
 * ========================================================== */

function invListCustomers(PDO $db, string $search = '', bool $activeOnly = true): array
{
    $sql = 'SELECT * FROM customers';
    $where = [];
    $params = [];
    if ($activeOnly) {
        $where[] = 'active = 1';
    }
    if ($search !== '') {
        $where[] = '(name LIKE ? OR phone LIKE ? OR email LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like);
    }
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY name COLLATE NOCASE';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function invGetCustomer(PDO $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT * FROM customers WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Create or update a customer. Returns customer id.
 *
 * @param array<string,mixed> $data
 */
function invSaveCustomer(PDO $db, array $data): int
{
    $name = trim((string) ($data['name'] ?? ''));
    if ($name === '') {
        throw new RuntimeException('Customer name is required.');
    }
    $id = (int) ($data['id'] ?? 0);
    $params = [
        $name,
        trim((string) ($data['phone'] ?? '')) ?: null,
        trim((string) ($data['email'] ?? '')) ?: null,
        trim((string) ($data['address'] ?? '')) ?: null,
        trim((string) ($data['tax_number'] ?? '')) ?: null,
        trim((string) ($data['notes'] ?? '')) ?: null,
    ];
    if ($id > 0) {
        $params[] = $id;
        $db->prepare('UPDATE customers SET name=?, phone=?, email=?, address=?, tax_number=?, notes=?, updated_at=CURRENT_TIMESTAMP WHERE id=?')
            ->execute($params);
        return $id;
    }
    $db->prepare('INSERT INTO customers (name, phone, email, address, tax_number, notes, active) VALUES (?,?,?,?,?,?,1)')
        ->execute($params);
    return (int) $db->lastInsertId();
}

/* ============================================================
 * Products (pickers / quick create)
 * ========================================================== */

function invListProducts(PDO $db, string $search = '', int $limit = 50): array
{
    $limit = max(1, min(2000, $limit));

    // Include image/barcode when the columns exist (older DBs may lack them).
    $cols = [];
    foreach ($db->query('PRAGMA table_info(products)')->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $cols[(string) $c['name']] = true;
    }
    $select = 'id, name, price, quantity, category';
    $select .= isset($cols['image_url']) ? ', image_url' : ", '' AS image_url";
    $select .= isset($cols['barcode']) ? ', barcode' : ", '' AS barcode";

    if ($search !== '') {
        $where = isset($cols['barcode']) ? '(name LIKE ? OR barcode LIKE ?)' : 'name LIKE ?';
        $params = isset($cols['barcode']) ? ['%' . $search . '%', '%' . $search . '%'] : ['%' . $search . '%'];
        $stmt = $db->prepare("SELECT $select FROM products WHERE $where ORDER BY name COLLATE NOCASE LIMIT " . $limit);
        $stmt->execute($params);
    } else {
        $stmt = $db->query("SELECT $select FROM products ORDER BY name COLLATE NOCASE LIMIT " . $limit);
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/* ============================================================
 * Quotations
 * ========================================================== */

/**
 * @return array{quotation: array, customer: array|null, items: array}|null
 */
function invLoadQuotation(PDO $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT * FROM quotations WHERE id = ?');
    $stmt->execute([$id]);
    $q = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$q) {
        return null;
    }
    $customer = invGetCustomer($db, (int) $q['customer_id']);
    $itemsStmt = $db->prepare('
        SELECT qi.*, p.name AS product_name
        FROM quotation_items qi
        LEFT JOIN products p ON p.id = qi.product_id
        WHERE qi.quotation_id = ?
        ORDER BY qi.id ASC
    ');
    $itemsStmt->execute([$id]);
    return [
        'quotation' => $q,
        'customer' => $customer,
        'items' => $itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
    ];
}

/**
 * Create or update a quotation with its items.
 *
 * @param array<string,mixed> $header
 * @param array<int,array<string,mixed>> $items
 * @return int quotation id
 */
function invSaveQuotation(PDO $db, array $header, array $items, string $status = 'Draft'): int
{
    invValidateDocument($header, $items, 'quotation');

    $status = in_array($status, ['Draft', 'Sent', 'Accepted', 'Rejected', 'Expired', 'Converted'], true) ? $status : 'Draft';
    $discountType = (string) ($header['discount_type'] ?? 'none');
    $discountValue = (float) ($header['discount_value'] ?? 0);
    $vatPct = (float) ($header['vat_percentage'] ?? 0);
    $shipping = (float) ($header['shipping_amount'] ?? 0);
    $totals = invCalcTotals($items, $discountType, $discountValue, $vatPct, $shipping);

    $id = (int) ($header['id'] ?? 0);
    $user = invCurrentUsername();

    $ownTransaction = !$db->inTransaction();
    if ($ownTransaction) {
        $db->beginTransaction();
    }
    try {
        if ($id > 0) {
            $existing = invLoadQuotation($db, $id);
            if (!$existing) {
                throw new RuntimeException('Quotation not found.');
            }
            if ((string) $existing['quotation']['status'] === 'Converted') {
                throw new RuntimeException('A converted quotation cannot be edited.');
            }
            $db->prepare('
                UPDATE quotations SET
                    customer_id=?, quotation_date=?, expiry_date=?, status=?,
                    subtotal=?, discount_type=?, discount_value=?, discount_amount=?,
                    vat_percentage=?, vat_amount=?, shipping_amount=?, total=?,
                    notes=?, terms_conditions=?, updated_at=CURRENT_TIMESTAMP
                WHERE id=?
            ')->execute([
                (int) $header['customer_id'],
                (string) $header['quotation_date'],
                ((string) ($header['expiry_date'] ?? '')) ?: null,
                $status,
                $totals['subtotal'], $discountType, $discountValue, $totals['discount_amount'],
                $vatPct, $totals['vat_amount'], $totals['shipping_amount'], $totals['total'],
                ((string) ($header['notes'] ?? '')) ?: null,
                ((string) ($header['terms_conditions'] ?? '')) ?: null,
                $id,
            ]);
            $db->prepare('DELETE FROM quotation_items WHERE quotation_id = ?')->execute([$id]);
        } else {
            $number = invNextNumber($db, 'quotation');
            $db->prepare('
                INSERT INTO quotations
                    (quotation_number, customer_id, quotation_date, expiry_date, status,
                     subtotal, discount_type, discount_value, discount_amount,
                     vat_percentage, vat_amount, shipping_amount, total,
                     notes, terms_conditions, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ')->execute([
                $number,
                (int) $header['customer_id'],
                (string) $header['quotation_date'],
                ((string) ($header['expiry_date'] ?? '')) ?: null,
                $status,
                $totals['subtotal'], $discountType, $discountValue, $totals['discount_amount'],
                $vatPct, $totals['vat_amount'], $totals['shipping_amount'], $totals['total'],
                ((string) ($header['notes'] ?? '')) ?: null,
                ((string) ($header['terms_conditions'] ?? '')) ?: null,
                $user,
            ]);
            $id = (int) $db->lastInsertId();
        }

        invInsertItems($db, 'quotation_items', 'quotation_id', $id, $totals['items']);

        if ($ownTransaction) {
            $db->commit();
        }
        return $id;
    } catch (Throwable $e) {
        if ($ownTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function invDeleteQuotation(PDO $db, int $id): void
{
    $data = invLoadQuotation($db, $id);
    if (!$data) {
        throw new RuntimeException('Quotation not found.');
    }
    $status = (string) $data['quotation']['status'];
    if ($status === 'Converted') {
        throw new RuntimeException('A converted quotation cannot be deleted.');
    }
    $db->prepare('DELETE FROM quotations WHERE id = ?')->execute([$id]);
}

function invDuplicateQuotation(PDO $db, int $id): int
{
    $data = invLoadQuotation($db, $id);
    if (!$data) {
        throw new RuntimeException('Quotation not found.');
    }
    $q = $data['quotation'];
    $items = array_map(static function ($it) {
        return [
            'product_id' => $it['product_id'],
            'description' => $it['description'],
            'quantity' => $it['quantity'],
            'unit_price' => $it['unit_price'],
            'discount' => $it['discount'],
        ];
    }, $data['items']);

    return invSaveQuotation($db, [
        'customer_id' => (int) $q['customer_id'],
        'quotation_date' => date('Y-m-d'),
        'expiry_date' => $q['expiry_date'],
        'discount_type' => $q['discount_type'],
        'discount_value' => $q['discount_value'],
        'vat_percentage' => $q['vat_percentage'],
        'shipping_amount' => $q['shipping_amount'],
        'notes' => $q['notes'],
        'terms_conditions' => $q['terms_conditions'],
    ], $items, 'Draft');
}

/**
 * Convert a quotation into an invoice. Marks quotation Converted.
 * Returns the new invoice id.
 */
function invConvertQuotationToInvoice(PDO $db, int $quotationId, array $overrides = []): int
{
    $data = invLoadQuotation($db, $quotationId);
    if (!$data) {
        throw new RuntimeException('Quotation not found.');
    }
    $q = $data['quotation'];
    if ((string) $q['status'] === 'Converted') {
        throw new RuntimeException('This quotation has already been converted.');
    }
    if (count($data['items']) < 1) {
        throw new RuntimeException('Cannot convert an empty quotation.');
    }

    $items = array_map(static function ($it) {
        return [
            'product_id' => $it['product_id'],
            'description' => $it['description'],
            'quantity' => $it['quantity'],
            'unit_price' => $it['unit_price'],
            'discount' => $it['discount'],
        ];
    }, $data['items']);

    $settings = invGetDocumentSettings();
    $invoiceDate = date('Y-m-d');

    $ownTransaction = !$db->inTransaction();
    if ($ownTransaction) {
        $db->beginTransaction();
    }
    try {
        $invoiceId = invSaveInvoice($db, [
            'quotation_id' => $quotationId,
            'customer_id' => (int) $q['customer_id'],
            'invoice_date' => $invoiceDate,
            'due_date' => $overrides['due_date'] ?? null,
            'payment_terms' => $overrides['payment_terms'] ?? ($settings['default_payment_terms'] ?? ''),
            'discount_type' => $q['discount_type'],
            'discount_value' => $q['discount_value'],
            'vat_percentage' => $q['vat_percentage'],
            'shipping_amount' => $q['shipping_amount'],
            'notes' => $q['notes'],
            'terms_conditions' => $q['terms_conditions'],
        ], $items, 'Draft');

        $db->prepare("UPDATE quotations SET status='Converted', updated_at=CURRENT_TIMESTAMP WHERE id=?")
            ->execute([$quotationId]);

        if ($ownTransaction) {
            $db->commit();
        }
        return $invoiceId;
    } catch (Throwable $e) {
        if ($ownTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/* ============================================================
 * Invoices
 * ========================================================== */

/**
 * @return array{invoice: array, customer: array|null, items: array, payments: array}|null
 */
function invLoadInvoice(PDO $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT * FROM invoices WHERE id = ?');
    $stmt->execute([$id]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$inv) {
        return null;
    }
    $customer = invGetCustomer($db, (int) $inv['customer_id']);
    $itemsStmt = $db->prepare('
        SELECT ii.*, p.name AS product_name
        FROM invoice_items ii
        LEFT JOIN products p ON p.id = ii.product_id
        WHERE ii.invoice_id = ?
        ORDER BY ii.id ASC
    ');
    $itemsStmt->execute([$id]);
    $payStmt = $db->prepare('SELECT * FROM invoice_payments WHERE invoice_id = ? ORDER BY payment_date ASC, id ASC');
    $payStmt->execute([$id]);
    return [
        'invoice' => $inv,
        'customer' => $customer,
        'items' => $itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
        'payments' => $payStmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
    ];
}

/**
 * Create or update an invoice with items.
 *
 * @param array<string,mixed> $header
 * @param array<int,array<string,mixed>> $items
 * @return int invoice id
 */
function invSaveInvoice(PDO $db, array $header, array $items, string $status = 'Draft'): int
{
    invValidateDocument($header, $items, 'invoice');

    $allowed = ['Draft', 'Issued', 'Partially Paid', 'Paid', 'Cancelled', 'Overdue'];
    $status = in_array($status, $allowed, true) ? $status : 'Draft';
    $discountType = (string) ($header['discount_type'] ?? 'none');
    $discountValue = (float) ($header['discount_value'] ?? 0);
    $vatPct = (float) ($header['vat_percentage'] ?? 0);
    $shipping = (float) ($header['shipping_amount'] ?? 0);
    $totals = invCalcTotals($items, $discountType, $discountValue, $vatPct, $shipping);

    $id = (int) ($header['id'] ?? 0);
    $user = invCurrentUsername();

    $ownTransaction = !$db->inTransaction();
    if ($ownTransaction) {
        $db->beginTransaction();
    }
    try {
        if ($id > 0) {
            $existing = invLoadInvoice($db, $id);
            if (!$existing) {
                throw new RuntimeException('Invoice not found.');
            }
            $exStatus = (string) $existing['invoice']['status'];
            if ($exStatus === 'Paid') {
                throw new RuntimeException('A paid invoice cannot be edited.');
            }
            if ($exStatus === 'Cancelled') {
                throw new RuntimeException('A cancelled invoice cannot be edited.');
            }
            $paid = (float) $existing['invoice']['paid_amount'];
            $balance = round($totals['total'] - $paid, 2);
            $db->prepare('
                UPDATE invoices SET
                    customer_id=?, invoice_date=?, due_date=?, payment_terms=?,
                    subtotal=?, discount_type=?, discount_value=?, discount_amount=?,
                    vat_percentage=?, vat_amount=?, shipping_amount=?,
                    grand_total=?, balance_due=?,
                    notes=?, terms_conditions=?, updated_at=CURRENT_TIMESTAMP
                WHERE id=?
            ')->execute([
                (int) $header['customer_id'],
                (string) $header['invoice_date'],
                ((string) ($header['due_date'] ?? '')) ?: null,
                ((string) ($header['payment_terms'] ?? '')) ?: null,
                $totals['subtotal'], $discountType, $discountValue, $totals['discount_amount'],
                $vatPct, $totals['vat_amount'], $totals['shipping_amount'],
                $totals['total'], $balance,
                ((string) ($header['notes'] ?? '')) ?: null,
                ((string) ($header['terms_conditions'] ?? '')) ?: null,
                $id,
            ]);
            $db->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$id]);
        } else {
            $number = invNextNumber($db, 'invoice');
            $db->prepare('
                INSERT INTO invoices
                    (invoice_number, quotation_id, customer_id, invoice_date, due_date, payment_terms, status,
                     subtotal, discount_type, discount_value, discount_amount,
                     vat_percentage, vat_amount, shipping_amount,
                     paid_amount, balance_due, grand_total,
                     notes, terms_conditions, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ')->execute([
                $number,
                isset($header['quotation_id']) && $header['quotation_id'] !== '' ? (int) $header['quotation_id'] : null,
                (int) $header['customer_id'],
                (string) $header['invoice_date'],
                ((string) ($header['due_date'] ?? '')) ?: null,
                ((string) ($header['payment_terms'] ?? '')) ?: null,
                $status,
                $totals['subtotal'], $discountType, $discountValue, $totals['discount_amount'],
                $vatPct, $totals['vat_amount'], $totals['shipping_amount'],
                0, $totals['total'], $totals['total'],
                ((string) ($header['notes'] ?? '')) ?: null,
                ((string) ($header['terms_conditions'] ?? '')) ?: null,
                $user,
            ]);
            $id = (int) $db->lastInsertId();
        }

        invInsertItems($db, 'invoice_items', 'invoice_id', $id, $totals['items']);

        if ($ownTransaction) {
            $db->commit();
        }
        return $id;
    } catch (Throwable $e) {
        if ($ownTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function invDeleteInvoice(PDO $db, int $id): void
{
    $data = invLoadInvoice($db, $id);
    if (!$data) {
        throw new RuntimeException('Invoice not found.');
    }
    $inv = $data['invoice'];
    if ((string) $inv['status'] === 'Paid' && !invCan('delete_paid_invoice')) {
        throw new RuntimeException('You do not have permission to delete a paid invoice.');
    }
    $ownTransaction = !$db->inTransaction();
    if ($ownTransaction) {
        $db->beginTransaction();
    }
    try {
        // Restore stock if it had been applied.
        if ((int) $inv['stock_applied'] === 1) {
            invReverseStock($db, $id);
        }
        $db->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
        if ($ownTransaction) {
            $db->commit();
        }
    } catch (Throwable $e) {
        if ($ownTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function invDuplicateInvoice(PDO $db, int $id): int
{
    $data = invLoadInvoice($db, $id);
    if (!$data) {
        throw new RuntimeException('Invoice not found.');
    }
    $inv = $data['invoice'];
    $items = array_map(static function ($it) {
        return [
            'product_id' => $it['product_id'],
            'description' => $it['description'],
            'quantity' => $it['quantity'],
            'unit_price' => $it['unit_price'],
            'discount' => $it['discount'],
        ];
    }, $data['items']);

    return invSaveInvoice($db, [
        'customer_id' => (int) $inv['customer_id'],
        'invoice_date' => date('Y-m-d'),
        'due_date' => $inv['due_date'],
        'payment_terms' => $inv['payment_terms'],
        'discount_type' => $inv['discount_type'],
        'discount_value' => $inv['discount_value'],
        'vat_percentage' => $inv['vat_percentage'],
        'shipping_amount' => $inv['shipping_amount'],
        'notes' => $inv['notes'],
        'terms_conditions' => $inv['terms_conditions'],
    ], $items, 'Draft');
}

/**
 * Issue an invoice: change status Draft -> Issued and deduct stock once.
 */
function invIssueInvoice(PDO $db, int $id): void
{
    $data = invLoadInvoice($db, $id);
    if (!$data) {
        throw new RuntimeException('Invoice not found.');
    }
    $inv = $data['invoice'];
    if (count($data['items']) < 1) {
        throw new RuntimeException('Cannot issue an empty invoice.');
    }
    if (in_array((string) $inv['status'], ['Cancelled'], true)) {
        throw new RuntimeException('A cancelled invoice cannot be issued.');
    }

    $ownTransaction = !$db->inTransaction();
    if ($ownTransaction) {
        $db->beginTransaction();
    }
    try {
        if ((int) $inv['stock_applied'] !== 1) {
            invApplyStock($db, $id);
            $db->prepare('UPDATE invoices SET stock_applied = 1 WHERE id = ?')->execute([$id]);
        }
        // Only advance status if still a draft.
        if ((string) $inv['status'] === 'Draft') {
            $db->prepare("UPDATE invoices SET status='Issued', updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$id]);
        }
        invRecalculateInvoiceState($db, $id);
        if ($ownTransaction) {
            $db->commit();
        }
    } catch (Throwable $e) {
        if ($ownTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/**
 * Cancel an invoice and restore stock if it was applied.
 */
function invCancelInvoice(PDO $db, int $id): void
{
    $data = invLoadInvoice($db, $id);
    if (!$data) {
        throw new RuntimeException('Invoice not found.');
    }
    $inv = $data['invoice'];
    if ((string) $inv['status'] === 'Paid') {
        throw new RuntimeException('A paid invoice cannot be cancelled.');
    }
    $ownTransaction = !$db->inTransaction();
    if ($ownTransaction) {
        $db->beginTransaction();
    }
    try {
        if ((int) $inv['stock_applied'] === 1) {
            invReverseStock($db, $id);
            $db->prepare('UPDATE invoices SET stock_applied = 0 WHERE id = ?')->execute([$id]);
        }
        $db->prepare("UPDATE invoices SET status='Cancelled', updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$id]);
        if ($ownTransaction) {
            $db->commit();
        }
    } catch (Throwable $e) {
        if ($ownTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/* ============================================================
 * Payments
 * ========================================================== */

/**
 * Record a payment against an invoice. Blocks overpayment.
 *
 * @param array<string,mixed> $data
 */
function invRecordPayment(PDO $db, int $invoiceId, array $data): int
{
    $amount = round((float) ($data['amount'] ?? 0), 2);
    if ($amount <= 0) {
        throw new RuntimeException('Payment amount must be greater than zero.');
    }
    $method = (string) ($data['payment_method'] ?? '');
    $validMethods = ['Cash', 'Card', 'Bank Transfer', 'Mobile Money', 'Cheque', 'Credit'];
    if (!in_array($method, $validMethods, true)) {
        throw new RuntimeException('Invalid payment method.');
    }

    $ownTransaction = !$db->inTransaction();
    if ($ownTransaction) {
        $db->beginTransaction();
    }
    try {
        $data2 = invLoadInvoice($db, $invoiceId);
        if (!$data2) {
            throw new RuntimeException('Invoice not found.');
        }
        $inv = $data2['invoice'];
        if ((string) $inv['status'] === 'Cancelled') {
            throw new RuntimeException('Cannot record payment on a cancelled invoice.');
        }
        $balance = (float) $inv['balance_due'];
        if ($amount > $balance + 0.001) {
            throw new RuntimeException('Payment cannot exceed the outstanding balance (' . number_format($balance, 2) . ').');
        }

        $db->prepare('
            INSERT INTO invoice_payments (invoice_id, payment_date, payment_method, reference, amount, notes, received_by)
            VALUES (?,?,?,?,?,?,?)
        ')->execute([
            $invoiceId,
            (string) ($data['payment_date'] ?? date('Y-m-d')),
            $method,
            trim((string) ($data['reference'] ?? '')) ?: null,
            $amount,
            trim((string) ($data['notes'] ?? '')) ?: null,
            invCurrentUsername(),
        ]);
        $paymentId = (int) $db->lastInsertId();

        // A payment implies the invoice is live: apply stock if it was still a draft.
        if ((int) $inv['stock_applied'] !== 1) {
            invApplyStock($db, $invoiceId);
            $db->prepare('UPDATE invoices SET stock_applied = 1 WHERE id = ?')->execute([$invoiceId]);
        }

        invRecalculateInvoiceState($db, $invoiceId);

        if ($ownTransaction) {
            $db->commit();
        }
        return $paymentId;
    } catch (Throwable $e) {
        if ($ownTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/**
 * Recompute paid_amount, balance_due and status from payments + dates.
 */
function invRecalculateInvoiceState(PDO $db, int $invoiceId): void
{
    $stmt = $db->prepare('SELECT * FROM invoices WHERE id = ?');
    $stmt->execute([$invoiceId]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$inv) {
        return;
    }
    $paidStmt = $db->prepare('SELECT COALESCE(SUM(amount),0) FROM invoice_payments WHERE invoice_id = ?');
    $paidStmt->execute([$invoiceId]);
    $paid = round((float) $paidStmt->fetchColumn(), 2);
    $grand = (float) $inv['grand_total'];
    $balance = round($grand - $paid, 2);
    if ($balance < 0) {
        $balance = 0.0;
    }

    $status = (string) $inv['status'];
    if ($status !== 'Cancelled') {
        if ($paid <= 0) {
            // Keep Draft/Issued/Overdue as-is unless it was a paid state.
            if (in_array($status, ['Paid', 'Partially Paid'], true)) {
                $status = 'Issued';
            }
        } elseif ($balance <= 0.001) {
            $status = 'Paid';
        } else {
            $status = 'Partially Paid';
        }

        // Overdue overlay for unpaid balances past due date.
        if ($status !== 'Paid' && !empty($inv['due_date'])) {
            $today = date('Y-m-d');
            if ($inv['due_date'] < $today && $balance > 0.001 && $status !== 'Draft') {
                $status = 'Overdue';
            }
        }
    }

    $db->prepare('UPDATE invoices SET paid_amount=?, balance_due=?, status=?, updated_at=CURRENT_TIMESTAMP WHERE id=?')
        ->execute([$paid, $balance, $status, $invoiceId]);
}

/* ============================================================
 * Stock integration
 * ========================================================== */

/**
 * Deduct stock for all invoice items (mirrors process_order.php rules).
 */
function invApplyStock(PDO $db, int $invoiceId): void
{
    invAdjustStock($db, $invoiceId, -1);
}

/**
 * Restore stock previously deducted for an invoice.
 */
function invReverseStock(PDO $db, int $invoiceId): void
{
    invAdjustStock($db, $invoiceId, 1);
}

/**
 * @param int $sign -1 to deduct, +1 to restore
 */
function invAdjustStock(PDO $db, int $invoiceId, int $sign): void
{
    $recipeHelper = __DIR__ . '/recipe_stock_helper.php';
    $hasRecipe = false;
    if (is_readable($recipeHelper)) {
        require_once $recipeHelper;
        $hasRecipe = function_exists('deductRecipeStockByProductName');
    }

    $stmt = $db->prepare('
        SELECT ii.product_id, ii.quantity, p.name AS product_name, p.category
        FROM invoice_items ii
        LEFT JOIN products p ON p.id = ii.product_id
        WHERE ii.invoice_id = ?
    ');
    $stmt->execute([$invoiceId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $update = $db->prepare('UPDATE products SET quantity = quantity + :delta WHERE id = :id');
    foreach ($rows as $row) {
        $pid = $row['product_id'] !== null ? (int) $row['product_id'] : 0;
        $qty = (float) $row['quantity'];
        if ($pid < 1 || $qty <= 0) {
            continue; // Custom/description-only lines never touch stock.
        }
        $isFood = strtolower(trim((string) ($row['category'] ?? ''))) === 'food';

        if ($hasRecipe && !empty($row['product_name'])) {
            // Recipe ingredients: deduct on apply, restore on reverse.
            deductRecipeStockByProductName($db, (string) $row['product_name'], $sign === -1 ? $qty : -$qty);
        }
        if (!$isFood) {
            $update->execute([':delta' => $sign * $qty, ':id' => $pid]);
        }
    }
}

/* ============================================================
 * Status maintenance (batch)
 * ========================================================== */

/**
 * Mark expired quotations and overdue invoices. Safe to call on list views.
 */
function invRefreshStatuses(PDO $db): void
{
    $today = date('Y-m-d');
    // Expire quotations past expiry that are still open.
    $db->prepare("
        UPDATE quotations SET status='Expired', updated_at=CURRENT_TIMESTAMP
        WHERE expiry_date IS NOT NULL AND expiry_date < ?
          AND status IN ('Draft','Sent')
    ")->execute([$today]);

    // Overdue invoices with an outstanding balance past due date.
    $db->prepare("
        UPDATE invoices SET status='Overdue', updated_at=CURRENT_TIMESTAMP
        WHERE due_date IS NOT NULL AND due_date < ?
          AND balance_due > 0.001
          AND status IN ('Issued','Partially Paid')
    ")->execute([$today]);
}

/* ============================================================
 * Listing (search / filter / paginate / sort)
 * ========================================================== */

/**
 * @param array<string,mixed> $filters status, customer_id, date_from, date_to, search
 * @return array{rows: array, total: int, page: int, pages: int}
 */
function invListQuotations(PDO $db, array $filters = [], int $page = 1, int $perPage = 15, string $sort = 'date_desc'): array
{
    $where = [];
    $params = [];
    if (!empty($filters['status'])) {
        $where[] = 'q.status = ?';
        $params[] = (string) $filters['status'];
    }
    if (!empty($filters['customer_id'])) {
        $where[] = 'q.customer_id = ?';
        $params[] = (int) $filters['customer_id'];
    }
    if (!empty($filters['date_from'])) {
        $where[] = 'q.quotation_date >= ?';
        $params[] = (string) $filters['date_from'];
    }
    if (!empty($filters['date_to'])) {
        $where[] = 'q.quotation_date <= ?';
        $params[] = (string) $filters['date_to'];
    }
    if (!empty($filters['search'])) {
        $where[] = '(q.quotation_number LIKE ? OR c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)';
        $like = '%' . $filters['search'] . '%';
        array_push($params, $like, $like, $like, $like);
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $orderSql = invSortSql($sort, 'q.quotation_date', 'q.total');

    $countStmt = $db->prepare("SELECT COUNT(*) FROM quotations q LEFT JOIN customers c ON c.id = q.customer_id $whereSql");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    [$limit, $offset, $page, $pages] = invPaginate($total, $page, $perPage);

    $sql = "
        SELECT q.*, c.name AS customer_name, c.phone AS customer_phone
        FROM quotations q
        LEFT JOIN customers c ON c.id = q.customer_id
        $whereSql
        $orderSql
        LIMIT $limit OFFSET $offset
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total, 'page' => $page, 'pages' => $pages];
}

/**
 * @param array<string,mixed> $filters status, payment (paid|unpaid|overdue), customer_id, date_from, date_to, search
 * @return array{rows: array, total: int, page: int, pages: int}
 */
function invListInvoices(PDO $db, array $filters = [], int $page = 1, int $perPage = 15, string $sort = 'date_desc'): array
{
    $where = [];
    $params = [];
    if (!empty($filters['status'])) {
        $where[] = 'i.status = ?';
        $params[] = (string) $filters['status'];
    }
    if (!empty($filters['payment'])) {
        $today = date('Y-m-d');
        if ($filters['payment'] === 'paid') {
            $where[] = "i.status = 'Paid'";
        } elseif ($filters['payment'] === 'unpaid') {
            $where[] = "i.balance_due > 0.001 AND i.status NOT IN ('Cancelled')";
        } elseif ($filters['payment'] === 'overdue') {
            $where[] = "i.due_date IS NOT NULL AND i.due_date < ? AND i.balance_due > 0.001 AND i.status NOT IN ('Cancelled','Paid')";
            $params[] = $today;
        }
    }
    if (!empty($filters['customer_id'])) {
        $where[] = 'i.customer_id = ?';
        $params[] = (int) $filters['customer_id'];
    }
    if (!empty($filters['date_from'])) {
        $where[] = 'i.invoice_date >= ?';
        $params[] = (string) $filters['date_from'];
    }
    if (!empty($filters['date_to'])) {
        $where[] = 'i.invoice_date <= ?';
        $params[] = (string) $filters['date_to'];
    }
    if (!empty($filters['search'])) {
        $where[] = '(i.invoice_number LIKE ? OR c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)';
        $like = '%' . $filters['search'] . '%';
        array_push($params, $like, $like, $like, $like);
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $orderSql = invSortSql($sort, 'i.invoice_date', 'i.grand_total');

    $countStmt = $db->prepare("SELECT COUNT(*) FROM invoices i LEFT JOIN customers c ON c.id = i.customer_id $whereSql");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    [$limit, $offset, $page, $pages] = invPaginate($total, $page, $perPage);

    $sql = "
        SELECT i.*, c.name AS customer_name, c.phone AS customer_phone
        FROM invoices i
        LEFT JOIN customers c ON c.id = i.customer_id
        $whereSql
        $orderSql
        LIMIT $limit OFFSET $offset
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total, 'page' => $page, 'pages' => $pages];
}

function invSortSql(string $sort, string $dateCol, string $amountCol): string
{
    switch ($sort) {
        case 'date_asc':
            return "ORDER BY $dateCol ASC, id ASC";
        case 'amount_desc':
            return "ORDER BY $amountCol DESC";
        case 'amount_asc':
            return "ORDER BY $amountCol ASC";
        case 'date_desc':
        default:
            return "ORDER BY $dateCol DESC, id DESC";
    }
}

/**
 * @return array{0:int,1:int,2:int,3:int} [limit, offset, page, pages]
 */
function invPaginate(int $total, int $page, int $perPage): array
{
    $perPage = max(1, min(200, $perPage));
    $pages = max(1, (int) ceil($total / $perPage));
    $page = max(1, min($pages, $page));
    $offset = ($page - 1) * $perPage;
    return [$perPage, $offset, $page, $pages];
}

/* ============================================================
 * Internal
 * ========================================================== */

/**
 * @param array<int,array<string,mixed>> $items normalized items
 */
function invInsertItems(PDO $db, string $table, string $fkColumn, int $docId, array $items): void
{
    $stmt = $db->prepare("
        INSERT INTO $table ($fkColumn, product_id, description, quantity, unit_price, discount, tax, line_total)
        VALUES (?,?,?,?,?,?,?,?)
    ");
    foreach ($items as $it) {
        $hasProduct = !empty($it['product_id']);
        $hasDesc = trim((string) ($it['description'] ?? '')) !== '';
        if (!$hasProduct && !$hasDesc) {
            continue; // skip empty rows
        }
        $stmt->execute([
            $docId,
            $it['product_id'] !== null ? (int) $it['product_id'] : null,
            (string) ($it['description'] ?? ''),
            (float) $it['quantity'],
            (float) $it['unit_price'],
            (float) $it['discount'],
            (float) $it['tax'],
            (float) $it['line_total'],
        ]);
    }
}

/* ============================================================
 * Status badge helper (UI)
 * ========================================================== */

function invStatusBadgeClass(string $status): string
{
    switch ($status) {
        case 'Draft':
            return 'bg-gray-100 text-gray-700';
        case 'Sent':
        case 'Issued':
            return 'bg-sky-100 text-sky-700';
        case 'Accepted':
        case 'Paid':
            return 'bg-emerald-100 text-emerald-700';
        case 'Partially Paid':
            return 'bg-amber-100 text-amber-700';
        case 'Rejected':
        case 'Cancelled':
            return 'bg-rose-100 text-rose-700';
        case 'Expired':
        case 'Overdue':
            return 'bg-orange-100 text-orange-700';
        case 'Converted':
            return 'bg-indigo-100 text-indigo-700';
        default:
            return 'bg-gray-100 text-gray-700';
    }
}
