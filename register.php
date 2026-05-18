<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

startSecureSession();

// If already logged in, redirect to respective homes
if (isLoggedIn()) {
    $role = $_SESSION['role'];
    if ($role === 'Admin' || $role === 'Instructor') {
        redirect('/pages/admin/dashboard.php');
    } else {
        redirect('/pages/intern/home.php');
    }
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_id = sanitize($_POST['student_id'] ?? '');
    $first_name = sanitize($_POST['first_name'] ?? '');
    $last_name  = sanitize($_POST['last_name'] ?? '');
    $email      = sanitize($_POST['email'] ?? '');
    $company    = sanitize($_POST['company'] ?? '');
    $password   = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Form Validation
    if (empty($first_name) || empty($last_name) || empty($email) || empty($password)) {
        $error = 'Please fill out all required fields.';
    } elseif (!str_ends_with($email, ALLOWED_DOMAIN)) {
        $error = 'Only official university accounts (' . ALLOWED_DOMAIN . ') are allowed.';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } else {
        $pdo = db();

        // Check if email already exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = 'An account with this email already exists.';
        } else {
            // Hash password safely using standard PHP password hashing matching your verify function
            $password_hash = password_hash($password, PASSWORD_BCRYPT);
            
            // Hardcode Role ID to '2' (Intern role from your roles table insert script)
            $role_id = 2; 
            // Standard dynamic default parameters for interns
            $required_hours = 70; 

            try {
                $stmt = $pdo->prepare("INSERT INTO users (role_id, student_id, first_name, last_name, email, password_hash, company, required_hours) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $inserted = $stmt->execute([
                    $role_id,
                    !empty($student_id) ? $student_id : null,
                    $first_name,
                    $last_name,
                    $email,
                    $password_hash,
                    !empty($company) ? $company : null,
                    $required_hours
                ]);

                if ($inserted) {
                    $success = 'Registration successful! You can now sign in.';
                } else {
                    $error = 'Something went wrong during registration. Please try again.';
                }
            } catch (PDOException $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
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
    padding: 20px 0;
}

.login-wrap {
    display: flex;
    width: 900px;
    max-width: 96vw;
    background: #fff;
    border-radius: 18px;
    overflow: hidden;
    box-shadow: 0 12px 48px rgba(15,39,68,.14);
}

.login-panel {
    flex: 1;
    background: var(--navy);
    padding: 48px 40px;
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
}

.logo-mark svg { width: 20px; height: 20px; stroke: var(--navy); fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

.logo-text {
    font-family: 'Space Mono', monospace;
    font-size: 13px;
    color: #fff;
    letter-spacing: .04em;
}

.panel-body { z-index: 1; margin-top: 60px; margin-bottom: 60px; }
.panel-heading {
    font-size: 26px;
    font-weight: 700;
    color: #fff;
    line-height: 1.2;
    margin-bottom: 12px;
}

.panel-sub { font-size: 14px; color: rgba(255,255,255,.55); line-height: 1.6; }

.panel-dots {
    display: flex;
    gap: 8px;
    z-index: 1;
}

.dot {
    width: 7px; height: 7px;
    border-radius: 50%;
    background: rgba(255,255,255,.2);
}

.dot.fill { background: var(--accent); }

.login-form-side {
    width: 460px;
    padding: 48px 40px;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.form-heading { font-size: 22px; font-weight: 700; color: var(--text); margin-bottom: 4px; }
.form-sub { font-size: 13px; color: var(--muted); margin-bottom: 24px; }

.form-row {
    display: flex;
    gap: 12px;
}

.form-group { margin-bottom: 14px; flex: 1; }

label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: var(--text);
    margin-bottom: 6px;
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

.btn-login {
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
    margin-top: 8px;
}

.btn-login:hover { background: var(--navy-mid); }

.alert-err {
    background: #fce8e6;
    color: #c5221f;
    border: 1px solid #f5c6c2;
    border-radius: 8px;
    padding: 10px 14px;
    font-size: 13px;
    margin-bottom: 16px;
}

.alert-success {
    background: #e6f4ea;
    color: #1e8e3e;
    border: 1px solid #b7dfbb;
    border-radius: 8px;
    padding: 10px 14px;
    font-size: 13px;
    margin-bottom: 16px;
}

.login-hint {
    font-size: 13px;
    color: var(--muted);
    text-align: center;
    margin-top: 16px;
}

.login-hint a {
    color: var(--navy-mid);
    text-decoration: none;
    font-weight: 600;
}

.login-hint a:hover {
    text-decoration: underline;
}

@media (max-width: 768px) {
    .login-panel { display: none; }
    .login-form-side { width: 100%; padding: 36px 24px; }
    .login-wrap { border-radius: 12px; }
}
</style>
</head>
<body>

<div class="login-wrap">
    <div class="login-panel">
        <div class="panel-logo">
            <div class="logo-mark">
                <svg viewBox="0 0 24 24"><path d="M12 2L3 7l9 5 9-5-9-5zM3 17l9 5 9-5M3 12l9 5 9-5"/></svg>
            </div>
            <span class="logo-text">EVSU-OC</span>
        </div>
        <div class="panel-body">
            <h1 class="panel-heading">Create an<br>Intern Account</h1>
            <p class="panel-sub">Register your details to gain access to your progress timeline, dashboard logs, and work hours management portal.</p>
        </div>
        <div class="panel-dots">
            <div class="dot"></div>
            <div class="dot fill"></div>
            <div class="dot"></div>
        </div>
    </div>

    <div class="login-form-side">
        <h2 class="form-heading">Get Started</h2>
        <p class="form-sub">Sign up using your verified credentials</p>

        <?php if ($error): ?>
            <div class="alert-err"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-row">
                <div class="form-group">
                    <label>First Name *</label>
                    <input type="text" name="first_name" placeholder="John" value="<?= isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : '' ?>" required autofocus>
                </div>
                <div class="form-group">
                    <label>Last Name *</label>
                    <input type="text" name="last_name" placeholder="Doe" value="<?= isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : '' ?>" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Student ID</label>
                    <input type="text" name="student_id" placeholder="202X-XXXXX" value="<?= isset($_POST['student_id']) ? htmlspecialchars($_POST['student_id']) : '' ?>">
                </div>
                <div class="form-group">
                    <label>Company / Agency</label>
                    <input type="text" name="company" placeholder="EVSU-OC IT Dept" value="<?= isset($_POST['company']) ? htmlspecialchars($_POST['company']) : '' ?>">
                </div>
            </div>

            <div class="form-group">
                <label>Email Address *</label>
                <input type="email" name="email" placeholder="username@evsu.edu.ph" value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>" required>
            </div>

            <div class="form-group">
                <label>Password *</label>
                <input type="password" name="password" placeholder="••••••••" required>
            </div>

            <div class="form-group">
                <label>Confirm Password *</label>
                <input type="password" name="confirm_password" placeholder="••••••••" required>
            </div>

            <button type="submit" class="btn-login">Register Account</button>
        </form>

        <p class="login-hint">Already have an account? <a href="index.php">Sign in here</a></p>
    </div>
</div>

</body>
</html>