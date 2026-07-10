<?php

/**
 * Persist hidden menu / report cards per scope in info.db.
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
