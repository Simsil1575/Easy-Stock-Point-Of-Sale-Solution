<?php
$pageTitle = $pageTitle ?? 'Medical Aid';
$mobileTitle = $mobileTitle ?? $pageTitle;
$roleFolder = $roleFolder ?? 'admin';
$maBase = $maBase ?? (($roleFolder === 'cashier') ? '' : '../');
$sidebarPath = ($roleFolder === 'cashier')
    ? __DIR__ . '/../../sidebar.php'
    : __DIR__ . '/../../' . $roleFolder . '/sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - POS System</title>
    <script src="<?= $maBase ?>navigation.js" async></script>
    <link href="<?= $maBase ?>src/output.css" rel="stylesheet">
    <link rel="icon" href="<?= $maBase ?>favicon.ico" type="image/png">
    <link rel="stylesheet" href="<?= $maBase ?>src/font-awesome/css/all.min.css">
    <script src="<?= $maBase ?>sweetalert2@11.js"></script>
    <style>
        .fade-in { animation: fadeIn 0.35s ease; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body class="bg-gray-100">
    <div class="flex min-h-screen">
        <?php include $sidebarPath; ?>
        <div id="mobileOverlay" class="mobile-overlay lg:hidden" onclick="closeSidebar()"></div>
        <div class="content flex-1 lg:ml-64">
            <div class="lg:hidden bg-white shadow-sm p-4 flex items-center justify-between sticky top-0 z-50">
                <div class="hamburger" onclick="toggleSidebar()"><span></span><span></span><span></span></div>
                <h1 class="text-lg font-semibold text-gray-800"><?= htmlspecialchars($mobileTitle) ?></h1>
                <div class="w-8"></div>
            </div>
            <main class="p-4 lg:p-6 fade-in">
