<?php
/**
 * Shared page chrome (head + sidebar + main open) for invoicing pages.
 * Expects: $pageTitle, $mobileTitle, $roleFolder.
 */
$pageTitle = $pageTitle ?? 'Quotations & Invoicing';
$mobileTitle = $mobileTitle ?? $pageTitle;
$roleFolder = $roleFolder ?? 'admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - POS System</title>
    <script src="../navigation.js" async></script>
    <link href="../src/output.css" rel="stylesheet">
    <link rel="icon" href="../favicon.ico" type="image/png">
    <link rel="stylesheet" href="../src/font-awesome/css/all.min.css">
    <script src="../sweetalert2@11.js"></script>
    <style>
        .fade-in { animation: fadeIn 0.35s ease; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: translateY(0); } }
        .inv-badge { display:inline-flex; align-items:center; padding:2px 10px; border-radius:9999px; font-size:11px; font-weight:600; }
        .inv-sticky-actions { position: sticky; bottom: 0; z-index: 30; }
        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); display:flex; align-items:center; justify-content:center; padding:1rem; opacity:0; visibility:hidden; transition: all .2s ease; z-index: 60; }
        .modal-overlay.active { opacity:1; visibility:visible; }
        .modal-card { background:#fff; border-radius:0.75rem; width:100%; max-width:520px; max-height:90vh; overflow:auto; transform: translateY(8px); transition: transform .2s ease; }
        .modal-overlay.active .modal-card { transform: translateY(0); }
        .inv-toast { position: fixed; top: 1rem; right: 1rem; z-index: 99999; padding: 0.75rem 1rem; border-radius: 0.5rem; color:#fff; box-shadow: 0 10px 25px rgba(0,0,0,.15); display:flex; align-items:center; gap:.5rem; }
        table.inv-table th { position: sticky; top: 0; }
        .spin { animation: spin 1s linear infinite; } @keyframes spin { to { transform: rotate(360deg); } }
        /* Match home.php product image 1:1 ratio */
        #pickerGrid .product-image-container {
            position: relative;
            width: 100%;
            padding-bottom: 100%;
            height: 0 !important;
        }
        #pickerGrid .product-image-container img,
        #pickerGrid .product-image-container > div {
            position: absolute !important;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
        }
    </style>
</head>
<body class="bg-gray-100">
    <div class="flex min-h-screen">
        <?php include __DIR__ . '/../../' . $roleFolder . '/sidebar.php'; ?>
        <div id="mobileOverlay" class="mobile-overlay lg:hidden" onclick="closeSidebar()"></div>
        <div class="content flex-1 lg:ml-64">
            <div class="lg:hidden bg-white shadow-sm p-4 flex items-center justify-between sticky top-0 z-50">
                <div class="hamburger" onclick="toggleSidebar()">
                    <span></span><span></span><span></span>
                </div>
                <h1 class="text-lg font-semibold text-gray-800"><?= htmlspecialchars($mobileTitle) ?></h1>
                <div class="w-8"></div>
            </div>
            <main class="p-4 lg:p-6 fade-in">
