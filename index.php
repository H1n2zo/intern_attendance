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

$error = '';
$success = '';

// Handle cancellation action to go back to username/password input phase
if (isset($_GET['action']) && $_GET['action'] === 'cancel_mfa') {
    unset($_SESSION['mfa_pending']);
    unset($_SESSION['mfa_user_id']);
    redirect('/index.php');
}

// Handle trigger action to generate and resend a fresh multi-factor code
if (isset($_GET['action']) && $_GET['action'] === 'resend_mfa') {
    if (isset($_SESSION['mfa_pending']) && isset($_SESSION['mfa_user_id'])) {
        $userId = $_SESSION['mfa_user_id'];
        $pdo = db();
        
        // Lookup user to acquire their exact contextual parameters
        $stmt = $pdo->prepare("SELECT email, first_name FROM users WHERE id = ? AND is_active = 1");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if ($user) {
            $code = generateMFACode();
            $expiry = date('Y-m-d H:i:s', time() + MFA_CODE_EXPIRY);
            
            $pdo->prepare("UPDATE users SET mfa_code = ?, mfa_expires_at = ? WHERE id = ?")->execute([$code, $expiry, $userId]);
            
            if (sendMFACode($user['email'], $user['first_name'], $code)) {
                logAction('mfa_sent', $userId, $user['email']);
                $success = 'A fresh verification code has been dispatched to your inbox!';
            } else {
                $error = 'Could not deliver mail notification. Please try again.';
            }
        } else {
            redirect('/index.php?action=cancel_mfa');
        }
    } else {
        redirect('/index.php');
    }
}

$step = $_SESSION['mfa_pending'] ?? false ? 'mfa' : 'login';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($step === 'login' && isset($_POST['email']) && isset($_POST['password'])) {
        $email = sanitize($_POST['email']);
        $password = $_POST['password'];

        if (!str_ends_with($email, ALLOWED_DOMAIN)) {
            $error = 'Only @evsu.edu.ph accounts are allowed.';
        } else {
            $pdo = db();
            $stmt = $pdo->prepare("SELECT u.*, r.name as role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.email = ? AND u.is_active = 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && verifyPassword($password, $user['password_hash'])) {
                $code = generateMFACode();
                $expiry = date('Y-m-d H:i:s', time() + MFA_CODE_EXPIRY);

                $pdo->prepare("UPDATE users SET mfa_code = ?, mfa_expires_at = ? WHERE id = ?")->execute([$code, $expiry, $user['id']]);

                $sent = sendMFACode($user['email'], $user['first_name'], $code);
                if ($sent) {
                    $_SESSION['mfa_pending'] = true;
                    $_SESSION['mfa_user_id'] = $user['id'];
                    logAction('mfa_sent', $user['id'], $user['email']);
                    $step = 'mfa';
                } else {
                    $error = 'Could not send verification email. Try again.';
                }
            } else {
                logAction('login_failed', null, $email);
                $error = 'Invalid email or password.';
            }
        }
    } elseif ($step === 'mfa' && isset($_POST['mfa_code'])) {
        $userId = $_SESSION['mfa_user_id'] ?? null;
        $inputCode = sanitize($_POST['mfa_code']);

        if ($userId) {
            $pdo = db();
            $stmt = $pdo->prepare("SELECT u.*, r.name as role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = ? AND mfa_code = ? AND mfa_expires_at > NOW()");
            $stmt->execute([$userId, $inputCode]);
            $user = $stmt->fetch();

            if ($user) {
                $pdo->prepare("UPDATE users SET mfa_code = NULL, mfa_expires_at = NULL WHERE id = ?")->execute([$userId]);

                $_SESSION['user_id'] = $user['id'];
                $_SESSION['role_id'] = $user['role_id'];
                $_SESSION['role'] = $user['role_name'];
                $_SESSION['first_name'] = $user['first_name'];
                $_SESSION['last_name'] = $user['last_name'];
                $_SESSION['email'] = $user['email'];
                unset($_SESSION['mfa_pending'], $_SESSION['mfa_user_id']);

                logAction('login_success', $user['id'], $user['email']);

                if ($user['role_name'] === 'Intern') redirect('/pages/intern/home.php');
                else redirect('/pages/admin/dashboard.php');
            } else {
                logAction('mfa_failed', $userId);
                $error = 'Invalid or expired code.';
                $step = 'mfa';
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
<title>Login | EVSU-OC Attendance</title>
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
}

.login-wrap {
    display: flex;
    width: 840px;
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

.panel-body { z-index: 1; }
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
    width: 380px;
    padding: 48px 40px;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.form-heading { font-size: 22px; font-weight: 700; color: var(--text); margin-bottom: 4px; }
.form-sub { font-size: 13px; color: var(--muted); margin-bottom: 28px; }

.form-group { margin-bottom: 16px; }

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

.mfa-hint {
    font-size: 13px;
    color: var(--muted);
    text-align: center;
    margin-top: 12px;
}

.mfa-utility-links {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 16px;
    font-size: 13px;
}

.back-link {
    font-size: 13px;
    color: var(--navy-mid);
    text-decoration: none;
    font-weight: 500;
}

.back-link:hover {
    text-decoration: underline;
}

.otp-inputs {
    display: flex;
    gap: 8px;
    justify-content: center;
    margin: 20px 0;
}

.otp-inputs input {
    width: 46px;
    text-align: center;
    font-size: 22px;
    font-weight: 700;
    letter-spacing: .02em;
    padding: 10px 6px;
}

@media (max-width: 680px) {
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
            <h1 class="panel-heading">Internship<br>Attendance<br>System</h1>
            <p class="panel-sub">Track your hours, verify your presence, and monitor your internship progress.</p>
        </div>
        <div class="panel-dots">
            <div class="dot fill"></div>
            <div class="dot"></div>
            <div class="dot"></div>
        </div>
    </div>

    <div class="login-form-side">
        <?php if ($step === 'login'): ?>
            <h2 class="form-heading">Welcome back</h2>
            <p class="form-sub">Sign in with your EVSU account</p>

            <?php if ($error): ?>
                <div class="alert-err"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" name="email" placeholder="you@evsu.edu.ph" required autofocus>
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" placeholder="••••••••" required>
                </div>
                <button type="submit" class="btn-login">Continue</button>
            </form>

        <?php else: ?>
            <h2 class="form-heading">Verify your identity</h2>
            <p class="form-sub">A 6-digit code was sent to your EVSU email</p>

            <?php if ($error): ?>
                <div class="alert-err"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert-success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <form method="POST" id="mfa-form">
                <div class="otp-inputs">
                    <?php for ($i = 0; $i < 6; $i++): ?>
                        <input type="text" maxlength="1" class="otp-digit" inputmode="numeric" pattern="[0-9]" autocomplete="one-time-code" required>
                    <?php endfor; ?>
                </div>
                <input type="hidden" name="mfa_code" id="mfa_code_hidden">
                <button type="submit" class="btn-login">Verify</button>
            </form>

            <div class="mfa-utility-links">
                <a href="index.php?action=cancel_mfa" class="back-link">← Back to login</a>
                <a href="index.php?action=resend_mfa" class="back-link">Resend Code</a>
            </div>
            <p class="mfa-hint">Code expires in 5 minutes</p>
        <?php endif; ?>
    </div>
</div>

<script>
const digits = document.querySelectorAll('.otp-digit');
digits.forEach((d, i) => {
    d.addEventListener('input', () => {
        d.value = d.value.replace(/\D/g, '');
        if (d.value && i < digits.length - 1) digits[i + 1].focus();
    });
    d.addEventListener('keydown', e => {
        if (e.key === 'Backspace' && !d.value && i > 0) digits[i - 1].focus();
    });
});

const form = document.getElementById('mfa-form');
if (form) {
    form.addEventListener('submit', (e) => {
        const code = [...digits].map(d => d.value).join('');
        if (code.length !== 6) {
            e.preventDefault();
            alert('Please enter all 6 verification digits.');
        } else {
            document.getElementById('mfa_code_hidden').value = code;
        }
    });
}
</script>
</body>
</html>