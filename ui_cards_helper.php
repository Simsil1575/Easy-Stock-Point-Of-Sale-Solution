<?php

/**
 * Persist hidden menu / report cards and card order per scope in info.db.
 */

function ensureUiCardsSchema(PDO $infoDb): void
{
    $infoDb->exec("
        CREATE TABLE IF NOT EXISTS hidden_ui_cards (
            scope TEXT NOT NULL,
            card_id TEXT NOT NULL,
            hidden_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (scope, card_id)
        )
    ");
    $infoDb->exec("
        CREATE TABLE IF NOT EXISTS ui_card_order (
            scope TEXT NOT NULL,
            card_id TEXT NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (scope, card_id)
        )
    ");
}

/**
 * @return list<string>
 */
function uiGetHiddenCards(PDO $infoDb, string $scope): array
{
    ensureUiCardsSchema($infoDb);
    $st = $infoDb->prepare('SELECT card_id FROM hidden_ui_cards WHERE scope = ? ORDER BY card_id COLLATE NOCASE');
    $st->execute([$scope]);
    return $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

/**
 * @return list<string> card ids in display order
 */
function uiGetCardOrder(PDO $infoDb, string $scope): array
{
    ensureUiCardsSchema($infoDb);
    $st = $infoDb->prepare('SELECT card_id FROM ui_card_order WHERE scope = ? ORDER BY sort_order ASC, card_id COLLATE NOCASE ASC');
    $st->execute([$scope]);
    return $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

/**
 * @param list<string> $cardIds ordered list of card ids
 */
function uiSaveCardOrder(PDO $infoDb, string $scope, array $cardIds): int
{
    ensureUiCardsSchema($infoDb);
    $infoDb->beginTransaction();
    try {
        $del = $infoDb->prepare('DELETE FROM ui_card_order WHERE scope = ?');
        $del->execute([$scope]);
        $ins = $infoDb->prepare('INSERT INTO ui_card_order (scope, card_id, sort_order) VALUES (?, ?, ?)');
        $count = 0;
        $order = 0;
        foreach ($cardIds as $cardId) {
            $cardId = trim((string) $cardId);
            if ($cardId === '') {
                continue;
            }
            $ins->execute([$scope, $cardId, $order]);
            $order++;
            $count++;
        }
        $infoDb->commit();
        return $count;
    } catch (Throwable $e) {
        $infoDb->rollBack();
        throw $e;
    }
}

/**
 * @param list<string> $cardIds
 */
function uiHideCards(PDO $infoDb, string $scope, array $cardIds): int
{
    ensureUiCardsSchema($infoDb);
    $ins = $infoDb->prepare('INSERT OR IGNORE INTO hidden_ui_cards (scope, card_id) VALUES (?, ?)');
    $count = 0;
    foreach ($cardIds as $cardId) {
        $cardId = trim((string) $cardId);
        if ($cardId === '') {
            continue;
        }
        $ins->execute([$scope, $cardId]);
        $count++;
    }
    return $count;
}

/**
 * @param list<string> $cardIds
 */
function uiShowCards(PDO $infoDb, string $scope, array $cardIds): int
{
    ensureUiCardsSchema($infoDb);
    $del = $infoDb->prepare('DELETE FROM hidden_ui_cards WHERE scope = ? AND card_id = ?');
    $count = 0;
    foreach ($cardIds as $cardId) {
        $cardId = trim((string) $cardId);
        if ($cardId === '') {
            continue;
        }
        $del->execute([$scope, $cardId]);
        $count += $del->rowCount();
    }
    return $count;
}

/**
 * Clear custom order and unhide all cards for a scope (back to page defaults).
 */
function uiResetCardsToDefault(PDO $infoDb, string $scope): array
{
    ensureUiCardsSchema($infoDb);
    $orderDel = $infoDb->prepare('DELETE FROM ui_card_order WHERE scope = ?');
    $orderDel->execute([$scope]);
    $hiddenDel = $infoDb->prepare('DELETE FROM hidden_ui_cards WHERE scope = ?');
    $hiddenDel->execute([$scope]);
    return [
        'order_cleared' => $orderDel->rowCount(),
        'hidden_cleared' => $hiddenDel->rowCount(),
    ];
}

/**
 * @return list<string>
 */
function uiAllCardScopes(): array
{
    return [
        'admin_menu',
        'manager_menu',
        'admin_reports',
        'manager_reports',
        'cashier_menu',
        'cashier_reports',
    ];
}

function uiBaseScope(string $scope): string
{
    $pos = strpos($scope, ':');
    return $pos === false ? $scope : substr($scope, 0, $pos);
}

function uiPersonalScope(string $baseScope, string $userKey): string
{
    $userKey = trim($userKey);
    if ($userKey === '') {
        return $baseScope;
    }
    $safeKey = preg_replace('/[^a-zA-Z0-9_.@-]/', '_', $userKey);
    return $baseScope . ':' . $safeKey;
}

/**
 * @return array{allow_menu: bool, allow_transactions: bool, allow_reports: bool, allow_settings: bool}
 */
function uiGetCashierPermissions(PDO $infoDb): array
{
    $defaults = [
        'allow_menu' => true,
        'allow_transactions' => true,
        'allow_reports' => true,
        'allow_settings' => true,
    ];
    try {
        ensureUiCardsSchema($infoDb);
        $row = $infoDb->query('SELECT * FROM cashier_permissions LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return $defaults;
        }
        return [
            'allow_menu' => array_key_exists('allow_menu', $row) ? (bool) $row['allow_menu'] : true,
            'allow_transactions' => array_key_exists('allow_transactions', $row) ? (bool) $row['allow_transactions'] : true,
            'allow_reports' => array_key_exists('allow_reports', $row) ? (bool) $row['allow_reports'] : true,
            'allow_settings' => array_key_exists('allow_settings', $row) ? (bool) $row['allow_settings'] : true,
        ];
    } catch (Throwable $e) {
        return $defaults;
    }
}

function uiCanCustomizeScope(string $role, string $scope, PDO $infoDb, string $userId): bool
{
    $role = strtolower(trim($role));
    $userId = trim($userId);
    $baseScope = uiBaseScope($scope);
    if (!in_array($baseScope, uiAllCardScopes(), true)) {
        return false;
    }

    if ($role === 'manager') {
        return false;
    }

    $roleScopes = [
        'admin' => ['admin_menu', 'admin_reports', 'manager_menu', 'manager_reports', 'cashier_menu', 'cashier_reports'],
        'cashier' => ['cashier_menu', 'cashier_reports'],
        'waitress' => ['cashier_menu', 'cashier_reports'],
    ];

    if (!isset($roleScopes[$role]) || !in_array($baseScope, $roleScopes[$role], true)) {
        return false;
    }

    if (in_array($baseScope, ['cashier_menu', 'cashier_reports'], true)) {
        if ($scope !== uiPersonalScope($baseScope, $userId)) {
            return false;
        }
        if ($role === 'admin') {
            return true;
        }
        if (!in_array($role, ['cashier', 'waitress'], true)) {
            return false;
        }
        $perms = uiGetCashierPermissions($infoDb);
        if ($baseScope === 'cashier_menu') {
            return !empty($perms['allow_menu']);
        }
        return !empty($perms['allow_reports']);
    }

    return $scope === $baseScope;
}

/**
 * Shared page bootstrap for card hide/order/customize UI.
 *
 * @return array{
 *   uiCardScope: string,
 *   hiddenUiCards: list<string>,
 *   orderedUiCards: list<string>,
 *   showHiddenUiCards: bool,
 *   uiCardsCustomizeMode: bool,
 *   uiCardsCanCustomize: bool
 * }
 */
function uiCardsInitPageState(PDO $infoDb, string $baseScope, string $userId, bool $personalScope = false, ?string $role = null): array
{
    ensureUiCardsSchema($infoDb);
    $scope = $personalScope ? uiPersonalScope($baseScope, $userId) : $baseScope;
    $showHiddenUiCards = isset($_GET['show_hidden']);
    $roleNorm = strtolower(trim((string) ($role ?? '')));
    $canCustomize = $roleNorm !== '' && uiCanCustomizeScope($roleNorm, $scope, $infoDb, $userId);

    return [
        'uiCardScope' => $scope,
        'hiddenUiCards' => uiGetHiddenCards($infoDb, $scope),
        'orderedUiCards' => uiGetCardOrder($infoDb, $scope),
        'showHiddenUiCards' => $showHiddenUiCards,
        'uiCardsCanCustomize' => $canCustomize,
        'uiCardsCustomizeMode' => $canCustomize && (isset($_GET['customize']) || $showHiddenUiCards),
    ];
}
