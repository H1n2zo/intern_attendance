<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

startSecureSession();

if (isLoggedIn()) {
    logAction('logout', $_SESSION['user_id'], $_SESSION['email'] ?? null);
}

session_unset();
session_destroy();

header('Location: ' . APP_URL . '/index.php');
exit;
