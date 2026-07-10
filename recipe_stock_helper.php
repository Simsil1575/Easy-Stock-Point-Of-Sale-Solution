<?php

function configureSqlitePdo(PDO $db): void
{
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA busy_timeout=5000');
}

function configureSqlite3(SQLite3 $db): void
{
    $db->busyTimeout(5000);
    $db->exec('PRAGMA journal_mode=WAL');
}

function ensureRecipeTablesSQLite(SQLite3 $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS product_recipes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL UNIQUE,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (product_id) REFERENCES products(id)
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS recipe_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            recipe_id INTEGER NOT NULL,
            ingredient_product_id INTEGER NOT NULL,
            quantity_per_unit DECIMAL(10,4) NOT NULL DEFAULT 0,
            FOREIGN KEY (recipe_id) REFERENCES product_recipes(id) ON DELETE CASCADE,
            FOREIGN KEY (ingredient_product_id) REFERENCES products(id)
        )
    ");
}

function ensureRecipeTables(PDO $db): void
{
    configureSqlitePdo($db);
    $db->exec("
        CREATE TABLE IF NOT EXISTS product_recipes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL UNIQUE,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (product_id) REFERENCES products(id)
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS recipe_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            recipe_id INTEGER NOT NULL,
            ingredient_product_id INTEGER NOT NULL,
            quantity_per_unit DECIMAL(10,4) NOT NULL DEFAULT 0,
            FOREIGN KEY (recipe_id) REFERENCES product_recipes(id) ON DELETE CASCADE,
            FOREIGN KEY (ingredient_product_id) REFERENCES products(id)
        )
    ");
}

function deductRecipeStockByProductName(PDO $db, string $productName, float $soldQty): bool
{
    if ($soldQty <= 0) {
        return false;
    }

    ensureRecipeTables($db);

    $recipeQuery = $db->prepare("
        SELECT ri.ingredient_product_id, ri.quantity_per_unit
        FROM products p
        INNER JOIN product_recipes pr ON pr.product_id = p.id
        INNER JOIN recipe_items ri ON ri.recipe_id = pr.id
        WHERE p.name = :product_name
    ");
    $recipeQuery->execute([':product_name' => $productName]);
    $ingredients = $recipeQuery->fetchAll(PDO::FETCH_ASSOC);

    if (empty($ingredients)) {
        return false;
    }

    $deductStmt = $db->prepare("
        UPDATE products
        SET quantity = quantity - :deduct_qty
        WHERE id = :ingredient_id
    ");

    foreach ($ingredients as $ingredient) {
        $deductQty = floatval($ingredient['quantity_per_unit']) * $soldQty;
        if ($deductQty <= 0) {
            continue;
        }
        $deductStmt->execute([
            ':deduct_qty' => $deductQty,
            ':ingredient_id' => intval($ingredient['ingredient_product_id'])
        ]);
    }

    return true;
}

/**
 * Restore ingredient quantities when reversing a sale that used recipe stock.
 */
function restoreRecipeStockByProductName(PDO $db, string $productName, float $soldQty): bool
{
    if ($soldQty <= 0) {
        return false;
    }

    ensureRecipeTables($db);

    $recipeQuery = $db->prepare("
        SELECT ri.ingredient_product_id, ri.quantity_per_unit
        FROM products p
        INNER JOIN product_recipes pr ON pr.product_id = p.id
        INNER JOIN recipe_items ri ON ri.recipe_id = pr.id
        WHERE p.name = :product_name
    ");
    $recipeQuery->execute([':product_name' => $productName]);
    $ingredients = $recipeQuery->fetchAll(PDO::FETCH_ASSOC);

    if (empty($ingredients)) {
        return false;
    }

    $restoreStmt = $db->prepare("
        UPDATE products
        SET quantity = quantity + :add_qty
        WHERE id = :ingredient_id
    ");

    foreach ($ingredients as $ingredient) {
        $addQty = floatval($ingredient['quantity_per_unit']) * $soldQty;
        if ($addQty <= 0) {
            continue;
        }
        $restoreStmt->execute([
            ':add_qty' => $addQty,
            ':ingredient_id' => intval($ingredient['ingredient_product_id'])
        ]);
    }

    return true;
}

/**
 * When main product stock changes (restock/adjust/receiving), mirror the change onto linked recipe ingredients.
 */
function adjustRecipeStockByProductId(PDO $db, int $productId, float $qtyChange): bool
{
    if (abs($qtyChange) < 0.00001) {
        return false;
    }

    ensureRecipeTables($db);

    $recipeQuery = $db->prepare("
        SELECT ri.ingredient_product_id, ri.quantity_per_unit
        FROM product_recipes pr
        INNER JOIN recipe_items ri ON ri.recipe_id = pr.id
        WHERE pr.product_id = :product_id
    ");
    $recipeQuery->execute([':product_id' => $productId]);
    $ingredients = $recipeQuery->fetchAll(PDO::FETCH_ASSOC);

    if (empty($ingredients)) {
        return false;
    }

    if ($qtyChange > 0) {
        $stmt = $db->prepare("
            UPDATE products
            SET quantity = quantity + :add_qty
            WHERE id = :ingredient_id
        ");
        foreach ($ingredients as $ingredient) {
            $addQty = floatval($ingredient['quantity_per_unit']) * $qtyChange;
            if ($addQty <= 0) {
                continue;
            }
            $stmt->execute([
                ':add_qty' => $addQty,
                ':ingredient_id' => intval($ingredient['ingredient_product_id']),
            ]);
        }
    } else {
        $deductStmt = $db->prepare("
            UPDATE products
            SET quantity = quantity - :deduct_qty
            WHERE id = :ingredient_id
        ");
        $absChange = abs($qtyChange);
        foreach ($ingredients as $ingredient) {
            $deductQty = floatval($ingredient['quantity_per_unit']) * $absChange;
            if ($deductQty <= 0) {
                continue;
            }
            $deductStmt->execute([
                ':deduct_qty' => $deductQty,
                ':ingredient_id' => intval($ingredient['ingredient_product_id']),
            ]);
        }
    }

    return true;
}

/**
 * SQLite3 variant — use the same connection as other stock writes to avoid database locks.
 */
function adjustRecipeStockByProductIdSQLite3(SQLite3 $db, int $productId, float $qtyChange): bool
{
    if (abs($qtyChange) < 0.00001) {
        return false;
    }

    ensureRecipeTablesSQLite($db);

    $recipeQuery = $db->prepare("
        SELECT ri.ingredient_product_id, ri.quantity_per_unit
        FROM product_recipes pr
        INNER JOIN recipe_items ri ON ri.recipe_id = pr.id
        WHERE pr.product_id = :product_id
    ");
    $recipeQuery->bindValue(':product_id', $productId, SQLITE3_INTEGER);
    $result = $recipeQuery->execute();
    $ingredients = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $ingredients[] = $row;
    }

    if (empty($ingredients)) {
        return false;
    }

    if ($qtyChange > 0) {
        $stmt = $db->prepare("
            UPDATE products
            SET quantity = quantity + :add_qty
            WHERE id = :ingredient_id
        ");
        foreach ($ingredients as $ingredient) {
            $addQty = floatval($ingredient['quantity_per_unit']) * $qtyChange;
            if ($addQty <= 0) {
                continue;
            }
            $stmt->bindValue(':add_qty', $addQty, SQLITE3_FLOAT);
            $stmt->bindValue(':ingredient_id', (int) $ingredient['ingredient_product_id'], SQLITE3_INTEGER);
            $stmt->execute();
        }
    } else {
        $deductStmt = $db->prepare("
            UPDATE products
            SET quantity = quantity - :deduct_qty
            WHERE id = :ingredient_id
        ");
        $absChange = abs($qtyChange);
        foreach ($ingredients as $ingredient) {
            $deductQty = floatval($ingredient['quantity_per_unit']) * $absChange;
            if ($deductQty <= 0) {
                continue;
            }
            $deductStmt->bindValue(':deduct_qty', $deductQty, SQLITE3_FLOAT);
            $deductStmt->bindValue(':ingredient_id', (int) $ingredient['ingredient_product_id'], SQLITE3_INTEGER);
            $deductStmt->execute();
        }
    }

    return true;
}

/**
 * Whether this product deducts stock via recipe ingredients (has at least one recipe line).
 */
function laybyeProductHasRecipe(PDO $db, string $productName): bool
{
    ensureRecipeTables($db);
    $recipeQuery = $db->prepare("
        SELECT 1
        FROM products p
        INNER JOIN product_recipes pr ON pr.product_id = p.id
        INNER JOIN recipe_items ri ON ri.recipe_id = pr.id
        WHERE p.name = :product_name
        LIMIT 1
    ");
    $recipeQuery->execute([':product_name' => $productName]);
    return (bool) $recipeQuery->fetchColumn();
}

/**
 * @throws Exception when any ingredient lacks sufficient quantity
 */
function laybyeAssertRecipeIngredientsAvailable(PDO $db, string $productName, float $soldQty): void
{
    if ($soldQty <= 0) {
        return;
    }
    ensureRecipeTables($db);
    $recipeQuery = $db->prepare("
        SELECT ri.ingredient_product_id, ri.quantity_per_unit, ing.name AS ingredient_name
        FROM products p
        INNER JOIN product_recipes pr ON pr.product_id = p.id
        INNER JOIN recipe_items ri ON ri.recipe_id = pr.id
        INNER JOIN products ing ON ing.id = ri.ingredient_product_id
        WHERE p.name = :product_name
    ");
    $recipeQuery->execute([':product_name' => $productName]);
    $ingredients = $recipeQuery->fetchAll(PDO::FETCH_ASSOC);
    if (empty($ingredients)) {
        return;
    }
    $qtyStmt = $db->prepare('SELECT quantity FROM products WHERE id = ?');
    foreach ($ingredients as $ingredient) {
        $deductQty = floatval($ingredient['quantity_per_unit']) * $soldQty;
        if ($deductQty <= 0) {
            continue;
        }
        $qtyStmt->execute([(int) $ingredient['ingredient_product_id']]);
        $avail = floatval($qtyStmt->fetchColumn());
        if ($avail + 0.00001 < $deductQty) {
            $ingName = $ingredient['ingredient_name'] ?? 'ingredient';
            throw new Exception(
                'Insufficient stock for recipe item (' . $productName . '): ' . $ingName
                . ' needs ' . rtrim(rtrim(number_format($deductQty, 4, '.', ''), '0'), '.')
                . ', available ' . rtrim(rtrim(number_format($avail, 4, '.', ''), '0'), '.')
            );
        }
    }
}

/**
 * Enforce stock for lay-bye line adds when POS "Skip stock checks" is off.
 *
 * @param array|null $productInfo Row from products: category, quantity
 * @throws Exception
 */
function laybyeAssertStockForAddItem(PDO $db, string $productName, int $qty, ?array $productInfo, bool $skipStockChecks): void
{
    if ($skipStockChecks || $qty <= 0) {
        return;
    }
    if (!$productInfo) {
        throw new Exception('Product not found: ' . $productName);
    }
    $category = strtolower(trim((string) ($productInfo['category'] ?? '')));
    if ($category === 'food') {
        return;
    }
    if (laybyeProductHasRecipe($db, $productName)) {
        laybyeAssertRecipeIngredientsAvailable($db, $productName, floatval($qty));
    }
    $avail = floatval($productInfo['quantity'] ?? 0);
    if ($avail + 0.00001 < $qty) {
        $availDisp = (abs($avail - round($avail)) < 0.00001) ? (string) (int) round($avail) : number_format($avail, 2);
        throw new Exception('Insufficient stock for ' . $productName . ': available ' . $availDisp . ', requested ' . $qty);
    }
}

