<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';

function startSecureSession() {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path' => '/',
            'secure' => false,
            'httponly' => true,
            'samesite' => 'Strict'
        ]);
        session_start();
    }
}

function isLoggedIn() {
    startSecureSession();
    return isset($_SESSION['user_id']) && isset($_SESSION['role']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . APP_URL . '/index.php');
        exit;
    }
}

function requireRole($roles) {
    requireLogin();
    $roles = is_array($roles) ? $roles : [$roles];
    if (!in_array($_SESSION['role'], $roles)) {
        header('Location: ' . APP_URL . '/pages/unauthorized.php');
        exit;
    }
}

function canAccess($moduleSlug, $permission = 'can_view') {
    if (!isLoggedIn()) return false;
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT rm.$permission FROM role_modules rm
        JOIN modules m ON rm.module_id = m.id
        WHERE rm.role_id = ? AND m.slug = ? AND m.is_active = 1
    ");
    $stmt->execute([$_SESSION['role_id'], $moduleSlug]);
    $row = $stmt->fetch();
    return $row && $row[$permission];
}

function encryptField($value) {
    $iv = openssl_random_pseudo_bytes(16);
    $encrypted = openssl_encrypt($value, 'AES-256-CBC', ENCRYPTION_KEY, 0, $iv);
    return base64_encode($iv . $encrypted);
}

function decryptField($value) {
    $decoded = base64_decode($value);
    $iv = substr($decoded, 0, 16);
    $encrypted = substr($decoded, 16);
    return openssl_decrypt($encrypted, 'AES-256-CBC', ENCRYPTION_KEY, 0, $iv);
}

function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

function generateMFACode() {
    return str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function logAction($action, $userId = null, $email = null) {
    $pdo = db();
    $stmt = $pdo->prepare("INSERT INTO login_logs (user_id, email, action, ip_address, user_agent) VALUES (?,?,?,?,?)");
    $stmt->execute([
        $userId,
        $email,
        $action,
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);
}

function sanitize($input) {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

function redirect($path) {
    header("Location: " . APP_URL . $path);
    exit;
}
