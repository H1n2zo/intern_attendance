<?php
function renderHead($title = 'EVSU-OC Attendance') {
    echo "<!DOCTYPE html>
<html lang='en'>
<head>
<meta charset='UTF-8'>
<meta name='viewport' content='width=device-width, initial-scale=1.0'>
<title>$title | EVSU-OC</title>
<link rel='stylesheet' href='" . APP_URL . "/assets/css/main.css'>
<script src='https://unpkg.com/feather-icons'></script>
</head>
<body>";
}

function renderSidebar() {
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT m.name, m.slug, m.icon FROM role_modules rm
        JOIN modules m ON rm.module_id = m.id
        WHERE rm.role_id = ? AND rm.can_view = 1 AND m.is_active = 1
        ORDER BY m.sort_order
    ");
    $stmt->execute([$_SESSION['role_id']]);
    $modules = $stmt->fetchAll();

    $current = basename($_SERVER['PHP_SELF'], '.php');

    echo "<aside class='sidebar'>
        <div class='sidebar-brand'>
            <span class='brand-icon'><i data-feather='shield'></i></span>
            <span class='brand-text'>EVSU-OC</span>
        </div>
        <nav class='sidebar-nav'>";

    foreach ($modules as $m) {
        $active = $current === $m['slug'] ? 'active' : '';
        echo "<a href='" . APP_URL . "/pages/admin/{$m['slug']}.php' class='nav-item $active'>
            <i data-feather='{$m['icon']}'></i>
            <span>{$m['name']}</span>
        </a>";
    }

    echo "</nav>
        <div class='sidebar-footer'>
            <div class='user-chip'>
                <span class='chip-avatar'>" . strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1)) . "</span>
                <div>
                    <p class='chip-name'>{$_SESSION['first_name']} {$_SESSION['last_name']}</p>
                    <p class='chip-role'>{$_SESSION['role']}</p>
                </div>
            </div>
            <a href='" . APP_URL . "/pages/logout.php' class='logout-btn'><i data-feather='log-out'></i></a>
        </div>
    </aside>";
}

function renderFoot() {
    echo "<script>feather.replace();</script></body></html>";
}
