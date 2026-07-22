<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
date_default_timezone_set('Africa/Harare');

header('Content-Type: application/json');

require_once __DIR__ . '/invoicing_lib.php';

/**
 * Guard: JSON 401/403 responses (this is an API, not a page).
 */
if (!isset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['role'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Please log in again.']);
    exit;
}
if (!in_array(invCurrentRole(), ['admin', 'manager'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission to perform this action.']);
    exit;
}

invBootstrap();
$db = invGetDb();

/** Read merged input: JSON body first, then form POST. */
function invInput(): array
{
    $raw = file_get_contents('php://input');
    $json = $raw ? json_decode($raw, true) : null;
    if (is_array($json)) {
        return array_merge($_POST, $json);
    }
    return $_POST;
}

$input = invInput();
$action = (string) ($input['action'] ?? ($_GET['action'] ?? ''));

/** Normalize an items array coming from the client. */
function invNormalizeInputItems($items): array
{
    if (!is_array($items)) {
        return [];
    }
    $out = [];
    foreach ($items as $it) {
        if (!is_array($it)) {
            continue;
        }
        $out[] = [
            'product_id' => isset($it['product_id']) && $it['product_id'] !== '' ? (int) $it['product_id'] : null,
            'description' => (string) ($it['description'] ?? ''),
            'quantity' => (float) ($it['quantity'] ?? 0),
            'unit_price' => (float) ($it['unit_price'] ?? 0),
            'discount' => (float) ($it['discount'] ?? 0),
        ];
    }
    return $out;
}

function invRespond(bool $success, string $message = '', array $data = []): void
{
    echo json_encode(['success' => $success, 'message' => $message, 'data' => $data]);
    exit;
}

try {
    switch ($action) {

        /* ---------------- Quotations ---------------- */
        case 'save_quotation': {
            $header = [
                'id' => (int) ($input['id'] ?? 0),
                'customer_id' => (int) ($input['customer_id'] ?? 0),
                'quotation_date' => (string) ($input['quotation_date'] ?? date('Y-m-d')),
                'expiry_date' => (string) ($input['expiry_date'] ?? ''),
                'discount_type' => (string) ($input['discount_type'] ?? 'none'),
                'discount_value' => (float) ($input['discount_value'] ?? 0),
                'vat_percentage' => (float) ($input['vat_percentage'] ?? 0),
                'shipping_amount' => (float) ($input['shipping_amount'] ?? 0),
                'notes' => (string) ($input['notes'] ?? ''),
                'terms_conditions' => (string) ($input['terms_conditions'] ?? ''),
            ];
            $status = (string) ($input['status'] ?? 'Draft');
            $items = invNormalizeInputItems($input['items'] ?? []);
            $id = invSaveQuotation($db, $header, $items, $status);
            invRespond(true, 'Quotation saved.', ['id' => $id]);
            break;
        }

        case 'delete_quotation': {
            invDeleteQuotation($db, (int) ($input['id'] ?? 0));
            invRespond(true, 'Quotation deleted.');
            break;
        }

        case 'duplicate_quotation': {
            $newId = invDuplicateQuotation($db, (int) ($input['id'] ?? 0));
            invRespond(true, 'Quotation duplicated.', ['id' => $newId]);
            break;
        }

        case 'convert_quotation': {
            $invoiceId = invConvertQuotationToInvoice($db, (int) ($input['id'] ?? 0), [
                'due_date' => (string) ($input['due_date'] ?? ''),
                'payment_terms' => (string) ($input['payment_terms'] ?? ''),
            ]);
            invRespond(true, 'Quotation converted to invoice.', ['invoice_id' => $invoiceId]);
            break;
        }

        case 'set_quotation_status': {
            $id = (int) ($input['id'] ?? 0);
            $status = (string) ($input['status'] ?? '');
            if (!in_array($status, ['Draft', 'Sent', 'Accepted', 'Rejected', 'Expired'], true)) {
                throw new RuntimeException('Invalid status.');
            }
            $existing = invLoadQuotation($db, $id);
            if (!$existing) {
                throw new RuntimeException('Quotation not found.');
            }
            if ((string) $existing['quotation']['status'] === 'Converted') {
                throw new RuntimeException('A converted quotation cannot change status.');
            }
            $db->prepare('UPDATE quotations SET status=?, updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$status, $id]);
            invRespond(true, 'Status updated.');
            break;
        }

        /* ---------------- Invoices ---------------- */
        case 'save_invoice': {
            $header = [
                'id' => (int) ($input['id'] ?? 0),
                'quotation_id' => $input['quotation_id'] ?? null,
                'customer_id' => (int) ($input['customer_id'] ?? 0),
                'invoice_date' => (string) ($input['invoice_date'] ?? date('Y-m-d')),
                'due_date' => (string) ($input['due_date'] ?? ''),
                'payment_terms' => (string) ($input['payment_terms'] ?? ''),
                'discount_type' => (string) ($input['discount_type'] ?? 'none'),
                'discount_value' => (float) ($input['discount_value'] ?? 0),
                'vat_percentage' => (float) ($input['vat_percentage'] ?? 0),
                'shipping_amount' => (float) ($input['shipping_amount'] ?? 0),
                'notes' => (string) ($input['notes'] ?? ''),
                'terms_conditions' => (string) ($input['terms_conditions'] ?? ''),
            ];
            $items = invNormalizeInputItems($input['items'] ?? []);
            $id = invSaveInvoice($db, $header, $items, 'Draft');

            if (!empty($input['issue'])) {
                invIssueInvoice($db, $id);
            }
            invRespond(true, 'Invoice saved.', ['id' => $id]);
            break;
        }

        case 'issue_invoice': {
            invIssueInvoice($db, (int) ($input['id'] ?? 0));
            invRespond(true, 'Invoice issued.');
            break;
        }

        case 'cancel_invoice': {
            invCancelInvoice($db, (int) ($input['id'] ?? 0));
            invRespond(true, 'Invoice cancelled.');
            break;
        }

        case 'delete_invoice': {
            invDeleteInvoice($db, (int) ($input['id'] ?? 0));
            invRespond(true, 'Invoice deleted.');
            break;
        }

        case 'duplicate_invoice': {
            $newId = invDuplicateInvoice($db, (int) ($input['id'] ?? 0));
            invRespond(true, 'Invoice duplicated.', ['id' => $newId]);
            break;
        }

        case 'record_payment': {
            $paymentId = invRecordPayment($db, (int) ($input['invoice_id'] ?? 0), [
                'payment_date' => (string) ($input['payment_date'] ?? date('Y-m-d')),
                'payment_method' => (string) ($input['payment_method'] ?? ''),
                'reference' => (string) ($input['reference'] ?? ''),
                'amount' => (float) ($input['amount'] ?? 0),
                'notes' => (string) ($input['notes'] ?? ''),
            ]);
            $data = invLoadInvoice($db, (int) ($input['invoice_id'] ?? 0));
            invRespond(true, 'Payment recorded.', [
                'payment_id' => $paymentId,
                'balance_due' => $data ? (float) $data['invoice']['balance_due'] : 0,
                'status' => $data ? (string) $data['invoice']['status'] : '',
            ]);
            break;
        }

        /* ---------------- Customers ---------------- */
        case 'save_customer': {
            $id = invSaveCustomer($db, [
                'id' => (int) ($input['id'] ?? 0),
                'name' => (string) ($input['name'] ?? ''),
                'phone' => (string) ($input['phone'] ?? ''),
                'email' => (string) ($input['email'] ?? ''),
                'address' => (string) ($input['address'] ?? ''),
                'tax_number' => (string) ($input['tax_number'] ?? ''),
                'notes' => (string) ($input['notes'] ?? ''),
            ]);
            $customer = invGetCustomer($db, $id);
            invRespond(true, 'Customer saved.', ['customer' => $customer]);
            break;
        }

        case 'list_customers': {
            $rows = invListCustomers($db, (string) ($input['search'] ?? ($_GET['search'] ?? '')));
            invRespond(true, '', ['customers' => $rows]);
            break;
        }

        /* ---------------- Products (quick create / picker) ---------------- */
        case 'list_products': {
            $rows = invListProducts($db, (string) ($input['search'] ?? ($_GET['search'] ?? '')));
            invRespond(true, '', ['products' => $rows]);
            break;
        }

        case 'quick_create_product': {
            $name = trim((string) ($input['name'] ?? ''));
            $price = (float) ($input['price'] ?? 0);
            if ($name === '') {
                throw new RuntimeException('Product name is required.');
            }
            if ($price < 0) {
                throw new RuntimeException('Price cannot be negative.');
            }
            // Reuse products table; name is UNIQUE.
            $exists = $db->prepare('SELECT id, name, price FROM products WHERE name = ?');
            $exists->execute([$name]);
            $row = $exists->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                invRespond(true, 'Product already exists.', ['product' => $row]);
            }
            $ins = $db->prepare('INSERT INTO products (name, quantity, price, category) VALUES (?,?,?,?)');
            $ins->execute([$name, (int) ($input['quantity'] ?? 0), $price, trim((string) ($input['category'] ?? '')) ?: null]);
            $newId = (int) $db->lastInsertId();
            invRespond(true, 'Product created.', ['product' => ['id' => $newId, 'name' => $name, 'price' => $price]]);
            break;
        }

        default:
            http_response_code(400);
            invRespond(false, 'Unknown action.');
    }
} catch (Throwable $e) {
    invRespond(false, $e->getMessage());
}
