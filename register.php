<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/mailer/mailer.php';

startSecureSession();

if (isLoggedIn()) {
    $role = $_SESSION['role'];
    if ($role === 'Admin' || $role === 'Instructor') redirect('/pages/admin/dashboard.php');
    else redirect('/pages/intern/home.php');
}

$pdo = db();
$errors = [];
$old    = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = sanitize($_POST['first_name'] ?? '');
    $last_name  = sanitize($_POST['last_name']  ?? '');
    $email      = sanitize($_POST['email']      ?? '');
    $student_id = sanitize($_POST['student_id'] ?? '');
    $company    = sanitize($_POST['company']    ?? '');
    $password   = $_POST['password']         ?? '';
    $confirm    = $_POST['confirm_password'] ?? '';

    $old = compact('first_name', 'last_name', 'email', 'student_id', 'company');

    if (!$first_name)                              $errors[] = 'First name is required.';
    if (!$last_name)                               $errors[] = 'Last name is required.';
    if (!str_ends_with($email, ALLOWED_DOMAIN))    $errors[] = 'Only ' . ALLOWED_DOMAIN . ' emails are allowed.';
    if (strlen($password) < 8)                     $errors[] = 'Password must be at least 8 characters.';
    if ($password !== $confirm)                    $errors[] = 'Passwords do not match.';

    if (empty($errors)) {
        $chk = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $chk->execute([$email]);
        if ($chk->fetchColumn())                   $errors[] = 'An account with this email already exists.';
    }

    if (empty($errors)) {
        $internRoleId = $pdo->query("SELECT id FROM roles WHERE name='Intern'")->fetchColumn();
        $stmt = $pdo->prepare("
            INSERT INTO users (role_id, student_id, first_name, last_name, email, password_hash, company, required_hours, is_verified)
            VALUES (?, ?, ?, ?, ?, ?, ?, 70, 1)
        ");
        $stmt->execute([
            $internRoleId,
            encryptField($student_id),
            $first_name,
            $last_name,
            $email,
            hashPassword($password),
            $company,
        ]);
        $success = true;
        $old = [];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Register | EVSU-OC Attendance</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Space+Mono:wght@700&display=swap">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --navy: #0f2744;
    --navy-mid: #1a3c5e;
    --accent: #e8a020;
    --border: #dde3ef;
    --text: #1c2b3a;
    --muted: #6b7e96;
    --surface: #f7f9fc;
}

body {
    font-family: 'DM Sans', sans-serif;
    background: var(--surface);
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 32px 16px;
}

.register-wrap {
    display: flex;
    width: 900px;
    max-width: 96vw;
    background: #fff;
    border-radius: 18px;
    overflow: hidden;
    box-shadow: 0 12px 48px rgba(15,39,68,.14);
}

.login-panel {
    width: 300px;
    flex-shrink: 0;
    background: var(--navy);
    padding: 48px 36px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    position: relative;
    overflow: hidden;
}

.login-panel::before {
    content: '';
    position: absolute;
    top: -60px; right: -60px;
    width: 220px; height: 220px;
    background: rgba(232,160,32,.12);
    border-radius: 50%;
}

.login-panel::after {
    content: '';
    position: absolute;
    bottom: -40px; left: -40px;
    width: 160px; height: 160px;
    background: rgba(232,160,32,.07);
    border-radius: 50%;
}

.panel-logo {
    display: flex;
    align-items: center;
    gap: 10px;
    z-index: 1;
}

.logo-mark {
    width: 36px; height: 36px;
    background: var(--accent);
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}

.logo-mark svg { width: 20px; height: 20px; stroke: var(--navy); fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

.logo-text {
    font-family: 'Space Mono', monospace;
    font-size: 13px;
    color: #fff;
    letter-spacing: .04em;
}

.panel-body { z-index: 1; }

.panel-heading {
    font-size: 24px;
    font-weight: 700;
    color: #fff;
    line-height: 1.2;
    margin-bottom: 12px;
}

.panel-sub { font-size: 13px; color: rgba(255,255,255,.55); line-height: 1.7; }

.panel-steps {
    z-index: 1;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.step-item {
    display: flex;
    align-items: center;
    gap: 10px;
}

.step-dot {
    width: 22px; height: 22px;
    border-radius: 50%;
    background: rgba(255,255,255,.12);
    display: flex; align-items: center; justify-content: center;
    font-size: 11px;
    font-weight: 700;
    color: rgba(255,255,255,.4);
    flex-shrink: 0;
}

.step-dot.active {
    background: var(--accent);
    color: var(--navy);
}

.step-label {
    font-size: 12px;
    color: rgba(255,255,255,.4);
}

.step-label.active { color: rgba(255,255,255,.9); }

.form-side {
    flex: 1;
    padding: 44px 40px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    overflow-y: auto;
}

.form-heading { font-size: 22px; font-weight: 700; color: var(--text); margin-bottom: 4px; }
.form-sub { font-size: 13px; color: var(--muted); margin-bottom: 24px; }

.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}

.form-group { margin-bottom: 14px; }
.form-group.full { grid-column: 1 / -1; }

label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: var(--text);
    margin-bottom: 6px;
}

.label-opt {
    font-weight: 400;
    color: var(--muted);
    margin-left: 4px;
    font-size: 12px;
}

input {
    width: 100%;
    padding: 10px 13px;
    border: 1px solid var(--border);
    border-radius: 8px;
    font-size: 14px;
    font-family: 'DM Sans', sans-serif;
    outline: none;
    transition: border .2s;
    background: #fff;
    color: var(--text);
}

input:focus {
    border-color: var(--navy-mid);
    box-shadow: 0 0 0 3px rgba(26,60,94,.07);
}

input.has-error { border-color: #dc3545; }

.pw-wrap { position: relative; }
.pw-wrap input { padding-right: 40px; }
.pw-toggle {
    position: absolute;
    right: 12px; top: 50%;
    transform: translateY(-50%);
    background: none; border: none;
    cursor: pointer; padding: 0;
    color: var(--muted);
    display: flex;
}

.pw-toggle svg { width: 16px; height: 16px; }

.btn-submit {
    width: 100%;
    padding: 11px;
    background: var(--navy);
    color: #fff;
    border: none;
    border-radius: 8px;
    font-size: 15px;
    font-weight: 600;
    font-family: 'DM Sans', sans-serif;
    cursor: pointer;
    transition: background .2s;
    margin-top: 4px;
}

.btn-submit:hover { background: var(--navy-mid); }

.alert-err {
    background: #fce8e6;
    color: #c5221f;
    border: 1px solid #f5c6c2;
    border-radius: 8px;
    padding: 10px 14px;
    font-size: 13px;
    margin-bottom: 16px;
}

.alert-err ul { list-style: none; padding: 0; margin: 0; }
.alert-err li + li { margin-top: 4px; }
.alert-err li::before { content: '· '; font-weight: 700; }

.alert-ok {
    background: #e6f4ea;
    color: #1e8e3e;
    border: 1px solid #b7dfbb;
    border-radius: 8px;
    padding: 14px 16px;
    font-size: 14px;
    margin-bottom: 16px;
    text-align: center;
}

.alert-ok strong { display: block; font-size: 15px; margin-bottom: 4px; }

.signin-link {
    text-align: center;
    font-size: 13px;
    color: var(--muted);
    margin-top: 14px;
}

.signin-link a { color: var(--navy); font-weight: 600; text-decoration: none; }
.signin-link a:hover { text-decoration: underline; }

.divider {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 4px 0 14px;
    color: var(--muted);
    font-size: 12px;
}

.divider::before, .divider::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--border);
}

@media (max-width: 720px) {
    .login-panel { display: none; }
    .form-side { padding: 36px 24px; }
    .register-wrap { border-radius: 12px; }
    .form-grid { grid-template-columns: 1fr; }
}
</style>
</head>
<body>

<div class="register-wrap">
    <div class="login-panel">
        <div class="panel-logo">
            <div class="logo-mark">
                <svg viewBox="0 0 24 24"><path d="M12 2L3 7l9 5 9-5-9-5zM3 17l9 5 9-5M3 12l9 5 9-5"/></svg>
            </div>
            <span class="logo-text">EVSU-OC</span>
        </div>

        <div class="panel-body">
            <h1 class="panel-heading">Create your<br>account</h1>
            <p class="panel-sub">Register with your EVSU email to start tracking your internship hours.</p>
        </div>

        <div class="panel-steps">
            <div class="step-item">
                <div class="step-dot active">1</div>
                <span class="step-label active">Fill in your details</span>
            </div>
            <div class="step-item">
                <div class="step-dot">2</div>
                <span class="step-label">Log in to your account</span>
            </div>
            <div class="step-item">
                <div class="step-dot">3</div>
                <span class="step-label">Start recording attendance</span>
            </div>
        </div>
    </div>

    <div class="form-side">
        <?php if ($success): ?>
            <div class="alert-ok">
                <strong>Account created!</strong>
                You can now sign in with your EVSU email.
            </div>
            <p class="signin-link" style="margin-top:0">
                <a href="<?= APP_URL ?>/index.php">← Go to login</a>
            </p>

        <?php else: ?>
            <h2 class="form-heading">Get started</h2>
            <p class="form-sub">All fields are required unless marked optional</p>

            <?php if ($errors): ?>
            <div class="alert-err">
                <ul>
                    <?php foreach ($errors as $e): ?>
                    <li><?= htmlspecialchars($e) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <form method="POST" novalidate>
                <div class="form-grid">
                    <div class="form-group">
                        <label>First Name</label>
                        <input type="text" name="first_name" value="<?= htmlspecialchars($old['first_name'] ?? '') ?>" placeholder="Juan" required autofocus>
                    </div>
                    <div class="form-group">
                        <label>Last Name</label>
                        <input type="text" name="last_name" value="<?= htmlspecialchars($old['last_name'] ?? '') ?>" placeholder="Dela Cruz" required>
                    </div>
                    <div class="form-group full">
                        <label>EVSU Email</label>
                        <input type="email" name="email" value="<?= htmlspecialchars($old['email'] ?? '') ?>" placeholder="you@evsu.edu.ph" required>
                    </div>
                    <div class="form-group">
                        <label>Student ID <span class="label-opt">optional</span></label>
                        <input type="text" name="student_id" value="<?= htmlspecialchars($old['student_id'] ?? '') ?>" placeholder="e.g. 2021-00001">
                    </div>
                    <div class="form-group">
                        <label>Company / OJT <span class="label-opt">optional</span></label>
                        <input type="text" name="company" value="<?= htmlspecialchars($old['company'] ?? '') ?>" placeholder="Where you're deployed">
                    </div>
                </div>

                <div class="divider">password</div>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Password</label>
                        <div class="pw-wrap">
                            <input type="password" name="password" id="pw1" placeholder="Min. 8 characters" required>
                            <button type="button" class="pw-toggle" onclick="togglePw('pw1',this)">
                                <svg id="eye1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            </button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Confirm Password</label>
                        <div class="pw-wrap">
                            <input type="password" name="confirm_password" id="pw2" placeholder="Re-enter password" required>
                            <button type="button" class="pw-toggle" onclick="togglePw('pw2',this)">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            </button>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn-submit">Create Account</button>
            </form>

            <p class="signin-link">Already have an account? <a href="<?= APP_URL ?>/index.php">Sign in</a></p>
        <?php endif; ?>
    </div>
</div>

<script>
function togglePw(id, btn) {
    const input = document.getElementById(id);
    const isText = input.type === 'text';
    input.type = isText ? 'password' : 'text';
    btn.querySelector('svg').style.opacity = isText ? '1' : '.4';
}
</script>
</body>
</html>
