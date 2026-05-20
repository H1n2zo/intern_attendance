<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/settings.php';
require_once __DIR__ . '/../../mailer/mailer.php';

startSecureSession();
requireLogin();
requireRole(['Intern']);

$pdo = db();
$userId = $_SESSION['user_id'];

$user = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$user->execute([$userId]);
$user = $user->fetch();

$today = date('Y-m-d');
$todayRecord = $pdo->prepare("SELECT * FROM attendance WHERE user_id = ? AND date = ?");
$todayRecord->execute([$userId, $today]);
$todayRecord = $todayRecord->fetch();

$recentRecords = $pdo->prepare("SELECT * FROM attendance WHERE user_id = ? ORDER BY date DESC LIMIT 10");
$recentRecords->execute([$userId]);
$recentRecords = $recentRecords->fetchAll();

$completed = floatval($user['completed_hours']);
$required = intval($user['required_hours']);
$remaining = max(0, $required - $completed);
$pct = min(100, round(($completed / max(1, $required)) * 100));

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $selfieData = $_POST['selfie_data'] ?? '';

    if ($action === 'time_in' && !$todayRecord) {
        $selfiePath = '';
        if ($selfieData && str_starts_with($selfieData, 'data:image/')) {
            $imgData = explode(',', $selfieData)[1];
            $dir = __DIR__ . '/../../assets/img/selfies/' . $userId;
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $fname = 'in_' . date('Ymd_His') . '.jpg';
            file_put_contents("$dir/$fname", base64_decode($imgData));
            $selfiePath = "assets/img/selfies/$userId/$fname";
        }
        $pdo->prepare("INSERT INTO attendance (user_id, time_in, selfie_in, date, status) VALUES (?,NOW(),?,?,?)")->execute([$userId, $selfiePath, $today, 'ongoing']);
        sendTimeInConfirmation($user);
        notifyInstructorTimeIn($user);
        header("Location: home.php?msg=in"); exit;
    }

    if ($action === 'time_out' && $todayRecord && $todayRecord['status'] === 'ongoing') {
        $selfiePath = '';
        if ($selfieData && str_starts_with($selfieData, 'data:image/')) {
            $imgData = explode(',', $selfieData)[1];
            $dir = __DIR__ . '/../../assets/img/selfies/' . $userId;
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $fname = 'out_' . date('Ymd_His') . '.jpg';
            file_put_contents("$dir/$fname", base64_decode($imgData));
            $selfiePath = "assets/img/selfies/$userId/$fname";
        }
        $timeIn = strtotime($todayRecord['time_in']);
        $timeOut = time();
        $hours = round(($timeOut - $timeIn) / 3600, 2);

        $pdo->prepare("UPDATE attendance SET time_out=NOW(), selfie_out=?, hours_rendered=?, status='completed' WHERE id=?")->execute([$selfiePath, $hours, $todayRecord['id']]);
        $pdo->prepare("UPDATE users SET completed_hours = completed_hours + ? WHERE id = ?")->execute([$hours, $userId]);

        $user = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $user->execute([$userId]);
        $user = $user->fetch();
        $todayRecord['hours_rendered'] = $hours;
        $todayRecord['time_in'] = $todayRecord['time_in'];
        sendTimeOutConfirmation($user, $todayRecord);
        notifyInstructorTimeOut($user, $todayRecord);
        header("Location: home.php?msg=out"); exit;
    }
}

if (isset($_GET['msg'])) {
    $msg = $_GET['msg'] === 'in' ? 'Time-in recorded. Have a great day!' : 'Time-out recorded. Well done!';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Attendance | EVSU-OC</title>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css">
<script src="https://unpkg.com/feather-icons"></script>
<style>
.intern-wrap { max-width: 900px; margin: 0 auto; padding: 24px 20px; }

.intern-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 24px;
}

.intern-brand {
    font-family: 'Space Mono', monospace;
    font-size: 13px;
    color: var(--navy);
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 8px;
}

.intern-brand svg { width: 18px; height: 18px; stroke: var(--accent); }

.hero-card {
    background: var(--navy);
    border-radius: 16px;
    padding: 32px;
    display: flex;
    align-items: center;
    gap: 32px;
    margin-bottom: 20px;
    position: relative;
    overflow: hidden;
}

.hero-card::before {
    content: '';
    position: absolute;
    top: -40px; right: -40px;
    width: 180px; height: 180px;
    background: rgba(232,160,32,.1);
    border-radius: 50%;
}

.hero-info { flex: 1; }
.hero-greeting { font-size: 14px; color: rgba(255,255,255,.5); margin-bottom: 4px; }
.hero-name { font-size: 24px; font-weight: 700; color: #fff; margin-bottom: 4px; }
.hero-company { font-size: 13px; color: rgba(255,255,255,.5); }

.ring-wrap { flex-shrink: 0; }

.camera-card {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 14px;
    overflow: hidden;
    margin-bottom: 20px;
}

.camera-header {
    background: var(--surface);
    padding: 14px 20px;
    border-bottom: 1px solid var(--border);
    font-weight: 600;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.camera-body { padding: 20px; display: flex; gap: 20px; align-items: flex-start; }

#camera-preview {
    width: 260px;
    height: 195px;
    border-radius: 10px;
    background: #000;
    object-fit: cover;
    flex-shrink: 0;
    border: 3px solid var(--border);
}

.camera-controls { flex: 1; display: flex; flex-direction: column; gap: 12px; }
.camera-status { font-size: 13px; color: var(--muted); }
.capture-preview { width: 100%; border-radius: 8px; display: none; border: 2px solid var(--green); }

.time-display {
    font-family: 'Space Mono', monospace;
    font-size: 28px;
    font-weight: 700;
    color: var(--navy);
    margin-bottom: 4px;
}

.date-display { font-size: 13px; color: var(--muted); }
</style>
</head>
<body style="background:var(--surface)">

<div class="intern-wrap">
    <div class="intern-header">
        <div class="intern-brand">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L3 7l9 5 9-5-9-5zM3 17l9 5 9-5M3 12l9 5 9-5"/></svg>
            EVSU-OC
        </div>
        <a href="<?= APP_URL ?>/pages/logout.php" class="btn btn-ghost btn-sm"><i data-feather="log-out"></i> Logout</a>
    </div>

    <?php if ($msg): ?><div class="alert alert-success" style="margin-bottom:16px"><i data-feather="check-circle"></i><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger" style="margin-bottom:16px"><i data-feather="alert-circle"></i><?= htmlspecialchars($err) ?></div><?php endif; ?>

    <div class="hero-card">
        <div class="hero-info">
            <div class="hero-greeting">Good <?= date('H') < 12 ? 'morning' : (date('H') < 17 ? 'afternoon' : 'evening') ?>,</div>
            <div class="hero-name"><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></div>
            <div class="hero-company"><?= htmlspecialchars($user['company'] ?: 'No company assigned') ?></div>

            <div style="margin-top:20px">
                <div style="display:flex;gap:16px">
                    <div>
                        <div style="font-size:11px;color:rgba(255,255,255,.5);margin-bottom:2px;text-transform:uppercase;letter-spacing:.04em">Completed</div>
                        <div style="font-size:20px;font-weight:700;color:var(--accent)"><?= number_format($completed, 1) ?>h</div>
                    </div>
                    <div style="width:1px;background:rgba(255,255,255,.1)"></div>
                    <div>
                        <div style="font-size:11px;color:rgba(255,255,255,.5);margin-bottom:2px;text-transform:uppercase;letter-spacing:.04em">Remaining</div>
                        <div style="font-size:20px;font-weight:700;color:#fff"><?= number_format($remaining, 1) ?>h</div>
                    </div>
                    <div style="width:1px;background:rgba(255,255,255,.1)"></div>
                    <div>
                        <div style="font-size:11px;color:rgba(255,255,255,.5);margin-bottom:2px;text-transform:uppercase;letter-spacing:.04em">Required</div>
                        <div style="font-size:20px;font-weight:700;color:rgba(255,255,255,.7)"><?= $required ?>h</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="ring-wrap">
            <div class="hour-ring">
                <svg width="120" height="120" viewBox="0 0 120 120">
                    <circle cx="60" cy="60" r="50" fill="none" stroke="rgba(255,255,255,.1)" stroke-width="8"/>
                    <circle cx="60" cy="60" r="50" fill="none" stroke="<?= $pct >= 100 ? '#20c05a' : '#e8a020' ?>" stroke-width="8"
                        stroke-dasharray="<?= round(314.16 * $pct / 100) ?> 314.16" stroke-linecap="round"/>
                </svg>
                <div class="hour-ring-text">
                    <div class="hour-ring-value" style="color:#fff"><?= $pct ?>%</div>
                    <div class="hour-ring-label" style="color:rgba(255,255,255,.5)">done</div>
                </div>
            </div>
        </div>
    </div>

    <?php if (!$todayRecord || $todayRecord['status'] === 'ongoing'): ?>
    <div class="camera-card">
        <div class="camera-header">
            <i data-feather="camera"></i>
            <?php if (!$todayRecord): ?>
            Time-In — Take your selfie to start
            <?php else: ?>
            Time-Out — Take your selfie to complete today
            <span style="margin-left:auto;font-weight:400;font-size:12px;color:var(--muted)">Started at <?= date('h:i A', strtotime($todayRecord['time_in'])) ?></span>
            <?php endif; ?>
        </div>
        <div class="camera-body">
            <video id="camera-preview" autoplay playsinline muted></video>
            <div class="camera-controls">
                <div>
                    <div class="time-display" id="clock"></div>
                    <div class="date-display"><?= date('l, F j, Y') ?></div>
                </div>
                <img id="capture-preview" class="capture-preview" alt="Captured selfie">
                <div class="camera-status" id="cam-status">Camera loading...</div>
                <form method="POST" id="attendance-form">
                    <input type="hidden" name="action" value="<?= !$todayRecord ? 'time_in' : 'time_out' ?>">
                    <input type="hidden" name="selfie_data" id="selfie-data">
                    <div style="display:flex;gap:8px">
                        <button type="button" class="btn btn-ghost" id="capture-btn" disabled><i data-feather="camera"></i> Capture</button>
                        <button type="submit" class="btn <?= !$todayRecord ? 'btn-accent' : 'btn-primary' ?>" id="submit-btn" disabled>
                            <i data-feather="<?= !$todayRecord ? 'log-in' : 'log-out' ?>"></i>
                            <?= !$todayRecord ? 'Time In' : 'Time Out' ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="card">
        <div class="card-body" style="text-align:center;padding:32px">
            <div style="width:56px;height:56px;background:#e6f4ea;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;color:#1e8e3e">
                <i data-feather="check-circle" style="width:28px;height:28px"></i>
            </div>
            <div style="font-size:16px;font-weight:700;margin-bottom:4px">Today's attendance complete</div>
            <div style="font-size:13px;color:var(--muted)">
                <?= date('h:i A', strtotime($todayRecord['time_in'])) ?> → <?= date('h:i A', strtotime($todayRecord['time_out'])) ?>
                <span class="badge badge-green" style="margin-left:8px"><?= number_format($todayRecord['hours_rendered'], 2) ?>h rendered</span>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header"><span class="card-title">Attendance History</span></div>
        <table>
            <thead>
                <tr><th>Date</th><th>Time In</th><th>Time Out</th><th>Hours</th><th>Status</th></tr>
            </thead>
            <tbody>
                <?php foreach ($recentRecords as $r): ?>
                <tr>
                    <td style="font-size:13px"><?= date('M d, Y', strtotime($r['date'])) ?></td>
                    <td style="font-size:13px"><?= $r['time_in'] ? date('h:i A', strtotime($r['time_in'])) : '—' ?></td>
                    <td style="font-size:13px"><?= $r['time_out'] ? date('h:i A', strtotime($r['time_out'])) : '—' ?></td>
                    <td><span class="badge badge-<?= $r['hours_rendered'] > 0 ? 'green' : 'gray' ?>"><?= $r['hours_rendered'] > 0 ? number_format($r['hours_rendered'],2).'h' : '—' ?></span></td>
                    <td><span class="badge badge-<?= $r['status']==='completed' ? 'green' : 'amber' ?>"><?= ucfirst($r['status']) ?></span></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$recentRecords): ?>
                <tr><td colspan="5" style="text-align:center;color:var(--muted);padding:24px">No attendance records yet</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
feather.replace();

const video = document.getElementById('camera-preview');
const captureBtn = document.getElementById('capture-btn');
const submitBtn = document.getElementById('submit-btn');
const preview = document.getElementById('capture-preview');
const selfieInput = document.getElementById('selfie-data');
const status = document.getElementById('cam-status');
let captured = false;

navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false })
    .then(stream => {
        video.srcObject = stream;
        captureBtn.disabled = false;
        status.textContent = 'Camera ready. Position your face and capture.';
    })
    .catch(() => {
        status.textContent = 'Camera access denied. Please allow camera permissions.';
    });

captureBtn.addEventListener('click', () => {
    const canvas = document.createElement('canvas');
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    canvas.getContext('2d').drawImage(video, 0, 0);
    const data = canvas.toDataURL('image/jpeg', 0.8);
    selfieInput.value = data;
    preview.src = data;
    preview.style.display = 'block';
    submitBtn.disabled = false;
    captured = true;
    status.textContent = 'Selfie captured. Click the button to record.';
    captureBtn.innerHTML = '<i data-feather="refresh-cw"></i> Retake';
    feather.replace();
});

const clock = document.getElementById('clock');
function updateClock() {
    const now = new Date();
    clock.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}
updateClock();
setInterval(updateClock, 1000);
</script>
</body>
</html>
