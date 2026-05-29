<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/mailer/mailer.php';

startSecureSession();

if (isLoggedIn()) {
    if ($_SESSION['role'] === 'Intern') {
        header('Location: ' . APP_URL . '/pages/intern/home.php'); exit;
    }
    header('Location: ' . APP_URL . '/pages/admin/dashboard.php'); exit;
}

// Clear MFA and go back to login step
if (isset($_GET['cancel_mfa'])) {
    unset($_SESSION['mfa_pending'], $_SESSION['mfa_user_id']);
    header('Location: ' . APP_URL . '/index.php'); exit;
}

// Resend OTP
if (isset($_GET['resend_otp']) && !empty($_SESSION['mfa_user_id'])) {
    $pdo = db();
    $u = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $u->execute([$_SESSION['mfa_user_id']]);
    $u = $u->fetch();
    if ($u) {
        $code   = generateMFACode();
        $expiry = date('Y-m-d H:i:s', time() + MFA_CODE_EXPIRY);
        $pdo->prepare("UPDATE users SET mfa_code = ?, mfa_expires_at = ? WHERE id = ?")->execute([$code, $expiry, $u['id']]);
        sendMFACode($u['email'], $u['first_name'], $code);
        logAction('mfa_sent', $u['id'], $u['email']);
        $_SESSION['resent'] = true;
    }
    header('Location: ' . APP_URL . '/index.php'); exit;
}

$error   = '';
$success = '';
$step    = !empty($_SESSION['mfa_pending']) ? 'mfa' : 'login';

// Flash from resend OTP
if (isset($_SESSION['resent'])) {
    $success = 'A new code has been sent to your email.';
    unset($_SESSION['resent']);
}

// Flash from successful password reset
if (isset($_SESSION['pw_reset_success'])) {
    $success = 'Password reset successfully. You can now sign in with your new password.';
    unset($_SESSION['pw_reset_success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Step 1: email + password
    if (isset($_POST['email'], $_POST['password'])) {
        $email    = sanitize($_POST['email']);
        $password = $_POST['password'];

        if (!str_ends_with($email, ALLOWED_DOMAIN)) {
            $error = 'Only ' . ALLOWED_DOMAIN . ' emails are allowed.';
        } else {
            $pdo  = db();
            $stmt = $pdo->prepare("SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.email = ? AND u.is_active = 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && verifyPassword($password, $user['password_hash'])) {

                // Check if device is already trusted
                $deviceToken = $_COOKIE['device_token'] ?? null;
                $trusted = false;
                if ($deviceToken) {
                    $chk = $pdo->prepare("SELECT id FROM trusted_devices WHERE user_id = ? AND device_token = ? AND trusted_until > NOW()");
                    $chk->execute([$user['id'], $deviceToken]);
                    $trusted = (bool) $chk->fetchColumn();
                }

                if ($trusted) {
                    // Trusted device — skip OTP
                    $_SESSION['user_id']    = $user['id'];
                    $_SESSION['role_id']    = $user['role_id'];
                    $_SESSION['role']       = $user['role_name'];
                    $_SESSION['first_name'] = $user['first_name'];
                    $_SESSION['last_name']  = $user['last_name'];
                    $_SESSION['email']      = $user['email'];
                    logAction('login_success', $user['id'], $user['email']);
                    if ($user['role_name'] === 'Intern') {
                        header('Location: ' . APP_URL . '/pages/intern/home.php'); exit;
                    }
                    header('Location: ' . APP_URL . '/pages/admin/dashboard.php'); exit;
                }

                // Unknown device — send OTP
                $code   = generateMFACode();
                $expiry = date('Y-m-d H:i:s', time() + MFA_CODE_EXPIRY);
                $pdo->prepare("UPDATE users SET mfa_code = ?, mfa_expires_at = ? WHERE id = ?")->execute([$code, $expiry, $user['id']]);

                if (sendMFACode($user['email'], $user['first_name'], $code)) {
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
    }

    // Step 2: OTP verify
    elseif (isset($_POST['mfa_code'])) {
        $userId    = $_SESSION['mfa_user_id'] ?? null;
        $inputCode = trim(sanitize($_POST['mfa_code']));

        if (!$userId) {
            $error = 'Session expired. Please log in again.';
            $step  = 'login';
            unset($_SESSION['mfa_pending'], $_SESSION['mfa_user_id']);
        } else {
            $pdo  = db();
            $current_time = date('Y-m-d H:i:s');
            $stmt = $pdo->prepare("SELECT u.*, r.name as role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = ? AND mfa_code = ? AND mfa_expires_at > ?");
            $stmt->execute([$userId, $inputCode, $current_time]);
            $user = $stmt->fetch();

            if ($user) {
                $pdo->prepare("UPDATE users SET mfa_code = NULL, mfa_expires_at = NULL WHERE id = ?")->execute([$user['id']]);

                // Trust this device for 7 days
                $token  = bin2hex(random_bytes(32));
                $expiry = date('Y-m-d H:i:s', strtotime('+7 days'));
                $pdo->prepare("
                    INSERT INTO trusted_devices (user_id, device_token, user_agent, ip_address, trusted_until)
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE trusted_until = VALUES(trusted_until)
                ")->execute([$user['id'], $token, $_SERVER['HTTP_USER_AGENT'] ?? null, $_SERVER['REMOTE_ADDR'] ?? null, $expiry]);
                setcookie('device_token', $token, [
                    'expires'  => strtotime('+7 days'),
                    'path'     => '/',
                    'httponly' => true,
                    'samesite' => 'Strict',
                    'secure'   => true,
                ]);

                $_SESSION['user_id']    = $user['id'];
                $_SESSION['role_id']    = $user['role_id'];
                $_SESSION['role']       = $user['role_name'];
                $_SESSION['first_name'] = $user['first_name'];
                $_SESSION['last_name']  = $user['last_name'];
                $_SESSION['email']      = $user['email'];
                unset($_SESSION['mfa_pending'], $_SESSION['mfa_user_id']);

                logAction('login_success', $user['id'], $user['email']);

                if ($user['role_name'] === 'Intern') {
                    header('Location: ' . APP_URL . '/pages/intern/home.php'); exit;
                }
                header('Location: ' . APP_URL . '/pages/admin/dashboard.php'); exit;
            } else {
                logAction('mfa_failed', $userId);
                $error = 'Invalid or expired code. Check your email and try again.';
                $step  = 'mfa';
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

input[type="email"],
input[type="password"] {
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

input[type="email"]:focus,
input[type="password"]:focus {
    border-color: var(--navy-mid);
    box-shadow: 0 0 0 3px rgba(26,60,94,.07);
}

.forgot-row {
    display: flex;
    justify-content: flex-end;
    margin-top: -8px;
    margin-bottom: 16px;
}

.forgot-link {
    font-size: 12px;
    color: var(--muted);
    text-decoration: none;
    font-weight: 500;
    transition: color .15s;
}

.forgot-link:hover { color: var(--navy-mid); text-decoration: underline; }

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

.alert-ok {
    background: #e6f4ea;
    color: #1e8e3e;
    border: 1px solid #b7dfbb;
    border-radius: 8px;
    padding: 10px 14px;
    font-size: 13px;
    margin-bottom: 16px;
}

.back-link {
    font-size: 13px;
    color: var(--navy-mid);
    text-decoration: none;
    display: inline-block;
    margin-bottom: 16px;
}

.back-link:hover { text-decoration: underline; }

.otp-inputs {
    display: flex;
    gap: 8px;
    justify-content: center;
    margin: 20px 0;
}

.otp-box {
    width: 46px;
    height: 52px;
    text-align: center;
    font-size: 22px;
    font-weight: 700;
    font-family: 'Space Mono', monospace;
    border: 1px solid var(--border);
    border-radius: 8px;
    outline: none;
    transition: border .2s, box-shadow .2s;
    background: #fff;
    color: var(--text);
    caret-color: var(--accent);
}

.otp-box:focus {
    border-color: var(--navy-mid);
    box-shadow: 0 0 0 3px rgba(26,60,94,.1);
}

.mfa-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 14px;
}

.mfa-hint { font-size: 12px; color: var(--muted); }

.resend-btn {
    font-size: 13px;
    color: var(--navy-mid);
    background: none;
    border: none;
    cursor: pointer;
    font-family: 'DM Sans', sans-serif;
    font-weight: 600;
    padding: 0;
    text-decoration: underline;
}

.resend-btn:disabled { color: var(--muted); text-decoration: none; cursor: default; }

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
            <p class="form-sub">Sign in with your account</p>

            <?php if ($error):   ?><div class="alert-err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php if ($success): ?><div class="alert-ok"><?= htmlspecialchars($success) ?></div><?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" name="email" placeholder="you@gmail.com" required autofocus>
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" placeholder="••••••••" required>
                </div>
                <div class="forgot-row">
                    <a href="<?= APP_URL ?>/forgot_password.php" class="forgot-link">Forgot password?</a>
                </div>
                <button type="submit" class="btn-login">Continue</button>
            </form>

        <?php else: ?>
            <a href="<?= APP_URL ?>/index.php?cancel_mfa=1" class="back-link">← Back to login</a>
            <h2 class="form-heading">Verify your identity</h2>
            <p class="form-sub">Enter the 6-digit code sent to your email</p>

            <?php if ($error):   ?><div class="alert-err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php if ($success): ?><div class="alert-ok"><?= htmlspecialchars($success) ?></div><?php endif; ?>

            <form method="POST" id="mfa-form" autocomplete="off">
                <div class="otp-inputs">
                    <input type="text" class="otp-box" maxlength="1" inputmode="numeric" autocomplete="off">
                    <input type="text" class="otp-box" maxlength="1" inputmode="numeric" autocomplete="off">
                    <input type="text" class="otp-box" maxlength="1" inputmode="numeric" autocomplete="off">
                    <input type="text" class="otp-box" maxlength="1" inputmode="numeric" autocomplete="off">
                    <input type="text" class="otp-box" maxlength="1" inputmode="numeric" autocomplete="off">
                    <input type="text" class="otp-box" maxlength="1" inputmode="numeric" autocomplete="off">
                </div>
                <input type="hidden" name="mfa_code" id="mfa_code_hidden" value="">
                <button type="submit" class="btn-login">Verify</button>
            </form>

            <div class="mfa-footer">
                <span class="mfa-hint">Code expires in 5 minutes</span>
                <button class="resend-btn" id="resend-btn" onclick="resendOTP()">Resend code</button>
            </div>
        <?php endif; ?>

    </div>
</div>

<script>
(function () {
    const boxes  = document.querySelectorAll('.otp-box');
    const hidden = document.getElementById('mfa_code_hidden');
    const form   = document.getElementById('mfa-form');

    if (!boxes.length || !form) return;

    function getCode() {
        return Array.from(boxes).map(b => b.value.trim()).join('');
    }

    boxes.forEach((box, i) => {
        box.addEventListener('keydown', function (e) {
            if (e.key === 'Backspace') {
                if (box.value === '' && i > 0) {
                    boxes[i - 1].value = '';
                    boxes[i - 1].focus();
                } else {
                    box.value = '';
                }
                e.preventDefault();
            }
        });

        box.addEventListener('input', function () {
            const val = box.value.replace(/\D/g, '');
            box.value = val.slice(-1);
            if (box.value && i < boxes.length - 1) {
                boxes[i + 1].focus();
            }
        });

        box.addEventListener('paste', function (e) {
            e.preventDefault();
            const text = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '');
            text.slice(0, 6).split('').forEach((ch, j) => {
                if (boxes[i + j]) boxes[i + j].value = ch;
            });
            const next = Math.min(i + text.length, boxes.length - 1);
            boxes[next].focus();
        });
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        const code = getCode();
        if (code.length < 6) {
            boxes[code.length < boxes.length ? code.length : 0].focus();
            return;
        }
        hidden.value = code;
        form.submit();
    });

    const resendBtn = document.getElementById('resend-btn');
    let cooldown = 30;

    window.resendOTP = function () {
        if (cooldown > 0) return;
        window.location.href = '<?= APP_URL ?>/index.php?resend_otp=1';
    };

    if (resendBtn) {
        resendBtn.disabled = true;
        resendBtn.textContent = 'Resend in ' + cooldown + 's';
        const timer = setInterval(() => {
            cooldown--;
            if (cooldown <= 0) {
                clearInterval(timer);
                resendBtn.disabled = false;
                resendBtn.textContent = 'Resend code';
            } else {
                resendBtn.textContent = 'Resend in ' + cooldown + 's';
            }
        }, 1000);
    }
})();
</script>
</body>
</html>