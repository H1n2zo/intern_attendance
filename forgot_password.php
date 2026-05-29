<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/mailer/mailer.php';

startSecureSession();

if (isLoggedIn()) {
    if ($_SESSION['role'] === 'Intern') redirect('/pages/intern/home.php');
    redirect('/pages/admin/dashboard.php');
}

// Cancel / restart — go back to step 1
if (isset($_GET['cancel'])) {
    unset($_SESSION['fp_step'], $_SESSION['fp_user_id'], $_SESSION['fp_verified']);
    header('Location: ' . APP_URL . '/forgot_password.php'); exit;
}

// Resend OTP on the OTP step
if (isset($_GET['resend']) && !empty($_SESSION['fp_user_id']) && ($_SESSION['fp_step'] ?? '') === 'otp') {
    $pdo = db();
    $u   = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $u->execute([$_SESSION['fp_user_id']]);
    $u = $u->fetch();
    if ($u) {
        $code   = generateMFACode();
        $expiry = date('Y-m-d H:i:s', time() + MFA_CODE_EXPIRY);
        $pdo->prepare("UPDATE users SET mfa_code = ?, mfa_expires_at = ? WHERE id = ?")->execute([$code, $expiry, $u['id']]);
        sendPasswordResetCode($u['email'], $u['first_name'], $code);
        logAction('password_reset_otp_resent', $u['id'], $u['email']);
        $_SESSION['fp_resent'] = true;
    }
    header('Location: ' . APP_URL . '/forgot_password.php'); exit;
}

$step    = $_SESSION['fp_step'] ?? 'email';
$error   = '';
$success = '';

if (isset($_SESSION['fp_resent'])) {
    $success = 'A new code has been sent to your email.';
    unset($_SESSION['fp_resent']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── Step 1: Email ──────────────────────────────────────────────────────────
    if ($step === 'email' && isset($_POST['email'])) {
        $email = sanitize($_POST['email']);
        $pdo   = db();
        $stmt  = $pdo->prepare("SELECT * FROM users WHERE email = ? AND is_active = 1");
        $stmt->execute([$email]);
        $user  = $stmt->fetch();

        if ($user) {
            $code   = generateMFACode();
            $expiry = date('Y-m-d H:i:s', time() + MFA_CODE_EXPIRY);
            $pdo->prepare("UPDATE users SET mfa_code = ?, mfa_expires_at = ? WHERE id = ?")->execute([$code, $expiry, $user['id']]);
            sendPasswordResetCode($user['email'], $user['first_name'], $code);
            logAction('password_reset_requested', $user['id'], $user['email']);
            $_SESSION['fp_user_id'] = $user['id'];
        }
        // Same message whether user exists or not (prevents email enumeration)
        $_SESSION['fp_step'] = 'otp';
        $step    = 'otp';
        $success = 'If that email is registered, a 6-digit code has been sent.';
    }

    // ── Step 2: OTP ───────────────────────────────────────────────────────────
    elseif ($step === 'otp' && isset($_POST['mfa_code'])) {
        $userId    = $_SESSION['fp_user_id'] ?? null;
        $inputCode = trim(sanitize($_POST['mfa_code']));

        if (!$userId) {
            $error = 'Session expired. Please start again.';
            unset($_SESSION['fp_step'], $_SESSION['fp_user_id'], $_SESSION['fp_verified']);
            $step = 'email';
        } else {
            $pdo  = db();
            $now  = date('Y-m-d H:i:s');
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND mfa_code = ? AND mfa_expires_at > ?");
            $stmt->execute([$userId, $inputCode, $now]);
            $user = $stmt->fetch();

            if ($user) {
                $pdo->prepare("UPDATE users SET mfa_code = NULL, mfa_expires_at = NULL WHERE id = ?")->execute([$userId]);
                $_SESSION['fp_step']     = 'password';
                $_SESSION['fp_verified'] = true;
                $step = 'password';
            } else {
                logAction('password_reset_otp_failed', $userId);
                $error = 'Invalid or expired code. Please try again.';
            }
        }
    }

    // ── Step 3: New password ──────────────────────────────────────────────────
    elseif ($step === 'password' && isset($_POST['new_password'])) {
        $userId   = $_SESSION['fp_user_id'] ?? null;
        $verified = $_SESSION['fp_verified'] ?? false;

        if (!$userId || !$verified) {
            $error = 'Session expired. Please start again.';
            unset($_SESSION['fp_step'], $_SESSION['fp_user_id'], $_SESSION['fp_verified']);
            $step = 'email';
        } else {
            $newpass = $_POST['new_password']      ?? '';
            $confirm = $_POST['confirm_password']  ?? '';

            if (strlen($newpass) < 8) {
                $error = 'Password must be at least 8 characters.';
            } elseif ($newpass !== $confirm) {
                $error = 'Passwords do not match.';
            } else {
                $pdo = db();
                $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([hashPassword($newpass), $userId]);
                logAction('password_reset_success', $userId);
                unset($_SESSION['fp_step'], $_SESSION['fp_user_id'], $_SESSION['fp_verified']);
                $_SESSION['pw_reset_success'] = true;
                header('Location: ' . APP_URL . '/index.php'); exit;
            }
        }
    }
}

// Step order for sidebar progress tracker
$stepOrder = ['email', 'otp', 'password'];
$stepMeta  = [
    'email'    => ['num' => 1, 'label' => 'Enter your email'],
    'otp'      => ['num' => 2, 'label' => 'Verify with OTP'],
    'password' => ['num' => 3, 'label' => 'Set new password'],
];
$currentIdx = array_search($step, $stepOrder);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password | EVSU-OC Attendance</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Space+Mono:wght@700&display=swap">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --navy: #0f2744;
    --navy-mid: #1a3c5e;
    --accent: #e8a020;
    --accent-dim: #f5c05a;
    --border: #dde3ef;
    --text: #1c2b3a;
    --muted: #6b7e96;
    --surface: #f7f9fc;
    --green: #20c05a;
    --red: #dc3545;
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

/* ── Left panel ── */
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

.logo-mark svg {
    width: 20px; height: 20px;
    stroke: var(--navy); fill: none;
    stroke-width: 2; stroke-linecap: round; stroke-linejoin: round;
}

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

/* Step tracker */
.step-track {
    z-index: 1;
    display: flex;
    flex-direction: column;
    gap: 0;
}

.step-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 8px 0;
    position: relative;
}

.step-row:not(:last-child)::after {
    content: '';
    position: absolute;
    left: 11px;
    top: calc(50% + 14px);
    width: 2px;
    height: 16px;
    background: rgba(255,255,255,.12);
}

.step-num {
    width: 24px; height: 24px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 11px; font-weight: 700;
    flex-shrink: 0;
    transition: all .3s;
}

.step-num.done   { background: var(--green); color: #fff; }
.step-num.active { background: var(--accent); color: var(--navy); }
.step-num.todo   { background: rgba(255,255,255,.1); color: rgba(255,255,255,.35); }

.step-text {
    font-size: 13px;
    transition: color .3s;
}

.step-text.active { color: #fff; font-weight: 600; }
.step-text.done   { color: rgba(255,255,255,.65); }
.step-text.todo   { color: rgba(255,255,255,.3); }

/* ── Right form side ── */
.login-form-side {
    width: 380px;
    padding: 48px 40px;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.form-heading { font-size: 22px; font-weight: 700; color: var(--text); margin-bottom: 4px; }
.form-sub { font-size: 13px; color: var(--muted); margin-bottom: 28px; line-height: 1.55; }

.form-group { margin-bottom: 16px; }

label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: var(--text);
    margin-bottom: 6px;
}

input[type="email"],
input[type="password"],
input[type="text"] {
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

/* Password show/hide */
.pw-wrap { position: relative; }
.pw-wrap input { padding-right: 42px; }

.pw-toggle {
    position: absolute;
    right: 12px; top: 50%;
    transform: translateY(-50%);
    background: none; border: none;
    cursor: pointer; padding: 2px;
    color: var(--muted);
    display: flex;
    border-radius: 4px;
    transition: color .15s;
}

.pw-toggle:hover { color: var(--text); }
.pw-toggle svg { width: 16px; height: 16px; }

/* Password strength */
.pw-strength-bar {
    height: 4px;
    background: var(--border);
    border-radius: 99px;
    margin-top: 8px;
    overflow: hidden;
}

.pw-strength-fill {
    height: 100%;
    border-radius: 99px;
    transition: width .3s, background .3s;
    width: 0%;
}

.pw-strength-label {
    font-size: 11px;
    font-weight: 600;
    margin-top: 4px;
    min-height: 16px;
    transition: color .3s;
}

/* Submit button */
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
    margin-top: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.btn-submit:hover { background: var(--navy-mid); }

/* Alerts */
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
    display: inline-flex;
    align-items: center;
    gap: 4px;
    margin-bottom: 18px;
    font-weight: 500;
}

.back-link:hover { text-decoration: underline; }

/* OTP boxes */
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

.signin-link {
    text-align: center;
    font-size: 13px;
    color: var(--muted);
    margin-top: 20px;
}

.signin-link a { color: var(--navy); font-weight: 600; text-decoration: none; }
.signin-link a:hover { text-decoration: underline; }

@media (max-width: 680px) {
    .login-panel { display: none; }
    .login-form-side { width: 100%; padding: 36px 24px; }
    .login-wrap { border-radius: 12px; }
}
</style>
</head>
<body>

<div class="login-wrap">

    <!-- ── Left panel ── -->
    <div class="login-panel">
        <div class="panel-logo">
            <div class="logo-mark">
                <svg viewBox="0 0 24 24"><path d="M12 2L3 7l9 5 9-5-9-5zM3 17l9 5 9-5M3 12l9 5 9-5"/></svg>
            </div>
            <span class="logo-text">EVSU-OC</span>
        </div>

        <div class="panel-body">
            <h1 class="panel-heading">Reset your<br>password</h1>
            <p class="panel-sub">Verify your identity with a one-time code, then choose a new password.</p>
        </div>

        <div class="step-track">
            <?php foreach ($stepOrder as $i => $s):
                if ($i < $currentIdx)      $state = 'done';
                elseif ($i === $currentIdx) $state = 'active';
                else                        $state = 'todo';
            ?>
            <div class="step-row">
                <div class="step-num <?= $state ?>">
                    <?php if ($state === 'done'): ?>
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    <?php else: ?>
                        <?= $stepMeta[$s]['num'] ?>
                    <?php endif; ?>
                </div>
                <span class="step-text <?= $state ?>"><?= $stepMeta[$s]['label'] ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ── Right form side ── -->
    <div class="login-form-side">

        <?php if ($step === 'email'): ?>
            <!-- Step 1: Email -->
            <h2 class="form-heading">Forgot password?</h2>
            <p class="form-sub">Enter your registered email and we'll send you a 6-digit reset code.</p>

            <?php if ($error):   ?><div class="alert-err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php if ($success): ?><div class="alert-ok"><?= htmlspecialchars($success) ?></div><?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" name="email" placeholder="you@gmail.com" required autofocus>
                </div>
                <button type="submit" class="btn-submit">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                    Send Reset Code
                </button>
            </form>

            <p class="signin-link">
                Remember your password? <a href="<?= APP_URL ?>/index.php">Sign in</a>
            </p>

        <?php elseif ($step === 'otp'): ?>
            <!-- Step 2: OTP -->
            <a href="<?= APP_URL ?>/forgot_password.php?cancel=1" class="back-link">← Start over</a>
            <h2 class="form-heading">Check your email</h2>
            <p class="form-sub">Enter the 6-digit code we sent. It expires in 5 minutes.</p>

            <?php if ($error):   ?><div class="alert-err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php if ($success): ?><div class="alert-ok"><?= htmlspecialchars($success) ?></div><?php endif; ?>

            <form method="POST" id="otp-form" autocomplete="off">
                <div class="otp-inputs">
                    <?php for ($i = 0; $i < 6; $i++): ?>
                    <input type="text" class="otp-box" maxlength="1" inputmode="numeric" autocomplete="off">
                    <?php endfor; ?>
                </div>
                <input type="hidden" name="mfa_code" id="mfa_code_hidden" value="">
                <button type="submit" class="btn-submit">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    Verify Code
                </button>
            </form>

            <div class="mfa-footer">
                <span class="mfa-hint">Didn't get a code?</span>
                <button class="resend-btn" id="resend-btn" disabled>Resend in 30s</button>
            </div>

        <?php elseif ($step === 'password'): ?>
            <!-- Step 3: New password -->
            <h2 class="form-heading">Set new password</h2>
            <p class="form-sub">Choose a strong password you haven't used before.</p>

            <?php if ($error): ?><div class="alert-err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

            <form method="POST" id="pw-form">
                <div class="form-group">
                    <label>New Password</label>
                    <div class="pw-wrap">
                        <input type="password" name="new_password" id="pw1" placeholder="Min. 8 characters" required autofocus oninput="checkStrength(this.value)">
                        <button type="button" class="pw-toggle" onclick="togglePw('pw1',this)" tabindex="-1">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                    <div class="pw-strength-bar"><div class="pw-strength-fill" id="strength-fill"></div></div>
                    <div class="pw-strength-label" id="strength-label"></div>
                </div>
                <div class="form-group">
                    <label>Confirm Password</label>
                    <div class="pw-wrap">
                        <input type="password" name="confirm_password" id="pw2" placeholder="Re-enter new password" required>
                        <button type="button" class="pw-toggle" onclick="togglePw('pw2',this)" tabindex="-1">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                </div>
                <button type="submit" class="btn-submit">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    Reset Password
                </button>
            </form>

        <?php endif; ?>

    </div><!-- /form side -->
</div>

<script>
// ── OTP input behaviour ────────────────────────────────────────────────────
(function () {
    const boxes  = document.querySelectorAll('.otp-box');
    const hidden = document.getElementById('mfa_code_hidden');
    const form   = document.getElementById('otp-form');
    if (!boxes.length || !form) return;

    boxes[0].focus();

    boxes.forEach((box, i) => {
        box.addEventListener('keydown', function (e) {
            if (e.key === 'Backspace') {
                if (box.value === '' && i > 0) { boxes[i-1].value = ''; boxes[i-1].focus(); }
                else box.value = '';
                e.preventDefault();
            }
        });
        box.addEventListener('input', function () {
            box.value = box.value.replace(/\D/g,'').slice(-1);
            if (box.value && i < boxes.length - 1) boxes[i+1].focus();
        });
        box.addEventListener('paste', function (e) {
            e.preventDefault();
            const text = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g,'');
            text.slice(0,6).split('').forEach((ch, j) => { if (boxes[i+j]) boxes[i+j].value = ch; });
            boxes[Math.min(i + text.length, boxes.length - 1)].focus();
        });
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        const code = Array.from(boxes).map(b => b.value.trim()).join('');
        if (code.length < 6) { boxes[code.length < boxes.length ? code.length : 0].focus(); return; }
        hidden.value = code;
        form.submit();
    });
})();

// ── Resend OTP countdown ───────────────────────────────────────────────────
(function () {
    const btn = document.getElementById('resend-btn');
    if (!btn) return;
    let t = 30;
    const timer = setInterval(() => {
        t--;
        if (t <= 0) {
            clearInterval(timer);
            btn.disabled = false;
            btn.textContent = 'Resend code';
            btn.onclick = function () {
                window.location.href = '<?= APP_URL ?>/forgot_password.php?resend=1';
            };
        } else {
            btn.textContent = 'Resend in ' + t + 's';
        }
    }, 1000);
})();

// ── Password show/hide ─────────────────────────────────────────────────────
function togglePw(id, btn) {
    const input = document.getElementById(id);
    const isText = input.type === 'text';
    input.type = isText ? 'password' : 'text';
    btn.style.opacity = isText ? '1' : '.4';
}

// ── Password strength indicator ────────────────────────────────────────────
function checkStrength(val) {
    const fill  = document.getElementById('strength-fill');
    const label = document.getElementById('strength-label');
    if (!fill) return;

    let score = 0;
    if (val.length >= 8)               score++;
    if (/[A-Z]/.test(val))             score++;
    if (/[0-9]/.test(val))             score++;
    if (/[^A-Za-z0-9]/.test(val))     score++;

    const levels = [
        { pct: '0%',   color: '',          text: '' },
        { pct: '25%',  color: '#dc3545',   text: 'Weak' },
        { pct: '50%',  color: '#ea8600',   text: 'Fair' },
        { pct: '75%',  color: '#1967d2',   text: 'Good' },
        { pct: '100%', color: '#20c05a',   text: 'Strong' },
    ];

    const lvl = val.length === 0 ? levels[0] : levels[score];
    fill.style.width      = lvl.pct;
    fill.style.background = lvl.color;
    label.textContent     = lvl.text;
    label.style.color     = lvl.color;
}
</script>
</body>
</html>