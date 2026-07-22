<?php

declare(strict_types=1);

/**
 * Quotations & Invoicing schema.
 *
 * Transactional tables (customers, quotations, invoices, payments, document_sequence)
 * live in pos.db so numbering and stock changes stay atomic with the documents.
 * Company/document display settings live in info.db (business settings DB).
 *
 * Follows the ensure_*_schema.php pattern used across the codebase:
 * CREATE TABLE IF NOT EXISTS + PRAGMA table_info guards for incremental columns.
 */

/**
 * Create/patch the transactional invoicing tables on pos.db.
 */
function ensureInvoicingSchema(PDO $db): void
{
    // ---- Customers (dedicated customer table for the invoicing module) ----
    $db->exec("
        CREATE TABLE IF NOT EXISTS customers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            phone TEXT,
            email TEXT,
            address TEXT,
            tax_number TEXT,
            notes TEXT,
            active INTEGER NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $db->exec('CREATE INDEX IF NOT EXISTS idx_customers_name ON customers(name)');

    // ---- Quotations ----
    $db->exec("
        CREATE TABLE IF NOT EXISTS quotations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            quotation_number TEXT UNIQUE,
            customer_id INTEGER NOT NULL,
            quotation_date DATE NOT NULL,
            expiry_date DATE,
            status TEXT NOT NULL DEFAULT 'Draft'
                CHECK(status IN ('Draft','Sent','Accepted','Rejected','Expired','Converted')),
            subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
            discount_type TEXT NOT NULL DEFAULT 'none'
                CHECK(discount_type IN ('none','percentage','fixed')),
            discount_value DECIMAL(12,2) NOT NULL DEFAULT 0,
            discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            vat_percentage DECIMAL(6,2) NOT NULL DEFAULT 0,
            vat_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            shipping_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            total DECIMAL(12,2) NOT NULL DEFAULT 0,
            notes TEXT,
            terms_conditions TEXT,
            created_by TEXT,
            approved_by TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (customer_id) REFERENCES customers(id)
        )
    ");
    $db->exec('CREATE INDEX IF NOT EXISTS idx_quotations_customer ON quotations(customer_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_quotations_status ON quotations(status)');

    $db->exec("
        CREATE TABLE IF NOT EXISTS quotation_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            quotation_id INTEGER NOT NULL,
            product_id INTEGER,
            description TEXT,
            quantity DECIMAL(12,3) NOT NULL DEFAULT 1,
            unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
            discount DECIMAL(12,2) NOT NULL DEFAULT 0,
            tax DECIMAL(6,2) NOT NULL DEFAULT 0,
            line_total DECIMAL(12,2) NOT NULL DEFAULT 0,
            FOREIGN KEY (quotation_id) REFERENCES quotations(id) ON DELETE CASCADE
        )
    ");
    $db->exec('CREATE INDEX IF NOT EXISTS idx_quotation_items_qid ON quotation_items(quotation_id)');

    // ---- Invoices ----
    $db->exec("
        CREATE TABLE IF NOT EXISTS invoices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            invoice_number TEXT UNIQUE,
            quotation_id INTEGER,
            customer_id INTEGER NOT NULL,
            invoice_date DATE NOT NULL,
            due_date DATE,
            payment_terms TEXT,
            status TEXT NOT NULL DEFAULT 'Draft'
                CHECK(status IN ('Draft','Issued','Partially Paid','Paid','Cancelled','Overdue')),
            subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
            discount_type TEXT NOT NULL DEFAULT 'none'
                CHECK(discount_type IN ('none','percentage','fixed')),
            discount_value DECIMAL(12,2) NOT NULL DEFAULT 0,
            discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            vat_percentage DECIMAL(6,2) NOT NULL DEFAULT 0,
            vat_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            shipping_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            balance_due DECIMAL(12,2) NOT NULL DEFAULT 0,
            grand_total DECIMAL(12,2) NOT NULL DEFAULT 0,
            notes TEXT,
            terms_conditions TEXT,
            stock_applied INTEGER NOT NULL DEFAULT 0,
            created_by TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (customer_id) REFERENCES customers(id),
            FOREIGN KEY (quotation_id) REFERENCES quotations(id)
        )
    ");
    $db->exec('CREATE INDEX IF NOT EXISTS idx_invoices_customer ON invoices(customer_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_invoices_status ON invoices(status)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_invoices_quotation ON invoices(quotation_id)');

    $db->exec("
        CREATE TABLE IF NOT EXISTS invoice_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            invoice_id INTEGER NOT NULL,
            product_id INTEGER,
            description TEXT,
            quantity DECIMAL(12,3) NOT NULL DEFAULT 1,
            unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
            discount DECIMAL(12,2) NOT NULL DEFAULT 0,
            tax DECIMAL(6,2) NOT NULL DEFAULT 0,
            line_total DECIMAL(12,2) NOT NULL DEFAULT 0,
            FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
        )
    ");
    $db->exec('CREATE INDEX IF NOT EXISTS idx_invoice_items_iid ON invoice_items(invoice_id)');

    // ---- Invoice payments ----
    $db->exec("
        CREATE TABLE IF NOT EXISTS invoice_payments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            invoice_id INTEGER NOT NULL,
            payment_date DATE NOT NULL,
            payment_method TEXT NOT NULL
                CHECK(payment_method IN ('Cash','Card','Bank Transfer','Mobile Money','Cheque','Credit')),
            reference TEXT,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            notes TEXT,
            received_by TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
        )
    ");
    $db->exec('CREATE INDEX IF NOT EXISTS idx_invoice_payments_iid ON invoice_payments(invoice_id)');

    // ---- Number sequences (kept in pos.db for atomic numbering) ----
    $db->exec("
        CREATE TABLE IF NOT EXISTS document_sequence (
            type TEXT PRIMARY KEY,
            last_number INTEGER NOT NULL DEFAULT 0
        )
    ");
    $db->exec("INSERT OR IGNORE INTO document_sequence (type, last_number) VALUES ('invoice', 0)");
    $db->exec("INSERT OR IGNORE INTO document_sequence (type, last_number) VALUES ('quotation', 0)");
}

/**
 * Create/patch and seed the single-row document_settings table on info.db.
 * Seeds from business_info when available.
 */
function ensureDocumentSettingsSchema(PDO $infoDb): void
{
    $infoDb->exec("
        CREATE TABLE IF NOT EXISTS document_settings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            company_name TEXT NOT NULL DEFAULT '',
            company_logo TEXT NOT NULL DEFAULT '',
            company_address TEXT NOT NULL DEFAULT '',
            telephone TEXT NOT NULL DEFAULT '',
            email TEXT NOT NULL DEFAULT '',
            website TEXT NOT NULL DEFAULT '',
            tax_number TEXT NOT NULL DEFAULT '',
            vat_number TEXT NOT NULL DEFAULT '',
            currency TEXT NOT NULL DEFAULT 'N$',
            invoice_prefix TEXT NOT NULL DEFAULT 'INV-',
            quotation_prefix TEXT NOT NULL DEFAULT 'QTN-',
            default_payment_terms TEXT NOT NULL DEFAULT 'Due within 30 days',
            default_terms_conditions TEXT NOT NULL DEFAULT '',
            default_notes TEXT NOT NULL DEFAULT '',
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // Guard for future incremental columns (mirrors business_settings.php pattern).
    $cols = [];
    foreach ($infoDb->query('PRAGMA table_info(document_settings)')->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $cols[(string) $c['name']] = true;
    }
    $wanted = [
        'company_name' => "TEXT NOT NULL DEFAULT ''",
        'company_logo' => "TEXT NOT NULL DEFAULT ''",
        'company_address' => "TEXT NOT NULL DEFAULT ''",
        'telephone' => "TEXT NOT NULL DEFAULT ''",
        'email' => "TEXT NOT NULL DEFAULT ''",
        'website' => "TEXT NOT NULL DEFAULT ''",
        'tax_number' => "TEXT NOT NULL DEFAULT ''",
        'vat_number' => "TEXT NOT NULL DEFAULT ''",
        'currency' => "TEXT NOT NULL DEFAULT 'N$'",
        'invoice_prefix' => "TEXT NOT NULL DEFAULT 'INV-'",
        'quotation_prefix' => "TEXT NOT NULL DEFAULT 'QTN-'",
        'default_payment_terms' => "TEXT NOT NULL DEFAULT 'Due within 30 days'",
        'default_terms_conditions' => "TEXT NOT NULL DEFAULT ''",
        'default_notes' => "TEXT NOT NULL DEFAULT ''",
    ];
    foreach ($wanted as $name => $ddl) {
        if (!isset($cols[$name])) {
            $infoDb->exec("ALTER TABLE document_settings ADD COLUMN $name $ddl");
        }
    }

    // Seed a single row, pulling sensible defaults from business_info if present.
    $existing = (int) $infoDb->query('SELECT COUNT(*) FROM document_settings')->fetchColumn();
    if ($existing === 0) {
        $name = '';
        $address = '';
        $phone = '';
        $logo = '';
        $vatRate = 15.0;
        try {
            $bi = $infoDb->query('SELECT * FROM business_info LIMIT 1')->fetch(PDO::FETCH_ASSOC);
            if ($bi) {
                $name = (string) ($bi['name'] ?? '');
                $address = (string) ($bi['location'] ?? '');
                $phone = (string) ($bi['phone'] ?? '');
                $logo = (string) ($bi['logo_path'] ?? '');
                if (isset($bi['vat_rate'])) {
                    $vatRate = (float) $bi['vat_rate'];
                }
            }
        } catch (Throwable $e) {
            // business_info may not exist yet
        }
        $ins = $infoDb->prepare("
            INSERT INTO document_settings
                (company_name, company_logo, company_address, telephone,
                 default_terms_conditions)
            VALUES (?,?,?,?,?)
        ");
        $ins->execute([
            $name,
            $logo,
            $address,
            $phone,
            'Thank you for your business.',
        ]);
        // Keep VAT default handy for callers that read business_info directly.
        unset($vatRate);
    }
}
