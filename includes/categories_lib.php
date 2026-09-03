<?php

declare(strict_types=1);

function catEnsureTable(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS product_categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE COLLATE NOCASE,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $db->exec('CREATE INDEX IF NOT EXISTS idx_product_categories_name ON product_categories(name COLLATE NOCASE)');
}

/**
 * @return list<array{category: string, product_count: int, total_qty: float, stock_value: float, registered: bool}>
 */
function catListMerged(PDO $db): array
{
    catEnsureTable($db);

    $fromProducts = [];
    $stmt = $db->query("
        SELECT
            TRIM(category) AS category,
            COUNT(*) AS product_count,
            COALESCE(SUM(quantity), 0) AS total_qty,
            COALESCE(SUM(quantity * price), 0) AS stock_value
        FROM products
        WHERE category IS NOT NULL AND TRIM(category) != ''
        GROUP BY TRIM(category)
    ");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $fromProducts[$row['category']] = [
            'category' => $row['category'],
            'product_count' => (int) $row['product_count'],
            'total_qty' => (float) $row['total_qty'],
            'stock_value' => (float) $row['stock_value'],
            'registered' => false,
        ];
    }

    $registered = $db->query('SELECT name FROM product_categories ORDER BY name COLLATE NOCASE')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($registered as $name) {
        $name = trim((string) $name);
        if ($name === '') {
            continue;
        }
        if (!isset($fromProducts[$name])) {
            $fromProducts[$name] = [
                'category' => $name,
                'product_count' => 0,
                'total_qty' => 0.0,
                'stock_value' => 0.0,
                'registered' => true,
            ];
        } else {
            $fromProducts[$name]['registered'] = true;
        }
    }

    $list = array_values($fromProducts);
    usort($list, static fn($a, $b) => strcasecmp($a['category'], $b['category']));
    return $list;
}

function catNameExists(PDO $db, string $name): bool
{
    $name = trim($name);
    if ($name === '') {
        return false;
    }
    catEnsureTable($db);

    $reg = $db->prepare('SELECT 1 FROM product_categories WHERE name = ? COLLATE NOCASE LIMIT 1');
    $reg->execute([$name]);
    if ($reg->fetchColumn()) {
        return true;
    }

    $prod = $db->prepare("SELECT 1 FROM products WHERE TRIM(category) = ? COLLATE NOCASE LIMIT 1");
    $prod->execute([$name]);
    return (bool) $prod->fetchColumn();
}

/** @throws RuntimeException */
function catCreate(PDO $db, string $name): void
{
    $name = trim($name);
    if ($name === '') {
        throw new RuntimeException('Category name is required.');
    }
    if (strlen($name) > 120) {
        throw new RuntimeException('Category name is too long (max 120 characters).');
    }

    catEnsureTable($db);

    if (catNameExists($db, $name)) {
        // Ensure it exists in the registry even if only on products today
        $ins = $db->prepare('INSERT OR IGNORE INTO product_categories (name) VALUES (?)');
        $ins->execute([$name]);
        if ((int) $ins->rowCount() === 0) {
            throw new RuntimeException('Category already exists.');
        }
        return;
    }

    $db->prepare('INSERT INTO product_categories (name) VALUES (?)')->execute([$name]);
}

/**
 * Remove a category from the registry and unassign it from products.
 *
 * @throws RuntimeException
 */
function catDelete(PDO $db, string $name): int
{
    $name = trim($name);
    if ($name === '') {
        throw new RuntimeException('Category is required.');
    }

    $existing = catFindByName($db, $name);
    if (!$existing) {
        throw new RuntimeException('Category not found.');
    }
    $canonical = (string) $existing['category'];

    catEnsureTable($db);

    $updated = 0;
    $db->beginTransaction();
    try {
        $del = $db->prepare('DELETE FROM product_categories WHERE name = ? COLLATE NOCASE');
        $del->execute([$canonical]);

        $upd = $db->prepare("UPDATE products SET category = NULL WHERE TRIM(category) = ? COLLATE NOCASE");
        $upd->execute([$canonical]);
        $updated = (int) $upd->rowCount();

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    return $updated;
}

/** Ensure category exists in registry without error if already present. */
function catEnsureRegistered(PDO $db, string $name): void
{
    $name = trim($name);
    if ($name === '') {
        return;
    }
    catEnsureTable($db);
    $db->prepare('INSERT OR IGNORE INTO product_categories (name) VALUES (?)')->execute([$name]);
}

/**
 * @return list<array{id: int, name: string, quantity: float, price: float, category: string}>
 */
function catListProductsInCategory(PDO $db, string $category): array
{
    $category = trim($category);
    if ($category === '') {
        return [];
    }
    $stmt = $db->prepare("
        SELECT id, name, quantity, price, COALESCE(TRIM(category), '') AS category
        FROM products
        WHERE TRIM(category) = ? COLLATE NOCASE
        ORDER BY name COLLATE NOCASE
    ");
    $stmt->execute([$category]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * All products for the assign-to-category picker (client filters by target category).
 *
 * @return list<array{id: int, name: string, quantity: float, price: float, category: string}>
 */
function catListAllProductsForAssign(PDO $db): array
{
    $stmt = $db->query("
        SELECT id, name, quantity, price, COALESCE(TRIM(category), '') AS category
        FROM products
        ORDER BY name COLLATE NOCASE
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Products not already in the target category (for assignment picker).
 *
 * @return list<array{id: int, name: string, quantity: float, price: float, category: string}>
 */
function catListProductsForPicker(PDO $db, string $targetCategory): array
{
    $targetCategory = trim($targetCategory);
    if ($targetCategory === '') {
        return [];
    }
    $stmt = $db->prepare("
        SELECT id, name, quantity, price, COALESCE(TRIM(category), '') AS category
        FROM products
        WHERE category IS NULL OR TRIM(category) = '' OR TRIM(category) != ? COLLATE NOCASE
        ORDER BY
            CASE WHEN category IS NULL OR TRIM(category) = '' THEN 0 ELSE 1 END,
            name COLLATE NOCASE
    ");
    $stmt->execute([$targetCategory]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @throws RuntimeException */
function catAssignProducts(PDO $db, string $category, array $productIds): int
{
    $category = trim($category);
    if ($category === '') {
        throw new RuntimeException('Category is required.');
    }
    catEnsureTable($db);
    catEnsureRegistered($db, $category);

    $ids = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn($id) => $id > 0)));
    if (empty($ids)) {
        throw new RuntimeException('Select at least one product.');
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("UPDATE products SET category = ? WHERE id IN ($placeholders)");
    $stmt->execute(array_merge([$category], $ids));
    return (int) $stmt->rowCount();
}

/** @throws RuntimeException */
function catRemoveProducts(PDO $db, array $productIds): int
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn($id) => $id > 0)));
    if (empty($ids)) {
        throw new RuntimeException('Select at least one product.');
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("UPDATE products SET category = NULL WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    return (int) $stmt->rowCount();
}

function catFindByName(PDO $db, string $name): ?array
{
    $name = trim($name);
    if ($name === '') {
        return null;
    }
    foreach (catListMerged($db) as $row) {
        if (strcasecmp($row['category'], $name) === 0) {
            return $row;
        }
    }
    return null;
}

/**
 * @return list<string>
 */
function catListNames(PDO $db): array
{
    catEnsureTable($db);
    $names = $db->query('SELECT name FROM product_categories ORDER BY name COLLATE NOCASE')->fetchAll(PDO::FETCH_COLUMN);
    $merged = catListMerged($db);
    foreach ($merged as $row) {
        if (!in_array($row['category'], $names, true)) {
            $names[] = $row['category'];
        }
    }
    usort($names, 'strcasecmp');
    return array_values(array_unique($names));
}
