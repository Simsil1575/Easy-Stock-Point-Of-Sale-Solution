<?php

require_once __DIR__ . '/../ensure_laybye_schema.php';
require_once __DIR__ . '/product_image_helper.php';

/**
 * @return array{lowStock: array<int, array<string, mixed>>, outOfStock: array<int, array<string, mixed>>}
 */
function inventoryListFetchAlerts(PDO $db): array
{
    $lowStock = [];
    $outOfStock = [];
    $stmt = $db->query(
        'SELECT id, name, quantity FROM products WHERE '
        . laybyePaymentProductWhereExclude('name')
        . ' AND quantity < 5 ORDER BY quantity ASC, name COLLATE NOCASE ASC'
    );

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ((int) ($row['quantity'] ?? 0) <= 0) {
            $outOfStock[] = $row;
        } else {
            $lowStock[] = $row;
        }
    }

    return ['lowStock' => $lowStock, 'outOfStock' => $outOfStock];
}

/**
 * @return list<string>
 */
function inventoryListFetchCategories(PDO $db): array
{
    $categories = [];
    $stmt = $db->query(
        "SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category != '' AND "
        . laybyePaymentProductWhereExclude('name')
        . ' ORDER BY category COLLATE NOCASE ASC'
    );
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $categories[] = (string) $row['category'];
    }

    return $categories;
}

function inventoryListBuildRowHtml(array $row): string
{
    $id = (int) ($row['id'] ?? 0);
    $name = htmlspecialchars((string) ($row['name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $quantity = (int) ($row['quantity'] ?? 0);
    $price = htmlspecialchars((string) ($row['price'] ?? '0'), ENT_QUOTES, 'UTF-8');
    $buyingPrice = htmlspecialchars((string) ($row['buying_price'] ?? '0'), ENT_QUOTES, 'UTF-8');
    $category = htmlspecialchars((string) ($row['category'] ?? ''), ENT_QUOTES, 'UTF-8');
    $rawImageUrl = (string) ($row['image_url'] ?? '');
    $hasCustomImage = productImageHasCustomFile($rawImageUrl);
    $imageSrc = htmlspecialchars(productImageDisplayPath($rawImageUrl), ENT_QUOTES, 'UTF-8');
    $nameAttr = htmlspecialchars((string) ($row['name'] ?? ''), ENT_QUOTES, 'UTF-8');

    $imageCell = '<i class="fas fa-cube text-gray-400 text-lg sm:text-xl md:text-2xl lg:text-3xl mobile-table-icon"></i>';
    if ($hasCustomImage) {
        $imageCell = '<img src="' . $imageSrc . '" alt="Product" loading="lazy" decoding="async" class="w-6 h-6 sm:w-8 sm:h-8 md:w-9 md:h-9 lg:w-10 lg:h-10 rounded-lg object-cover mobile-table-image" onerror="this.onerror=null;this.style.display=\'none\';this.nextElementSibling.style.display=\'inline-block\';">'
            . '<i class="fas fa-cube text-gray-400 text-lg sm:text-xl md:text-2xl lg:text-3xl mobile-table-icon" style="display:none;"></i>';
    }

    return '<tr class="hover:bg-gray-50 transition-colors" data-category="' . $category . '" data-product-id="' . $id . '">'
        . '<td class="px-2 sm:px-3 md:px-4 lg:px-6 py-2 sm:py-3 md:py-4 lg:py-4 whitespace-nowrap text-[10px] sm:text-xs md:text-sm lg:text-sm font-medium text-black-900 truncate" title="' . $name . '">' . $name . '</td>'
        . '<td class="px-2 sm:px-3 md:px-4 lg:px-6 py-2 sm:py-3 md:py-4 lg:py-4 whitespace-nowrap text-center text-[10px] sm:text-xs md:text-sm lg:text-sm text-black-500">' . $quantity . '</td>'
        . '<td class="px-2 sm:px-3 md:px-4 lg:px-6 py-2 sm:py-3 md:py-4 lg:py-4 whitespace-nowrap text-center text-[10px] sm:text-xs md:text-sm lg:text-sm text-black-500">' . $price . '</td>'
        . '<td class="px-2 sm:px-3 md:px-4 lg:px-6 py-2 sm:py-3 md:py-4 lg:py-4 whitespace-nowrap text-center text-[10px] sm:text-xs md:text-sm lg:text-sm text-black-500">' . $buyingPrice . '</td>'
        . '<td class="px-2 sm:px-3 md:px-4 lg:px-6 py-2 sm:py-3 md:py-4 lg:py-4 whitespace-nowrap text-center"><div class="flex items-center justify-center relative">'
        . $imageCell . '</div></td>'
        . '<td class="px-2 sm:px-3 md:px-4 lg:px-6 py-2 sm:py-3 md:py-4 lg:py-4 whitespace-nowrap text-center text-[10px] sm:text-xs md:text-sm lg:text-sm font-medium">'
        . '<a href="edit.php?id=' . $id . '" class="text-teal-600 hover:text-teal-900 mr-0.5 sm:mr-3 lg:mr-3 px-0.5 py-0.5 inline-flex items-center justify-center" title="Edit">'
        . '<svg class="w-3.5 h-3.5 sm:hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>'
        . '<span class="hidden sm:inline">Edit</span></a>'
        . '<a href="#" data-product-id="' . $id . '" data-product-name="' . $nameAttr . '" class="delete-link text-red-600 hover:text-red-900 px-0.5 py-0.5 inline-flex items-center justify-center" title="Delete">'
        . '<svg class="w-3.5 h-3.5 sm:hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>'
        . '<span class="hidden sm:inline">Delete</span></a>'
        . '</td></tr>';
}

/**
 * @param array<string, mixed> $params
 * @return array{html: string, items: array<int, array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int}
 */
function inventoryListFetchPage(PDO $db, array $params): array
{
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
        'quantity' => 'quantity',
        'price' => 'price',
        'buying_price' => 'buying_price',
    ];
    $sortKey = (string) ($params['sort_col'] ?? 'name');
    $sortCol = $allowedSort[$sortKey] ?? $allowedSort['name'];
    $sortDir = strtoupper((string) ($params['sort_dir'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';

    $where = [laybyePaymentProductWhereExclude('name')];
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
        $html .= inventoryListBuildRowHtml($row);
        $items[] = [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'quantity' => (int) ($row['quantity'] ?? 0),
            'price' => (float) ($row['price'] ?? 0),
            'buying_price' => (float) ($row['buying_price'] ?? 0),
            'category' => (string) ($row['category'] ?? ''),
        ];
    }

    if ($html === '') {
        $html = '<tr><td colspan="6" class="py-6 px-6 text-center text-gray-500">No products found</td></tr>';
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
