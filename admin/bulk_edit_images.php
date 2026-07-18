<?php

session_start();
date_default_timezone_set('Africa/Harare');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    header('Location: ../');
    exit();
}

require_once __DIR__ . '/../ensure_laybye_schema.php';
require_once __DIR__ . '/../recipe_stock_helper.php';

function isDefaultProductImage(?string $imageUrl): bool
{
    if ($imageUrl === null || $imageUrl === '') {
        return true;
    }
    return $imageUrl === 'default.png'
        || strpos($imageUrl, 'default.png') !== false;
}

function productImageDisplayPath(?string $imageUrl): string
{
    if (isDefaultProductImage($imageUrl)) {
        return '../props/default.png';
    }
    if (strpos($imageUrl, '../') === 0) {
        return $imageUrl;
    }
    if (strpos($imageUrl, 'props/') === 0) {
        return '../' . $imageUrl;
    }
    return '../products/' . $imageUrl;
}

function deleteStoredProductImage(?string $imageUrl): void
{
    if (isDefaultProductImage($imageUrl)) {
        return;
    }

    $filename = basename((string) $imageUrl);
    if ($filename === '' || $filename === 'default.png') {
        return;
    }

    $path = __DIR__ . '/../products/' . $filename;
    if (file_exists($path)) {
        unlink($path);
    }
}

function saveCroppedProductImage(string $base64Data): string
{
    $parts = explode(',', $base64Data, 2);
    if (count($parts) !== 2) {
        throw new InvalidArgumentException('Invalid image data.');
    }

    $binary = base64_decode($parts[1]);
    if ($binary === false) {
        throw new InvalidArgumentException('Could not decode image data.');
    }

    $targetDir = __DIR__ . '/../products/';
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true);
    }

    $filename = uniqid('img_', true) . '.png';
    if (file_put_contents($targetDir . $filename, $binary) === false) {
        throw new RuntimeException('Failed to save image file.');
    }

    return $filename;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload) || !isset($payload['images']) || !is_array($payload['images'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid request payload.']);
        exit();
    }

    $db = new SQLite3(__DIR__ . '/../pos.db');
    configureSqlite3($db);

    $updated = 0;
    $errors = [];
    $savedProducts = [];

    foreach ($payload['images'] as $index => $item) {
        $productId = isset($item['id']) ? (int) $item['id'] : 0;
        $croppedImage = isset($item['cropped_image']) ? trim((string) $item['cropped_image']) : '';
        $currentImage = isset($item['current_image']) ? trim((string) $item['current_image']) : '';

        if ($productId <= 0 || $croppedImage === '') {
            continue;
        }

        try {
            $stmt = $db->prepare('SELECT id, image_url FROM products WHERE id = :id');
            $stmt->bindValue(':id', $productId, SQLITE3_INTEGER);
            $result = $stmt->execute();
            $product = $result->fetchArray(SQLITE3_ASSOC);

            if (!$product) {
                $errors[] = "Product #{$productId} was not found.";
                continue;
            }

            $newImageUrl = saveCroppedProductImage($croppedImage);
            $oldImageUrl = $product['image_url'] ?: $currentImage;

            $updateStmt = $db->prepare('UPDATE products SET image_url = :image_url WHERE id = :id');
            $updateStmt->bindValue(':image_url', $newImageUrl, SQLITE3_TEXT);
            $updateStmt->bindValue(':id', $productId, SQLITE3_INTEGER);
            $updateStmt->execute();

            deleteStoredProductImage($oldImageUrl);
            $updated++;
            $savedProducts[] = [
                'id' => $productId,
                'image_url' => $newImageUrl,
                'image_src' => productImageDisplayPath($newImageUrl),
                'has_image' => true,
            ];
        } catch (Throwable $e) {
            $errors[] = 'Row ' . ($index + 1) . ': ' . $e->getMessage();
        }
    }

    echo json_encode([
        'success' => empty($errors),
        'updated' => $updated,
        'errors' => $errors,
        'products' => $savedProducts,
        'message' => $updated > 0
            ? "Updated {$updated} product image" . ($updated === 1 ? '' : 's') . '.'
            : 'No images were updated.',
    ]);
    exit();
}

$db = new SQLite3(__DIR__ . '/../pos.db');
configureSqlite3($db);

$products = [];
$categories = [];

$query = "
    SELECT id, name, category, image_url
    FROM products
    WHERE " . laybyePaymentProductWhereExclude('name') . "
    ORDER BY name COLLATE NOCASE ASC
";
$result = $db->query($query);

while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $row['has_image'] = !isDefaultProductImage($row['image_url']);
    $row['image_src'] = productImageDisplayPath($row['image_url']);
    $products[] = $row;

    $category = trim((string) ($row['category'] ?? ''));
    if ($category !== '' && !in_array($category, $categories, true)) {
        $categories[] = $category;
    }
}

sort($categories);
$missingImageCount = count(array_filter($products, static fn($product) => !$product['has_image']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk Product Images</title>
    <script src="3.4.16"></script>
    <link rel="stylesheet" href="cropper.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.js"></script>
    <style>
        .toast-notification {
            transition: opacity 0.5s, transform 0.5s;
            transform: translateY(-100%);
            opacity: 0;
            animation: slideIn 0.5s forwards;
        }
        @keyframes slideIn {
            from { transform: translateY(-100%); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .hamburger {
            position: relative;
            width: 30px;
            height: 24px;
            cursor: pointer;
            z-index: 10000;
        }

        .hamburger span {
            display: block;
            position: absolute;
            height: 3px;
            width: 100%;
            background: rgb(0, 0, 0);
            border-radius: 2px;
            opacity: 1;
            left: 0;
            transform: rotate(0deg);
            transition: .25s ease-in-out;
        }

        .hamburger span:nth-child(1) { top: 0; }
        .hamburger span:nth-child(2) { top: 10px; }
        .hamburger span:nth-child(3) { top: 20px; }

        .hamburger.open span:nth-child(1) {
            top: 10px;
            transform: rotate(135deg);
        }

        .hamburger.open span:nth-child(2) {
            opacity: 0;
            left: -60px;
        }

        .hamburger.open span:nth-child(3) {
            top: 10px;
            transform: rotate(-135deg);
        }

        .mobile-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 80;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .mobile-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .sidebar, #sidebar {
            z-index: 10000 !important;
        }

        @media (max-width: 1023px) {
            .ml-64 { margin-left: 0 !important; }
            .flex-1 {
                width: 100%;
                max-width: 100vw;
                overflow-x: hidden;
            }
            .content {
                margin-left: 0 !important;
                width: 100% !important;
                max-width: 100vw !important;
                overflow-x: hidden;
            }
        }

        .product-card {
            transition: box-shadow 0.2s ease, border-color 0.2s ease;
        }

        .product-card.pending {
            border-color: #0d9488;
            box-shadow: 0 0 0 1px #0d9488;
        }

        .product-card .thumb-wrap {
            width: 100%;
            aspect-ratio: 1 / 1;
            background: #f9fafb;
            overflow: hidden;
            border-radius: 0.5rem;
        }

        .product-card img.thumb {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        #cropperModal {
            backdrop-filter: blur(2px);
        }

        #cropperModal.is-open {
            position: fixed !important;
            top: 0 !important;
            right: 0 !important;
            bottom: 0 !important;
            left: 0 !important;
            z-index: 50000 !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            padding: 1rem;
            overflow-y: auto;
        }

        #cropperModal.is-open > .modal-panel {
            margin: auto;
            width: 100%;
            max-width: 42rem;
            max-height: 95vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        #cropperModal .modal-body {
            overflow-y: auto;
            flex: 1 1 auto;
            min-height: 0;
        }

        body.modal-open {
            overflow: hidden;
        }

        #cropperModal .cropper-wrap {
            width: 16rem;
            height: 16rem;
            margin: 0 auto;
            overflow: hidden;
            position: relative;
            background: #fff;
        }
    </style>
</head>
<body class="bg-gray-100">
    <div class="flex">
        <div class="sidebar fixed h-full">
            <?php include 'sidebar.php'; ?>
        </div>

        <div class="content flex-1 lg:ml-64">
            <div id="mobileOverlay" class="mobile-overlay lg:hidden" onclick="closeSidebar()"></div>

            <div class="w-full px-4 lg:px-6 py-6">
                <div class="sticky top-0 z-40 bg-gray-100 py-4 mb-6 flex flex-wrap items-center justify-between gap-4 -mx-4 lg:-mx-6 px-4 lg:px-6 shadow-sm">
                    <div class="flex items-center gap-3">
                        <div class="hamburger lg:hidden bg-[#f3f4f6] p-2 rounded" onclick="toggleSidebar()">
                            <span></span>
                            <span></span>
                            <span></span>
                        </div>
                        <div>
                            <h1 class="text-xl lg:text-2xl font-bold">Bulk Product Images</h1>
                            <p class="text-sm text-gray-500 mt-1">Attach and crop images for multiple products, then save all at once.</p>
                        </div>
                    </div>

                    <div class="flex items-center gap-2 flex-wrap">
                        <a href="inventory" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                            Back to Inventory
                        </a>
                        <button type="button" id="saveAllBtn" disabled
                            class="inline-flex items-center px-4 py-2 rounded-md shadow-sm text-sm font-medium text-white bg-teal-600 hover:bg-teal-700 disabled:opacity-50 disabled:cursor-not-allowed">
                            Save Changes (0)
                        </button>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow-lg p-4 sm:p-6 mb-6">
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div class="md:col-span-2">
                            <label for="searchInput" class="block text-sm font-medium text-gray-700 mb-1">Search products</label>
                            <input type="search" id="searchInput" placeholder="Search by name..."
                                class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500 sm:text-sm">
                        </div>
                        <div>
                            <label for="categoryFilter" class="block text-sm font-medium text-gray-700 mb-1">Category</label>
                            <select id="categoryFilter"
                                class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500 sm:text-sm">
                                <option value="">All categories</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?= htmlspecialchars($category) ?>"><?= htmlspecialchars($category) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="flex items-end">
                            <label class="inline-flex items-center gap-2 text-sm text-gray-700 cursor-pointer">
                                <input type="checkbox" id="missingOnlyFilter" class="rounded border-gray-300 text-teal-600 focus:ring-teal-500">
                                Missing image only (<?= (int) $missingImageCount ?>)
                            </label>
                        </div>
                    </div>
                    <p id="visibleCount" class="text-sm text-gray-500 mt-4"><?= count($products) ?> products shown</p>
                </div>

                <div id="productGrid" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4"></div>
                <div id="emptyState" class="hidden bg-white rounded-lg shadow p-10 text-center text-gray-500">
                    No products match your filters.
                </div>
            </div>
        </div>
    </div>

    <div id="cropperModal" class="fixed inset-0 z-[200] hidden bg-black/50">
        <div class="modal-panel bg-white rounded-lg shadow-xl overflow-hidden flex flex-col">
            <div class="px-5 py-4 border-b border-gray-200 flex items-center justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="text-lg font-semibold text-gray-900 truncate" id="modalProductName">Crop Image</h2>
                    <p class="text-sm text-gray-500">Search Google Images, paste a URL, or upload a file.</p>
                </div>
                <button type="button" id="closeModalBtn" class="text-gray-400 hover:text-gray-600 text-2xl leading-none flex-shrink-0">&times;</button>
            </div>

            <div class="modal-body p-5">
                <div id="modalGoogleSection" class="border border-gray-200 rounded-lg p-4 mb-4 bg-gray-50">
                    <label for="googleSearchInput" class="block text-sm font-medium text-gray-700 mb-2">Search Google Images</label>
                    <div class="flex flex-col sm:flex-row gap-2">
                        <input type="search" id="googleSearchInput" placeholder="Product name for image search"
                            class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500 sm:text-sm">
                        <button type="button" id="googleSearchBtn"
                            class="inline-flex items-center justify-center px-4 py-2 text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 whitespace-nowrap">
                            Search Images
                        </button>
                    </div>
                    <p class="text-xs text-gray-500 mt-2">Opens Google Images in a new tab. Copy an image address and paste it below.</p>

                    <div class="flex flex-col sm:flex-row gap-2 mt-3">
                        <input type="url" id="imageUrlInput" placeholder="Paste image URL from Google"
                            class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500 sm:text-sm">
                        <button type="button" id="loadUrlBtn"
                            class="inline-flex items-center justify-center px-4 py-2 text-sm font-medium rounded-md text-white bg-teal-600 hover:bg-teal-700 whitespace-nowrap">
                            Load URL
                        </button>
                    </div>
                </div>

                <div id="modalDropZone" class="border-2 border-dashed border-gray-300 rounded-lg p-6 text-center mb-4">
                    <label class="inline-flex items-center px-4 py-2 bg-white text-gray-600 rounded-md border border-gray-300 cursor-pointer hover:bg-gray-50">
                        Choose image
                        <input type="file" id="modalFileInput" accept="image/*" class="hidden">
                    </label>
                    <p class="text-sm text-gray-500 mt-2">or drag and drop an image here</p>
                </div>
                <div id="cropperArea" class="hidden">
                    <div class="flex items-center justify-between mb-3">
                        <p class="text-sm text-gray-600">Drag to reposition. Use scroll to zoom.</p>
                        <button type="button" id="resetCropBtn" disabled
                            class="px-3 py-1.5 text-sm border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed">
                            Reset cropper
                        </button>
                    </div>
                    <div class="cropper-wrap">
                        <img id="cropperImage" alt="Crop preview" class="w-full h-full object-cover">
                    </div>
                </div>
            </div>

            <div class="px-5 py-4 border-t border-gray-200 flex flex-wrap justify-end gap-3 flex-shrink-0">
                <button type="button" id="changeImageBtn" disabled
                    class="px-4 py-2 text-sm border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed">
                    Change image
                </button>
                <button type="button" id="clearPendingBtn" class="px-4 py-2 text-sm border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">
                    Clear pending
                </button>
                <button type="button" id="applyCropBtn" disabled
                    class="px-4 py-2 text-sm rounded-md text-white bg-teal-600 hover:bg-teal-700 disabled:opacity-50 disabled:cursor-not-allowed">
                    Apply to product
                </button>
            </div>
        </div>
    </div>

    <div id="toast-container" class="fixed top-4 right-4 z-[300]"></div>

    <script>
        const products = <?= json_encode($products, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        const pendingImages = new Map();
        let activeProductId = null;
        let cropper = null;

        const productGrid = document.getElementById('productGrid');
        const emptyState = document.getElementById('emptyState');
        const visibleCount = document.getElementById('visibleCount');
        const saveAllBtn = document.getElementById('saveAllBtn');
        const searchInput = document.getElementById('searchInput');
        const categoryFilter = document.getElementById('categoryFilter');
        const missingOnlyFilter = document.getElementById('missingOnlyFilter');

        const cropperModal = document.getElementById('cropperModal');
        const modalProductName = document.getElementById('modalProductName');
        const modalFileInput = document.getElementById('modalFileInput');
        const modalDropZone = document.getElementById('modalDropZone');
        const cropperArea = document.getElementById('cropperArea');
        const cropperImage = document.getElementById('cropperImage');
        const applyCropBtn = document.getElementById('applyCropBtn');
        const clearPendingBtn = document.getElementById('clearPendingBtn');
        const closeModalBtn = document.getElementById('closeModalBtn');
        const googleSearchInput = document.getElementById('googleSearchInput');
        const googleSearchBtn = document.getElementById('googleSearchBtn');
        const imageUrlInput = document.getElementById('imageUrlInput');
        const loadUrlBtn = document.getElementById('loadUrlBtn');
        const modalPanel = cropperModal.querySelector('.modal-panel');
        const resetCropBtn = document.getElementById('resetCropBtn');
        const changeImageBtn = document.getElementById('changeImageBtn');
        const modalGoogleSection = document.getElementById('modalGoogleSection');
        const cropperWrap = cropperArea.querySelector('.cropper-wrap');

        function logApplyDiagnostics(hypothesisId, message, data) {
            // #region agent log
            fetch('http://127.0.0.1:7918/ingest/543ece8e-e9a4-4ceb-9f09-b26f1ebce51b', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Debug-Session-Id': '46169c'
                },
                body: JSON.stringify({
                    sessionId: '46169c',
                    runId: 'apply-fix',
                    hypothesisId,
                    location: 'bulk_edit_images.php:applyCropToProduct',
                    message,
                    data,
                    timestamp: Date.now()
                })
            }).catch(() => {});
            // #endregion
        }

        function logModalDiagnostics(runId, hypothesisId, message, data) {
            // #region agent log
            fetch('http://127.0.0.1:7918/ingest/543ece8e-e9a4-4ceb-9f09-b26f1ebce51b', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Debug-Session-Id': '46169c'
                },
                body: JSON.stringify({
                    sessionId: '46169c',
                    runId,
                    hypothesisId,
                    location: 'bulk_edit_images.php:openCropperModal',
                    message,
                    data,
                    timestamp: Date.now()
                })
            }).catch(() => {});
            // #endregion
        }

        function getGoogleImagesUrl(query) {
            const params = new URLSearchParams({
                q: query,
                udm: '2'
            });
            return `https://www.google.com/search?${params.toString()}`;
        }

        function escapeHtml(value) {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function showToast(message, type = 'info') {
            const toast = document.createElement('div');
            toast.className = `toast-notification px-6 py-3 rounded-md text-white shadow-lg ${
                type === 'success' ? 'bg-teal-500' :
                type === 'error' ? 'bg-rose-600' :
                'bg-sky-500'
            }`;
            toast.textContent = message;
            document.getElementById('toast-container').appendChild(toast);
            setTimeout(() => {
                toast.classList.add('opacity-0', '-translate-y-full');
                setTimeout(() => toast.remove(), 500);
            }, 3000);
        }

        function getFilteredProducts() {
            const query = searchInput.value.trim().toLowerCase();
            const category = categoryFilter.value;
            const missingOnly = missingOnlyFilter.checked;

            return products.filter((product) => {
                const matchesQuery = !query || product.name.toLowerCase().includes(query);
                const matchesCategory = !category || (product.category || '') === category;
                const matchesMissing = !missingOnly || !product.has_image || pendingImages.has(String(product.id));
                return matchesQuery && matchesCategory && matchesMissing;
            });
        }

        function updateSaveButton() {
            const count = pendingImages.size;
            saveAllBtn.disabled = count === 0;
            saveAllBtn.textContent = `Save Changes (${count})`;
        }

        function renderProducts() {
            const filtered = getFilteredProducts();
            productGrid.innerHTML = '';

            if (filtered.length === 0) {
                emptyState.classList.remove('hidden');
                visibleCount.textContent = '0 products shown';
                return;
            }

            emptyState.classList.add('hidden');
            visibleCount.textContent = `${filtered.length} product${filtered.length === 1 ? '' : 's'} shown`;

            filtered.forEach((product) => {
                const productId = String(product.id);
                const pending = pendingImages.get(productId);
                const imageSrc = pending ? pending.preview : product.image_src;
                const isPending = Boolean(pending);

                const card = document.createElement('div');
                card.className = `product-card bg-white rounded-lg border border-gray-200 p-4 flex flex-col gap-3${isPending ? ' pending' : ''}`;
                card.dataset.productId = productId;
                card.innerHTML = `
                    <div class="thumb-wrap">
                        <img src="${escapeHtml(imageSrc)}" alt="${escapeHtml(product.name)}" class="thumb"
                            onerror="this.onerror=null;this.src='../props/default.png';">
                    </div>
                    <div class="min-w-0">
                        <h3 class="font-medium text-gray-900 truncate" title="${escapeHtml(product.name)}">${escapeHtml(product.name)}</h3>
                        <p class="text-xs text-gray-500 truncate">${escapeHtml(product.category || 'No category')}</p>
                    </div>
                    <div class="flex items-center justify-between gap-2 mt-auto">
                        <span class="text-xs ${product.has_image || isPending ? 'text-gray-500' : 'text-amber-600 font-medium'}">
                            ${isPending ? 'Pending save' : (product.has_image ? 'Has image' : 'No image')}
                        </span>
                        <button type="button" class="open-cropper px-3 py-1.5 text-xs font-medium rounded-md text-white bg-teal-600 hover:bg-teal-700">
                            ${isPending ? 'Re-crop' : 'Add image'}
                        </button>
                    </div>
                `;

                card.querySelector('.open-cropper').addEventListener('click', () => openCropperModal(product));
                productGrid.appendChild(card);
            });
        }

        function destroyCropper() {
            if (cropper) {
                cropper.destroy();
                cropper = null;
            }
        }

        function updateCropperControls() {
            const hasCropper = Boolean(cropper);
            resetCropBtn.disabled = !hasCropper;
            changeImageBtn.disabled = !hasCropper;
            applyCropBtn.disabled = !hasCropper;
        }

        function resetModalCropper() {
            destroyCropper();
            cropperImage.removeAttribute('src');
            cropperArea.classList.add('hidden');
            modalDropZone.classList.remove('hidden');
            modalGoogleSection.classList.remove('hidden');
            modalFileInput.value = '';
            imageUrlInput.value = '';
            updateCropperControls();
        }

        function resetCropperView() {
            if (!cropper) {
                showToast('Load an image first.', 'error');
                return;
            }
            cropper.reset();
            showToast('Cropper reset.', 'info');
        }

        function openCropperModal(product) {
            activeProductId = String(product.id);
            modalProductName.textContent = product.name;
            googleSearchInput.value = product.name;
            imageUrlInput.value = '';
            resetModalCropper();
            cropperModal.classList.remove('hidden');
            cropperModal.classList.add('is-open');
            document.body.classList.add('modal-open');

            requestAnimationFrame(() => {
                const modalStyle = window.getComputedStyle(cropperModal);
                const panelRect = modalPanel ? modalPanel.getBoundingClientRect() : null;
                const modalRect = cropperModal.getBoundingClientRect();
                let transformedAncestor = null;

                let node = cropperModal.parentElement;
                while (node && node !== document.body) {
                    const style = window.getComputedStyle(node);
                    if (style.transform !== 'none' || style.filter !== 'none' || style.perspective !== 'none') {
                        transformedAncestor = {
                            tag: node.tagName,
                            id: node.id || null,
                            className: node.className || null,
                            transform: style.transform,
                            filter: style.filter
                        };
                        break;
                    }
                    node = node.parentElement;
                }

                logModalDiagnostics('pre-fix', 'H1', 'Modal computed positioning', {
                    position: modalStyle.position,
                    display: modalStyle.display,
                    alignItems: modalStyle.alignItems,
                    justifyContent: modalStyle.justifyContent,
                    zIndex: modalStyle.zIndex,
                    top: modalStyle.top,
                    left: modalStyle.left
                });
                logModalDiagnostics('pre-fix', 'H2', 'Modal geometry', {
                    modalTop: modalRect.top,
                    modalLeft: modalRect.left,
                    modalWidth: modalRect.width,
                    modalHeight: modalRect.height,
                    viewportHeight: window.innerHeight,
                    scrollY: window.scrollY
                });
                logModalDiagnostics('pre-fix', 'H4', 'Modal panel geometry', {
                    panelTop: panelRect ? panelRect.top : null,
                    panelBottom: panelRect ? panelRect.bottom : null,
                    panelHeight: panelRect ? panelRect.height : null,
                    verticallyCentered: panelRect
                        ? Math.abs((panelRect.top + panelRect.bottom) / 2 - window.innerHeight / 2) < 80
                        : null
                });
                logModalDiagnostics('pre-fix', 'H3', 'Transformed ancestor check', {
                    transformedAncestor
                });
            });
        }

        function closeCropperModal() {
            cropperModal.classList.add('hidden');
            cropperModal.classList.remove('is-open');
            document.body.classList.remove('modal-open');
            activeProductId = null;
            resetModalCropper();
        }

        function initCropperFromDataUrl(dataUrl) {
            if (!dataUrl) {
                showToast('No image data to load.', 'error');
                return;
            }

            destroyCropper();
            cropperImage.src = dataUrl;
            cropperArea.classList.remove('hidden');
            modalDropZone.classList.add('hidden');
            modalGoogleSection.classList.add('hidden');
            updateCropperControls();

            let cropperStarted = false;
            const startCropper = () => {
                if (cropperStarted) {
                    return;
                }
                cropperStarted = true;
                cropperImage.onload = null;
                destroyCropper();
                cropper = new Cropper(cropperImage, {
                    aspectRatio: 1,
                    viewMode: 0,
                    dragMode: 'move',
                    autoCropArea: 1,
                    cropBoxMovable: true,
                    cropBoxResizable: true,
                    toggleDragModeOnDblclick: false,
                    background: false,
                    modal: false,
                    zoomable: true,
                    zoomOnTouch: true,
                    zoomOnWheel: true,
                    wheelZoomRatio: 0.1,
                    ready() {
                        updateCropperControls();
                        const container = cropperArea.querySelector('.cropper-container');
                        // #region agent log
                        logModalDiagnostics('crop-size', 'H2', 'Cropper ready - controls updated', {
                            hasCropper: Boolean(cropper),
                            applyDisabled: applyCropBtn.disabled,
                            wrapWidth: cropperWrap ? cropperWrap.offsetWidth : null,
                            wrapHeight: cropperWrap ? cropperWrap.offsetHeight : null,
                            containerWidth: container ? container.offsetWidth : null,
                            containerHeight: container ? container.offsetHeight : null,
                            panelHeight: modalPanel ? modalPanel.offsetHeight : null,
                            viewportHeight: window.innerHeight,
                            fitsViewport: modalPanel ? modalPanel.offsetHeight <= window.innerHeight : null
                        });
                        // #endregion
                    }
                });
            };

            cropperImage.onload = startCropper;
            if (cropperImage.complete) {
                startCropper();
            }
        }

        function initCropperFromFile(file) {
            if (!file || !file.type.startsWith('image/')) {
                showToast('Please choose a valid image file.', 'error');
                return;
            }

            const reader = new FileReader();
            reader.onload = (event) => {
                initCropperFromDataUrl(event.target.result);
            };
            reader.readAsDataURL(file);
        }

        async function loadImageFromUrl() {
            const url = imageUrlInput.value.trim();
            if (!url) {
                showToast('Paste an image URL first.', 'error');
                return;
            }

            loadUrlBtn.disabled = true;
            loadUrlBtn.textContent = 'Loading...';

            try {
                const response = await fetch('fetch_image_url.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ url })
                });
                const result = await response.json();
                if (!response.ok || !result.success || !result.data_url) {
                    throw new Error(result.message || 'Could not load image from URL.');
                }
                initCropperFromDataUrl(result.data_url);
                showToast('Image loaded. Adjust the crop, then apply.', 'success');
            } catch (error) {
                showToast(error.message || 'Could not load image from URL.', 'error');
            } finally {
                loadUrlBtn.disabled = false;
                loadUrlBtn.textContent = 'Load URL';
            }
        }

        function applyCropToProduct() {
            // #region agent log
            logApplyDiagnostics('H1', 'Apply clicked', {
                activeProductId,
                hasCropper: Boolean(cropper),
                applyDisabled: applyCropBtn.disabled,
                pendingCount: pendingImages.size
            });
            // #endregion

            if (!activeProductId || !cropper) {
                showToast('Cropper is not ready yet. Wait a moment and try again.', 'error');
                return;
            }

            let canvas = null;
            const cropBoxData = cropper.getCropBoxData();
            const cropData = cropper.getData(true);
            try {
                canvas = cropper.getCroppedCanvas();
            } catch (error) {
                // #region agent log
                logApplyDiagnostics('H3', 'getCroppedCanvas threw', {
                    error: error.message || String(error)
                });
                // #endregion
                showToast('Could not crop image.', 'error');
                return;
            }

            if (!canvas) {
                // #region agent log
                logApplyDiagnostics('H3', 'getCroppedCanvas returned null', {});
                // #endregion
                showToast('Could not crop image.', 'error');
                return;
            }

            // #region agent log
            logApplyDiagnostics('H6', 'Canvas export dimensions', {
                cropBoxWidth: cropBoxData.width,
                cropBoxHeight: cropBoxData.height,
                cropWidth: cropData.width,
                cropHeight: cropData.height,
                canvasWidth: canvas.width,
                canvasHeight: canvas.height,
                aspectRatio: canvas.height ? (canvas.width / canvas.height) : null
            });
            // #endregion

            const product = products.find((item) => String(item.id) === activeProductId);
            if (!product) {
                showToast('Product not found.', 'error');
                return;
            }

            let dataUrl = '';
            try {
                dataUrl = canvas.toDataURL('image/png');
            } catch (error) {
                // #region agent log
                logApplyDiagnostics('H4', 'toDataURL threw', {
                    error: error.message || String(error)
                });
                // #endregion
                showToast('Could not export cropped image.', 'error');
                return;
            }

            pendingImages.set(activeProductId, {
                cropped_image: dataUrl,
                preview: dataUrl,
                current_image: product.image_url || ''
            });

            // #region agent log
            logApplyDiagnostics('H5', 'Apply succeeded', {
                activeProductId,
                pendingCount: pendingImages.size
            });
            // #endregion

            updateSaveButton();
            renderProducts();
            closeCropperModal();
            showToast('Image ready. Save all changes when finished.', 'success');
        }

        async function saveAllChanges() {
            if (pendingImages.size === 0) {
                return;
            }

            const images = [];
            pendingImages.forEach((value, productId) => {
                images.push({
                    id: Number(productId),
                    cropped_image: value.cropped_image,
                    current_image: value.current_image
                });
            });

            saveAllBtn.disabled = true;
            saveAllBtn.textContent = 'Saving...';

            try {
                const response = await fetch('bulk_edit_images.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ images })
                });

                const result = await response.json();
                if (!response.ok) {
                    throw new Error(result.message || 'Failed to save images.');
                }

                if (result.updated > 0 && Array.isArray(result.products)) {
                    result.products.forEach((item) => {
                        const product = products.find((entry) => Number(entry.id) === Number(item.id));
                        if (!product) {
                            return;
                        }
                        product.image_url = item.image_url;
                        product.image_src = item.image_src;
                        product.has_image = item.has_image;
                        pendingImages.delete(String(item.id));
                    });
                }

                updateSaveButton();
                renderProducts();

                if (!result.success) {
                    const details = Array.isArray(result.errors) && result.errors.length
                        ? result.errors.join(' ')
                        : (result.message || 'Some images could not be saved.');
                    showToast(details, 'error');
                } else {
                    showToast(result.message || 'Images saved successfully.', 'success');
                }
            } catch (error) {
                showToast(error.message || 'Failed to save images.', 'error');
            } finally {
                updateSaveButton();
            }
        }

        modalFileInput.addEventListener('change', (event) => {
            const file = event.target.files[0];
            if (file) {
                initCropperFromFile(file);
            }
        });

        modalDropZone.addEventListener('dragover', (event) => {
            event.preventDefault();
            modalDropZone.classList.add('border-teal-500');
        });

        modalDropZone.addEventListener('dragleave', () => {
            modalDropZone.classList.remove('border-teal-500');
        });

        modalDropZone.addEventListener('drop', (event) => {
            event.preventDefault();
            modalDropZone.classList.remove('border-teal-500');
            const file = event.dataTransfer.files[0];
            if (file) {
                initCropperFromFile(file);
            }
        });

        applyCropBtn.addEventListener('click', applyCropToProduct);
        resetCropBtn.addEventListener('click', resetCropperView);
        changeImageBtn.addEventListener('click', () => {
            resetModalCropper();
            showToast('Choose another image.', 'info');
        });
        googleSearchBtn.addEventListener('click', () => {
            const query = googleSearchInput.value.trim();
            if (!query) {
                showToast('Enter a product name to search.', 'error');
                return;
            }
            window.open(getGoogleImagesUrl(query), '_blank', 'noopener,noreferrer');
        });
        googleSearchInput.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                googleSearchBtn.click();
            }
        });
        loadUrlBtn.addEventListener('click', loadImageFromUrl);
        imageUrlInput.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                loadImageFromUrl();
            }
        });
        clearPendingBtn.addEventListener('click', () => {
            if (activeProductId && pendingImages.has(activeProductId)) {
                pendingImages.delete(activeProductId);
                updateSaveButton();
                renderProducts();
            }
            resetModalCropper();
            showToast('Pending image cleared for this product.', 'info');
        });
        closeModalBtn.addEventListener('click', closeCropperModal);
        saveAllBtn.addEventListener('click', saveAllChanges);

        searchInput.addEventListener('input', renderProducts);
        categoryFilter.addEventListener('change', renderProducts);
        missingOnlyFilter.addEventListener('change', renderProducts);

        cropperModal.addEventListener('click', (event) => {
            if (event.target === cropperModal) {
                closeCropperModal();
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !cropperModal.classList.contains('hidden')) {
                closeCropperModal();
            }
        });

        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
            document.getElementById('mobileOverlay').classList.toggle('active');
            document.querySelector('.hamburger').classList.toggle('open');
        }

        function closeSidebar() {
            document.getElementById('sidebar').classList.remove('open');
            document.getElementById('mobileOverlay').classList.remove('active');
            document.querySelector('.hamburger').classList.remove('open');
        }

        renderProducts();
        updateSaveButton();
    </script>
</body>
</html>
