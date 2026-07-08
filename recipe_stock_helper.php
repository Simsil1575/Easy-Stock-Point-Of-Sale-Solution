<?php

function ensureRecipeTables(PDO $db): void
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

