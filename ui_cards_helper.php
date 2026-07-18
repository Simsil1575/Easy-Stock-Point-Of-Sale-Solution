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
