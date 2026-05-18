<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
startSecureSession();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Access Denied | EVSU-OC</title>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css">
</head>
<body style="display:flex;align-items:center;justify-content:center;min-height:100vh;background:var(--surface)">
<div style="text-align:center;max-width:360px;padding:40px 24px">
    <div style="width:64px;height:64px;background:#fce8e6;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;color:#c5221f;font-size:28px">🚫</div>
    <h2 style="font-size:20px;font-weight:700;margin-bottom:8px">Access Denied</h2>
    <p style="font-size:14px;color:var(--muted);margin-bottom:24px">You don't have permission to view this page.</p>
    <a href="javascript:history.back()" class="btn btn-ghost">← Go Back</a>
</div>
</body>
</html>
