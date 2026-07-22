<?php
/**
 * Hubbly POS entry — reuses root cashier home.php, locked to this user's assigned category.
 */
define('HUBBLY_POS', true);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set('Africa/Harare');

if (!isset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['role'])) {
    header('Location: ../');
    exit;
}

if (strtolower((string) $_SESSION['role']) !== 'hubbly') {
    session_unset();
    session_destroy();
    header('Location: ../');
    exit;
}

require_once __DIR__ . '/../ensure_user_role_constraint.php';
$_SESSION['assigned_category'] = getUserAssignedCategory((int) $_SESSION['user_id']);

// Run cashier home from project root so relative DB/asset paths resolve correctly.
chdir(dirname(__DIR__));
ob_start();
require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'home.php';
$html = ob_get_clean();

// Browser URL is /hubbly/home — point relative assets at the site root.
if ($html !== '' && stripos($html, '<base ') === false) {
    $html = preg_replace('/<head([^>]*)>/i', '<head$1><base href="../">', $html, 1) ?? $html;
}

echo $html;
