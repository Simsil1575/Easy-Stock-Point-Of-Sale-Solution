<?php
/**
 * Tab prepaid balance (advance payments) + single source of truth for current_balance.
 * current_balance = opening_balance + (sum of tab line totals − payments allocated to lines) − prepaid_balance + unpaid gratuity
 */
require_once __DIR__ . '/ensure_tab_gratuity_columns.php';

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

/** Subtotal for gratuity % — all tab lines except prepayment credits and legacy Gratuity product lines. */
function tab_gratuity_base_subtotal(PDO $db, int $tabId): float
{
    $stmt = $db->prepare('SELECT product_name, quantity, price FROM tab_items WHERE tab_id = ?');
    $stmt->execute([$tabId]);
    $subtotal = 0.0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $line) {
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

/** Waitress, cashier, or manager may request that a tab be voided (manager/admin perform the actual void). */
function can_mark_tab_for_void_from_session(): bool
{
    $r = strtolower(trim((string) ($_SESSION['role'] ?? '')));
    return in_array($r, ['waitress', 'cashier', 'manager'], true);
}

/** Admin and manager see void-pending tabs highlighted in credit-tabs lists. */
function can_view_tab_void_mark_in_list_from_session(): bool
{
    $r = strtolower(trim((string) ($_SESSION['role'] ?? '')));
    return in_array($r, ['admin', 'manager'], true);
}

function tab_is_marked_for_void(array $tab): bool
{
    return (int) ($tab['marked_for_void'] ?? 0) === 1;
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
    $balanceStmt = $db->prepare("
        SELECT 
            COALESCE(SUM(ti.quantity * ti.price), 0) as total_items,
            COALESCE((
                SELECT SUM(tip.amount) 
                FROM tab_item_payments tip
                INNER JOIN tab_items ti2 ON tip.tab_item_id = ti2.id
                WHERE ti2.tab_id = ?
            ), 0) as total_paid
        FROM tab_items ti
        WHERE ti.tab_id = ?
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
