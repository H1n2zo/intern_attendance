<?php
function getSetting($key) {
    static $cache = [];
    if (isset($cache[$key])) return $cache[$key];
    $pdo = db();
    $stmt = $pdo->prepare("SELECT value FROM settings WHERE key_name = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    $cache[$key] = $row ? $row['value'] : null;
    return $cache[$key];
}

function getSettingGroup($group) {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM settings WHERE group_name = ? ORDER BY id");
    $stmt->execute([$group]);
    return $stmt->fetchAll();
}
