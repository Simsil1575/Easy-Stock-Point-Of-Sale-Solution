<?php
/**
 * Persistent display-only overrides for report amounts (Reports Center PDF).
 */
declare(strict_types=1);

function raoEnsureSchema(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS report_amount_overrides (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            report_scope TEXT NOT NULL,
            report_type TEXT NOT NULL,
            start_date TEXT NOT NULL,
            end_date TEXT NOT NULL,
            cashier_id TEXT NOT NULL DEFAULT '',
            terminal_mac TEXT NOT NULL DEFAULT '',
            creditor_id TEXT NOT NULL DEFAULT '',
            category TEXT NOT NULL DEFAULT '',
            supplier_id TEXT NOT NULL DEFAULT '',
            cell_key TEXT NOT NULL,
            original_amount REAL NOT NULL,
            adjusted_amount REAL NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_by INTEGER,
            updated_by INTEGER,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            updated_at TEXT NOT NULL DEFAULT (datetime('now'))
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS report_amount_override_audit (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            override_id INTEGER,
            report_scope TEXT NOT NULL,
            cell_key TEXT NOT NULL,
            action TEXT NOT NULL,
            old_amount REAL,
            new_amount REAL,
            user_id INTEGER,
            username TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )
    ");

    $db->exec("
        CREATE INDEX IF NOT EXISTS idx_report_amount_overrides_scope_active
        ON report_amount_overrides (report_scope, is_active)
    ");
}

function raoBuildReportScope(
    string $reportType,
    string $startDateTime,
    string $endDateTime,
    string $cashierId = '',
    string $terminalMac = '',
    string $creditorId = '',
    string $category = '',
    string $supplierId = ''
): string {
    return implode('|', [
        $reportType,
        $startDateTime,
        $endDateTime,
        $cashierId,
        $terminalMac,
        $creditorId,
        $category,
        $supplierId,
    ]);
}

function raoLoadActiveOverrides(PDO $db, string $reportScope): array
{
    raoEnsureSchema($db);

    $stmt = $db->prepare("
        SELECT id, cell_key, original_amount, adjusted_amount, updated_at
        FROM report_amount_overrides
        WHERE report_scope = :scope AND is_active = 1
        ORDER BY id ASC
    ");
    $stmt->execute([':scope' => $reportScope]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $map = [];
    foreach ($rows as $row) {
        $map[$row['cell_key']] = [
            'id' => (int) $row['id'],
            'original_amount' => (float) $row['original_amount'],
            'adjusted_amount' => (float) $row['adjusted_amount'],
            'updated_at' => $row['updated_at'],
        ];
    }

    return $map;
}

function raoWriteAudit(
    PDO $db,
    ?int $overrideId,
    string $reportScope,
    string $cellKey,
    string $action,
    ?float $oldAmount,
    ?float $newAmount,
    ?int $userId,
    ?string $username
): void {
    $stmt = $db->prepare("
        INSERT INTO report_amount_override_audit
            (override_id, report_scope, cell_key, action, old_amount, new_amount, user_id, username)
        VALUES
            (:override_id, :report_scope, :cell_key, :action, :old_amount, :new_amount, :user_id, :username)
    ");
    $stmt->execute([
        ':override_id' => $overrideId,
        ':report_scope' => $reportScope,
        ':cell_key' => $cellKey,
        ':action' => $action,
        ':old_amount' => $oldAmount,
        ':new_amount' => $newAmount,
        ':user_id' => $userId,
        ':username' => $username,
    ]);
}

function raoSaveOverride(PDO $db, array $params, int $userId, string $username): array
{
    raoEnsureSchema($db);

    $reportScope = (string) ($params['report_scope'] ?? '');
    $cellKey = trim((string) ($params['cell_key'] ?? ''));
    $adjustedAmount = (float) ($params['adjusted_amount'] ?? 0);
    $originalAmount = (float) ($params['original_amount'] ?? 0);

    if ($reportScope === '' || $cellKey === '') {
        throw new InvalidArgumentException('Report scope and cell key are required.');
    }

    $context = [
        ':report_scope' => $reportScope,
        ':report_type' => (string) ($params['report_type'] ?? ''),
        ':start_date' => (string) ($params['start_date'] ?? ''),
        ':end_date' => (string) ($params['end_date'] ?? ''),
        ':cashier_id' => (string) ($params['cashier_id'] ?? ''),
        ':terminal_mac' => (string) ($params['terminal_mac'] ?? ''),
        ':creditor_id' => (string) ($params['creditor_id'] ?? ''),
        ':category' => (string) ($params['category'] ?? ''),
        ':supplier_id' => (string) ($params['supplier_id'] ?? ''),
        ':cell_key' => $cellKey,
        ':original_amount' => $originalAmount,
        ':adjusted_amount' => $adjustedAmount,
        ':user_id' => $userId,
    ];

    $existingStmt = $db->prepare("
        SELECT id, adjusted_amount
        FROM report_amount_overrides
        WHERE report_scope = :report_scope AND cell_key = :cell_key AND is_active = 1
        LIMIT 1
    ");
    $existingStmt->execute([
        ':report_scope' => $reportScope,
        ':cell_key' => $cellKey,
    ]);
    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

    $db->beginTransaction();
    try {
        if ($existing) {
            $overrideId = (int) $existing['id'];
            $oldAmount = (float) $existing['adjusted_amount'];
            $update = $db->prepare("
                UPDATE report_amount_overrides
                SET adjusted_amount = :adjusted_amount,
                    original_amount = :original_amount,
                    updated_by = :user_id,
                    updated_at = datetime('now')
                WHERE id = :id
            ");
            $update->execute([
                ':adjusted_amount' => $adjustedAmount,
                ':original_amount' => $originalAmount,
                ':user_id' => $userId,
                ':id' => $overrideId,
            ]);
            raoWriteAudit($db, $overrideId, $reportScope, $cellKey, 'update', $oldAmount, $adjustedAmount, $userId, $username);
        } else {
            $insert = $db->prepare("
                INSERT INTO report_amount_overrides (
                    report_scope, report_type, start_date, end_date,
                    cashier_id, terminal_mac, creditor_id, category, supplier_id,
                    cell_key, original_amount, adjusted_amount, created_by, updated_by
                ) VALUES (
                    :report_scope, :report_type, :start_date, :end_date,
                    :cashier_id, :terminal_mac, :creditor_id, :category, :supplier_id,
                    :cell_key, :original_amount, :adjusted_amount, :user_id, :user_id
                )
            ");
            $insert->execute($context);
            $overrideId = (int) $db->lastInsertId();
            raoWriteAudit($db, $overrideId, $reportScope, $cellKey, 'create', $originalAmount, $adjustedAmount, $userId, $username);
        }

        $db->commit();

        return [
            'id' => $overrideId,
            'cell_key' => $cellKey,
            'original_amount' => $originalAmount,
            'adjusted_amount' => $adjustedAmount,
        ];
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function raoRevertOverride(PDO $db, array $params, int $userId, string $username): array
{
    raoEnsureSchema($db);

    $overrideId = (int) ($params['override_id'] ?? 0);
    $reportScope = (string) ($params['report_scope'] ?? '');
    $cellKey = trim((string) ($params['cell_key'] ?? ''));

    if ($overrideId <= 0 && ($reportScope === '' || $cellKey === '')) {
        throw new InvalidArgumentException('Override id or report scope + cell key are required.');
    }

    if ($overrideId > 0) {
        $stmt = $db->prepare("
            SELECT id, report_scope, cell_key, original_amount, adjusted_amount
            FROM report_amount_overrides
            WHERE id = :id AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([':id' => $overrideId]);
    } else {
        $stmt = $db->prepare("
            SELECT id, report_scope, cell_key, original_amount, adjusted_amount
            FROM report_amount_overrides
            WHERE report_scope = :report_scope AND cell_key = :cell_key AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([
            ':report_scope' => $reportScope,
            ':cell_key' => $cellKey,
        ]);
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Override not found or already reverted.');
    }

    $db->beginTransaction();
    try {
        $deactivate = $db->prepare("
            UPDATE report_amount_overrides
            SET is_active = 0,
                updated_by = :user_id,
                updated_at = datetime('now')
            WHERE id = :id
        ");
        $deactivate->execute([
            ':user_id' => $userId,
            ':id' => (int) $row['id'],
        ]);

        raoWriteAudit(
            $db,
            (int) $row['id'],
            (string) $row['report_scope'],
            (string) $row['cell_key'],
            'revert',
            (float) $row['adjusted_amount'],
            (float) $row['original_amount'],
            $userId,
            $username
        );

        $db->commit();

        return [
            'id' => (int) $row['id'],
            'cell_key' => (string) $row['cell_key'],
            'original_amount' => (float) $row['original_amount'],
        ];
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}
