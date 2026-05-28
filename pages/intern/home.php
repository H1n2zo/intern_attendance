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
$required  = intval($user['required_hours']);
$remaining = max(0, $required - $completed);
$pct       = min(100, round(($completed / max(1, $required)) * 100));

$msg = '';
$err = '';

// --- Time window helpers ---
$nowTime = date('H:i'); // "HH:MM" 24h

function inWindow($nowTime, $from, $to) {
    return $nowTime >= $from && $nowTime <= $to;
}

// Time-in windows
$timeInOnTime  = inWindow($nowTime, '06:00', '08:00'); // 6:00–8:00 AM on-time
$timeInLate    = inWindow($nowTime, '08:01', '10:00'); // 8:01–10:00 AM late
$timeInOpen    = $timeInOnTime || $timeInLate;          // any valid time-in window
$timeInClosed  = $nowTime > '10:00';                    // past cutoff
$timeInTooEarly= $nowTime < '06:00';                    // before opening

// Time-out window
$timeOutOpen    = inWindow($nowTime, '16:00', '18:00'); // 4:00–6:00 PM
$timeOutTooEarly= $nowTime < '16:00';
$timeOutClosed  = $nowTime > '18:00';

// --- POST handler ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action     = $_POST['action'] ?? '';
    $selfieData = $_POST['selfie_data'] ?? '';

    // Re-evaluate windows at POST time (server-authoritative)
    $postTime     = date('H:i');
    $postInOnTime = $postTime >= '06:00' && $postTime <= '08:00';
    $postInLate   = $postTime >= '08:01' && $postTime <= '10:00';
    $postInOpen   = $postInOnTime || $postInLate;
    $postOutOpen  = $postTime >= '16:00' && $postTime <= '18:00';

    if ($action === 'time_in' && !$todayRecord) {
        if ($postTime < '06:00') {
            $err = 'Time-in is not yet open. Please come back at 6:00 AM.';
        } elseif ($postTime > '10:00') {
            $err = 'Time-in is now closed. The cutoff was 10:00 AM.';
        } else {
            $isLate     = $postInLate;
            $selfiePath = '';

            if ($selfieData && str_starts_with($selfieData, 'data:image/')) {
                $imgData = explode(',', $selfieData)[1];
                $dir     = __DIR__ . '/../../assets/img/selfies/' . $userId;
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $fname = 'in_' . date('Ymd_His') . '.jpg';
                file_put_contents("$dir/$fname", base64_decode($imgData));
                $selfiePath = "assets/img/selfies/$userId/$fname";
            }

            $statusVal = $isLate ? 'late' : 'ongoing';
            $pdo->prepare("INSERT INTO attendance (user_id, time_in, selfie_in, date, status) VALUES (?,NOW(),?,?,?)")
                ->execute([$userId, $selfiePath, $today, $statusVal]);

            sendTimeInConfirmation($user);
            notifyInstructorTimeIn($user);
            header("Location: home.php?msg=in" . ($isLate ? "&late=1" : "")); exit;
        }
    }

    if ($action === 'time_out' && $todayRecord && in_array($todayRecord['status'], ['ongoing', 'late'])) {
        if ($postTime < '16:00') {
            $err = 'Time-out is not yet available. Please wait until 4:00 PM.';
        } elseif ($postTime > '18:00') {
            $err = 'Time-out is now closed. The cutoff was 6:00 PM.';
        } else {
            $selfiePath = '';

            if ($selfieData && str_starts_with($selfieData, 'data:image/')) {
                $imgData = explode(',', $selfieData)[1];
                $dir     = __DIR__ . '/../../assets/img/selfies/' . $userId;
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $fname = 'out_' . date('Ymd_His') . '.jpg';
                file_put_contents("$dir/$fname", base64_decode($imgData));
                $selfiePath = "assets/img/selfies/$userId/$fname";
            }

            $timeIn  = strtotime($todayRecord['time_in']);
            $timeOut = time();
            $hours   = round(($timeOut - $timeIn) / 3600, 2);

            $pdo->prepare("UPDATE attendance SET time_out=NOW(), selfie_out=?, hours_rendered=?, status='completed' WHERE id=?")
                ->execute([$selfiePath, $hours, $todayRecord['id']]);
            $pdo->prepare("UPDATE users SET completed_hours = completed_hours + ? WHERE id = ?")
                ->execute([$hours, $userId]);

            $user = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $user->execute([$userId]);
            $user = $user->fetch();
            $todayRecord['hours_rendered'] = $hours;

            sendTimeOutConfirmation($user, $todayRecord);
            notifyInstructorTimeOut($user, $todayRecord);
            header("Location: home.php?msg=out"); exit;
        }
    }
}

if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'in') {
        $msg = isset($_GET['late'])
            ? 'Time-in recorded — you are marked Late.'
            : 'Time-in recorded. Have a great day!';
    } else {
        $msg = 'Time-out recorded. Well done!';
    }
}

// --- Window status badge helper ---
function windowBadge($label, $color) {
    return "<span class=\"badge badge-{$color}\" style=\"font-size:11px\">{$label}</span>";
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
.intern-wrap { max-width: 900px; margin: 0 auto; padding: 16px 12px; }

.intern-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
}

.intern-brand {
    font-family: 'Space Mono', monospace;
    font-size: 14px;
    color: var(--navy);
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 8px;
}
.intern-brand svg { width: 20px; height: 20px; stroke: var(--accent); }

.hero-card {
    background: var(--navy);
    border-radius: 14px;
    padding: 20px;
    display: flex;
    flex-direction: column-reverse;
    gap: 20px;
    margin-bottom: 16px;
    position: relative;
    overflow: hidden;
}

.hero-info { flex: 1; width: 100%; }
.hero-greeting { font-size: 13px; color: rgba(255,255,255,.5); margin-bottom: 4px; }
.hero-name { font-size: 20px; font-weight: 700; color: #fff; margin-bottom: 4px; }
.hero-company { font-size: 13px; color: rgba(255,255,255,.5); }
.ring-wrap { display: flex; justify-content: center; align-items: center; }

.camera-card {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 14px;
    overflow: hidden;
    margin-bottom: 16px;
}

.camera-header {
    background: var(--surface);
    padding: 14px 16px;
    border-bottom: 1px solid var(--border);
    font-weight: 600;
    font-size: 13px;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.camera-header-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 6px;
}

.window-info {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    color: var(--muted);
    font-weight: 400;
}

.camera-body {
    padding: 16px;
    display: flex;
    flex-direction: column;
    gap: 16px;
    align-items: center;
}

#camera-preview {
    width: 100%;
    max-width: 340px;
    height: auto;
    aspect-ratio: 4/3;
    border-radius: 10px;
    background: #000;
    object-fit: cover;
    border: 3px solid var(--border);
}

.camera-controls {
    width: 100%;
    display: flex;
    flex-direction: column;
    gap: 16px;
    text-align: center;
}

.btn-group-mobile {
    display: flex;
    flex-direction: column;
    gap: 8px;
    width: 100%;
}

.btn-group-mobile .btn {
    width: 100%;
    justify-content: center;
    padding: 12px;
}

.camera-status { font-size: 13px; color: var(--muted); }
.capture-preview {
    width: 100%;
    max-width: 340px;
    border-radius: 8px;
    display: none;
    border: 2px solid var(--green);
    margin: 0 auto;
}

.time-display {
    font-family: 'Space Mono', monospace;
    font-size: 32px;
    font-weight: 700;
    color: var(--navy);
    margin-bottom: 2px;
}
.date-display { font-size: 13px; color: var(--muted); }

.window-closed-card {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 28px 20px;
    margin-bottom: 16px;
    text-align: center;
}

.window-closed-icon {
    width: 52px; height: 52px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 12px;
}

.table-responsive {
    width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}
.table-responsive table { min-width: 500px; }

@media (min-width: 576px) {
    .intern-wrap { padding: 24px 20px; }
    .intern-brand { font-size: 15px; }
    .hero-card { padding: 32px; flex-direction: row; gap: 32px; }
    .hero-card::before {
        content: '';
        position: absolute;
        top: -40px; right: -40px;
        width: 180px; height: 180px;
        background: rgba(232,160,32,.1);
        border-radius: 50%;
    }
    .hero-name { font-size: 24px; }
    .camera-header { flex-direction: row; align-items: center; font-size: 14px; }
    .camera-body { flex-direction: row; align-items: flex-start; gap: 20px; }
    #camera-preview { width: 260px; }
    .camera-controls { text-align: left; }
    .btn-group-mobile { flex-direction: row; width: auto; }
    .btn-group-mobile .btn { width: auto; }
    .capture-preview { max-width: 100%; }
}
</style>
</head>
<body style="background:var(--surface)">

<div class="intern-wrap">
    <div class="intern-header">
        <div class="intern-brand">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 2L3 7l9 5 9-5-9-5zM3 17l9 5 9-5M3 12l9 5 9-5"/>
            </svg>
            EVSU-OC
        </div>
        <a href="<?= APP_URL ?>/pages/logout.php" class="btn btn-ghost btn-sm">
            <i data-feather="log-out"></i> Logout
        </a>
    </div>

    <?php if ($msg): ?>
    <div class="alert alert-success" style="margin-bottom:16px">
        <i data-feather="check-circle"></i><?= htmlspecialchars($msg) ?>
    </div>
    <?php endif; ?>
    <?php if ($err): ?>
    <div class="alert alert-danger" style="margin-bottom:16px">
        <i data-feather="alert-circle"></i><?= htmlspecialchars($err) ?>
    </div>
    <?php endif; ?>

    <!-- Hero Card -->
    <div class="hero-card">
        <div class="hero-info">
            <div class="hero-greeting">Good <?= date('H') < 12 ? 'morning' : (date('H') < 17 ? 'afternoon' : 'evening') ?>,</div>
            <div class="hero-name"><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></div>
            <div class="hero-company"><?= htmlspecialchars($user['company'] ?: 'No company assigned') ?></div>
            <div style="margin-top:20px">
                <div style="display:flex;gap:16px;flex-wrap:wrap">
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
                    <circle cx="60" cy="60" r="50" fill="none"
                        stroke="<?= $pct >= 100 ? '#20c05a' : '#e8a020' ?>" stroke-width="8"
                        stroke-dasharray="<?= round(314.16 * $pct / 100) ?> 314.16"
                        stroke-linecap="round"/>
                </svg>
                <div class="hour-ring-text">
                    <div class="hour-ring-value" style="color:#fff"><?= $pct ?>%</div>
                    <div class="hour-ring-label" style="color:rgba(255,255,255,.5)">done</div>
                </div>
            </div>
        </div>
    </div>

    <?php
    // Determine what to show in the attendance action area
    $alreadyDone   = $todayRecord && $todayRecord['status'] === 'completed';
    $canTimeIn     = !$todayRecord && $timeInOpen;
    $canTimeOut    = $todayRecord && in_array($todayRecord['status'], ['ongoing','late']) && $timeOutOpen;
    $showCamera    = $canTimeIn || $canTimeOut;
    ?>

    <?php if ($alreadyDone): ?>
    <!-- Attendance complete for today -->
    <div class="card">
        <div class="card-body" style="text-align:center;padding:32px 16px">
            <div style="width:56px;height:56px;background:#e6f4ea;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;color:#1e8e3e">
                <i data-feather="check-circle" style="width:28px;height:28px"></i>
            </div>
            <div style="font-size:16px;font-weight:700;margin-bottom:4px">Today's attendance complete</div>
            <div style="font-size:13px;color:var(--muted);display:flex;flex-direction:column;align-items:center;gap:6px">
                <span><?= date('h:i A', strtotime($todayRecord['time_in'])) ?> → <?= date('h:i A', strtotime($todayRecord['time_out'])) ?></span>
                <span class="badge badge-green"><?= number_format($todayRecord['hours_rendered'], 2) ?>h rendered</span>
            </div>
        </div>
    </div>

    <?php elseif ($showCamera): ?>
    <!-- Camera card (time-in or time-out window is open) -->
    <div class="camera-card">
        <div class="camera-header">
            <div class="camera-header-row">
                <span style="display:flex;align-items:center;gap:8px">
                    <i data-feather="camera"></i>
                    <?php if (!$todayRecord): ?>
                        Time-In — Take your selfie to start
                    <?php else: ?>
                        Time-Out — Take your selfie to complete today
                    <?php endif; ?>
                </span>
                <?php if ($todayRecord): ?>
                <span style="font-weight:400;font-size:12px;color:var(--muted)">
                    Started at <?= date('h:i A', strtotime($todayRecord['time_in'])) ?>
                </span>
                <?php endif; ?>
            </div>
            <div class="window-info">
                <?php if (!$todayRecord): ?>
                    <?php if ($timeInOnTime): ?>
                        <?= windowBadge('On Time', 'green') ?> Open until 8:00 AM &nbsp;·&nbsp; Late window: 8:01–10:00 AM
                    <?php elseif ($timeInLate): ?>
                        <?= windowBadge('Late', 'amber') ?> Late window — closes at 10:00 AM
                    <?php endif; ?>
                <?php else: ?>
                    <?= windowBadge('Time-Out Open', 'green') ?> Window closes at 6:00 PM
                <?php endif; ?>
            </div>
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
                <form method="POST" id="attendance-form" style="width:100%">
                    <input type="hidden" name="action" value="<?= !$todayRecord ? 'time_in' : 'time_out' ?>">
                    <input type="hidden" name="selfie_data" id="selfie-data">
                    <div class="btn-group-mobile">
                        <button type="button" class="btn btn-ghost" id="capture-btn" disabled>
                            <i data-feather="camera"></i> Capture
                        </button>
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
    <!-- Window closed / not yet open — show informational card -->
    <?php
    if (!$todayRecord) {
        // Time-in situation
        if ($timeInTooEarly) {
            $icon = 'clock'; $iconBg = '#e8f0fe'; $iconColor = '#1967d2';
            $title = 'Time-In Opens at 6:00 AM';
            $sub   = 'On-time window: 6:00 – 8:00 AM &nbsp;·&nbsp; Late window: 8:01 – 10:00 AM';
        } else {
            // $timeInClosed
            $icon = 'x-circle'; $iconBg = '#fce8e6'; $iconColor = '#c5221f';
            $title = 'Time-In Closed';
            $sub   = 'The time-in cutoff (10:00 AM) has passed. Please see your instructor.';
        }
    } else {
        // Has a record but status is ongoing/late — time-out situation
        if ($timeOutTooEarly) {
            $icon = 'clock'; $iconBg = '#fef7e0'; $iconColor = '#ea8600';
            $title = 'Time-Out Opens at 4:00 PM';
            $sub   = 'You are clocked in since ' . date('h:i A', strtotime($todayRecord['time_in'])) . '. Come back at 4:00 PM to time out.';
        } else {
            // $timeOutClosed
            $icon = 'x-circle'; $iconBg = '#fce8e6'; $iconColor = '#c5221f';
            $title = 'Time-Out Closed';
            $sub   = 'The time-out cutoff (6:00 PM) has passed. Please contact your instructor.';
        }
    }
    ?>
    <div class="window-closed-card">
        <div class="window-closed-icon" style="background:<?= $iconBg ?>;color:<?= $iconColor ?>">
            <i data-feather="<?= $icon ?>" style="width:26px;height:26px"></i>
        </div>
        <div style="font-size:16px;font-weight:700;margin-bottom:6px"><?= $title ?></div>
        <div style="font-size:13px;color:var(--muted)"><?= $sub ?></div>

        <?php if ($timeInTooEarly || $timeOutTooEarly): ?>
        <div style="margin-top:16px;font-size:12px;color:var(--muted)">
            <div class="time-display" id="clock-waiting" style="font-size:24px;margin-bottom:0"></div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Attendance History -->
    <div class="card">
        <div class="card-header"><span class="card-title">Attendance History</span></div>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr><th>Date</th><th>Time In</th><th>Time Out</th><th>Hours</th><th>Status</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($recentRecords as $r): ?>
                    <tr>
                        <td style="font-size:13px"><?= date('M d, Y', strtotime($r['date'])) ?></td>
                        <td style="font-size:13px"><?= $r['time_in']  ? date('h:i A', strtotime($r['time_in']))  : '—' ?></td>
                        <td style="font-size:13px"><?= $r['time_out'] ? date('h:i A', strtotime($r['time_out'])) : '—' ?></td>
                        <td>
                            <span class="badge badge-<?= $r['hours_rendered'] > 0 ? 'green' : 'gray' ?>">
                                <?= $r['hours_rendered'] > 0 ? number_format($r['hours_rendered'], 2) . 'h' : '—' ?>
                            </span>
                        </td>
                        <td>
                            <?php
                            $sBadge = match($r['status']) {
                                'completed' => 'green',
                                'late'      => 'amber',
                                default     => 'amber',
                            };
                            ?>
                            <span class="badge badge-<?= $sBadge ?>"><?= ucfirst($r['status']) ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$recentRecords): ?>
                    <tr><td colspan="5" style="text-align:center;color:var(--muted);padding:24px">No attendance records yet</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
feather.replace();

// --- Clock (used in camera card and waiting card) ---
function updateClock() {
    const now = new Date();
    const t   = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    const c1  = document.getElementById('clock');
    const c2  = document.getElementById('clock-waiting');
    if (c1) c1.textContent = t;
    if (c2) c2.textContent = t;
}
updateClock();
setInterval(updateClock, 1000);

// --- Camera logic (only runs when camera card is present) ---
const video      = document.getElementById('camera-preview');
const captureBtn = document.getElementById('capture-btn');
const submitBtn  = document.getElementById('submit-btn');
const preview    = document.getElementById('capture-preview');
const selfieInput= document.getElementById('selfie-data');
const camStatus  = document.getElementById('cam-status');

if (video) {
    let isCaptured = false;

    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false })
        .then(stream => {
            video.srcObject = stream;
            captureBtn.disabled = false;
            camStatus.textContent = 'Camera ready. Position your face and capture.';
        })
        .catch(() => {
            camStatus.textContent = 'Camera access denied. Please allow camera permissions.';
        });

    captureBtn.addEventListener('click', () => {
        if (!isCaptured) {
            const canvas = document.createElement('canvas');
            canvas.width  = video.videoWidth;
            canvas.height = video.videoHeight;
            canvas.getContext('2d').drawImage(video, 0, 0);

            const data = canvas.toDataURL('image/jpeg', 0.8);
            selfieInput.value      = data;
            preview.src            = data;
            video.style.display    = 'none';
            preview.style.display  = 'block';
            submitBtn.disabled     = false;
            isCaptured             = true;
            camStatus.textContent  = 'Selfie captured. Click the button to record.';
            captureBtn.innerHTML   = '<i data-feather="refresh-cw"></i> Retake';
        } else {
            selfieInput.value      = '';
            preview.src            = '';
            preview.style.display  = 'none';
            video.style.display    = 'block';
            submitBtn.disabled     = true;
            isCaptured             = false;
            camStatus.textContent  = 'Camera ready. Position your face and capture.';
            captureBtn.innerHTML   = '<i data-feather="camera"></i> Capture';
        }
        feather.replace();
    });

    // Client-side window guard (mirrors server-side)
    (function enforceTimeWindow() {
        const now  = new Date();
        const hhmm = now.getHours() * 100 + now.getMinutes();
        const action = document.querySelector('input[name="action"]')?.value;

        if (action === 'time_in'  && (hhmm < 600  || hhmm > 1000)) {
            captureBtn.disabled   = true;
            submitBtn.disabled    = true;
            camStatus.textContent = hhmm < 600
                ? 'Time-in opens at 6:00 AM.'
                : 'Time-in closed at 10:00 AM.';
        }
        if (action === 'time_out' && (hhmm < 1600 || hhmm > 1800)) {
            captureBtn.disabled   = true;
            submitBtn.disabled    = true;
            camStatus.textContent = hhmm < 1600
                ? 'Time-out opens at 4:00 PM.'
                : 'Time-out closed at 6:00 PM.';
        }
    })();
}
</script>
</body>
</html>