<?php
/**
 * Tab prepaid balance (advance payments) + single source of truth for current_balance.
 * current_balance = opening_balance + (sum of tab line totals − payments allocated to lines) − prepaid_balance + unpaid gratuity
 */
require_once __DIR__ . '/ensure_tab_gratuity_columns.php';
require_once __DIR__ . '/recipe_stock_helper.php';

/** Reserved tab line name: stored as qty 1 × negative unit price (credit). Not a catalog product. */
if (!defined('TAB_PREPAYMENT_LINE_NAME')) {
    define('TAB_PREPAYMENT_LINE_NAME', 'Tab Prepayment');
}

function is_tab_prepayment_line_name($name) {
    return trim((string) $name) === TAB_PREPAYMENT_LINE_NAME;
}

/** Reserved tab line: qty × positive unit price (charge) — not a catalog product; no stock movement. */
if (!defined('TAB_POSTPAID_LINE_NAME')) {
    define('TAB_POSTPAID_LINE_NAME', 'Tab Postpaid Charge');
}

function is_tab_postpaid_line_name($name) {
    return trim((string) $name) === TAB_POSTPAID_LINE_NAME;
}

/** Prepayment credit or postpaid service charge lines — never touch inventory. */
function is_tab_legacy_gratuity_line_name($name) {
    return trim((string) $name) === 'Gratuity';
}

function is_tab_non_inventory_tab_line_name($name) {
    $n = trim((string) $name);
    return is_tab_prepayment_line_name($n) || is_tab_postpaid_line_name($n) || $n === 'Cart Discount' || is_tab_legacy_gratuity_line_name($n);
}

function tab_gratuity_settings(PDO $db): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $percent = 0.0;
    $defaultEnabled = 0;
    try {
        $row = $db->query('SELECT gratuity_percent, gratuity_default_enabled FROM product_settings LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
        $percent = round(floatval($row['gratuity_percent'] ?? 0), 2);
        if ($percent < 0) {
            $percent = 0.0;
        }
        if ($percent > 100) {
            $percent = 100.0;
        }
        $defaultEnabled = (int) ($row['gratuity_default_enabled'] ?? 1);
    } catch (PDOException $e) {
    }
    $cache = [
        'percent' => $percent,
        'default_enabled' => $defaultEnabled,
        'feature_enabled' => $percent > 0,
    ];
    return $cache;
}

function tab_default_gratuity_enabled_on_create(PDO $db): int
{
    $settings = tab_gratuity_settings($db);
    return ($settings['feature_enabled'] && (int) $settings['default_enabled'] === 1) ? 1 : 0;
}

function tab_is_gratuity_enabled_for_tab(array $tab): bool
{
    return (int) ($tab['gratuity_enabled'] ?? 0) === 1;
}

/** Subtotal for gratuity % — payable tab lines only (excludes prepayment credits, legacy gratuity, void-pending). */
function tab_gratuity_base_subtotal(PDO $db, int $tabId): float
{
    ensureTabItemVoidMarkColumns($db);
    $stmt = $db->prepare('SELECT product_name, quantity, price, COALESCE(marked_for_void, 0) AS marked_for_void FROM tab_items WHERE tab_id = ?');
    $stmt->execute([$tabId]);
    $subtotal = 0.0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $line) {
        if ((int) ($line['marked_for_void'] ?? 0) === 1) {
            continue;
        }
        $name = $line['product_name'] ?? '';
        if (is_tab_prepayment_line_name($name) || is_tab_legacy_gratuity_line_name($name)) {
            continue;
        }
        $subtotal += floatval($line['quantity']) * floatval($line['price']);
    }
    return round(max(0.0, $subtotal), 2);
}

function tab_compute_gratuity_amount(PDO $db, int $tabId, ?array $tabRow = null): float
{
    ensureTabGratuityColumns($db);
    if ($tabRow === null) {
        $tabStmt = $db->prepare('SELECT gratuity_enabled FROM tabs WHERE id = ?');
        $tabStmt->execute([$tabId]);
        $tabRow = $tabStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
    if (!tab_is_gratuity_enabled_for_tab($tabRow)) {
        return 0.0;
    }
    $settings = tab_gratuity_settings($db);
    if (!$settings['feature_enabled']) {
        return 0.0;
    }
    $base = tab_gratuity_base_subtotal($db, $tabId);
    if ($base <= 0.0001) {
        return 0.0;
    }
    return round($base * ($settings['percent'] / 100), 2);
}

function tab_gratuity_remaining(PDO $db, int $tabId, ?array $tabRow = null): float
{
    ensureTabGratuityColumns($db);
    if ($tabRow === null) {
        $tabStmt = $db->prepare('SELECT gratuity_enabled, gratuity_paid FROM tabs WHERE id = ?');
        $tabStmt->execute([$tabId]);
        $tabRow = $tabStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
    $computed = tab_compute_gratuity_amount($db, $tabId, $tabRow);
    $paid = round(floatval($tabRow['gratuity_paid'] ?? 0), 2);
    if ($computed > 0.001) {
        $paid = min($paid, $computed);
    }
    return round(max(0.0, $computed - $paid), 2);
}

/**
 * Clear stale gratuity_paid when it exceeds the gratuity now due on current tab lines.
 * When $resetStaleOverpay is true, overpaid gratuity is zeroed so re-enabled gratuity shows on balance.
 */
function tab_normalize_gratuity_paid(PDO $db, int $tabId, ?array $tabRow = null, bool $resetStaleOverpay = false): void
{
    ensureTabGratuityColumns($db);
    if ($tabRow === null) {
        $tabStmt = $db->prepare('SELECT gratuity_enabled, gratuity_paid FROM tabs WHERE id = ?');
        $tabStmt->execute([$tabId]);
        $tabRow = $tabStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
    if (!tab_is_gratuity_enabled_for_tab($tabRow)) {
        return;
    }
    $computed = tab_compute_gratuity_amount($db, $tabId, $tabRow);
    $paid = round(floatval($tabRow['gratuity_paid'] ?? 0), 2);
    if ($computed <= 0.001) {
        if ($paid > 0.001) {
            $db->prepare('UPDATE tabs SET gratuity_paid = 0 WHERE id = ?')->execute([$tabId]);
        }
        return;
    }
    if ($paid > $computed + 0.001) {
        $newPaid = $resetStaleOverpay ? 0.0 : round($computed, 2);
        $db->prepare('UPDATE tabs SET gratuity_paid = ? WHERE id = ?')->execute([$newPaid, $tabId]);
    }
}

/** Toggle gratuity on/off for an open tab and recalculate current_balance. */
function tab_set_gratuity_enabled_on_tab(PDO $db, int $tabId, bool $enabled): float
{
    ensureTabGratuityColumns($db);
    $stmt = $db->prepare('SELECT gratuity_enabled, gratuity_paid FROM tabs WHERE id = ?');
    $stmt->execute([$tabId]);
    $prev = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $wasEnabled = tab_is_gratuity_enabled_for_tab($prev);

    $db->prepare('UPDATE tabs SET gratuity_enabled = ? WHERE id = ?')->execute([$enabled ? 1 : 0, $tabId]);

    if ($enabled && !$wasEnabled) {
        tab_normalize_gratuity_paid($db, $tabId, ['gratuity_enabled' => 1, 'gratuity_paid' => $prev['gratuity_paid'] ?? 0], true);
    }

    return recalculateTabBalance($db, $tabId);
}

/** Cashier, manager, or admin may add Tab Prepayment / Tab Postpaid Charge lines on view-tab. */
function can_add_tab_prepay_postpaid_lines_from_session() {
    $r = strtolower(trim((string)($_SESSION['role'] ?? '')));
    return in_array($r, ['cashier', 'manager', 'admin'], true);
}

/** Only manager or admin may remove lines from an open tab without a manager void PIN. */
function can_delete_tab_items_from_session() {
    $r = strtolower(trim((string) ($_SESSION['role'] ?? '')));
    return in_array($r, ['manager', 'admin'], true);
}

/** Cashier, hubbly, and waitress cannot edit quantities on tab lines (manager/admin may). */
function can_edit_tab_item_quantities_from_session(): bool
{
    $r = strtolower(trim((string) ($_SESSION['role'] ?? '')));
    return !in_array($r, ['cashier', 'hubbly', 'waitress'], true);
}

/** Cashier or waitress may remove tab lines when a valid manager void PIN is supplied. */
function requires_manager_void_pin_to_delete_tab_items_from_session(): bool
{
    $r = strtolower(trim((string) ($_SESSION['role'] ?? '')));
    return in_array($r, ['cashier', 'waitress'], true);
}

/** Whether the current user may remove a line from view-tab (directly or with PIN). */
function can_remove_tab_items_from_session(): bool
{
    return can_delete_tab_items_from_session() || requires_manager_void_pin_to_delete_tab_items_from_session();
}

/** Enforce delete permission; verify manager PIN when required. Exits on failure. */
function assert_tab_item_delete_allowed(int $tabId, ?string $managerPin = null): void
{
    if (!can_remove_tab_items_from_session()) {
        $_SESSION['error'] = 'You do not have permission to remove items from a tab. Ask a manager.';
        header('Location: view-tab.php?id=' . $tabId);
        exit();
    }
    if (!requires_manager_void_pin_to_delete_tab_items_from_session()) {
        return;
    }
    require_once __DIR__ . '/manager_pin_helper.php';
    if (!verifyManagerVoidPin(trim((string) ($managerPin ?? '')))) {
        $_SESSION['error'] = managerVoidPinIsConfigured()
            ? 'Invalid manager PIN.'
            : 'Manager void PIN is not set. Ask a manager to set it under Settings.';
        header('Location: view-tab.php?id=' . $tabId);
        exit();
    }
}

function ensure_waitress_can_take_tab_payments_column(PDO $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    try {
        $db->exec('ALTER TABLE product_settings ADD COLUMN waitress_can_take_tab_payments BOOLEAN NOT NULL DEFAULT 0');
    } catch (PDOException $e) {
        // Column already exists
    }
    $done = true;
}

/** When enabled in Admin → Business Settings, waitresses may use Pay on view-tab. */
function waitress_can_take_tab_payments(PDO $db): bool
{
    ensure_waitress_can_take_tab_payments_column($db);
    try {
        $row = $db->query('SELECT waitress_can_take_tab_payments FROM product_settings LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        return (int) ($row['waitress_can_take_tab_payments'] ?? 0) === 1;
    } catch (Exception $e) {
        return false;
    }
}

function ensureTabPrepaidBalanceColumn(PDO $db) {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $db->exec("ALTER TABLE tabs ADD COLUMN prepaid_balance DECIMAL(10,2) NOT NULL DEFAULT 0");
    } catch (PDOException $e) {
        // Column already exists
    }
}

function ensureTabVoidMarkColumns(PDO $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $db->exec('ALTER TABLE tabs ADD COLUMN marked_for_void INTEGER NOT NULL DEFAULT 0');
    } catch (PDOException $e) {
        // Column already exists
    }
    try {
        $db->exec('ALTER TABLE tabs ADD COLUMN void_marked_by TEXT');
    } catch (PDOException $e) {
        // Column already exists
    }
    try {
        $db->exec('ALTER TABLE tabs ADD COLUMN void_marked_at DATETIME');
    } catch (PDOException $e) {
        // Column already exists
    }
}

function ensureTabItemVoidMarkColumns(PDO $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $db->exec('ALTER TABLE tab_items ADD COLUMN marked_for_void INTEGER NOT NULL DEFAULT 0');
    } catch (PDOException $e) {
        // Column already exists
    }
    try {
        $db->exec('ALTER TABLE tab_items ADD COLUMN void_marked_by TEXT');
    } catch (PDOException $e) {
        // Column already exists
    }
    try {
        $db->exec('ALTER TABLE tab_items ADD COLUMN void_marked_at DATETIME');
    } catch (PDOException $e) {
        // Column already exists
    }
}

/**
 * Whether the current session user owns a tab (tabs.cashier_id stores username, or older numeric user id).
 * Unclaimed tabs (empty cashier_id) are treated as selectable by anyone.
 */
function session_owns_tab(?array $tab): bool
{
    if (!$tab) {
        return false;
    }
    $owner = trim((string) ($tab['cashier_id'] ?? ''));
    if ($owner === '') {
        return true;
    }
    $username = trim((string) ($_SESSION['username'] ?? ''));
    $userId = trim((string) ($_SESSION['user_id'] ?? ''));
    if ($username !== '' && strcasecmp($owner, $username) === 0) {
        return true;
    }
    if ($userId !== '' && (string) $owner === $userId) {
        return true;
    }
    return false;
}

/** Admin and manager may view or manage any tab. */
function session_role_can_view_all_tabs(): bool
{
    $r = strtolower(trim((string) ($_SESSION['role'] ?? '')));

    return in_array($r, ['admin', 'manager'], true);
}

/** Username and user id values that identify tabs owned by the current session user. */
function tab_owner_match_values_for_session(): array
{
    return array_values(array_unique(array_filter(
        [trim((string) ($_SESSION['username'] ?? '')), trim((string) ($_SESSION['user_id'] ?? ''))],
        static function ($value) {
            return $value !== '';
        }
    )));
}

/** Whether the current user may view or act on this tab. */
function session_can_view_tab(?array $tab): bool
{
    if (!$tab) {
        return false;
    }
    if (session_role_can_view_all_tabs()) {
        return true;
    }

    return session_owns_tab($tab);
}

/**
 * SQL filter for tab list queries. Admins/managers see all tabs; others see only their own.
 *
 * @return array{sql: string, params: array<int, string>}
 */
function tab_list_owner_filter_for_session(string $column = 't.cashier_id'): array
{
    if (session_role_can_view_all_tabs()) {
        return ['sql' => '1=1', 'params' => []];
    }

    $owners = tab_owner_match_values_for_session();
    if (empty($owners)) {
        return ['sql' => '0=1', 'params' => []];
    }

    $placeholders = implode(', ', array_fill(0, count($owners), '?'));

    return ['sql' => "$column IN ($placeholders)", 'params' => $owners];
}

/**
 * @return array<string, mixed>
 */
function tab_require_session_access(PDO $db, int $tabId, string $redirect = 'credit-tabs'): array
{
    $stmt = $db->prepare('SELECT * FROM tabs WHERE id = ?');
    $stmt->execute([$tabId]);
    $tab = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tab || !session_can_view_tab($tab)) {
        $_SESSION['error'] = 'You do not have access to this tab';
        header('Location: ' . $redirect);
        exit();
    }

    return $tab;
}

/** Block POST actions on tabs the current user does not own (except admin/manager). */
function tab_enforce_session_access_on_post(PDO $db): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || session_role_can_view_all_tabs()) {
        return;
    }

    $tabId = 0;
    if (!empty($_POST['tab_id'])) {
        $tabId = (int) $_POST['tab_id'];
    } elseif (!empty($_POST['void_tab_id'])) {
        $tabId = (int) $_POST['void_tab_id'];
    } elseif (!empty($_POST['delete_id'])) {
        $tabId = (int) $_POST['delete_id'];
    } elseif (!empty($_POST['close_id'])) {
        $tabId = (int) $_POST['close_id'];
    } elseif (!empty($_POST['reopen_id'])) {
        $tabId = (int) $_POST['reopen_id'];
    } elseif (!empty($_POST['delete_item_id']) || !empty($_POST['edit_item_id'])) {
        $itemId = (int) ($_POST['delete_item_id'] ?? $_POST['edit_item_id'] ?? 0);
        if ($itemId > 0) {
            $itemStmt = $db->prepare('SELECT tab_id FROM tab_items WHERE id = ?');
            $itemStmt->execute([$itemId]);
            $tabId = (int) ($itemStmt->fetchColumn() ?: 0);
        }
    } elseif (!empty($_POST['id']) && (isset($_POST['tab_name']) || isset($_POST['edit_tab_name']))) {
        $tabId = (int) $_POST['id'];
    }

    if ($tabId > 0) {
        tab_require_session_access($db, $tabId);
    }
}

/** Cashier, hubbly, waitress, or manager may request that an entire tab be voided (admin/manager perform the actual void). */
function can_mark_tab_for_void_from_session(): bool
{
    $r = strtolower(trim((string) ($_SESSION['role'] ?? '')));
    return in_array($r, ['cashier', 'hubbly', 'waitress', 'manager'], true);
}

/** Hubbly, waitress, or cashier may mark individual tab lines for void review. */
function can_mark_tab_item_for_void_from_session(): bool
{
    $r = strtolower(trim((string) ($_SESSION['role'] ?? '')));
    return in_array($r, ['hubbly', 'waitress', 'cashier'], true);
}

/** Admin and manager may approve or clear item void requests. */
function can_approve_tab_item_void_from_session(): bool
{
    $r = strtolower(trim((string) ($_SESSION['role'] ?? '')));
    return in_array($r, ['admin', 'manager'], true);
}

/** Admin and manager see void-pending tabs highlighted in credit-tabs lists. */
function can_view_tab_void_mark_in_list_from_session(): bool
{
    $r = strtolower(trim((string) ($_SESSION['role'] ?? '')));
    return in_array($r, ['admin', 'manager'], true);
}

/** Admin and manager may permanently void an entire tab. */
function can_void_entire_tab_from_session(): bool
{
    $r = strtolower(trim((string) ($_SESSION['role'] ?? '')));
    return in_array($r, ['admin', 'manager'], true);
}

/** Whether a void POST should put items back into inventory. Defaults to yes. */
function tab_void_restore_stock_from_post(): bool
{
    if (!isset($_POST['restore_stock'])) {
        return true;
    }
    $v = strtolower(trim((string) $_POST['restore_stock']));

    return !in_array($v, ['0', 'false', 'no', 'off'], true);
}

function tab_is_marked_for_void(array $tab): bool
{
    return (int) ($tab['marked_for_void'] ?? 0) === 1;
}

function tab_has_void_pending_in_list(array $tab): bool
{
    return tab_is_marked_for_void($tab) || ((int) ($tab['items_marked_for_void_count'] ?? 0) > 0);
}

/** Cashier, hubbly, and waitress cannot delete tabs that still have void-pending marks. */
function tab_roles_blocked_from_deleting_void_pending_from_session(): bool
{
    $r = strtolower(trim((string) ($_SESSION['role'] ?? '')));

    return in_array($r, ['cashier', 'hubbly', 'waitress'], true);
}

/**
 * @return array<string, mixed>|null
 */
function tab_fetch_for_delete_check(PDO $db, int $tabId): ?array
{
    ensureTabVoidMarkColumns($db);
    ensureTabItemVoidMarkColumns($db);
    $stmt = $db->prepare("
        SELECT
            t.id,
            t.current_balance,
            t.status,
            t.cashier_id,
            t.marked_for_void,
            (SELECT COUNT(*) FROM tab_items ti WHERE ti.tab_id = t.id AND ti.marked_for_void = 1) AS items_marked_for_void_count
        FROM tabs t
        WHERE t.id = ?
    ");
    $stmt->execute([$tabId]);
    $tab = $stmt->fetch(PDO::FETCH_ASSOC);

    return $tab ?: null;
}

function tab_delete_blocked_reason(array $tab): ?string
{
    if ((float) ($tab['current_balance'] ?? 0) > 0) {
        return 'Cannot delete tab with outstanding balance. Please close it first.';
    }
    if (tab_roles_blocked_from_deleting_void_pending_from_session() && tab_has_void_pending_in_list($tab)) {
        return 'Cannot delete tab with items marked for void. Wait for a manager to approve or clear the void request.';
    }

    return null;
}

function tab_can_show_delete_action(array $tab): bool
{
    return tab_delete_blocked_reason($tab) === null;
}

function tab_item_is_marked_for_void(array $item): bool
{
    return (int) ($item['marked_for_void'] ?? 0) === 1;
}

function tab_is_open(array $tab): bool
{
    return strtolower((string) ($tab['status'] ?? 'open')) === 'open';
}

/** Show edit/delete/void controls — also when a closed tab still has void-pending lines for manager/admin review. */
function tab_show_item_row_actions(PDO $db, array $tab): bool
{
    if (tab_is_open($tab)) {
        return true;
    }

    return can_approve_tab_item_void_from_session()
        && tab_has_items_marked_for_void($db, (int) ($tab['id'] ?? 0));
}

function tab_item_table_colspan(PDO $db, array $tab): int
{
    return tab_show_item_row_actions($db, $tab) ? 5 : 4;
}

function tab_has_unresolved_void_pending(PDO $db, int $tabId, ?array $tabRow = null): bool
{
    ensureTabVoidMarkColumns($db);
    if ($tabRow === null) {
        $stmt = $db->prepare('SELECT marked_for_void FROM tabs WHERE id = ?');
        $stmt->execute([$tabId]);
        $tabRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    return tab_is_marked_for_void($tabRow) || tab_has_items_marked_for_void($db, $tabId);
}

function tab_count_items_marked_for_void(PDO $db, int $tabId): int
{
    ensureTabItemVoidMarkColumns($db);
    $stmt = $db->prepare('SELECT COUNT(*) FROM tab_items WHERE tab_id = ? AND marked_for_void = 1');
    $stmt->execute([$tabId]);

    return (int) $stmt->fetchColumn();
}

function tab_has_items_marked_for_void(PDO $db, int $tabId): bool
{
    return tab_count_items_marked_for_void($db, $tabId) > 0;
}

/**
 * Unpaid tab lines that count toward balance and can receive payment (excludes void-pending).
 *
 * @return array<int, array<string, mixed>>
 */
function tab_fetch_unpaid_payable_items(PDO $db, int $tabId, bool $orderFifo = false): array
{
    ensureTabItemVoidMarkColumns($db);
    $sql = "
        SELECT ti.*,
               (ti.quantity * ti.price) AS item_total,
               COALESCE((SELECT SUM(amount) FROM tab_item_payments WHERE tab_item_id = ti.id), 0) AS paid_amount
        FROM tab_items ti
        WHERE ti.tab_id = ?
            AND COALESCE(ti.marked_for_void, 0) = 0
            AND (
                (ti.quantity * ti.price) < 0
                OR COALESCE((SELECT SUM(amount) FROM tab_item_payments WHERE tab_item_id = ti.id), 0) < (ti.quantity * ti.price)
            )";
    if ($orderFifo) {
        $sql .= ' ORDER BY ti.added_at ASC';
    }
    $stmt = $db->prepare($sql);
    $stmt->execute([$tabId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Remove a tab line (used for approved item voids). Stock restore is optional.
 */
function void_tab_item_remove_from_tab(PDO $db, int $itemId, bool $allowClosedTab = false, bool $restoreStock = true): array
{
    ensureTabItemVoidMarkColumns($db);

    $itemStmt = $db->prepare('SELECT id, tab_id, quantity, product_name, COALESCE(marked_for_void, 0) AS marked_for_void FROM tab_items WHERE id = ?');
    $itemStmt->execute([$itemId]);
    $item = $itemStmt->fetch(PDO::FETCH_ASSOC);
    if (!$item) {
        return ['ok' => false, 'error' => 'Item not found', 'tab_id' => 0];
    }

    $tabId = (int) $item['tab_id'];

    $tabStmt = $db->prepare('SELECT status FROM tabs WHERE id = ?');
    $tabStmt->execute([$tabId]);
    $tab = $tabStmt->fetch(PDO::FETCH_ASSOC);
    if (!$tab) {
        return ['ok' => false, 'error' => 'Tab not found', 'tab_id' => $tabId];
    }
    if (!$allowClosedTab && !tab_is_open($tab)) {
        return ['ok' => false, 'error' => 'Item can only be voided on an open tab', 'tab_id' => $tabId];
    }
    if ($allowClosedTab && !tab_is_open($tab) && !tab_item_is_marked_for_void($item)) {
        return ['ok' => false, 'error' => 'Only void-pending items can be removed from a closed tab', 'tab_id' => $tabId];
    }

    $db->beginTransaction();
    try {
        if ($restoreStock && !tab_product_skips_inventory($item['product_name'])) {
            restoreSaleLineStock($db, (string) $item['product_name'], floatval($item['quantity']));
        }

        $deletePaymentsStmt = $db->prepare('DELETE FROM tab_item_payments WHERE tab_item_id = ?');
        $deletePaymentsStmt->execute([$itemId]);

        $deleteStmt = $db->prepare('DELETE FROM tab_items WHERE id = ?');
        $deleteStmt->execute([$itemId]);

        recalculateTabBalance($db, $tabId);

        $db->commit();

        return ['ok' => true, 'tab_id' => $tabId, 'product_name' => $item['product_name']];
    } catch (Exception $e) {
        $db->rollBack();

        return ['ok' => false, 'error' => $e->getMessage(), 'tab_id' => $tabId];
    }
}

function tab_product_skips_inventory(string $productName): bool
{
    return is_tab_non_inventory_tab_line_name($productName)
        || trim($productName) === 'EFT Income'
        || trim($productName) === 'Lay-bye Payment';
}

/**
 * @return array{id: int, quantity: float, category: string}|null
 */
function tab_product_inventory_row(PDO $db, string $productName): ?array
{
    if (tab_product_skips_inventory($productName)) {
        return null;
    }
    $stmt = $db->prepare('SELECT id, quantity, category FROM products WHERE name = ? LIMIT 1');
    $stmt->execute([$productName]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function tab_update_daily_sold_summary(PDO $db, string $productName, float $soldQtyDelta): void
{
    if (abs($soldQtyDelta) < 0.0001) {
        return;
    }
    $resolveProductStmt = $db->prepare('SELECT id FROM products WHERE name = ? LIMIT 1');
    $resolveProductStmt->execute([$productName]);
    if (!$resolveProductStmt->fetchColumn()) {
        return;
    }
    $currentDate = date('Y-m-d');
    $stmtEnsureDailySummary = $db->prepare('
        INSERT OR IGNORE INTO daily_stock_summary
        (date, product_id, opening_quantity, closing_quantity, received_quantity, sold_quantity, damaged_quantity)
        VALUES (?, (SELECT id FROM products WHERE name = ?), 0, 0, 0, 0, 0)
    ');
    $stmtEnsureDailySummary->execute([$currentDate, $productName]);
    if ($soldQtyDelta > 0) {
        $stmtUpdateDailySummary = $db->prepare('
            UPDATE daily_stock_summary
            SET sold_quantity = sold_quantity + ?
            WHERE date = ? AND product_id = (SELECT id FROM products WHERE name = ?)
        ');
        $stmtUpdateDailySummary->execute([$soldQtyDelta, $currentDate, $productName]);
        return;
    }
    $restoreQty = abs($soldQtyDelta);
    $stmtUpdateDailySummary = $db->prepare('
        UPDATE daily_stock_summary
        SET sold_quantity = CASE
            WHEN sold_quantity - ? < 0 THEN 0
            ELSE sold_quantity - ?
        END
        WHERE date = ? AND product_id = (SELECT id FROM products WHERE name = ?)
    ');
    $stmtUpdateDailySummary->execute([$restoreQty, $restoreQty, $currentDate, $productName]);
}

/**
 * Enforce stock when tab line qty increases (same rules as process_tab.php / lay-bye adds).
 *
 * @throws Exception
 */
function tab_assert_stock_for_quantity_increase(PDO $db, string $productName, int $additionalQty): void
{
    if ($additionalQty <= 0) {
        return;
    }
    require_once __DIR__ . '/recipe_stock_helper.php';
    if (isSkipStockChecks($db)) {
        return;
    }
    $productInfo = tab_product_inventory_row($db, $productName);
    if (!$productInfo) {
        return;
    }
    laybyeAssertStockForAddItem($db, $productName, $additionalQty, $productInfo, false);
}

/**
 * Adjust inventory when a tab line quantity changes (deduct on increase, restore on decrease).
 *
 * @throws Exception
 */
function tab_apply_stock_for_item_quantity_change(PDO $db, string $productName, int $oldQty, int $newQty): void
{
    if ($oldQty === $newQty || tab_product_skips_inventory($productName)) {
        return;
    }

    require_once __DIR__ . '/recipe_stock_helper.php';
    $delta = $newQty - $oldQty;
    if ($delta === 0) {
        return;
    }

    $allowNegative = isSkipStockChecks($db);
    $productInfo = tab_product_inventory_row($db, $productName);

    if ($delta > 0) {
        tab_assert_stock_for_quantity_increase($db, $productName, $delta);
        deductRecipeStockByProductName($db, $productName, floatval($delta), $allowNegative);
        deductProductStockByName($db, $productName, floatval($delta), $allowNegative);
        tab_update_daily_sold_summary($db, $productName, floatval($delta));
        return;
    }

    $restoreQty = floatval(abs($delta));
    restoreRecipeStockByProductName($db, $productName, $restoreQty);
    if ($productInfo) {
        $restoreStmt = $db->prepare('UPDATE products SET quantity = quantity + ? WHERE name = ?');
        $restoreStmt->execute([$restoreQty, $productName]);
    }
    tab_update_daily_sold_summary($db, $productName, -$restoreQty);
}

/**
 * Handle edit tab item POST (quantity change with stock checks + inventory movement).
 */
function handle_tab_edit_item_post_request(PDO $db): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['edit_item_id'])) {
        return;
    }

    $itemId = (int) ($_POST['edit_item_id'] ?? 0);
    $tabId = (int) ($_POST['tab_id'] ?? 0);
    $newQuantity = (int) ($_POST['edit_item_quantity'] ?? 0);

    if (!can_edit_tab_item_quantities_from_session()) {
        $_SESSION['error'] = 'You do not have permission to edit quantities on a tab. Ask a manager.';
        header('Location: ' . ($tabId > 0 ? 'view-tab.php?id=' . $tabId : 'credit-tabs'));
        exit();
    }

    if ($newQuantity <= 0) {
        $_SESSION['error'] = 'Quantity must be greater than zero';
        header('Location: ' . ($tabId > 0 ? 'view-tab.php?id=' . $tabId : 'credit-tabs'));
        exit();
    }

    $itemStmt = $db->prepare('SELECT id, tab_id, quantity, price, product_name FROM tab_items WHERE id = ?');
    $itemStmt->execute([$itemId]);
    $item = $itemStmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        header('Location: credit-tabs');
        exit();
    }

    $tabId = (int) ($item['tab_id'] ?? $tabId);
    $productName = (string) ($item['product_name'] ?? '');

    if (is_tab_prepayment_line_name($productName) || is_tab_postpaid_line_name($productName)) {
        $_SESSION['error'] = 'This tab line cannot be edited. Remove it and add a new amount if needed.';
        header('Location: view-tab.php?id=' . $tabId);
        exit();
    }

    $oldQuantity = (int) ($item['quantity'] ?? 0);
    if ($newQuantity === $oldQuantity) {
        $_SESSION['success'] = 'Product updated successfully';
        header('Location: view-tab.php?id=' . $tabId);
        exit();
    }

    $db->beginTransaction();
    try {
        tab_apply_stock_for_item_quantity_change($db, $productName, $oldQuantity, $newQuantity);

        $updateStmt = $db->prepare('UPDATE tab_items SET quantity = ? WHERE id = ?');
        $updateStmt->execute([$newQuantity, $itemId]);

        recalculateTabBalance($db, $tabId);

        $db->commit();
        $_SESSION['success'] = 'Product updated successfully';
    } catch (Exception $e) {
        $db->rollBack();
        $_SESSION['error'] = 'Failed to update item: ' . $e->getMessage();
    }

    header('Location: view-tab.php?id=' . $tabId);
    exit();
}

/** Client-side stock guard for the edit-item modal (pairs with handle_tab_edit_item_post_request). */
function tab_edit_item_stock_scripts_html(PDO $db): string
{
    static $done = false;
    if ($done) {
        return '';
    }
    $done = true;
    require_once __DIR__ . '/recipe_stock_helper.php';
    $skip = isSkipStockChecks($db) ? 'true' : 'false';

    return <<<HTML
<script>
(function () {
    var skipStockChecks = {$skip};
    var editOldQty = 0;
    var editAvailableStock = null;
    var installed = false;

    function installTabEditStockGuard() {
        if (installed) {
            return;
        }
        var nativeOpen = window.openEditItemModal;
        if (typeof nativeOpen !== 'function') {
            return;
        }
        window.openEditItemModal = function (itemId, productName, quantity, price, tabId, availableStock) {
            editOldQty = parseInt(quantity, 10) || 0;
            var stock = parseFloat(availableStock);
            editAvailableStock = Number.isFinite(stock) ? stock : null;
            return nativeOpen(itemId, productName, quantity, price, tabId);
        };

        var form = document.getElementById('editItemForm');
        if (form && !skipStockChecks && !form.dataset.tabStockGuard) {
            form.dataset.tabStockGuard = '1';
            form.addEventListener('submit', function (e) {
                var qtyInput = document.getElementById('edit_item_quantity');
                if (!qtyInput) {
                    return;
                }
                var newQty = parseInt(qtyInput.value, 10) || 0;
                if (newQty <= editOldQty || editAvailableStock === null) {
                    return;
                }
                var extra = newQty - editOldQty;
                if (editAvailableStock + 0.0001 < extra) {
                    e.preventDefault();
                    var availText = Number.isInteger(editAvailableStock)
                        ? String(editAvailableStock)
                        : editAvailableStock.toFixed(2);
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            icon: 'warning',
                            title: 'Insufficient stock',
                            text: 'Only ' + availText + ' more unit(s) available for this product.',
                            confirmButtonColor: '#0d9488'
                        });
                    } else {
                        alert('Insufficient stock: only ' + availText + ' more unit(s) available.');
                    }
                }
            });
        }

        installed = true;
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', installTabEditStockGuard);
    } else {
        installTabEditStockGuard();
    }
    window.addEventListener('load', installTabEditStockGuard);
})();
</script>
HTML;
}

/**
 * Handle mark/clear void request POST actions. Exits after redirect when handled.
 */
function handle_tab_void_mark_post_request(PDO $db): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $isMark = isset($_POST['mark_tab_for_void']);
    $isClear = isset($_POST['clear_tab_void_mark']);
    if (!$isMark && !$isClear) {
        return;
    }

    ensureTabVoidMarkColumns($db);

    $tabId = (int) ($_POST['tab_id'] ?? 0);
    $redirect = trim((string) ($_POST['void_mark_redirect'] ?? ''));
    if ($redirect === '') {
        $redirect = $tabId > 0 ? 'view-tab.php?id=' . $tabId : 'credit-tabs';
    }

    if ($tabId <= 0) {
        $_SESSION['error'] = 'Invalid tab';
        header('Location: ' . $redirect);
        exit();
    }

    if ($isMark) {
        if (!can_mark_tab_for_void_from_session()) {
            $_SESSION['error'] = 'You do not have permission to mark tabs for void';
            header('Location: ' . $redirect);
            exit();
        }

        $tabStmt = $db->prepare("SELECT id, status, marked_for_void FROM tabs WHERE id = ?");
        $tabStmt->execute([$tabId]);
        $tab = $tabStmt->fetch(PDO::FETCH_ASSOC);
        if (!$tab) {
            $_SESSION['error'] = 'Tab not found';
            header('Location: credit-tabs');
            exit();
        }
        if (($tab['status'] ?? '') !== 'open') {
            $_SESSION['error'] = 'Only open tabs can be marked for void';
            header('Location: ' . $redirect);
            exit();
        }
        if (tab_is_marked_for_void($tab)) {
            $_SESSION['success'] = 'Tab is already marked for void';
            header('Location: ' . $redirect);
            exit();
        }

        $username = trim((string) ($_SESSION['username'] ?? 'Unknown'));
        $updateStmt = $db->prepare("UPDATE tabs SET marked_for_void = 1, void_marked_by = ?, void_marked_at = CURRENT_TIMESTAMP WHERE id = ?");
        $updateStmt->execute([$username, $tabId]);
        $_SESSION['success'] = 'Tab marked for void. A manager will review and void it.';
        header('Location: ' . $redirect);
        exit();
    }

    if (!can_mark_tab_for_void_from_session() && !can_view_tab_void_mark_in_list_from_session()) {
        $_SESSION['error'] = 'You do not have permission to clear void marks';
        header('Location: ' . $redirect);
        exit();
    }

    $clearStmt = $db->prepare("UPDATE tabs SET marked_for_void = 0, void_marked_by = NULL, void_marked_at = NULL WHERE id = ?");
    $clearStmt->execute([$tabId]);
    $_SESSION['success'] = 'Void request cleared';
    header('Location: ' . $redirect);
    exit();
}

/**
 * Permanently void a tab. Optionally restore catalog quantities (skip non-inventory lines).
 *
 * @return array{ok: bool, error?: string, restore_stock?: bool}
 */
function void_entire_tab(PDO $db, int $tabId, bool $restoreStock = true): array
{
    $tabStmt = $db->prepare('SELECT * FROM tabs WHERE id = ?');
    $tabStmt->execute([$tabId]);
    $tab = $tabStmt->fetch(PDO::FETCH_ASSOC);

    if (!$tab) {
        return ['ok' => false, 'error' => 'Tab not found'];
    }
    if (($tab['status'] ?? '') === 'closed') {
        return ['ok' => false, 'error' => 'Cannot void a closed tab'];
    }

    $db->beginTransaction();
    try {
        $ordersStmt = $db->prepare('SELECT DISTINCT order_id FROM tab_payments WHERE tab_id = ? AND order_id IS NOT NULL');
        $ordersStmt->execute([$tabId]);
        $orderIds = $ordersStmt->fetchAll(PDO::FETCH_COLUMN);

        if ($restoreStock) {
            $productsToRestore = [];

            if (!empty($orderIds)) {
                $placeholders = str_repeat('?,', count($orderIds) - 1) . '?';
                $orderItemsStmt = $db->prepare("SELECT product_name, SUM(quantity) as total_quantity FROM order_items WHERE order_id IN ($placeholders) GROUP BY product_name");
                $orderItemsStmt->execute($orderIds);
                foreach ($orderItemsStmt->fetchAll(PDO::FETCH_ASSOC) as $orderItem) {
                    $productName = (string) $orderItem['product_name'];
                    if (tab_product_skips_inventory($productName)) {
                        continue;
                    }
                    $quantity = (float) $orderItem['total_quantity'];
                    if (!isset($productsToRestore[$productName])) {
                        $productsToRestore[$productName] = 0.0;
                    }
                    $productsToRestore[$productName] += $quantity;
                }
            }

            $tabItemsStmt = $db->prepare('SELECT product_name, SUM(quantity) as total_quantity FROM tab_items WHERE tab_id = ? GROUP BY product_name');
            $tabItemsStmt->execute([$tabId]);
            foreach ($tabItemsStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
                $productName = (string) $item['product_name'];
                if (tab_product_skips_inventory($productName)) {
                    continue;
                }
                $quantity = (float) $item['total_quantity'];
                if (!isset($productsToRestore[$productName])) {
                    $productsToRestore[$productName] = 0.0;
                }
                $productsToRestore[$productName] += $quantity;
            }

            if (!empty($productsToRestore)) {
                foreach ($productsToRestore as $productName => $quantity) {
                    if ($quantity <= 0 || tab_product_skips_inventory($productName)) {
                        continue;
                    }
                    restoreSaleLineStock($db, $productName, floatval($quantity));
                }
            }
        }

        if (!empty($orderIds)) {
            $placeholders = str_repeat('?,', count($orderIds) - 1) . '?';

            $deleteEftStmt = $db->prepare("DELETE FROM eft_payments WHERE order_id IN ($placeholders)");
            $deleteEftStmt->execute($orderIds);

            $deleteMixedStmt = $db->prepare("DELETE FROM mixed_payments WHERE order_id IN ($placeholders)");
            $deleteMixedStmt->execute($orderIds);

            $deleteOrderItemsStmt = $db->prepare("DELETE FROM order_items WHERE order_id IN ($placeholders)");
            $deleteOrderItemsStmt->execute($orderIds);

            $deleteOrdersStmt = $db->prepare("DELETE FROM orders WHERE id IN ($placeholders)");
            $deleteOrdersStmt->execute($orderIds);
        }

        $deleteTabItemPaymentsStmt = $db->prepare('DELETE FROM tab_item_payments WHERE tab_item_id IN (SELECT id FROM tab_items WHERE tab_id = ?)');
        $deleteTabItemPaymentsStmt->execute([$tabId]);

        $deleteTabPaymentsStmt = $db->prepare('DELETE FROM tab_payments WHERE tab_id = ?');
        $deleteTabPaymentsStmt->execute([$tabId]);

        $deleteTabItemsStmt = $db->prepare('DELETE FROM tab_items WHERE tab_id = ?');
        $deleteTabItemsStmt->execute([$tabId]);

        $deleteTabStmt = $db->prepare('DELETE FROM tabs WHERE id = ?');
        $deleteTabStmt->execute([$tabId]);

        $db->commit();

        return ['ok' => true, 'restore_stock' => $restoreStock];
    } catch (Exception $e) {
        $db->rollBack();

        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Handle full-tab void POST from view-tab. Exits after redirect when handled.
 */
function handle_tab_void_post_request(PDO $db): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['void_tab_id'])) {
        return;
    }

    $tabId = (int) $_POST['void_tab_id'];
    $redirect = $tabId > 0 ? 'view-tab.php?id=' . $tabId : 'credit-tabs';

    if (!can_void_entire_tab_from_session()) {
        $_SESSION['error'] = 'Only admins or managers can void tabs';
        header('Location: ' . $redirect);
        exit();
    }

    $restoreStock = tab_void_restore_stock_from_post();
    $result = void_entire_tab($db, $tabId, $restoreStock);
    if (!$result['ok']) {
        $_SESSION['error'] = ($result['error'] ?? 'Failed to void tab') === 'Tab not found'
            ? 'Tab not found'
            : 'Failed to void tab: ' . ($result['error'] ?? 'Unknown error');
        if (($result['error'] ?? '') === 'Tab not found') {
            header('Location: credit-tabs');
        } else {
            header('Location: ' . $redirect);
        }
        exit();
    }

    $_SESSION['success'] = $restoreStock
        ? 'Tab voided successfully. All items have been restored to stock.'
        : 'Tab voided successfully. Stock was not changed.';
    header('Location: credit-tabs');
    exit();
}

/**
 * Handle direct remove-line POST (trash button on view-tab). Exits after redirect when handled.
 */
function handle_tab_delete_item_post_request(PDO $db): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['delete_item_id'])) {
        return;
    }

    $itemId = (int) $_POST['delete_item_id'];
    $tabId = (int) ($_POST['tab_id'] ?? 0);

    assert_tab_item_delete_allowed($tabId, $_POST['manager_pin'] ?? null);

    $restoreStock = tab_void_restore_stock_from_post();
    $result = void_tab_item_remove_from_tab($db, $itemId, false, $restoreStock);

    if (!$result['ok']) {
        $_SESSION['error'] = 'Failed to delete item: ' . ($result['error'] ?? 'Unknown error');
    } else {
        $_SESSION['success'] = $restoreStock
            ? 'Product removed from tab and restored to stock successfully'
            : 'Product removed from tab. Stock was not changed.';
    }

    header('Location: ' . ($tabId > 0 ? 'view-tab.php?id=' . $tabId : 'credit-tabs'));
    exit();
}

/** Hidden form used by openDeleteTabItemModal on view-tab. */
function tab_delete_item_form_html(): string
{
    static $done = false;
    if ($done) {
        return '';
    }
    $done = true;

    $pinField = requires_manager_void_pin_to_delete_tab_items_from_session()
        ? '<input type="hidden" name="manager_pin" id="deleteTabItemManagerPin" value="">'
        : '';

    return '<form id="deleteTabItemForm" method="POST" class="hidden" aria-hidden="true">'
        . '<input type="hidden" name="delete_item_id" value="">'
        . '<input type="hidden" name="tab_id" value="">'
        . $pinField
        . '</form>';
}

/**
 * Handle per-item mark/clear/approve void POST actions. Exits after redirect when handled.
 */
function handle_tab_item_void_mark_post_request(PDO $db): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $isMark = isset($_POST['mark_tab_item_for_void']);
    $isClear = isset($_POST['clear_tab_item_void_mark']);
    $isApprove = isset($_POST['approve_tab_item_void']);
    if (!$isMark && !$isClear && !$isApprove) {
        return;
    }

    ensureTabItemVoidMarkColumns($db);

    $itemId = (int) ($_POST['tab_item_id'] ?? 0);
    $tabId = (int) ($_POST['tab_id'] ?? 0);
    $redirect = trim((string) ($_POST['void_mark_redirect'] ?? ''));
    if ($redirect === '') {
        $redirect = $tabId > 0 ? 'view-tab.php?id=' . $tabId : 'credit-tabs';
    }

    if ($itemId <= 0 || $tabId <= 0) {
        $_SESSION['error'] = 'Invalid tab item';
        header('Location: ' . $redirect);
        exit();
    }

    $itemStmt = $db->prepare('SELECT ti.*, t.status AS tab_status FROM tab_items ti JOIN tabs t ON t.id = ti.tab_id WHERE ti.id = ? AND ti.tab_id = ?');
    $itemStmt->execute([$itemId, $tabId]);
    $item = $itemStmt->fetch(PDO::FETCH_ASSOC);
    if (!$item) {
        $_SESSION['error'] = 'Tab item not found';
        header('Location: ' . $redirect);
        exit();
    }
    if (($item['tab_status'] ?? '') !== 'open' && $isMark) {
        $_SESSION['error'] = 'Only items on open tabs can be marked for void';
        header('Location: ' . $redirect);
        exit();
    }

    $tabOwnerStmt = $db->prepare('SELECT cashier_id FROM tabs WHERE id = ?');
    $tabOwnerStmt->execute([$tabId]);
    $tabOwnerRow = $tabOwnerStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    if ($isMark || $isClear) {
        if (!session_can_view_tab($tabOwnerRow)) {
            $_SESSION['error'] = 'You do not have access to this tab';
            header('Location: ' . $redirect);
            exit();
        }
    }

    if ($isApprove) {
        if (!can_approve_tab_item_void_from_session()) {
            $_SESSION['error'] = 'You do not have permission to void tab items';
            header('Location: ' . $redirect);
            exit();
        }
        if (!tab_item_is_marked_for_void($item)) {
            $_SESSION['error'] = 'This item is not marked for void';
            header('Location: ' . $redirect);
            exit();
        }

        $allowClosedTab = !tab_is_open(['status' => $item['tab_status'] ?? '']);
        $restoreStock = tab_void_restore_stock_from_post();
        $result = void_tab_item_remove_from_tab($db, $itemId, $allowClosedTab, $restoreStock);
        if (!$result['ok']) {
            $_SESSION['error'] = 'Failed to void item: ' . ($result['error'] ?? 'Unknown error');
        } else {
            $_SESSION['success'] = $restoreStock
                ? 'Item voided and stock restored'
                : 'Item voided. Stock was not changed.';
        }
        header('Location: ' . $redirect);
        exit();
    }

    if ($isMark) {
        if (!can_mark_tab_item_for_void_from_session()) {
            $_SESSION['error'] = 'You do not have permission to mark items for void';
            header('Location: ' . $redirect);
            exit();
        }
        if (tab_item_is_marked_for_void($item)) {
            $_SESSION['success'] = 'Item is already marked for void';
            header('Location: ' . $redirect);
            exit();
        }

        $username = trim((string) ($_SESSION['username'] ?? 'Unknown'));
        tab_transfer_item_payments_to_prepaid($db, $itemId, $tabId);
        $updateStmt = $db->prepare('UPDATE tab_items SET marked_for_void = 1, void_marked_by = ?, void_marked_at = CURRENT_TIMESTAMP WHERE id = ?');
        $updateStmt->execute([$username, $itemId]);
        recalculateTabBalance($db, $tabId);
        $_SESSION['success'] = 'Item marked for void. Tab balance updated — pay only for remaining items.';
        header('Location: ' . $redirect);
        exit();
    }

    if (!can_mark_tab_item_for_void_from_session() && !can_approve_tab_item_void_from_session()) {
        $_SESSION['error'] = 'You do not have permission to clear void marks';
        header('Location: ' . $redirect);
        exit();
    }

    $clearStmt = $db->prepare('UPDATE tab_items SET marked_for_void = 0, void_marked_by = NULL, void_marked_at = NULL WHERE id = ?');
    $clearStmt->execute([$itemId]);
    recalculateTabBalance($db, $tabId);
    $_SESSION['success'] = 'Void request cleared for item';
    header('Location: ' . $redirect);
    exit();
}

function tab_status_badges_html(array $tab, bool $showVoidPending = false): string
{
    $status = strtolower((string) ($tab['status'] ?? 'open'));
    $openClass = $status === 'open' ? 'bg-teal-100 text-teal-800' : 'bg-gray-100 text-gray-800';
    $html = '<span class="px-2 py-1 text-xs font-semibold rounded-full ' . $openClass . '">' . htmlspecialchars(ucfirst($status)) . '</span>';
    if ($showVoidPending && tab_is_marked_for_void($tab)) {
        $by = trim((string) ($tab['void_marked_by'] ?? ''));
        $title = $by !== '' ? ' title="Requested by ' . htmlspecialchars($by, ENT_QUOTES, 'UTF-8') . '"' : '';
        $html .= ' <span class="px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800"' . $title . '>Void pending</span>';
    }
    $itemVoidCount = (int) ($tab['items_marked_for_void_count'] ?? 0);
    if ($showVoidPending && $itemVoidCount > 0) {
        $html .= ' <span class="px-2 py-1 text-xs font-semibold rounded-full bg-orange-100 text-orange-900" title="' . $itemVoidCount . ' item(s) marked for void">Item void (' . $itemVoidCount . ')</span>';
    }
    return $html;
}

function tab_view_header_styles_html(): string
{
    return <<<'HTML'
<style id="tab-view-header-styles">
.tab-key-info-row {
    display: flex;
    align-items: flex-end;
    gap: 0.625rem;
    min-width: 0;
    overflow-x: auto;
    padding-bottom: 2px;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: thin;
    scrollbar-color: #cbd5e1 transparent;
}
.tab-key-info-row::-webkit-scrollbar { height: 5px; }
.tab-key-info-row::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 999px; }
.tab-header-action {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.375rem 0.625rem;
    font-size: 0.75rem;
    line-height: 1.1;
    font-weight: 500;
    white-space: nowrap;
    flex-shrink: 0;
    border-radius: 0.5rem;
    transition: color 0.15s, background-color 0.15s, border-color 0.15s;
}
@media (min-width: 1280px) {
    .tab-header-action { padding: 0.4375rem 0.75rem; font-size: 0.8125rem; }
}
.tab-key-info-balance .text-2xl { font-size: 1.375rem; line-height: 1.75rem; }
@media (min-width: 1280px) {
    .tab-key-info-balance .text-2xl { font-size: 1.5rem; line-height: 2rem; }
}
.tab-gratuity-toggle {
    cursor: default;
    padding: 0.3125rem 0.5rem;
    gap: 0.375rem;
}
.tab-gratuity-toggle .tab-gratuity-switch {
    cursor: pointer;
    border: none;
    padding: 0;
}
.tab-gratuity-label {
    font-size: 0.6875rem;
    font-weight: 600;
    line-height: 1.1;
}
.tab-gratuity-due {
    font-size: 0.625rem;
    color: #0f766e;
    font-weight: 500;
    line-height: 1.1;
}
@media (min-width: 1280px) {
    .tab-gratuity-label { font-size: 0.75rem; }
    .tab-gratuity-due { font-size: 0.6875rem; }
}
</style>
HTML;
}

/** Compact gratuity toggle for the view-tab header action row (next to Print). */
function tab_gratuity_toggle_action_html(
    array $viewTab,
    bool $featureEnabled,
    bool $gratuityEnabled,
    float $gratuityPercent,
    float $gratuityAmount,
    float $gratuityRemaining
): string {
    if (!$featureEnabled || ($viewTab['status'] ?? '') !== 'open') {
        return '';
    }
    $tabId = (int) ($viewTab['id'] ?? 0);
    $pct = htmlspecialchars(rtrim(rtrim(number_format($gratuityPercent, 2, '.', ''), '0'), '.'), ENT_QUOTES, 'UTF-8');
    $enabledVal = $gratuityEnabled ? '0' : '1';
    $onClass = $gratuityEnabled ? 'bg-teal-600' : 'bg-gray-300';
    $knobClass = $gratuityEnabled ? 'translate-x-[16px]' : '';
    $title = $gratuityEnabled ? 'Turn gratuity off' : 'Turn gratuity on';

    $dueHtml = '';
    if ($gratuityEnabled && $gratuityAmount > 0.001) {
        $due = $gratuityRemaining > 0.001 ? $gratuityRemaining : $gratuityAmount;
        $suffix = $gratuityRemaining < $gratuityAmount - 0.001 ? ' due' : '';
        $dueHtml = '<span class="tab-gratuity-due">+N$' . htmlspecialchars(number_format($due, 2), ENT_QUOTES, 'UTF-8') . $suffix . '</span>';
    }

    return '<form method="POST" class="tab-header-action tab-gratuity-toggle border border-gray-200 text-gray-700 bg-white hover:bg-gray-50" title="'
        . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '">'
        . '<input type="hidden" name="toggle_tab_gratuity" value="1">'
        . '<input type="hidden" name="tab_id" value="' . $tabId . '">'
        . '<input type="hidden" name="gratuity_enabled" value="' . $enabledVal . '">'
        . '<span class="tab-gratuity-label">Gratuity (' . $pct . '%)</span>'
        . '<button type="submit" class="tab-gratuity-switch relative inline-flex h-6 w-11 shrink-0 rounded-full transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-500 '
        . $onClass . '" aria-label="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '">'
        . '<span class="pointer-events-none absolute left-[2px] top-[2px] h-5 w-5 rounded-full bg-white shadow-md transition-transform '
        . $knobClass . '"></span>'
        . '</button>'
        . $dueHtml
        . '</form>';
}

/** Header action button — opens prepay/postpaid modal (cashier, manager, admin). */
function tab_prepay_postpaid_action_html(array $viewTab): string
{
    if (($viewTab['status'] ?? '') !== 'open' || !can_add_tab_prepay_postpaid_lines_from_session()) {
        return '';
    }
    $tabId = (int) ($viewTab['id'] ?? 0);
    if ($tabId <= 0) {
        return '';
    }

    return '<button type="button" onclick="openTabPrepayPostpaidModal(' . $tabId . ')"'
        . ' class="tab-header-action border border-gray-300 text-gray-700 bg-white hover:bg-gray-50"'
        . ' title="Prepay credit or postpaid charge (no inventory)">'
        . '<i data-lucide="scale" class="w-3.5 h-3.5 shrink-0"></i>Adjust'
        . '</button>';
}

/** Modal markup for prepay / postpaid tab line adjustments. */
function tab_prepay_postpaid_modal_html(array $viewTab): string
{
    if (!can_add_tab_prepay_postpaid_lines_from_session()) {
        return '';
    }
    $tabId = (int) ($viewTab['id'] ?? 0);
    if ($tabId <= 0) {
        return '';
    }
    $prepayName = htmlspecialchars(TAB_PREPAYMENT_LINE_NAME, ENT_QUOTES, 'UTF-8');
    $postpaidName = htmlspecialchars(TAB_POSTPAID_LINE_NAME, ENT_QUOTES, 'UTF-8');

    return <<<HTML
<div id="tabPrepayPostpaidModal" class="fixed inset-0 bg-black bg-opacity-50 overflow-y-auto h-full w-full hidden" style="z-index: 10001;">
    <div class="relative top-12 mx-auto mb-10 max-w-md" style="z-index: 10002;">
        <div class="bg-white rounded-lg shadow-lg border border-gray-200">
            <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                <h3 class="text-lg font-semibold text-gray-900">Tab adjustment</h3>
                <button type="button" onclick="closeTabPrepayPostpaidModal()" class="text-gray-400 hover:text-gray-600">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>
            <form method="POST" id="tabPrepayPostpaidForm" class="p-6 space-y-4">
                <input type="hidden" name="tab_id" value="{$tabId}">
                <input type="hidden" name="add_tab_prepayment" id="tabPrepayPostpaidPrepayFlag" value="1">
                <input type="hidden" name="add_tab_postpaid" id="tabPrepayPostpaidPostpaidFlag" value="1" disabled>
                <p id="tabPrepayPostpaidHelp" class="text-sm text-gray-500">Prepay adds <strong>{$prepayName}</strong> (reduces balance). Neither uses inventory.</p>
                <div>
                    <span class="block text-sm font-medium text-gray-700 mb-2">Type</span>
                    <div class="grid grid-cols-2 gap-2">
                        <button type="button" id="tabPrepayPostpaidTypePrepay"
                            class="tab-prepay-postpaid-type px-3 py-2 rounded-lg text-sm font-semibold border-2 border-teal-500 bg-teal-50 text-teal-800"
                            onclick="setTabPrepayPostpaidType('prepay')">
                            Prepay (credit)
                        </button>
                        <button type="button" id="tabPrepayPostpaidTypePostpaid"
                            class="tab-prepay-postpaid-type px-3 py-2 rounded-lg text-sm font-semibold border-2 border-gray-200 bg-white text-gray-700 hover:bg-gray-50"
                            onclick="setTabPrepayPostpaidType('postpaid')">
                            Postpaid (charge)
                        </button>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1" for="tabPrepayPostpaidAmountPrepay">Amount (N$)</label>
                    <input type="number" name="prepayment_amount" id="tabPrepayPostpaidAmountPrepay" step="0.01" min="0.01" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500/40 focus:border-teal-500 [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none"
                        placeholder="0.00">
                    <input type="number" name="postpaid_amount" id="tabPrepayPostpaidAmountPostpaid" step="0.01" min="0.01" disabled
                        class="hidden w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-amber-500/40 focus:border-amber-500 [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none"
                        placeholder="0.00">
                </div>
                <div class="flex gap-3 pt-2 border-t border-gray-200">
                    <button type="button" onclick="closeTabPrepayPostpaidModal()" class="flex-1 px-4 py-2 border border-gray-300 text-gray-700 font-medium rounded-lg hover:bg-gray-50 transition-colors">Cancel</button>
                    <button type="submit" id="tabPrepayPostpaidSubmit" class="flex-1 px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white font-medium rounded-lg transition-colors">Add credit</button>
                </div>
            </form>
        </div>
    </div>
</div>
HTML;
}

/** JS for prepay/postpaid modal — output once per page. */
function tab_prepay_postpaid_modal_scripts_html(): string
{
    static $done = false;
    if ($done || !can_add_tab_prepay_postpaid_lines_from_session()) {
        return '';
    }
    $done = true;
    $prepayName = json_encode(TAB_PREPAYMENT_LINE_NAME, JSON_UNESCAPED_UNICODE);
    $postpaidName = json_encode(TAB_POSTPAID_LINE_NAME, JSON_UNESCAPED_UNICODE);

    return <<<HTML
<script>
(function () {
    var prepayName = {$prepayName};
    var postpaidName = {$postpaidName};

    window.setTabPrepayPostpaidType = function (type) {
        var isPrepay = type === 'prepay';
        var prepayFlag = document.getElementById('tabPrepayPostpaidPrepayFlag');
        var postpaidFlag = document.getElementById('tabPrepayPostpaidPostpaidFlag');
        var amountPrepay = document.getElementById('tabPrepayPostpaidAmountPrepay');
        var amountPostpaid = document.getElementById('tabPrepayPostpaidAmountPostpaid');
        var btnPrepay = document.getElementById('tabPrepayPostpaidTypePrepay');
        var btnPostpaid = document.getElementById('tabPrepayPostpaidTypePostpaid');
        var submitBtn = document.getElementById('tabPrepayPostpaidSubmit');
        var help = document.getElementById('tabPrepayPostpaidHelp');
        if (!prepayFlag || !postpaidFlag || !amountPrepay || !amountPostpaid) return;

        prepayFlag.disabled = !isPrepay;
        postpaidFlag.disabled = isPrepay;
        amountPrepay.disabled = !isPrepay;
        amountPostpaid.disabled = isPrepay;
        amountPrepay.classList.toggle('hidden', !isPrepay);
        amountPostpaid.classList.toggle('hidden', isPrepay);
        if (isPrepay) {
            amountPrepay.setAttribute('required', 'required');
            amountPostpaid.removeAttribute('required');
        } else {
            amountPostpaid.setAttribute('required', 'required');
            amountPrepay.removeAttribute('required');
        }

        if (btnPrepay) {
            btnPrepay.className = isPrepay
                ? 'tab-prepay-postpaid-type px-3 py-2 rounded-lg text-sm font-semibold border-2 border-teal-500 bg-teal-50 text-teal-800'
                : 'tab-prepay-postpaid-type px-3 py-2 rounded-lg text-sm font-semibold border-2 border-gray-200 bg-white text-gray-700 hover:bg-gray-50';
        }
        if (btnPostpaid) {
            btnPostpaid.className = isPrepay
                ? 'tab-prepay-postpaid-type px-3 py-2 rounded-lg text-sm font-semibold border-2 border-gray-200 bg-white text-gray-700 hover:bg-gray-50'
                : 'tab-prepay-postpaid-type px-3 py-2 rounded-lg text-sm font-semibold border-2 border-amber-500 bg-amber-50 text-amber-900';
        }
        if (submitBtn) {
            submitBtn.textContent = isPrepay ? 'Add credit' : 'Add charge';
            submitBtn.className = isPrepay
                ? 'flex-1 px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white font-medium rounded-lg transition-colors'
                : 'flex-1 px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white font-medium rounded-lg transition-colors';
        }
        if (help) {
            help.innerHTML = isPrepay
                ? 'Prepay adds <strong>' + prepayName + '</strong> (reduces balance). Neither uses inventory.'
                : 'Postpaid adds <strong>' + postpaidName + '</strong> (increases balance). Neither uses inventory.';
        }
    };

    window.openTabPrepayPostpaidModal = function () {
        var form = document.getElementById('tabPrepayPostpaidForm');
        if (form) form.reset();
        var tabIdInput = form && form.querySelector('input[name="tab_id"]');
        if (tabIdInput && arguments.length) tabIdInput.value = String(arguments[0]);
        setTabPrepayPostpaidType('prepay');
        var modal = document.getElementById('tabPrepayPostpaidModal');
        if (modal) {
            modal.classList.remove('hidden');
            if (typeof lucide !== 'undefined') lucide.createIcons();
            var amount = document.getElementById('tabPrepayPostpaidAmountPrepay');
            if (amount) amount.focus();
        }
    };

    window.closeTabPrepayPostpaidModal = function () {
        var modal = document.getElementById('tabPrepayPostpaidModal');
        if (modal) modal.classList.add('hidden');
    };
})();
</script>
HTML;
}

/** SweetAlert confirm attrs for POST forms (replaces native browser confirm). */
function tab_pos_confirm_form_onsubmit_attr(array $options): string
{
    $json = json_encode($options, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    if ($json === false) {
        $json = '{}';
    }

    return 'onsubmit="return confirmPosFormSubmit(event, ' . htmlspecialchars($json, ENT_QUOTES, 'UTF-8') . ');"';
}

function tab_reopen_form_onsubmit_attr(): string
{
    return tab_pos_confirm_form_onsubmit_attr([
        'title' => 'Reopen this tab?',
        'text' => 'The tab will be opened again for new items and payments.',
        'confirmButtonText' => 'Reopen',
        'variant' => 'primary',
    ]);
}

/** Include pos-confirm.js after SweetAlert2 ($prefix e.g. "../" for admin/manager/waitress). */
function tab_pos_confirm_script_tag(string $prefix = ''): string
{
    static $done = false;
    if ($done) {
        return '';
    }
    $done = true;
    $src = htmlspecialchars($prefix . 'js/pos-confirm.js', ENT_QUOTES, 'UTF-8');

    return '<script src="' . $src . '"></script>';
}

/** SweetAlert void-tab modal with optional stock restore (admin/manager). */
function tab_void_entire_tab_modal_scripts_html(): string
{
    static $done = false;
    if ($done || !can_void_entire_tab_from_session()) {
        return '';
    }
    $done = true;

    return <<<'HTML'
<script>
        function openVoidTabModal(tabId, tabName) {
            if (typeof Swal === 'undefined') {
                if (!confirm('Void this tab?')) {
                    return;
                }
                var fallbackForm = document.createElement('form');
                fallbackForm.method = 'POST';
                fallbackForm.action = 'view-tab.php';
                var fallbackId = document.createElement('input');
                fallbackId.type = 'hidden';
                fallbackId.name = 'void_tab_id';
                fallbackId.value = tabId;
                fallbackForm.appendChild(fallbackId);
                var fallbackStock = document.createElement('input');
                fallbackStock.type = 'hidden';
                fallbackStock.name = 'restore_stock';
                fallbackStock.value = '1';
                fallbackForm.appendChild(fallbackStock);
                document.body.appendChild(fallbackForm);
                fallbackForm.submit();
                return;
            }
            Swal.fire({
                icon: 'warning',
                title: 'Void Tab',
                html: `
                    <div class="text-left">
                        <p class="text-sm text-gray-700 mb-3">Are you sure you want to void this tab?</p>
                        <p class="text-sm font-semibold text-gray-900 mb-2">Tab: <span class="text-gray-700"></span></p>
                        <div class="bg-red-50 border border-red-200 rounded-lg p-3 mt-3">
                            <p class="text-xs text-red-800 font-semibold mb-1">This action cannot be undone!</p>
                            <ul class="text-xs text-red-700 space-y-1 list-disc list-inside">
                                <li id="voidTabStockBullet">All items will be restored to stock</li>
                                <li>All payments and orders will be deleted</li>
                                <li>The tab will be permanently deleted</li>
                            </ul>
                        </div>
                        <label class="mt-3 flex items-start gap-2 cursor-pointer select-none">
                            <input type="checkbox" id="voidTabRestoreStock" checked class="mt-1 h-4 w-4 rounded border-gray-300 text-red-600 focus:ring-red-500">
                            <span class="text-sm text-gray-800">
                                Restore items to stock
                                <span class="block text-xs text-gray-500 font-normal">Uncheck if items were already consumed and should not go back into inventory.</span>
                            </span>
                        </label>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: 'Yes, Void Tab',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#DC2626',
                cancelButtonColor: '#6B7280',
                focusConfirm: false,
                customClass: {
                    popup: 'rounded-xl shadow-lg'
                },
                didOpen: () => {
                    var nameEl = Swal.getHtmlContainer() && Swal.getHtmlContainer().querySelector('span.text-gray-700');
                    if (nameEl) {
                        nameEl.textContent = tabName;
                    }
                    var checkbox = document.getElementById('voidTabRestoreStock');
                    var bullet = document.getElementById('voidTabStockBullet');
                    var syncBullet = function () {
                        if (!bullet || !checkbox) return;
                        bullet.textContent = checkbox.checked
                            ? 'All items will be restored to stock'
                            : 'Stock will not be changed';
                    };
                    if (checkbox) {
                        checkbox.addEventListener('change', syncBullet);
                        syncBullet();
                    }
                },
                preConfirm: () => {
                    var checkbox = document.getElementById('voidTabRestoreStock');
                    // SweetAlert treats boolean false from preConfirm as validation failure — use '0'/'1'.
                    return checkbox ? (checkbox.checked ? '1' : '0') : '1';
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'view-tab.php';

                    const tabIdInput = document.createElement('input');
                    tabIdInput.type = 'hidden';
                    tabIdInput.name = 'void_tab_id';
                    tabIdInput.value = tabId;
                    form.appendChild(tabIdInput);

                    const restoreInput = document.createElement('input');
                    restoreInput.type = 'hidden';
                    restoreInput.name = 'restore_stock';
                    restoreInput.value = result.value === '0' ? '0' : '1';
                    form.appendChild(restoreInput);

                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }
</script>
HTML;
}

/** Remove-line modal (trash) with optional stock restore + manager PIN when required. */
function tab_delete_item_modal_scripts_html(): string
{
    static $done = false;
    if ($done) {
        return '';
    }
    $done = true;

    $canRemove = can_remove_tab_items_from_session();
    $needsPin = requires_manager_void_pin_to_delete_tab_items_from_session();

    return '<script>'
        . 'var canRemoveTabItems = ' . ($canRemove ? 'true' : 'false') . ';'
        . 'var needsManagerPinToDeleteTabItems = ' . ($needsPin ? 'true' : 'false') . ';'
        . <<<'JS'

if (typeof window.promptManagerVoidPin === 'undefined') {
    window.promptManagerVoidPin = function (options) {
        const opts = options || {};
        return Swal.fire({
            title: opts.title || 'Manager authorization',
            text: opts.text || 'Enter manager void PIN to continue.',
            icon: 'warning',
            input: 'password',
            inputLabel: 'Manager void PIN',
            inputAttributes: { autocapitalize: 'off', autocomplete: 'off', inputmode: 'numeric' },
            showCancelButton: true,
            confirmButtonText: opts.confirmButtonText || 'Confirm',
            cancelButtonText: 'Cancel',
            confirmButtonColor: opts.confirmButtonColor || '#dc2626',
            cancelButtonColor: '#6B7280',
            focusConfirm: false
        });
    };
}

function submitDeleteTabItemForm(itemId, tabId, managerPin, restoreStock) {
    const form = document.getElementById('deleteTabItemForm');
    if (!form) {
        return;
    }
    const delInput = form.querySelector('[name="delete_item_id"]');
    const tabInput = form.querySelector('[name="tab_id"]');
    const pinInput = document.getElementById('deleteTabItemManagerPin');
    if (delInput) {
        delInput.value = itemId;
    }
    if (tabInput) {
        tabInput.value = tabId;
    }
    if (pinInput) {
        pinInput.value = managerPin || '';
    }
    let restoreInput = form.querySelector('[name="restore_stock"]');
    if (!restoreInput) {
        restoreInput = document.createElement('input');
        restoreInput.type = 'hidden';
        restoreInput.name = 'restore_stock';
        form.appendChild(restoreInput);
    }
    restoreInput.value = restoreStock === '0' ? '0' : '1';
    form.submit();
}

function openDeleteTabItemModal(btn) {
    if (!canRemoveTabItems) {
        return;
    }
    const itemId = btn.getAttribute('data-delete-item-id');
    const tabId = btn.getAttribute('data-tab-id');
    const lineKind = btn.getAttribute('data-line-kind') || 'product';
    const productName = btn.getAttribute('data-product-name') || '';

    const kindMeta = {
        prepay: {
            title: 'Remove prepayment credit?',
            hint: 'This removes the credit line from the tab. Inventory is not changed.'
        },
        postpaid: {
            title: 'Remove postpaid charge?',
            hint: 'This removes the charge line from the tab. Inventory is not changed.'
        },
        product: {
            title: 'Remove product from tab?',
            hint: 'This line will be removed from the tab.'
        }
    };
    const meta = kindMeta[lineKind] || kindMeta.product;
    const showRestoreCheckbox = lineKind === 'product';

    let html = '<p class="text-gray-600 text-sm leading-relaxed">' + meta.hint + '</p>'
        + '<p class="text-xs font-medium text-gray-500 uppercase tracking-wide mt-4 mb-1">Line</p>'
        + '<p id="swal-delete-tab-item-name" class="text-sm font-semibold text-gray-900 px-3 py-2.5 rounded-xl bg-gray-50 border border-gray-100/80"></p>';
    if (showRestoreCheckbox) {
        html += '<label class="mt-3 flex items-start gap-2 cursor-pointer select-none">'
            + '<input type="checkbox" id="deleteTabItemRestoreStock" checked class="mt-1 h-4 w-4 rounded border-gray-300 text-red-600 focus:ring-red-500">'
            + '<span class="text-sm text-gray-800">Restore this item to stock'
            + '<span class="block text-xs text-gray-500 font-normal">Uncheck if it was already consumed and should not go back into inventory.</span>'
            + '</span></label>';
    }

    Swal.fire({
        title: meta.title,
        icon: 'warning',
        iconColor: '#d97706',
        showCancelButton: true,
        focusCancel: true,
        confirmButtonText: 'Remove',
        cancelButtonText: 'Cancel',
        buttonsStyling: false,
        reverseButtons: true,
        customClass: {
            popup: 'rounded-2xl shadow-2xl border border-gray-200/90 px-5 py-4 max-w-md !bg-white',
            title: 'text-xl font-semibold text-gray-900 tracking-tight pb-0',
            htmlContainer: 'text-left !mt-3',
            actions: 'flex flex-row-reverse flex-wrap gap-2 justify-end w-full mt-6 !mb-0 pt-2 border-t border-gray-100',
            confirmButton: 'inline-flex items-center justify-center rounded-xl bg-red-600 hover:bg-red-700 text-white text-sm font-semibold px-5 py-2.5 shadow-sm transition-colors focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-1',
            cancelButton: 'inline-flex items-center justify-center rounded-xl bg-gray-100 hover:bg-gray-200 text-gray-800 text-sm font-semibold px-5 py-2.5 transition-colors focus:outline-none focus:ring-2 focus:ring-gray-300 focus:ring-offset-1'
        },
        html: html,
        didOpen: () => {
            const el = document.getElementById('swal-delete-tab-item-name');
            if (el) {
                el.textContent = productName;
            }
        },
        preConfirm: () => {
            if (!showRestoreCheckbox) {
                return '1';
            }
            const checkbox = document.getElementById('deleteTabItemRestoreStock');
            return checkbox ? (checkbox.checked ? '1' : '0') : '1';
        }
    }).then((result) => {
        if (!result.isConfirmed) {
            return;
        }
        const restoreStock = showRestoreCheckbox ? (result.value === '0' ? '0' : '1') : '1';
        if (needsManagerPinToDeleteTabItems) {
            promptManagerVoidPin({
                title: 'Remove line',
                text: 'Enter manager void PIN to remove this line from the tab.',
                confirmButtonText: 'Remove'
            }).then((pinResult) => {
                if (!pinResult.isConfirmed) {
                    return;
                }
                submitDeleteTabItemForm(itemId, tabId, pinResult.value || '', restoreStock);
            });
            return;
        }
        submitDeleteTabItemForm(itemId, tabId, '', restoreStock);
    });
}
JS
        . '</script>';
}

function tab_void_mark_action_html(array $tab, string $redirect = ''): string
{
    if (($tab['status'] ?? '') !== 'open' || !can_mark_tab_for_void_from_session()) {
        return '';
    }

    $tabId = (int) ($tab['id'] ?? 0);
    if ($tabId <= 0) {
        return '';
    }

    if ($redirect === '') {
        $redirect = 'view-tab.php?id=' . $tabId;
    }

    if (tab_is_marked_for_void($tab)) {
        $onsubmit = tab_pos_confirm_form_onsubmit_attr([
            'title' => 'Cancel void request?',
            'text' => 'This tab will no longer be marked for void.',
            'confirmButtonText' => 'Cancel void request',
            'variant' => 'warning',
        ]);

        return '<form method="POST" class="inline shrink-0" ' . $onsubmit . '>'
            . '<input type="hidden" name="tab_id" value="' . $tabId . '">'
            . '<input type="hidden" name="clear_tab_void_mark" value="1">'
            . '<input type="hidden" name="void_mark_redirect" value="' . htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8') . '">'
            . '<button type="submit" class="tab-header-action border border-amber-300 text-amber-900 bg-amber-50 hover:bg-amber-100" title="Cancel void request">'
            . '<i data-lucide="undo-2" class="w-3.5 h-3.5 shrink-0"></i>Cancel Void'
            . '</button></form>';
    }

    $onsubmit = tab_pos_confirm_form_onsubmit_attr([
        'title' => 'Mark this tab for void?',
        'text' => 'A manager or admin will need to void it.',
        'confirmButtonText' => 'Mark for void',
        'variant' => 'danger',
    ]);

    return '<form method="POST" class="inline shrink-0" ' . $onsubmit . '>'
        . '<input type="hidden" name="tab_id" value="' . $tabId . '">'
        . '<input type="hidden" name="mark_tab_for_void" value="1">'
        . '<input type="hidden" name="void_mark_redirect" value="' . htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8') . '">'
        . '<button type="submit" class="tab-header-action border border-red-300 text-red-800 bg-red-50 hover:bg-red-100" title="Mark for void">'
        . '<i data-lucide="flag" class="w-3.5 h-3.5 shrink-0"></i>Mark Void'
        . '</button></form>';
}

function tab_void_mark_list_action_html(array $tab): string
{
    if (($tab['status'] ?? '') !== 'open' || !can_mark_tab_for_void_from_session()) {
        return '';
    }

    $tabId = (int) ($tab['id'] ?? 0);
    if ($tabId <= 0) {
        return '';
    }

    if (tab_is_marked_for_void($tab)) {
        $onsubmit = tab_pos_confirm_form_onsubmit_attr([
            'title' => 'Cancel void request?',
            'text' => 'This tab will no longer be marked for void.',
            'confirmButtonText' => 'Cancel void request',
            'variant' => 'warning',
        ]);

        return '<form method="POST" class="inline" ' . $onsubmit . '>'
            . '<input type="hidden" name="tab_id" value="' . $tabId . '">'
            . '<input type="hidden" name="clear_tab_void_mark" value="1">'
            . '<input type="hidden" name="void_mark_redirect" value="credit-tabs">'
            . '<button type="submit" class="inline-flex items-center gap-x-1 text-sm font-semibold rounded-lg border border-transparent text-amber-700 hover:text-amber-900" title="Cancel void request">'
            . '<i data-lucide="undo-2" class="w-4 h-4"></i>'
            . '</button></form>';
    }

    $onsubmit = tab_pos_confirm_form_onsubmit_attr([
        'title' => 'Mark this tab for void?',
        'text' => 'A manager or admin will need to void it.',
        'confirmButtonText' => 'Mark for void',
        'variant' => 'danger',
    ]);

    return '<form method="POST" class="inline" ' . $onsubmit . '>'
        . '<input type="hidden" name="tab_id" value="' . $tabId . '">'
        . '<input type="hidden" name="mark_tab_for_void" value="1">'
        . '<input type="hidden" name="void_mark_redirect" value="credit-tabs">'
        . '<button type="submit" class="inline-flex items-center gap-x-1 text-sm font-semibold rounded-lg border border-transparent text-red-600 hover:text-red-800" title="Mark for void">'
        . '<i data-lucide="flag" class="w-4 h-4"></i>'
        . '</button></form>';
}

function tab_item_void_pending_badge_html(array $item): string
{
    if (!tab_item_is_marked_for_void($item)) {
        return '';
    }
    $by = trim((string) ($item['void_marked_by'] ?? ''));
    $title = $by !== '' ? ' title="Requested by ' . htmlspecialchars($by, ENT_QUOTES, 'UTF-8') . '"' : '';

    return '<span class="inline-flex items-center px-2 py-0.5 text-xs font-semibold rounded-full bg-red-100 text-red-800"' . $title . '>Void pending</span>';
}

function tab_item_void_mark_action_html(array $item, int $tabId, bool $isPrepayLine = false, bool $isPostpaidLine = false): string
{
    if ($isPrepayLine || $isPostpaidLine) {
        return '';
    }

    $itemId = (int) ($item['id'] ?? 0);
    if ($itemId <= 0 || $tabId <= 0) {
        return '';
    }

    $redirect = 'view-tab.php?id=' . $tabId;

    if (can_approve_tab_item_void_from_session() && tab_item_is_marked_for_void($item)) {
        $approveOnsubmit = tab_pos_confirm_form_onsubmit_attr([
            'title' => 'Void this item?',
            'text' => 'The line will be removed from the tab.',
            'confirmButtonText' => 'Void item',
            'variant' => 'danger',
            'checkbox' => [
                'name' => 'restore_stock',
                'label' => 'Restore this item to stock',
                'hint' => 'Uncheck if it was already consumed and should not go back into inventory.',
                'checked' => true,
            ],
        ]);
        $clearOnsubmit = tab_pos_confirm_form_onsubmit_attr([
            'title' => 'Cancel void request?',
            'text' => 'This item will no longer be marked for void.',
            'confirmButtonText' => 'Clear request',
            'variant' => 'warning',
        ]);

        return '<form method="POST" class="inline" ' . $approveOnsubmit . '>'
            . '<input type="hidden" name="tab_item_id" value="' . $itemId . '">'
            . '<input type="hidden" name="tab_id" value="' . $tabId . '">'
            . '<input type="hidden" name="approve_tab_item_void" value="1">'
            . '<input type="hidden" name="void_mark_redirect" value="' . htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8') . '">'
            . '<button type="submit" class="inline-flex items-center gap-x-1.5 px-2.5 py-1.5 text-sm font-semibold rounded-lg border border-red-300 text-red-800 bg-red-50 hover:bg-red-100" title="Approve void — optionally restore stock">'
            . '<i data-lucide="check-circle" class="w-4 h-4 shrink-0"></i>Approve void'
            . '</button></form>'
            . '<form method="POST" class="inline" ' . $clearOnsubmit . '>'
            . '<input type="hidden" name="tab_item_id" value="' . $itemId . '">'
            . '<input type="hidden" name="tab_id" value="' . $tabId . '">'
            . '<input type="hidden" name="clear_tab_item_void_mark" value="1">'
            . '<input type="hidden" name="void_mark_redirect" value="' . htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8') . '">'
            . '<button type="submit" class="inline-flex items-center gap-x-1.5 px-2.5 py-1.5 text-sm font-semibold rounded-lg border border-amber-300 text-amber-900 bg-amber-50 hover:bg-amber-100" title="Clear void request">'
            . '<i data-lucide="undo-2" class="w-4 h-4 shrink-0"></i>Clear'
            . '</button></form>';
    }

    if (!can_mark_tab_item_for_void_from_session()) {
        return '';
    }

    if (tab_item_is_marked_for_void($item)) {
        $onsubmit = tab_pos_confirm_form_onsubmit_attr([
            'title' => 'Cancel void request?',
            'text' => 'This item will no longer be marked for void.',
            'confirmButtonText' => 'Cancel void request',
            'variant' => 'warning',
        ]);

        return '<form method="POST" class="inline" ' . $onsubmit . '>'
            . '<input type="hidden" name="tab_item_id" value="' . $itemId . '">'
            . '<input type="hidden" name="tab_id" value="' . $tabId . '">'
            . '<input type="hidden" name="clear_tab_item_void_mark" value="1">'
            . '<input type="hidden" name="void_mark_redirect" value="' . htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8') . '">'
            . '<button type="submit" class="inline-flex items-center gap-x-1 text-sm font-semibold rounded-lg border border-transparent text-amber-700 hover:text-amber-900" title="Cancel void request">'
            . '<i data-lucide="undo-2" class="w-4 h-4"></i>'
            . '</button></form>';
    }

    $onsubmit = tab_pos_confirm_form_onsubmit_attr([
        'title' => 'Mark this item for void?',
        'text' => 'A manager or admin will need to approve the void.',
        'confirmButtonText' => 'Mark for void',
        'variant' => 'danger',
    ]);

    return '<form method="POST" class="inline" ' . $onsubmit . '>'
        . '<input type="hidden" name="tab_item_id" value="' . $itemId . '">'
        . '<input type="hidden" name="tab_id" value="' . $tabId . '">'
        . '<input type="hidden" name="mark_tab_item_for_void" value="1">'
        . '<input type="hidden" name="void_mark_redirect" value="' . htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8') . '">'
        . '<button type="submit" class="inline-flex items-center gap-x-1 text-sm font-semibold rounded-lg border border-transparent text-red-600 hover:text-red-800" title="Mark for void">'
        . '<i data-lucide="flag" class="w-4 h-4"></i>'
        . '</button></form>';
}

function tab_debug_log(string $location, string $message, array $data = [], string $hypothesisId = ''): void
{
    // #region agent log
    $payload = [
        'sessionId' => 'd2a396',
        'timestamp' => (int) round(microtime(true) * 1000),
        'location' => $location,
        'message' => $message,
        'data' => $data,
    ];
    if ($hypothesisId !== '') {
        $payload['hypothesisId'] = $hypothesisId;
    }
    @file_put_contents(__DIR__ . '/debug-d2a396.log', json_encode($payload) . "\n", FILE_APPEND | LOCK_EX);
    // #endregion
}

/** Debug session 43c494 — prepaid/advance investigation (NDJSON to workspace debug-43c494.log). */
function tab_agent_debug_log(string $location, string $message, array $data = [], string $hypothesisId = ''): void
{
    // #region agent log
    $payload = [
        'sessionId' => '43c494',
        'timestamp' => (int) round(microtime(true) * 1000),
        'location' => $location,
        'message' => $message,
        'data' => $data,
    ];
    if ($hypothesisId !== '') {
        $payload['hypothesisId'] = $hypothesisId;
    }
    @file_put_contents(__DIR__ . '/debug-43c494.log', json_encode($payload) . "\n", FILE_APPEND | LOCK_EX);
    // #endregion
}

/** Log payment FIFO allocation — call from view-tab payment handler before committing. */
/** Remove tab lines that are fully paid (positive lines only; keeps prepayment credits). */
function tab_remove_fully_paid_tab_items(PDO $db, int $tabId): void
{
    $checkPaidItemsStmt = $db->prepare('
        SELECT ti.id, ti.quantity, ti.price,
               COALESCE((SELECT SUM(amount) FROM tab_item_payments WHERE tab_item_id = ti.id), 0) AS total_paid
        FROM tab_items ti
        WHERE ti.tab_id = ?
    ');
    $checkPaidItemsStmt->execute([$tabId]);
    $deletePaidStmt = $db->prepare('DELETE FROM tab_items WHERE id = ?');
    $deleteItemPaymentsStmt = $db->prepare('DELETE FROM tab_item_payments WHERE tab_item_id = ?');

    foreach ($checkPaidItemsStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $itemTotal = floatval($item['quantity']) * floatval($item['price']);
        $totalPaid = floatval($item['total_paid']);
        if ($itemTotal <= 0.01) {
            continue;
        }
        if ($totalPaid >= ($itemTotal - 0.01)) {
            $deleteItemPaymentsStmt->execute([(int) $item['id']]);
            $deletePaidStmt->execute([(int) $item['id']]);
        }
    }
}

/**
 * Move payments allocated to a void-pending line into prepaid_balance so credit is not lost.
 */
function tab_transfer_item_payments_to_prepaid(PDO $db, int $itemId, int $tabId): float
{
    ensureTabPrepaidBalanceColumn($db);

    $paidStmt = $db->prepare('SELECT COALESCE(SUM(amount), 0) FROM tab_item_payments WHERE tab_item_id = ?');
    $paidStmt->execute([$itemId]);
    $paidTotal = round(floatval($paidStmt->fetchColumn()), 2);
    if ($paidTotal <= 0.001) {
        return 0.0;
    }

    $deletePaymentsStmt = $db->prepare('DELETE FROM tab_item_payments WHERE tab_item_id = ?');
    $deletePaymentsStmt->execute([$itemId]);

    $updPrepaid = $db->prepare('UPDATE tabs SET prepaid_balance = COALESCE(prepaid_balance, 0) + ? WHERE id = ?');
    $updPrepaid->execute([$paidTotal, $tabId]);

    return $paidTotal;
}

/**
 * Apply tabs.prepaid_balance to unpaid payable lines (FIFO) so advance credit covers re-added items
 * after a void-pending mark instead of leaving a phantom advance + unpaid duplicate line.
 */
function tab_apply_prepaid_balance_to_items(PDO $db, int $tabId): float
{
    ensureTabPrepaidBalanceColumn($db);
    ensureTabItemVoidMarkColumns($db);

    try {
        $db->exec('
            CREATE TABLE IF NOT EXISTS tab_item_payments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tab_item_id INTEGER NOT NULL,
                payment_id INTEGER NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                FOREIGN KEY(tab_item_id) REFERENCES tab_items(id),
                FOREIGN KEY(payment_id) REFERENCES tab_payments(id)
            )
        ');
    } catch (PDOException $e) {
        // Table already exists
    }

    $tabStmt = $db->prepare('SELECT COALESCE(prepaid_balance, 0) AS prepaid_balance FROM tabs WHERE id = ?');
    $tabStmt->execute([$tabId]);
    $prepaidRemaining = round(floatval($tabStmt->fetchColumn()), 2);
    if ($prepaidRemaining <= 0.001) {
        return 0.0;
    }

    $unpaidItems = tab_fetch_unpaid_payable_items($db, $tabId, true);
    if (empty($unpaidItems)) {
        return 0.0;
    }

    $anchorPaymentId = null;
    $appliedTotal = 0.0;
    $linkStmt = $db->prepare('INSERT INTO tab_item_payments (tab_item_id, payment_id, amount) VALUES (?, ?, ?)');

    foreach ($unpaidItems as $item) {
        if ($prepaidRemaining <= 0.001) {
            break;
        }

        $itemTotal = floatval($item['item_total']);
        $alreadyPaid = floatval($item['paid_amount'] ?? 0);
        $unpaidAmount = round($itemTotal - $alreadyPaid, 2);
        if ($unpaidAmount <= 0.001) {
            continue;
        }

        $applyAmount = round(min($prepaidRemaining, $unpaidAmount), 2);
        if ($applyAmount <= 0.001) {
            continue;
        }

        if ($anchorPaymentId === null) {
            $paymentStmt = $db->prepare("INSERT INTO tab_payments (tab_id, amount, payment_method, cashier_id, payment_date) VALUES (?, 0, 'cash', 'system', datetime('now'))");
            $paymentStmt->execute([$tabId]);
            $anchorPaymentId = (int) $db->lastInsertId();
        }

        $linkStmt->execute([(int) $item['id'], $anchorPaymentId, $applyAmount]);
        $prepaidRemaining = round($prepaidRemaining - $applyAmount, 2);
        $appliedTotal = round($appliedTotal + $applyAmount, 2);
    }

    if ($appliedTotal > 0.001) {
        $newPrepaid = max(0.0, round($prepaidRemaining, 2));
        $updPrepaid = $db->prepare('UPDATE tabs SET prepaid_balance = ? WHERE id = ?');
        $updPrepaid->execute([$newPrepaid, $tabId]);
        tab_remove_fully_paid_tab_items($db, $tabId);
    }

    return $appliedTotal;
}

function tab_log_payment_allocation(PDO $db, int $tabId, float $paymentAmount, array $itemsToPay, float $prepaidToAdd): void
{
    ensureTabPrepaidBalanceColumn($db);
    $tabStmt = $db->prepare('SELECT current_balance, COALESCE(prepaid_balance, 0) AS prepaid_balance FROM tabs WHERE id = ?');
    $tabStmt->execute([$tabId]);
    $tabRow = $tabStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $linesStmt = $db->prepare('SELECT product_name, quantity, price, (quantity * price) AS line_total FROM tab_items WHERE tab_id = ?');
    $linesStmt->execute([$tabId]);
    $lines = $linesStmt->fetchAll(PDO::FETCH_ASSOC);

    $prepayLineCredit = 0.0;
    $positiveUnpaidTotal = 0.0;
    $negativeUnpaidTotal = 0.0;
    foreach ($lines as $line) {
        $lineTotal = floatval($line['line_total']);
        if (is_tab_prepayment_line_name($line['product_name'] ?? '')) {
            $prepayLineCredit += abs(min(0.0, $lineTotal));
            $negativeUnpaidTotal += abs(min(0.0, $lineTotal));
        } elseif ($lineTotal > 0.01) {
            $positiveUnpaidTotal += $lineTotal;
        } elseif ($lineTotal < -0.01) {
            $negativeUnpaidTotal += abs($lineTotal);
        }
    }

    $itemsToPayTotal = 0.0;
    foreach ($itemsToPay as $item) {
        $itemsToPayTotal += floatval($item['payment_amount'] ?? 0);
    }

    tab_debug_log('tab_balance_helper.php:tab_log_payment_allocation', 'Payment allocation computed', [
        'tabId' => $tabId,
        'paymentAmount' => $paymentAmount,
        'currentBalanceBefore' => floatval($tabRow['current_balance'] ?? 0),
        'itemsToPayCount' => count($itemsToPay),
        'itemsToPayTotal' => round($itemsToPayTotal, 2),
        'prepaidToAdd' => $prepaidToAdd,
        'prepayLineCredit' => round($prepayLineCredit, 2),
        'prepaidBalanceColumn' => floatval($tabRow['prepaid_balance'] ?? 0),
        'positiveUnpaidTotal' => round($positiveUnpaidTotal, 2),
        'negativeUnpaidTotal' => round($negativeUnpaidTotal, 2),
        'projectedDoubleCredit' => round($prepayLineCredit + floatval($tabRow['prepaid_balance'] ?? 0), 2),
    ], 'A');
}

function recalculateTabBalance(PDO $db, $tabId) {
    ensureTabPrepaidBalanceColumn($db);
    ensureTabGratuityColumns($db);
    ensureTabItemVoidMarkColumns($db);
    tab_apply_prepaid_balance_to_items($db, (int) $tabId);
    $balanceStmt = $db->prepare("
        SELECT 
            COALESCE(SUM(ti.quantity * ti.price), 0) as total_items,
            COALESCE((
                SELECT SUM(tip.amount) 
                FROM tab_item_payments tip
                INNER JOIN tab_items ti2 ON tip.tab_item_id = ti2.id
                WHERE ti2.tab_id = ?
                    AND COALESCE(ti2.marked_for_void, 0) = 0
            ), 0) as total_paid
        FROM tab_items ti
        WHERE ti.tab_id = ?
            AND COALESCE(ti.marked_for_void, 0) = 0
    ");
    $balanceStmt->execute([$tabId, $tabId]);
    $balance = $balanceStmt->fetch(PDO::FETCH_ASSOC);

    $newBalance = floatval($balance['total_items']) - floatval($balance['total_paid']);

    $openingStmt = $db->prepare("SELECT opening_balance, COALESCE(prepaid_balance, 0) as prepaid_balance, gratuity_enabled, COALESCE(gratuity_paid, 0) as gratuity_paid FROM tabs WHERE id = ?");
    $openingStmt->execute([$tabId]);
    $row = $openingStmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return 0.0;
    }
    $openingBalance = floatval($row['opening_balance'] ?? 0);
    $prepaidBalance = floatval($row['prepaid_balance'] ?? 0);
    tab_normalize_gratuity_paid($db, (int) $tabId, $row, true);
    $openingStmt->execute([$tabId]);
    $row = $openingStmt->fetch(PDO::FETCH_ASSOC) ?: $row;
    $gratuityRemaining = tab_gratuity_remaining($db, (int) $tabId, $row);

    $finalBalance = $openingBalance + $newBalance - $prepaidBalance + $gratuityRemaining;

    // #region agent log
    $lineStatsStmt = $db->prepare('SELECT product_name, quantity, price FROM tab_items WHERE tab_id = ?');
    $lineStatsStmt->execute([$tabId]);
    $positiveLineCount = 0;
    $positiveLineTotal = 0.0;
    $prepayLineCount = 0;
    $prepayLineTotal = 0.0;
    foreach ($lineStatsStmt->fetchAll(PDO::FETCH_ASSOC) as $lineRow) {
        $lineTotal = floatval($lineRow['quantity']) * floatval($lineRow['price']);
        if (is_tab_prepayment_line_name($lineRow['product_name'] ?? '')) {
            $prepayLineCount++;
            $prepayLineTotal += $lineTotal;
        } elseif ($lineTotal > 0.01) {
            $positiveLineCount++;
            $positiveLineTotal += $lineTotal;
        }
    }
    tab_debug_log('tab_balance_helper.php:recalculateTabBalance', 'Balance recalculated', [
        'tabId' => (int) $tabId,
        'totalItems' => floatval($balance['total_items'] ?? 0),
        'totalPaid' => floatval($balance['total_paid'] ?? 0),
        'newBalance' => $newBalance,
        'openingBalance' => $openingBalance,
        'prepaidBalanceColumn' => $prepaidBalance,
        'finalBalance' => $finalBalance,
        'positiveLineCount' => $positiveLineCount,
        'positiveLineTotal' => round($positiveLineTotal, 2),
        'prepayLineCount' => $prepayLineCount,
        'prepayLineTotal' => round($prepayLineTotal, 2),
        'negativeBalanceFromPrepayOnly' => ($finalBalance < -0.01 && $positiveLineCount === 0 && $prepayLineCount > 0),
    ], 'A');
    // #endregion

    $updateStmt = $db->prepare("UPDATE tabs SET current_balance = ? WHERE id = ?");
    $updateStmt->execute([$finalBalance, $tabId]);

    return $finalBalance;
}
