<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../mailer/mailer.php';

$pdo = db();
$currentHour = (int) date('H');
$today = date('Y-m-d');

$lateThreshold  = (int) getSetting('late_threshold_hour');
$missingCheckAt = (int) getSetting('missing_check_hour');

$internRoleId = $pdo->query("SELECT id FROM roles WHERE name='Intern'")->fetchColumn();
$interns = $pdo->prepare("SELECT * FROM users WHERE role_id = ? AND is_active = 1");
$interns->execute([$internRoleId]);
$interns = $interns->fetchAll();

foreach ($interns as $intern) {
    $record = $pdo->prepare("SELECT * FROM attendance WHERE user_id = ? AND date = ?");
    $record->execute([$intern['id'], $today]);
    $record = $record->fetch();

    if ($currentHour >= $lateThreshold && !$record) {
        notifyLateIntern($intern);
    }

    if ($currentHour >= $missingCheckAt && !$record) {
        notifyMissingIntern($intern);
    }
}

echo '[' . date('Y-m-d H:i:s') . '] Attendance check complete. ' . count($interns) . " interns checked.\n";
