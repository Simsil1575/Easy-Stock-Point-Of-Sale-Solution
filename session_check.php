<?php
require_once __DIR__ . '/config.php';

function checkLogin() {
    if (!isset($_SESSION['user_id']) || $_SESSION['user_id'] === '' || $_SESSION['user_id'] === null) {
        session_unset();
        session_destroy();
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['login_message'] = 'Please log in to continue.';
        header('Location: index.php');
        exit();
    }
}

checkLogin();