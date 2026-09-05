<?php

require_once __DIR__ . '/../ensure_product_report_schema.php';

/**
 * @return list<string>
 */
function receivingListFetchCategories(PDO $db): array
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

function receivingListBuildRowHtml(array $row): string
{
    $id = (int) ($row['id'] ?? 0);
    $name = htmlspecialchars((string) ($row['name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $category = htmlspecialchars((string) ($row['category'] ?? ''), ENT_QUOTES, 'UTF-8');
    $quantity = (int) ($row['quantity'] ?? 0);
    $price = (float) ($row['price'] ?? 0);
    $buyingPrice = (float) ($row['buying_price'] ?? $price);
    $imageUrl = htmlspecialchars((string) ($row['image_url'] ?? ''), ENT_QUOTES, 'UTF-8');

    $badge = '';
    if ($quantity <= 0) {
        $badge = '<span class="ml-1 sm:ml-2 inline-flex items-center px-1 sm:px-2.5 py-0.5 rounded-full text-[8px] sm:text-xs font-medium bg-red-100 text-red-800">Out</span>';
    } elseif ($quantity < 5) {
        $badge = '<span class="ml-1 sm:ml-2 inline-flex items-center px-1 sm:px-2.5 py-0.5 rounded-full text-[8px] sm:text-xs font-medium bg-yellow-100 text-yellow-800">Low</span>';
    }

    $buyingFormatted = number_format($buyingPrice, 2);
    $priceFormatted = number_format($price, 2);

    return '<tr class="receiving-row hover:bg-gray-50 transition-colors" data-category="' . $category . '" data-product-id="' . $id . '">'
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
        . '<span class="product-price font-medium text-teal-600">N$ ' . $priceFormatted . '</span>'
        . '</td>'
        . '<td class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-4 whitespace-nowrap text-center">'
        . '<div class="buying-price-wrap">'
        . '<input type="number" step="0.01" class="buying-price-input quantity-input px-1 sm:px-2 py-0.5 sm:py-1 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-teal-500 text-center text-[10px] sm:text-xs" '
        . 'placeholder="' . $buyingFormatted . '" value="' . $buyingFormatted . '" data-original-buying-price="' . htmlspecialchars((string) $buyingPrice, ENT_QUOTES, 'UTF-8') . '">'
        . '<span class="receiving-cost-calculator-icon calculator-icon" title="Cost calculator">'
        . '<i class="fas fa-calculator text-gray-500 hover:text-teal-500 cursor-pointer text-[10px] sm:text-xs"></i>'
        . '</span></div>'
        . '</td>'
        . '<td class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-4 whitespace-nowrap text-center text-[10px] sm:text-xs lg:text-sm text-black-500">'
        . '<span class="current-stock">' . $quantity . '</span>'
        . '</td>'
        . '<td class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-4 whitespace-nowrap text-center">'
        . '<input type="number" step="any" class="receiving-quantity quantity-input px-1 sm:px-2 py-0.5 sm:py-1 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-teal-500 text-center text-[10px] sm:text-xs" placeholder="0" title="Positive to receive. Negative to transfer stock out.">'
        . '</td>'
        . '<td class="px-1 sm:px-2 lg:px-6 py-2 sm:py-3 lg:py-4 whitespace-nowrap text-center text-[10px] sm:text-xs lg:text-sm text-black-500">'
        . '<span class="new-total">' . $quantity . '</span>'
        . '</td>'
        . '</tr>';
}

/**
 * @param array<string, mixed> $params
 * @return array{html: string, items: array<int, array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int}
 */
function receivingListFetchPage(PDO $db, array $params): array
{
    ensureProductReportSchema($db);
    $lineItemWhere = reportProductWhereInclude();
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
        'name' => 'name COLLATE NOCASE',
        'price' => 'price',
        'quantity' => 'quantity',
    ];
    $sortKey = (string) ($params['sort_col'] ?? 'name');
    $sortCol = $allowedSort[$sortKey] ?? $allowedSort['name'];
    $sortDir = strtoupper((string) ($params['sort_dir'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';

    $where = [$lineItemWhere];
    $bind = [];
    if ($search !== '') {
        $where[] = 'name LIKE :search';
        $bind[':search'] = '%' . $search . '%';
    }
    if ($category !== '') {
        $where[] = "COALESCE(category, '') = :category";
        $bind[':category'] = $category;
    }
    $whereSql = implode(' AND ', $where);

    $countStmt = $db->prepare("SELECT COUNT(*) FROM products WHERE {$whereSql}");
    $countStmt->execute($bind);
    $total = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($total / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;

    $stmt = $db->prepare("SELECT * FROM products WHERE {$whereSql} ORDER BY {$sortCol} {$sortDir} LIMIT :limit OFFSET :offset");
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
        $html .= receivingListBuildRowHtml($row);
        $items[] = [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'quantity' => (int) ($row['quantity'] ?? 0),
            'price' => (float) ($row['price'] ?? 0),
            'buying_price' => (float) ($row['buying_price'] ?? $row['price'] ?? 0),
            'category' => (string) ($row['category'] ?? ''),
        ];
    }

    if ($html === '') {
        $html = '<tr><td colspan="8" class="py-6 px-6 text-center text-gray-500">No products found</td></tr>';
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
