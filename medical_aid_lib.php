<?php

declare(strict_types=1);

/**
 * Medical Aid module — schema, business logic, and access guards.
 */

function medicalAidPosDbPath(): string
{
    return __DIR__ . '/pos.db';
}

function medicalAidGetDb(): PDO
{
    static $db = null;
    if ($db instanceof PDO) {
        return $db;
    }
    $db = new PDO('sqlite:' . medicalAidPosDbPath());
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA busy_timeout=5000');
    $db->exec('PRAGMA foreign_keys=ON');
    return $db;
}

function medicalAidBootstrap(): void
{
    ensureMedicalAidSchema(medicalAidGetDb());
}

function medicalAidCurrentRole(): string
{
    return strtolower((string) ($_SESSION['role'] ?? ''));
}

function medicalAidCurrentUsername(): string
{
    return (string) ($_SESSION['username'] ?? 'Unknown');
}

function medicalAidRequireAccess(): void
{
    if (!isset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['role'])) {
        header('Location: ../');
        exit;
    }
    $role = medicalAidCurrentRole();
    if (!in_array($role, ['admin', 'manager', 'cashier'], true)) {
        http_response_code(403);
        echo 'Access denied.';
        exit;
    }
}

function ensureMedicalAidSchema(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS medical_aid_patients (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            patient_name TEXT NOT NULL,
            phone TEXT,
            email TEXT,
            scheme_name TEXT,
            member_number TEXT,
            dependant_code TEXT,
            auth_reference TEXT,
            notes TEXT,
            active INTEGER NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS medical_aid_running_sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            patient_id INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT 'open' CHECK(status IN ('open', 'closed')),
            current_balance DECIMAL(10,2) DEFAULT 0.00,
            opened_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            closed_at DATETIME,
            cashier_id TEXT,
            FOREIGN KEY(patient_id) REFERENCES medical_aid_patients(id)
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS medical_aid_sales (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            patient_id INTEGER NOT NULL,
            session_id INTEGER,
            total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            paid_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            payment_status TEXT NOT NULL DEFAULT 'unpaid' CHECK(payment_status IN ('unpaid', 'partial', 'paid')),
            sale_type TEXT NOT NULL DEFAULT 'account' CHECK(sale_type IN ('account', 'running')),
            cashier_id TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            notes TEXT,
            FOREIGN KEY(patient_id) REFERENCES medical_aid_patients(id),
            FOREIGN KEY(session_id) REFERENCES medical_aid_running_sessions(id)
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS medical_aid_sale_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sale_id INTEGER NOT NULL,
            product_name TEXT NOT NULL,
            quantity INTEGER NOT NULL DEFAULT 1,
            price DECIMAL(10,2) NOT NULL,
            added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            added_by TEXT,
            FOREIGN KEY(sale_id) REFERENCES medical_aid_sales(id) ON DELETE CASCADE
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS medical_aid_payments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            patient_id INTEGER NOT NULL,
            sale_id INTEGER,
            amount DECIMAL(10,2) NOT NULL,
            payment_reference TEXT,
            scheme_name TEXT,
            recorded_by TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(patient_id) REFERENCES medical_aid_patients(id),
            FOREIGN KEY(sale_id) REFERENCES medical_aid_sales(id)
        )
    ");

    $db->exec("CREATE INDEX IF NOT EXISTS idx_ma_sales_patient ON medical_aid_sales(patient_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_ma_sales_status ON medical_aid_sales(payment_status)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_ma_sessions_patient ON medical_aid_running_sessions(patient_id, status)");

    medicalAidEnsurePaymentAllocationColumns($db);
}

function medicalAidEnsurePaymentAllocationColumns(PDO $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    try {
        $db->exec('ALTER TABLE medical_aid_payments ADD COLUMN allocated_amount DECIMAL(10,2)');
    } catch (PDOException $e) {
        // column exists
    }
    try {
        $db->exec('UPDATE medical_aid_payments SET allocated_amount = amount WHERE allocated_amount IS NULL');
    } catch (PDOException $e) {
        // non-fatal
    }
    $done = true;
}

function medicalAidRoundMoney(float $value): float
{
    return round($value, 2);
}

function medicalAidValidateSchemePaymentAmount(PDO $db, int $patientId, float $amount): array
{
    if ($amount <= 0) {
        throw new RuntimeException('Payment amount must be greater than zero.');
    }

    $outstanding = medicalAidRoundMoney(medicalAidGetPatientOutstanding($db, $patientId));
    if ($outstanding <= 0) {
        throw new RuntimeException('This patient has no outstanding claims to apply a scheme payment to.');
    }

    $amount = medicalAidRoundMoney($amount);
    if ($amount > $outstanding) {
        throw new RuntimeException(
            'Payment amount (N$ ' . number_format($amount, 2)
            . ') exceeds outstanding balance (N$ ' . number_format($outstanding, 2) . ').'
        );
    }

    return [
        'outstanding' => $outstanding,
        'amount' => $amount,
    ];
}

function medicalAidDescribePaymentResult(array $result): string
{
    $allocated = medicalAidRoundMoney((float) ($result['allocated_total'] ?? 0));
    $outstandingAfter = medicalAidRoundMoney((float) ($result['outstanding_after'] ?? 0));
    $saleCount = count($result['allocated'] ?? []);

    $message = 'Payment of N$ ' . number_format($allocated, 2) . ' recorded and applied to '
        . $saleCount . ' claim' . ($saleCount === 1 ? '' : 's') . ' (FIFO).';

    if ($outstandingAfter > 0) {
        $message .= ' Remaining outstanding: N$ ' . number_format($outstandingAfter, 2) . '.';
    } else {
        $message .= ' All claims for this patient are now settled.';
    }

    return $message;
}

function medicalAidTableExists(PDO $db, string $table): bool
{
    try {
        $stmt = $db->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function medicalAidGetPatientOutstanding(PDO $db, int $patientId): float
{
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(total_amount - paid_amount), 0)
        FROM medical_aid_sales
        WHERE patient_id = ? AND payment_status != 'paid'
    ");
    $stmt->execute([$patientId]);
    return (float) $stmt->fetchColumn();
}

function medicalAidRecalcSaleStatus(PDO $db, int $saleId): void
{
    $stmt = $db->prepare("SELECT total_amount, paid_amount FROM medical_aid_sales WHERE id = ?");
    $stmt->execute([$saleId]);
    $sale = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$sale) {
        return;
    }
    $total = (float) $sale['total_amount'];
    $paid = (float) $sale['paid_amount'];
    $status = 'unpaid';
    if ($paid >= $total && $total > 0) {
        $status = 'paid';
    } elseif ($paid > 0) {
        $status = 'partial';
    }
    $upd = $db->prepare("UPDATE medical_aid_sales SET payment_status = ? WHERE id = ?");
    $upd->execute([$status, $saleId]);
}

function medicalAidRecalcSessionBalance(PDO $db, int $sessionId): void
{
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(total_amount - paid_amount), 0)
        FROM medical_aid_sales
        WHERE session_id = ? AND payment_status != 'paid'
    ");
    $stmt->execute([$sessionId]);
    $balance = (float) $stmt->fetchColumn();
    $upd = $db->prepare("UPDATE medical_aid_running_sessions SET current_balance = ? WHERE id = ?");
    $upd->execute([$balance, $sessionId]);
}

function medicalAidGetOpenSession(PDO $db, int $patientId): ?array
{
    $stmt = $db->prepare("
        SELECT * FROM medical_aid_running_sessions
        WHERE patient_id = ? AND status = 'open'
        ORDER BY opened_at DESC
        LIMIT 1
    ");
    $stmt->execute([$patientId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function medicalAidOpenSession(PDO $db, int $patientId, string $cashierId): int
{
    $existing = medicalAidGetOpenSession($db, $patientId);
    if ($existing) {
        return (int) $existing['id'];
    }
    $stmt = $db->prepare("
        INSERT INTO medical_aid_running_sessions (patient_id, status, current_balance, cashier_id)
        VALUES (?, 'open', 0, ?)
    ");
    $stmt->execute([$patientId, $cashierId]);
    return (int) $db->lastInsertId();
}

function medicalAidCloseSession(PDO $db, int $sessionId): void
{
    medicalAidRecalcSessionBalance($db, $sessionId);
    $stmt = $db->prepare("
        UPDATE medical_aid_running_sessions
        SET status = 'closed', closed_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");
    $stmt->execute([$sessionId]);
}

function medicalAidFetchPatientsWithBalances(PDO $db): array
{
    $rows = $db->query("
        SELECT
            p.id,
            p.patient_name,
            p.phone,
            p.email,
            p.scheme_name,
            p.member_number,
            p.dependant_code,
            p.auth_reference,
            p.notes,
            p.active,
            p.created_at,
            COALESCE(SUM(CASE WHEN s.payment_status != 'paid' THEN s.total_amount - s.paid_amount ELSE 0 END), 0) AS outstanding_balance,
            COUNT(s.id) AS total_transactions,
            MAX(s.created_at) AS last_transaction_date,
            (
                SELECT rs.id FROM medical_aid_running_sessions rs
                WHERE rs.patient_id = p.id AND rs.status = 'open'
                ORDER BY rs.opened_at DESC LIMIT 1
            ) AS open_session_id,
            (
                SELECT rs.current_balance FROM medical_aid_running_sessions rs
                WHERE rs.patient_id = p.id AND rs.status = 'open'
                ORDER BY rs.opened_at DESC LIMIT 1
            ) AS session_balance
        FROM medical_aid_patients p
        LEFT JOIN medical_aid_sales s ON s.patient_id = p.id
        WHERE p.active = 1
        GROUP BY p.id
        ORDER BY p.patient_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    return $rows;
}

function medicalAidCreatePatient(PDO $db, array $data): int
{
    $stmt = $db->prepare("
        INSERT INTO medical_aid_patients
        (patient_name, phone, email, scheme_name, member_number, dependant_code, auth_reference, notes, active)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
    ");
    $stmt->execute([
        trim((string) ($data['patient_name'] ?? '')),
        trim((string) ($data['phone'] ?? '')),
        trim((string) ($data['email'] ?? '')),
        trim((string) ($data['scheme_name'] ?? '')),
        trim((string) ($data['member_number'] ?? '')),
        trim((string) ($data['dependant_code'] ?? '')),
        trim((string) ($data['auth_reference'] ?? '')),
        trim((string) ($data['notes'] ?? '')),
    ]);
    return (int) $db->lastInsertId();
}

function medicalAidUpdatePatient(PDO $db, int $id, array $data): void
{
    $stmt = $db->prepare("
        UPDATE medical_aid_patients SET
            patient_name = ?,
            phone = ?,
            email = ?,
            scheme_name = ?,
            member_number = ?,
            dependant_code = ?,
            auth_reference = ?,
            notes = ?,
            active = ?
        WHERE id = ?
    ");
    $stmt->execute([
        trim((string) ($data['patient_name'] ?? '')),
        trim((string) ($data['phone'] ?? '')),
        trim((string) ($data['email'] ?? '')),
        trim((string) ($data['scheme_name'] ?? '')),
        trim((string) ($data['member_number'] ?? '')),
        trim((string) ($data['dependant_code'] ?? '')),
        trim((string) ($data['auth_reference'] ?? '')),
        trim((string) ($data['notes'] ?? '')),
        isset($data['active']) ? (int) $data['active'] : 1,
        $id,
    ]);
}

function medicalAidGetPatient(PDO $db, int $id): ?array
{
    $stmt = $db->prepare("SELECT * FROM medical_aid_patients WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function medicalAidInsertSaleItems(PDO $db, int $saleId, array $items, string $addedBy, bool $allowNegative = false): float
{
    require_once __DIR__ . '/recipe_stock_helper.php';

    $total = 0.0;
    $stmtGetProductInfo = $db->prepare("SELECT buying_price, category FROM products WHERE name = ?");
    $itemStmt = $db->prepare("
        INSERT INTO medical_aid_sale_items (sale_id, product_name, quantity, price, added_by)
        VALUES (?, ?, ?, ?, ?)
    ");

    $stmtUpdateDailySummary = $db->prepare("
        INSERT OR REPLACE INTO daily_stock_summary
        (date, product_id, opening_quantity, closing_quantity, received_quantity, sold_quantity, damaged_quantity)
        VALUES (
            ?,
            (SELECT id FROM products WHERE name = ?),
            COALESCE((SELECT opening_quantity FROM daily_stock_summary WHERE date = ? AND product_id = (SELECT id FROM products WHERE name = ?)), 0),
            COALESCE((SELECT closing_quantity FROM daily_stock_summary WHERE date = ? AND product_id = (SELECT id FROM products WHERE name = ?)), 0),
            COALESCE((SELECT received_quantity FROM daily_stock_summary WHERE date = ? AND product_id = (SELECT id FROM products WHERE name = ?)), 0),
            COALESCE((SELECT sold_quantity FROM daily_stock_summary WHERE date = ? AND product_id = (SELECT id FROM products WHERE name = ?)), 0) + ?,
            COALESCE((SELECT damaged_quantity FROM daily_stock_summary WHERE date = ? AND product_id = (SELECT id FROM products WHERE name = ?)), 0)
        )
    ");

    $stmtEnsureDailySummary = $db->prepare("
        INSERT OR IGNORE INTO daily_stock_summary
        (date, product_id, opening_quantity, closing_quantity, received_quantity, sold_quantity, damaged_quantity)
        VALUES (?, (SELECT id FROM products WHERE name = ?), 0, 0, 0, 0, 0)
    ");

    $currentDate = date('Y-m-d');

    foreach ($items as $item) {
        $name = (string) ($item['name'] ?? '');
        $qty = (float) ($item['quantity'] ?? 1);
        $lineTotal = (float) ($item['price'] ?? 0);
        $unitPrice = $qty > 0 ? $lineTotal / $qty : $lineTotal;
        $total += $lineTotal;

        $skipStock = in_array($name, ['Cart Discount', 'Gratuity'], true);
        if (!$skipStock) {
            $stmtGetProductInfo->execute([$name]);
            $productInfo = $stmtGetProductInfo->fetch(PDO::FETCH_ASSOC);
            deductRecipeStockByProductName($db, $name, $qty, $allowNegative);
            deductProductStockByName($db, $name, $qty, $allowNegative);
            $stmtEnsureDailySummary->execute([$currentDate, $name]);
            $stmtUpdateDailySummary->execute([
                $currentDate, $name,
                $currentDate, $name,
                $currentDate, $name,
                $currentDate, $name,
                $currentDate, $name, $qty,
                $currentDate, $name,
            ]);
        }

        $itemStmt->execute([$saleId, $name, (int) max(1, round($qty)), $unitPrice, $addedBy]);
    }

    return $total;
}

function medicalAidAllocatePayment(PDO $db, int $patientId, float $amount, string $reference, string $schemeName, string $recordedBy): array
{
    $validated = medicalAidValidateSchemePaymentAmount($db, $patientId, $amount);
    $amount = $validated['amount'];
    $outstandingBefore = $validated['outstanding'];
    $remaining = $amount;
    $allocated = [];

    $stmt = $db->prepare("
        SELECT id, total_amount, paid_amount, session_id
        FROM medical_aid_sales
        WHERE patient_id = ? AND payment_status != 'paid'
        ORDER BY created_at ASC, id ASC
    ");
    $stmt->execute([$patientId]);
    $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $upd = $db->prepare('UPDATE medical_aid_sales SET paid_amount = paid_amount + ? WHERE id = ?');
    $touchedSessionIds = [];

    foreach ($sales as $sale) {
        if ($remaining <= 0.009) {
            break;
        }
        $saleId = (int) $sale['id'];
        $due = medicalAidRoundMoney((float) $sale['total_amount'] - (float) $sale['paid_amount']);
        if ($due <= 0) {
            continue;
        }
        $apply = medicalAidRoundMoney(min($remaining, $due));
        $upd->execute([$apply, $saleId]);
        medicalAidRecalcSaleStatus($db, $saleId);
        $allocated[] = ['sale_id' => $saleId, 'amount' => $apply];
        $remaining = medicalAidRoundMoney($remaining - $apply);
        if (!empty($sale['session_id'])) {
            $touchedSessionIds[(int) $sale['session_id']] = true;
        }
    }

    $allocatedTotal = medicalAidRoundMoney($amount - max(0, $remaining));
    if ($allocatedTotal <= 0.009) {
        throw new RuntimeException('Could not allocate payment to any outstanding claim.');
    }

    if ($remaining > 0.009) {
        throw new RuntimeException('Payment could not be fully allocated. Please try again.');
    }

    $payStmt = $db->prepare("
        INSERT INTO medical_aid_payments (
            patient_id, sale_id, amount, allocated_amount, payment_reference, scheme_name, recorded_by, created_at
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $payStmt->execute([
        $patientId,
        !empty($allocated) ? $allocated[0]['sale_id'] : null,
        $allocatedTotal,
        $allocatedTotal,
        $reference,
        $schemeName,
        $recordedBy,
        date('Y-m-d H:i:s'),
    ]);
    $paymentId = (int) $db->lastInsertId();

    foreach (array_keys($touchedSessionIds) as $sessionId) {
        medicalAidRecalcSessionBalance($db, $sessionId);
        $bal = $db->prepare('SELECT current_balance FROM medical_aid_running_sessions WHERE id = ?');
        $bal->execute([$sessionId]);
        if ((float) $bal->fetchColumn() <= 0.009) {
            medicalAidCloseSession($db, $sessionId);
        }
    }

    $outstandingAfter = medicalAidRoundMoney(medicalAidGetPatientOutstanding($db, $patientId));

    return [
        'payment_id' => $paymentId,
        'allocated' => $allocated,
        'allocated_total' => $allocatedTotal,
        'unallocated' => 0.0,
        'outstanding_before' => $outstandingBefore,
        'outstanding_after' => $outstandingAfter,
    ];
}

function medicalAidGetPatientSales(PDO $db, int $patientId): array
{
    $stmt = $db->prepare("
        SELECT s.*, (
            SELECT GROUP_CONCAT(product_name || ' x' || quantity, ', ')
            FROM medical_aid_sale_items WHERE sale_id = s.id
        ) AS items_summary
        FROM medical_aid_sales s
        WHERE s.patient_id = ?
        ORDER BY s.created_at DESC
    ");
    $stmt->execute([$patientId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function medicalAidGetPatientPayments(PDO $db, int $patientId): array
{
    $stmt = $db->prepare("
        SELECT * FROM medical_aid_payments
        WHERE patient_id = ?
        ORDER BY created_at DESC
    ");
    $stmt->execute([$patientId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function medicalAidCanDeletePatientFromSession(): bool
{
    return in_array(medicalAidCurrentRole(), ['admin', 'manager', 'cashier'], true);
}

function medicalAidRequiresVoidPinToDeletePatient(): bool
{
    return medicalAidCurrentRole() === 'cashier';
}

function medicalAidAssertPatientDeleteAllowed(?string $managerPin = null): void
{
    if (!medicalAidCanDeletePatientFromSession()) {
        throw new RuntimeException('You do not have permission to delete patients.');
    }
    if (!medicalAidRequiresVoidPinToDeletePatient()) {
        return;
    }
    require_once __DIR__ . '/manager_pin_helper.php';
    if (!verifyManagerVoidPin(trim((string) ($managerPin ?? '')))) {
        throw new RuntimeException(
            managerVoidPinIsConfigured()
                ? 'Invalid manager PIN.'
                : 'Manager void PIN is not set. Ask a manager to set it under Settings.'
        );
    }
}

function medicalAidPatientCanBeRemoved(PDO $db, int $patientId): bool
{
    if (medicalAidGetPatientOutstanding($db, $patientId) > 0.009) {
        return false;
    }
    return medicalAidGetOpenSession($db, $patientId) === null;
}

function medicalAidPatientHasHistory(PDO $db, int $patientId): bool
{
    foreach ([
        'SELECT COUNT(*) FROM medical_aid_sales WHERE patient_id = ?',
        'SELECT COUNT(*) FROM medical_aid_payments WHERE patient_id = ?',
        'SELECT COUNT(*) FROM medical_aid_running_sessions WHERE patient_id = ?',
    ] as $sql) {
        $stmt = $db->prepare($sql);
        $stmt->execute([$patientId]);
        if ((int) $stmt->fetchColumn() > 0) {
            return true;
        }
    }
    return false;
}

/** @return array{mode: string, patient_name: string} */
function medicalAidDeletePatient(PDO $db, int $patientId): array
{
    $patient = medicalAidGetPatient($db, $patientId);
    if (!$patient) {
        throw new RuntimeException('Patient not found.');
    }
    if (medicalAidGetPatientOutstanding($db, $patientId) > 0.009) {
        throw new RuntimeException('Cannot delete patient with outstanding balance. Record scheme payments first.');
    }
    if (medicalAidGetOpenSession($db, $patientId) !== null) {
        throw new RuntimeException('Close the open running session before deleting this patient.');
    }
    if (medicalAidPatientHasHistory($db, $patientId)) {
        $stmt = $db->prepare('UPDATE medical_aid_patients SET active = 0 WHERE id = ?');
        $stmt->execute([$patientId]);
        return ['mode' => 'deactivated', 'patient_name' => (string) $patient['patient_name']];
    }
    $stmt = $db->prepare('DELETE FROM medical_aid_patients WHERE id = ?');
    $stmt->execute([$patientId]);
    return ['mode' => 'deleted', 'patient_name' => (string) $patient['patient_name']];
}
