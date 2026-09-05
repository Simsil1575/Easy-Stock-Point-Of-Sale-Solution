<?php

require_once __DIR__ . '/../ensure_product_report_schema.php';

/**
 * @return list<string>
 */
function stockTakingListFetchCategories(PDO $db): array
{
    ensureProductReportSchema($db);
    $lineItemWhere = reportProductWhereInclude();
    $categories = [];
    $stmt = $db->query(
        "SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category != '' AND {$lineItemWhere} ORDER BY category COLLATE NOCASE ASC"
    );
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $categories[] = (string) $row['category'];
    }

    return $categories;
}

function stockTakingListMetricsSelectSql(): string
{
    return '
        COALESCE(
            (SELECT SUM(oi.quantity)
             FROM order_items oi
             JOIN orders o ON oi.order_id = o.id
             WHERE oi.product_name = p.name
             AND DATE(o.created_at) = date("now")
            ), 0
        ) + COALESCE(
            (SELECT SUM(csi.quantity)
             FROM credit_sale_items csi
             JOIN credit_sales cs ON csi.sale_id = cs.id
             WHERE csi.product_name = p.name
             AND DATE(cs.created_at) = date("now")
            ), 0
        ) AS total_sold,
        (SELECT COALESCE(SUM(sc.quantity_change), 0)
         FROM stock_changes sc
         WHERE sc.product_id = p.id
         AND sc.action = "Restock"
         AND DATE(sc.changed_at) = date("now")) AS received_stock,
        COALESCE(
            (SELECT os.opening_quantity
             FROM opening_stock os
             WHERE os.product_id = p.id
             AND os.recorded_at >= date("now", "start of day")
             ORDER BY os.recorded_at DESC
             LIMIT 1),
            (SELECT os.opening_quantity
             FROM opening_stock os
             WHERE os.product_id = p.id
             ORDER BY os.recorded_at DESC
             LIMIT 1),
            0
        ) AS opening_stock
    ';
}

function stockTakingListBuildRowHtml(array $row): string
{
    $id = (int) ($row['id'] ?? 0);
    $name = htmlspecialchars((string) ($row['name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $category = htmlspecialchars((string) ($row['category'] ?? ''), ENT_QUOTES, 'UTF-8');
    $quantity = (int) ($row['quantity'] ?? 0);
    $openingStock = (int) ($row['opening_stock'] ?? 0);
    $receivedStock = (int) ($row['received_stock'] ?? 0);
    $totalSold = (int) ($row['total_sold'] ?? 0);
    $price = htmlspecialchars((string) ($row['price'] ?? 0), ENT_QUOTES, 'UTF-8');
    $buyingPrice = htmlspecialchars((string) ($row['buying_price'] ?? 0), ENT_QUOTES, 'UTF-8');
    $imageUrl = htmlspecialchars((string) ($row['image_url'] ?? ''), ENT_QUOTES, 'UTF-8');

    $badge = '';
    if ($quantity <= 0) {
        $badge = '<span class="ml-1 sm:ml-2 inline-flex items-center px-1 sm:px-2.5 py-0.5 rounded-full text-[8px] sm:text-xs font-medium bg-red-100 text-red-800">Out</span>';
    } elseif ($quantity < 5) {
        $badge = '<span class="ml-1 sm:ml-2 inline-flex items-center px-1 sm:px-2.5 py-0.5 rounded-full text-[8px] sm:text-xs font-medium bg-yellow-100 text-yellow-800">Low</span>';
    }

    return '<tr class="stock-taking-row hover:bg-gray-50 transition-colors" data-category="' . $category . '" data-product-id="' . $id . '" data-sold="' . $totalSold . '" data-opening="' . $openingStock . '" data-received="' . $receivedStock . '" data-price="' . $price . '" data-buying-price="' . $buyingPrice . '" data-quantity="' . $quantity . '">'
        . '<td class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-4 whitespace-nowrap text-center">'
        . '<input type="checkbox" class="product-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500" data-product-id="' . $id . '">'
        . '</td>'
        . '<td class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-4 whitespace-nowrap text-center">'
        . '<div class="flex items-center justify-center"><img src="../products/' . $imageUrl . '" alt="Product" loading="lazy" decoding="async" class="w-6 h-6 sm:w-8 sm:h-8 lg:w-10 lg:h-10 rounded-lg object-cover" onerror="this.onerror=null;this.style.display=\'none\';this.nextElementSibling.style.display=\'inline-block\';"><i class="fas fa-cube text-gray-400 text-xl sm:text-2xl lg:text-3xl" style="display:none;"></i></div>'
        . '</td>'
        . '<td class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-4 whitespace-nowrap text-[10px] sm:text-xs lg:text-sm font-medium text-black-900 truncate" title="' . $name . '">'
        . $name . $badge
        . '</td>'
        . '<td class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-4 whitespace-nowrap text-center text-[10px] sm:text-xs lg:text-sm text-black-500">'
        . '<span class="expected-stock">' . $openingStock . '</span>'
        . '<span class="expected-closing-stock" style="display: none;">' . $quantity . '</span>'
        . '</td>'
        . '<td class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-4 whitespace-nowrap text-center text-[10px] sm:text-xs lg:text-sm text-black-500 received-stock-cell" style="display: none;">'
        . '<span class="received-stock">' . $receivedStock . '</span>'
        . '</td>'
        . '<td class="sold-today-cell px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-4 whitespace-nowrap text-center text-[10px] sm:text-xs lg:text-sm text-black-500" style="display: none;">'
        . '<span class="sold-today">' . $totalSold . '</span>'
        . '</td>'
        . '<td class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-4 whitespace-nowrap text-center">'
        . '<input type="number" min="0" step="any" class="actual-quantity quantity-input px-1 sm:px-2 py-0.5 sm:py-1 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-teal-500 text-center text-[10px] sm:text-xs" placeholder="0" value="">'
        . '</td>'
        . '<td class="adjusted-actual-cell px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-4 whitespace-nowrap text-center text-[10px] sm:text-xs lg:text-sm text-black-500" style="display: none;">'
        . '<span class="adjusted-actual text-gray-400">—</span>'
        . '</td>'
        . '<td class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-4 whitespace-nowrap text-center text-[10px] sm:text-xs lg:text-sm font-semibold">'
        . '<span class="count-difference text-gray-400">—</span>'
        . '</td>'
        . '</tr>';
}

/**
 * @param array<string, mixed> $params
 * @return array{html: string, items: array<int, array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int}
 */
function stockTakingListFetchPage(PDO $db, array $params): array
{
    ensureProductReportSchema($db);
    $lineItemWhere = reportProductWhereInclude('p');
    $page = max(1, (int) ($params['page'] ?? 1));
    $viewAll = !empty($params['view_all']);
    $perPage = (int) ($params['per_page'] ?? 6);
    if ($perPage < 1) {
        $perPage = 6;
    }
    if ($viewAll) {
        $perPage = 10000;
        $page = 1;
    }

    $search = trim((string) ($params['search'] ?? ''));
    $category = trim((string) ($params['category'] ?? ''));
    $allowedSort = [
        'name' => 'p.name COLLATE NOCASE',
        'quantity' => 'p.quantity',
        'opening_stock' => 'opening_stock',
        'received_stock' => 'received_stock',
        'total_sold' => 'total_sold',
    ];
    $sortKey = (string) ($params['sort_col'] ?? 'name');
    $sortCol = $allowedSort[$sortKey] ?? $allowedSort['name'];
    $sortDir = strtoupper((string) ($params['sort_dir'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';

    $where = [$lineItemWhere];
    $bind = [];
    if ($search !== '') {
        $where[] = 'p.name LIKE :search';
        $bind[':search'] = '%' . $search . '%';
    }
    if ($category !== '') {
        $where[] = "COALESCE(p.category, '') = :category";
        $bind[':category'] = $category;
    }
    $whereSql = implode(' AND ', $where);
    $metricsSql = stockTakingListMetricsSelectSql();

    $countStmt = $db->prepare("SELECT COUNT(*) FROM products p WHERE {$whereSql}");
    $countStmt->execute($bind);
    $total = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($total / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;

    $stmt = $db->prepare("
        SELECT p.*, {$metricsSql}
        FROM products p
        WHERE {$whereSql}
        ORDER BY {$sortCol} {$sortDir}
        LIMIT :limit OFFSET :offset
    ");
    foreach ($bind as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $html = '';
    $items = [];
    foreach ($rows as $row) {
        $html .= stockTakingListBuildRowHtml($row);
        $items[] = [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'quantity' => (int) ($row['quantity'] ?? 0),
            'opening_stock' => (int) ($row['opening_stock'] ?? 0),
            'received_stock' => (int) ($row['received_stock'] ?? 0),
            'total_sold' => (int) ($row['total_sold'] ?? 0),
            'price' => (float) ($row['price'] ?? 0),
            'category' => (string) ($row['category'] ?? ''),
        ];
    }

    if ($html === '') {
        $html = '<tr><td colspan="9" class="py-6 px-6 text-center text-gray-500">No products found</td></tr>';
    }

    return [
        'html' => $html,
        'items' => $items,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => $totalPages,
    ];
}
